<?php

namespace Xakki\PhpErrorCatcher\dto;

use Xakki\PhpErrorCatcher\Tools;

class LogData extends AbstractData
{
    /** @var string */
    public $logKey;
    /** @var string */
    public $message;
    /** @var string */
    public $level;
    /** @var int */
    public $levelInt;
    /** @var string */
    public $type;
    /** @var string|null */
    public $trace = null;
    /** @var string */
    public $file;
    /** @var array<string, mixed> */
    public $fields = [];
    /** @var float */
    public $microtime;
    /** @var int */
    public $count = 1;

    /**
     * @return string
     * @throws \Exception
     */
    public function __toString()
    {
        return Tools::safeJsonEncode($this->__toArray(), JSON_UNESCAPED_UNICODE);
    }
}
