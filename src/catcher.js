/*
PHPErrorCatcher browser-side error collector.

Inclusion:
  - static:  <script src="src/catcher.js"></script>
             (opt. window.jsLogSecret — legacy shared-secret)
  - dynamic: <script src="/catcher.js"></script>
             (JsLogPlugin serves the script with the key and token injected)

Manual report:
    try { ... } catch (e) { errorCatcher(e); }
    errorCatcher('custom error');

Optional parameters (window.*):
  jsLogKey        name of the GET-parameter trigger (default 'catcherLogName')
  jsLogUrl        URL of the log-intake endpoint (default '/')
  jsLogToken      stateless token (injected by the server) or jsLogSecret (legacy)
  jsVer           application build version
  jsLogAllowHosts array of application domains — only report errors from them
  jsLogBotRe      extra RegExp to filter bots by User-Agent
*/
(function () {
    'use strict';

    var originalConsoleError = window.console.error;

    var CONFIG = {
        url: (typeof jsLogUrl !== 'undefined') ? jsLogUrl : '/',
        key: (typeof jsLogKey !== 'undefined') ? jsLogKey : 'catcherLogName',
        token: (typeof jsLogToken !== 'undefined' && jsLogToken)
            ? jsLogToken
            : ((typeof jsLogSecret !== 'undefined') ? jsLogSecret : ''),
        ver: (typeof jsVer !== 'undefined') ? jsVer : 0,
        maxMsg: 1600,
        maxStack: 3200,
        dedupWindowMs: 5000,
        maxSendPerSession: 50,
        maxBreadcrumbs: 20
    };

    // Known bots/crawlers/headless.
    var BOT_RE = /bot|crawl|spider|slurp|mediapartners|headless|phantom|puppeteer|playwright|lighthouse|pingdom|monitor|uptime|curl|wget|python-requests|facebookexternalhit|whatsapp|telegrambot/i;

    var recentSends = {}; // signature -> ts, for de-duplication
    var recentSendsKeys = 0;
    var sentCount = 0;
    var breadcrumbs = [];
    var _sid = '';

    function now() {
        return Date.now ? Date.now() : (new Date()).getTime();
    }

    function rand() {
        return now().toString(36) + Math.random().toString(36).slice(2, 8);
    }

    function sessionId() {
        if (_sid) {
            return _sid;
        }
        try {
            _sid = window.sessionStorage.getItem('pecSid') || '';
            if (!_sid) {
                _sid = rand();
                window.sessionStorage.setItem('pecSid', _sid);
            }
        } catch (e) {
            _sid = _sid || rand();
        }
        return _sid;
    }

    // --- serialization with cycle protection ---
    function stringifyOnce(obj) {
        var seen = [];
        try {
            return JSON.stringify(obj, function (key, value) {
                if (typeof value === 'object' && value !== null) {
                    if (seen.indexOf(value) !== -1) {
                        return '[circular]';
                    }
                    seen.push(value);
                }
                return value;
            });
        } catch (e) {
            return String(obj);
        }
    }

    // --- filtering ---
    function isBot() {
        try {
            if (navigator.webdriver) {
                return true;
            }
        } catch (e) { /* noop */ }
        var ua = (navigator && navigator.userAgent) ? navigator.userAgent : '';
        if (!ua) {
            return true; // no User-Agent — not a real browser
        }
        if (BOT_RE.test(ua)) {
            return true;
        }
        if (typeof jsLogBotRe !== 'undefined' && jsLogBotRe) {
            try {
                if (jsLogBotRe.test(ua)) {
                    return true;
                }
            } catch (e) { /* noop */ }
        }
        return false;
    }

    function isExtensionUrl(s) {
        return /(?:chrome|moz|safari|webkit|ms-browser)-extension:\/\//i.test(s || '');
    }

    function hostFrom(u) {
        if (!u) {
            return '';
        }
        try {
            var a = document.createElement('a');
            a.href = u;
            return a.hostname || '';
        } catch (e) {
            return '';
        }
    }

    function shouldReport(msg, url, stack, hadRealStack) {
        var m = (msg || '').toString().trim();
        // cross-origin "Script error." with no real stack is useless noise.
        // Rely on hadRealStack (was there an original Error/stack before normalize),
        // since normalize() fabricates a stub stack so !stack is always false here.
        if (/^script error\.?$/i.test(m) && !hadRealStack) {
            return false;
        }
        // browser-extension errors
        if (isExtensionUrl(url) || isExtensionUrl(stack)) {
            return false;
        }
        // opt. allow-list of application domains
        if (typeof jsLogAllowHosts !== 'undefined' && jsLogAllowHosts && jsLogAllowHosts.length) {
            var host = hostFrom(url);
            if (host && jsLogAllowHosts.indexOf(host) === -1) {
                return false;
            }
        }
        return true;
    }

    // --- breadcrumbs (anonymized: type + tag + selector, no text) ---
    function selectorFor(el) {
        if (!el || !el.tagName) {
            return '';
        }
        var s = el.tagName.toLowerCase();
        if (el.id) {
            s += '#' + el.id;
        } else if (typeof el.className === 'string' && el.className) {
            var c = el.className.trim().split(/\s+/).slice(0, 3).join('.');
            if (c) {
                s += '.' + c;
            }
        }
        return s.substring(0, 100);
    }

    function pushBreadcrumb(crumb) {
        crumb.ts = now();
        breadcrumbs.push(crumb);
        if (breadcrumbs.length > CONFIG.maxBreadcrumbs) {
            breadcrumbs.shift();
        }
    }

    function bindBreadcrumbs() {
        try {
            window.addEventListener('click', function (e) {
                var t = e.target;
                pushBreadcrumb({
                    t: 'click',
                    tag: (t && t.tagName) ? t.tagName.toLowerCase() : '',
                    sel: selectorFor(t)
                });
            }, true);
        } catch (e) { /* noop */ }

        function navCrumb() {
            try {
                pushBreadcrumb({ t: 'nav', sel: location.pathname });
            } catch (e) { /* noop */ }
        }
        try {
            window.addEventListener('popstate', navCrumb);
            window.addEventListener('hashchange', navCrumb);
        } catch (e) { /* noop */ }
        navCrumb();
    }

    function buildCtx() {
        var ctx = { sid: sessionId(), bld: CONFIG.ver };
        try {
            ctx.vp = window.innerWidth + 'x' + window.innerHeight;
        } catch (e) { /* noop */ }
        try {
            ctx.scr = window.screen.width + 'x' + window.screen.height;
        } catch (e) { /* noop */ }
        if (breadcrumbs.length) {
            ctx.bc = breadcrumbs.slice();
        }
        return ctx;
    }

    // --- normalize the error into {msg, url, line, col, stack} ---
    function normalize(msg, url, line, col, err) {
        var stack = '';
        if (msg && msg instanceof Error) {
            stack = msg.stack || '';
            msg = msg.message;
        } else if (err && err instanceof Error) {
            stack = (err.message || '') + ';\n% ' + (err.stack || '');
        } else if (typeof err === 'string' && err) {
            stack = err;
        } else {
            // no Error object — capture the current stack (instead of arguments.callee)
            try {
                throw new Error('catcher');
            } catch (e) {
                stack = '* ' + (e.stack || '');
            }
        }
        if (msg && typeof msg !== 'string') {
            msg = stringifyOnce(msg);
        }
        return {
            msg: msg || '',
            url: url || location.toString(),
            line: line,
            col: col,
            stack: stack
        };
    }

    // --- sending ---
    function send(message, n) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', CONFIG.url + '?' + encodeURIComponent(CONFIG.key) + '=' + (now() / 1000), true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        if (CONFIG.token) {
            xhr.setRequestHeader('X-Log-Secret', CONFIG.token);
        }

        var query = 'm=' + encodeURIComponent(message) +
            '&v=' + encodeURIComponent(CONFIG.ver) +
            '&r=' + encodeURIComponent(document.referrer) +
            '&u=' + encodeURIComponent(n.url) +
            '&ua=' + encodeURIComponent((navigator && navigator.userAgent) || '');
        if (n.line) {
            query += '&l=' + encodeURIComponent(n.line + (n.col ? ':' + n.col : ''));
        }
        if (n.stack) {
            query += '&s=' + encodeURIComponent(('' + n.stack).replace(/\n/g, '||').substring(0, CONFIG.maxStack));
        }
        try {
            query += '&ctx=' + encodeURIComponent(stringifyOnce(buildCtx()));
        } catch (e) { /* noop */ }
        xhr.send(query);
    }

    function errorCatcher(msg, url, line, col, err) {
        if (isBot()) {
            return;
        }
        // whether there was a real Error/stack BEFORE normalize() fabricated one —
        // needed to filter out cross-origin "Script error." with no stack
        var hadRealStack = (msg instanceof Error) || (err instanceof Error) || (typeof err === 'string' && !!err);
        var n = normalize(msg, url, line, col, err);
        if (!shouldReport(n.msg, n.url, n.stack, hadRealStack)) {
            return;
        }

        if (typeof app !== 'undefined' && app && app.debug) {
            alert('Debug. An error occurred:\n' + n.msg);
        }

        var message = (typeof n.msg === 'string') ? n.msg : stringifyOnce(n.msg);
        message = message.replace(/\n/g, '||').substring(0, CONFIG.maxMsg);

        // de-dup identical messages within a window + per-session cap
        var sig = message + '|' + n.url;
        var t = now();
        if (recentSends[sig] && (t - recentSends[sig]) < CONFIG.dedupWindowMs) {
            return;
        }
        // bound the growth of the signature set in a long-lived session
        if (recentSendsKeys >= 200) {
            recentSends = {};
            recentSendsKeys = 0;
        }
        recentSends[sig] = t;
        recentSendsKeys++;
        if (sentCount >= CONFIG.maxSendPerSession) {
            return;
        }
        sentCount++;

        send(message, n);
    }

    // --- interceptors ---
    console.error = function () {
        var args = Array.prototype.slice.call(arguments);
        originalConsoleError.apply(window.console, args); // always mirror to the console
        try {
            var parts = args.map(function (a) {
                if (a instanceof Error) {
                    return (a.message || '') + '\n' + (a.stack || '');
                }
                if (typeof a === 'object' && a !== null) {
                    return stringifyOnce(a);
                }
                return String(a);
            });
            errorCatcher(parts.join(' '));
        } catch (e) {
            originalConsoleError('catcher.js failed', e);
        }
    };

    window.onerror = function (msg, url, line, col, err) {
        try {
            errorCatcher(msg, url, line, col, err);
        } catch (e) { /* noop */ }
        return false;
    };

    try {
        window.addEventListener('unhandledrejection', function (e) {
            var reason = e ? e.reason : null;
            if (reason instanceof Error) {
                errorCatcher(reason);
            } else {
                errorCatcher('UnhandledRejection: ' +
                    (typeof reason === 'object' ? stringifyOnce(reason) : String(reason)));
            }
        });

        // resource errors (img/script/link failed to load) — caught only in the
        // capture phase; regular JS errors come through window.onerror, not duplicated.
        window.addEventListener('error', function (e) {
            var t = e && e.target;
            if (t && t !== window && t.tagName && (t.src || t.href)) {
                errorCatcher('ResourceError: <' + t.tagName.toLowerCase() + '> ' + (t.src || t.href),
                    location.toString());
            }
        }, true);
    } catch (e) { /* noop */ }

    bindBreadcrumbs();

    window.errorCatcher = errorCatcher;
})();
