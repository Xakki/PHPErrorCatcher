# PHPErrorCatcher
Logger all error in file for PHP

--------------

 * include_once "PHPErrorCatcher.php";

 * PHPErrorCatcher::model(false);

or, if you have profiler

 * PHPErrorCatcher::model(false, \_\_DIR\_\_.'/scripts/xhprof', '/scripts/xhprof');


--------------

Custom error report

 * PHPErrorCatcher::model()->handleException($e);   // from Exception
 
 * trigger_error($message, E_USER_WARNING);         // simple error trigger
 
-------------

Example

    define("ERROR_VIEW_GET", "showMeLogs");         // url view logs http://exaple.com/?showMeLogs=1
    define("ERROR_VIEW_PATH", __DIR__ . "/public"); // absolute path for view dir
    define("ERROR_BACKUP_DIR", "/_backUp");         // Backup relative dir / Only inside ERROR_VIEW_PATH
    define("ERROR_LOG_DIR", "/errorLogs");          // Error log relative dir / Only inside ERROR_VIEW_PATH
    
    include_once __DIR__ . "/libs/PHPErrorCatcher.class.php";
    if (isset($_COOKIE['prof']) || isset($_GET['prof'])){
        PHPErrorCatcher::model(false, __DIR__.'/scripts/xhprof', '/scripts/xhprof');
    }
    else {
        PHPErrorCatcher::model(false);
    }