# PHPErrorCatcher
Logger all error in file for PHP

Example  example/test.php

--------------

 * `include_once "src/PHPErrorCatcher.php";`

 * `Xakki\PhpErrorCatcher\PHPErrorCatcher::init([...]);`

--------------

Custom error report

 * `Xakki\PhpErrorCatcher\PHPErrorCatcher::logException($e);` from Exception
 
 * `Xakki\PhpErrorCatcher\PHPErrorCatcher::logError('My custom error');` custom error
 
 * `trigger_error($message, E_USER_WARNING);` simple error trigger
 
-------------

Log browser errors
 * `<script src="catcher.js"/>` and yoy can catch all errors
 * `errorCatcher('custom errors')`
