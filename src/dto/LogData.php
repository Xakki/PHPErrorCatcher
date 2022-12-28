<?php
declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\dto;

use Stringable;
use Xakki\PhpErrorCatcher\Tools;

class LogData extends AbstractData implements Stringable
{
    public string $logKey;
    public string $message;
    public string $level;
    public string $type;
    public ?string $trace = null;
    public string $file;
    public array $tags = [];
    public array $fields = [];
    public float $timestamp;
    public int $count = 1;

    public function __toString(): string
    {
        return Tools::safeJsonEncode(get_object_vars($this), JSON_UNESCAPED_UNICODE);
    }
}