<?php

namespace xakki\phperrorcatcher\connector;

use \xakki\phperrorcatcher\PHPErrorCatcher;

/**
 * Class Logger
 * @package xakki\phperrorcatcher\yii2
 *
 * Catch all logs
 *
 * Add to config
 *    Yii::$container->set('yii\log\Logger', '\xakki\phperrorcatcher\connector\YiiLogger');
 *    $config['bootstrap'][] = 'log';
 */
class YiiLogger extends \yii\log\Logger {
    public $targets = [];
    static $toMylevels = [
        self::LEVEL_ERROR => PHPErrorCatcher::LEVEL_ERROR,
        self::LEVEL_WARNING => PHPErrorCatcher::LEVEL_WARNING,
        self::LEVEL_INFO => PHPErrorCatcher::LEVEL_INFO,
        self::LEVEL_TRACE => PHPErrorCatcher::LEVEL_DEBUG,
    ];

    public function log($message, $level, $category = '') {
        if (isset(self::$toMylevels[$level])) {
            return PHPErrorCatcher::logger(self::$toMylevels[$level], $message, [], ['category' => $category]);
        }
        return false;
    }
}