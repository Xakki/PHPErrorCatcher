/*
Use to catch all exception
    try {
        ...
    } catch (e) {
        errorCatcher(e);
    }
*/

const originalConsoleError = window.console.error;

console.error = function(...data) {
    errorCatcher(data);
}

window.onerror = function (msg, url, line, col, err) {
    originalConsoleError('onerror', msg, url, line, col, err);
    errorCatcher(msg, url, line, col, err);
    return false;
};

const getCircularReplacer = () => {
    const seen = new WeakSet()
    return (key, value) => {
        if (typeof value === 'object' && value !== null) {
            if (seen.has(value)) {
                return
            }
            seen.add(value)
        }
        return value
    }
}

//Exceptions
function errorCatcher(msg, url, line, col, err) {
    if (!navigator.userAgent) return;

    if (msg instanceof Error) {
        err = msg.stack;
        msg = msg.message;
    }
    else if (err && err instanceof Error) {
        err = err.message + ";\n% " + err.stack;
    }
    else {
        err = '';
        if (arguments['callee'] && arguments.callee['caller']) {
            err += "\n# " + (arguments.callee.caller['name'] ? arguments.callee.caller['name'] : '');
            err += "\n# " + arguments.callee.caller.toString();
        }
        else {
            try {
                throw new Error();
            }
            catch (e) {
                err += "\n* " + e.stack;
            }
        }
    }

    if (!url) {
        url = window.location.toString();
    }

    if (msg) {
        if (typeof msg !== 'string') {
            msg = JSON.stringifyOnce(msg);
        }
        msg = msg.replace(/\n/g, "||");
        msg = msg.substring(0, 1600);
    }

    if (app && app.debug) {
        alert('Debug. Произошла ошибка:\n' + msg);
    }

    var xmlhttp = new XMLHttpRequest();
    xmlhttp.open("POST", '/?catcherLogName=' + (new Date().getTime() / 1000), true);
    xmlhttp.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xmlhttp.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    var query = 'm=' + encodeURIComponent(msg) +
        '&v=' + (typeof jsVer === "undefined" ? 0 : jsVer) +
        '&r=' + encodeURIComponent(document.referrer) +
        '&u=' + encodeURIComponent(url) +
        '&ua=' + encodeURIComponent(window.navigator.userAgent);
    if (line) query += '&l=' + encodeURIComponent(line + (col ? ':' + col : ''));
    if (err) {
        err = err.replace(/\n/g, "||");
        err = err.substring(0, 3200);
        query += '&s=' + encodeURIComponent(err);
    }
    xmlhttp.send(query);
}


JSON.stringifyOnce = function(obj, replacer, indent){
    var printedObjects = [];
    var printedObjectKeys = [];

    function printOnceReplacer(key, value){
        if ( printedObjects.length > 2000){ // browsers will not print more than 20K, I don't see the point to allow 2K.. algorithm will not be fast anyway if we have too many objects
            return 'object too long';
        }
        var printedObjIndex = false;
        printedObjects.forEach(function(obj, index){
            if(obj===value){
                printedObjIndex = index;
            }
        });

        if ( key == ''){ //root element
            printedObjects.push(obj);
            printedObjectKeys.push("root");
            return value;
        }

        else if(printedObjIndex+"" != "false" && typeof(value)=="object"){
            if ( printedObjectKeys[printedObjIndex] == "root"){
                return "(pointer to root)";
            }else{
                return "(see " + ((!!value && !!value.constructor) ? value.constructor.name.toLowerCase()  : typeof(value)) + " with key " + printedObjectKeys[printedObjIndex] + ")";
            }
        }else{

            var qualifiedKey = key || "(empty key)";
            printedObjects.push(value);
            printedObjectKeys.push(qualifiedKey);
            if(replacer){
                return replacer(key, value);
            }else{
                return value;
            }
        }
    }
    return JSON.stringify(obj, printOnceReplacer, indent);
};