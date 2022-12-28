/*
Use to catch all exception
    try {
        ...
    } catch (e) {
        errorCatcher(e);
    }
*/

window.onerror = function (msg, url, line, col, err) {
    errorCatcher(msg, url, line, err)
    return false
}

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
function errorCatcher (msg, url, line, err) {
    if (!navigator.userAgent) return

    if (msg instanceof Error) {
        err = msg.stack
        msg = msg.message
    } else if (err && err instanceof Error) {
        err = err.message + ';\n% ' + err.stack
    } else {
        err = ''
        if (arguments['callee'] && arguments.callee['caller']) {
            err += '\n# ' + (typeof arguments.callee.caller.name === 'undefined' ? '' : arguments.callee.caller.name)
            err += '\n# ' + arguments.callee.caller.toString()
        } else {
            try {
                throw new Error()
            } catch (e) {
                err += '\n* ' + e.stack
            }
        }
    }

    if (!url) {
        url = window.location.toString()
    }

    if (msg) {
        if (typeof msg !== 'string') {
            try {
                msg = JSON.stringify(msg)
            } catch (e) {
                console.error(e)
                msg = '****'
            }
        }
        msg = msg.replace(/\n/g, '||')
        msg = msg.substr(0, 1600)
    }

    console.log('ErrException', msg, url, line, err)
    var tme = (new Date().getTime() / 1000)
    var xmlhttp = new XMLHttpRequest()
    xmlhttp.open('POST', '/?myCatcherLog=' + tme, true)
    xmlhttp.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8')
    xmlhttp.setRequestHeader('X-Requested-With', 'XMLHttpRequest')
    var query = 'm=' + encodeURIComponent(msg) +
        '&v=' + (typeof jsVer === 'undefined' ? 0 : jsVer) +
        '&r=' + encodeURIComponent(document.referrer) +
        '&u=' + encodeURIComponent(url)
    if (line) query += '&l=' + encodeURIComponent(line)
    if (err) {
        err = err.replace(/\n/g, '||')
        query += '&s=' + encodeURIComponent(err.substr(0, 2000))
    }
    xmlhttp.send(query)
    document.cookie = '__err=' + tme + ';path=/'
}