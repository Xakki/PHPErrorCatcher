<?php

namespace Xakki\PhpErrorCatcher\connector;

use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use yii\log\Logger;

class YiiLogger extends Logger
{
    public $targets = [];
    static $toMylevels = [
        self::LEVEL_ERROR => PhpErrorCatcher::LEVEL_ERROR,
        self::LEVEL_WARNING => PhpErrorCatcher::LEVEL_WARNING,
        self::LEVEL_INFO => PhpErrorCatcher::LEVEL_INFO,
        self::LEVEL_TRACE => PhpErrorCatcher::LEVEL_DEBUG,
    ];

    public function log($message, $level, $category = '')
    {
        if (isset(self::$toMylevels[$level])) {
            PhpErrorCatcher::init()->log(self::$toMylevels[$level], $message, ['category' => $category]);
        }
    }
}
