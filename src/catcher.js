/*
Use to catch all exception
    try {
        ...
    } catch (e) {
        errorCatcher(e);
    }
*/

window.onerror = function (msg, url, line, col, error) {
    if (msg.indexOf('Script error.') > -1) {
        return;
    }
    //console.info('window.onerror * ', msg, url, line, col, error, '*');
    var errStack;
    if (error && typeof error.stack !== "undefined")
        errStack = error.stack;
    errorCatcher(msg, url, line, errStack);
    return false;
};

//Exceptions
function errorCatcher(msg, url, line, errStack) {
    if (!navigator.userAgent) return;
    var arg = '';

    if (!errStack && typeof arguments.callee !== "undefined") {
        if (typeof arguments.callee.caller !== "undefined" && arguments.callee.caller) {
            arg += '+ ' + (typeof arguments.callee.caller.name === "undefined" ? '' : arguments.callee.caller.name);
            arg += ' + ' + arguments.callee.caller.toString();
            if (arg.indexOf('window.onerror') >= 0 || arg.indexOf('var errStack') >= 0)
                arg = "window.onerror";
            else
                arg = arg.replace(/\n/g, " ");
            if (typeof arguments.callee.caller.caller !== "undefined" && arguments.callee.caller.caller) {
                var arg2 = '* ' + (typeof arguments.callee.caller.caller.name === "undefined" ? '' : arguments.callee.caller.caller.name);
                arg2 += ' * ' + arguments.callee.caller.caller.toString();
                arg = arg2.replace(/\n/g, " ");
            }
        }
    } else {
        arg = errStack.replace(/\n/g, " | ");
    }

    if (!url) {
        url = window.location.toString();
    }

    if (msg) {
        msg = msg.replace(/\n/g, " ");
        msg = msg.substr(0, 1000);
    }

    console.log('ErrException', msg, arg, url);

    var xmlhttp = new XMLHttpRequest();
    xmlhttp.open("POST", '/index.php?errorsJs=' + (new Date().getTime() / 1000), true);
    xmlhttp.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xmlhttp.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    var query = 'm=' + encodeURIComponent(msg) +
        '&r=' + encodeURIComponent(document.referrer) +
        '&u=' + encodeURIComponent(url);
    if (line) query += '&l=' + encodeURIComponent(line);
    if (arg) query += '&s=' + encodeURIComponent(arg.substr(0, 1000));
    xmlhttp.send(query);
}