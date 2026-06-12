<?php

declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\viewer;

use Xakki\PhpErrorCatcher\Base;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\Tools;

/**
 * @method string getInitGetKey()
 */
abstract class BaseViewer extends Base
{
    protected string $initGetKey;

    public function getHomeUrl(string $end = '/'): string
    {
        $url = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '')) ?: [];
        // path comes from REQUEST_URI (untrusted) and goes into href — escape it.
        return Tools::escAttr($url['path'] ?? '') . '?' . $this->getInitGetKey() . '=' . $end;
    }

    abstract public function renderItemLog(LogData $logData): void;

    public function renderItemLogString(LogData $logData): string
    {
        ob_start();
        $this->renderItemLog($logData);
        return (string) ob_get_clean();
    }
}
