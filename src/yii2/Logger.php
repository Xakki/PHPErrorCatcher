<?php
namespace xakki\phperrorcatcher\yii2;

use \xakki\phperrorcatcher\PHPErrorCatcher;

/**
 * Class Logger
 * @package xakki\phperrorcatcher\yii2
 *
 * Catch all logs
 *
 * Add to config
 *    Yii::$container->set('yii\log\Logger', '\xakki\phperrorcatcher\yii2\Logger');
 *    $config['bootstrap'][] = 'log';
 */
class Logger extends \yii\log\Logger
{
    public $targets = [];
    static $toMylevels = [
        self::LEVEL_ERROR => E_USER_ERROR,
        self::LEVEL_WARNING => E_USER_WARNING,
//        self::LEVEL_INFO => E_USER_INFO,
//        self::LEVEL_TRACE => E_USER_ALERT,
//        self::LEVEL_PROFILE_BEGIN => E_USER_INFO,
//        self::LEVEL_PROFILE_END => E_USER_INFO,
//        self::LEVEL_PROFILE => E_USER_ALERT,
    ];

    public function log($message, $level, $category = '')
    {

        if ($message instanceof \Throwable) {
            PHPErrorCatcher::logException($message, $category);
        }
        elseif (isset(self::$toMylevels[$level])) {
//            if ($level == self::LEVEL_INFO || $level == self::LEVEL_TRACE) return;
//            if (!YII_DEBUG && ($level == self::LEVEL_INFO || $level == self::LEVEL_TRACE)) {
//                return;
//            }
            PHPErrorCatcher::log(self::$toMylevels[$level], $message, [PHPErrorCatcher::TOPIC_OPTION => $category]);
        }

//        if(YII_DEBUG) {
//            PHPErrorCatcher::init()->setViewAlert($this->getLevelName($level) . ' : ' . $category .PHP_EOL.PHPErrorCatcher::renderVars($message));
//        }
    }
}