<?php

namespace Xakki\PhpErrorCatcher\dto;

use Stringable;
use Xakki\PhpErrorCatcher\Tools;

class LogData extends AbstractData implements Stringable
{
    /** @var string */
    public $logKey;
    /** @var string */
    public $message;
    /** @var string */
    public $level;
    /** @var string */
    public $type;
    /** @var string|null */
    public $trace = null;
    /** @var string */
    public $file;
    /** @var array */
    public $tags = [];
    /** @var array */
    public $fields = [];
    /** @var float */
    public $timestamp;
    /** @var int */
    public $count = 1;

    /**
     * @return string
     * @throws \Exception
     */
    public function __toString()
    {
        return Tools::safeJsonEncode(get_object_vars($this), JSON_UNESCAPED_UNICODE);
    }
}