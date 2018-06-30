# PHPErrorCatcher
Logger all error in file for PHP

Example  example/test.php

--------------

 * include_once "src/PHPErrorCatcher.php";

 * xakki\phperrorcatcher\PHPErrorCatcher::init([...]);

--------------

Custom error report

 * xakki\phperrorcatcher\PHPErrorCatcher::logException($e); // from Exception
 
 * xakki\phperrorcatcher\PHPErrorCatcher::logError('My custom error'); // custom error
 
 * trigger_error($message, E_USER_WARNING); // simple error trigger
 
-------------

Log browser errors
 * <script src="catcher.js"> and yoy can catch all errors
 * errorCatcher('custom errors')
