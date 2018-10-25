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
    public function log($message, $level, $category = 'application')
    {
        if ($message instanceof \Exception)
            PHPErrorCatcher::logException($message, $this->getLevelName($level) . ' : ' . $category );

        elseif ($level & self::LEVEL_ERROR || $level & self::LEVEL_WARNING) {
            PHPErrorCatcher::logError($this->getLevelName($level) . ' : ' . $category, $message, true, 2);

        } elseif(YII_DEBUG) {
            PHPErrorCatcher::init()->setViewAlert($this->getLevelName($level) . ' : ' . $category .PHP_EOL.PHPErrorCatcher::renderVars($message));
        }
    }
}