<?php
namespace xakki\phperrorcatcher;
use \Psr\Log\LogLevel;

class PsrLogger implements \Psr\Log\LoggerInterface {

    private static $levelConvert = [
        LogLevel::EMERGENCY => E_USER_ERROR,
        LogLevel::ALERT     => E_USER_ALERT,
        LogLevel::CRITICAL  => E_USER_ERROR,
        LogLevel::ERROR     => E_USER_ERROR,
        LogLevel::WARNING   => E_USER_WARNING,
        LogLevel::NOTICE    => E_USER_NOTICE,
        LogLevel::INFO      => E_USER_INFO,
        LogLevel::DEBUG     => E_USER_INFO,
    ];
    /**
     * singleton object
     * @var static
     */
    private static $_obj;

    /**
     * Initialization
     */
    public static function init() {
        if (!static::$_obj) {
            static::$_obj = new self();
        }
        return static::$_obj;
    }

    public function emergency($message, array $context = array()) {
        $this->log(E_USER_ERROR, $message, $context);
    }


    public function alert($message, array $context = array()) {
        $this->log(E_USER_ALERT, $message, $context);
        if ($context) $message .= PHP_EOL.PHPErrorCatcher::renderVars($context);
        PHPErrorCatcher::init()->setViewAlert('[ALERT] '. $message );
    }

    public function critical($message, array $context = array()) {
        $this->log(E_USER_ERROR, $message, $context);
    }

    public function error($message, array $context = array()) {
        $this->log(E_USER_ERROR, $message, $context);
    }

    public function warning($message, array $context = array()) {
        $this->log(E_USER_WARNING, $message, $context);
    }

    public function notice($message, array $context = array()) {
        $this->log(E_USER_NOTICE, $message, $context);
    }

    public function info($message, array $context = array()) {
        $this->log(E_USER_INFO, $message, $context);
    }

    public function debug($message, array $context = array()) {
        if ($context) $message .= PHP_EOL.PHPErrorCatcher::renderVars($context);
        PHPErrorCatcher::init()->setViewAlert('[DEBUG] '. $message );
        $this->log(E_USER_INFO, $message, $context);
    }

    public function log($level, $message, array $context = array()) {
        if ($level == LogLevel::DEBUG) return;
        echo '..... LOG '.$level.' : '.$message.PHP_EOL;
        if (isset(self::$levelConvert[$level]))
            $level = self::$levelConvert[$level];
        PHPErrorCatcher::log($level, $message, $context, 4);
    }
}