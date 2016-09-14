# PHPErrorCatcher
Logger all error in file for PHP

--------------

 * include_once "src/PHPErrorCatcher.php";

 * PHPErrorCatcher::init();

--------------

Custom error report

 * PHPErrorCatcher::init()->handleException($e);   // from Exception
 
 * trigger_error($message, E_USER_WARNING);         // simple error trigger
 
-------------
