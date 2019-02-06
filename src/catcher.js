/*
Use to catch all exception
    try {
        ...
    } catch (e) {
        errorCatcher(e);
    }
*/

window.onerror = function (msg, url, line, col, error) {
    if (msg && msg.indexOf('Script error.') > -1) {
        return;
    }
    var errStack;
    if (error) {
        if (typeof error.stack !== "undefined")
            errStack = error.stack;
        else
            errStack = JSON.stringify(error);
    }
    errorCatcher(msg, url, line, errStack);
    return false;
};

//Exceptions
function errorCatcher(msg, url, line, errStack) {
    if (!navigator.userAgent) return;
    if (msg instanceof Error) {
        errStack = msg.stack;
        msg = msg.message;
    }

    if (!errStack) {
        if (typeof arguments.callee !== "undefined" && typeof arguments.callee.caller !== "undefined" && arguments.callee.caller) {
            errStack += "\n# " + (typeof arguments.callee.caller.name === "undefined" ? '' : arguments.callee.caller.name);
            errStack += "\n# " + arguments.callee.caller.toString();
            // if (errStack.indexOf('window.onerror') >= 0 || errStack.indexOf('var errStack') >= 0)
            //     errStack = "window.onerror";
            // else
            //     errStack = arg;//.replace(/\n/g, " ");
        } else {
            try { throw new Error(); }
            catch (e) {
                errStack += "\n# " + e.message + "\n" + e.stack;
            }
        }
    } else {
        //errStack = errStack.replace(/\n/g, " | ");
    }

    if (!url) {
        url = window.location.toString();
    }

    if (msg) {
        //msg = msg.replace(/\n/g, " ");
        msg = msg.substr(0, 1600);
    }

    console.log('ErrException', msg, arg, url);

    var xmlhttp = new XMLHttpRequest();
    xmlhttp.open("POST", '/?myCatcherLog=' + (new Date().getTime() / 1000), true);
    xmlhttp.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xmlhttp.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    var query = 'm=' + encodeURIComponent(msg) +
        '&r=' + encodeURIComponent(document.referrer) +
        '&u=' + encodeURIComponent(url);
    if (line) query += '&l=' + encodeURIComponent(line);
    if (errStack) query += '&s=' + encodeURIComponent(errStack.substr(0, 2000));
    xmlhttp.send(query);
}