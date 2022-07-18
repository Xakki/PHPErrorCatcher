<?php

namespace Xakki\PhpErrorCatcher\viewer;

use Xakki\PhpErrorCatcher\HttpData;

/**
 * @method string getInitGetKey()
 */
abstract class BaseViewer extends \Xakki\PhpErrorCatcher\Base
{
    protected $initGetKey;

    public function getHomeUrl($end = '/')
    {
        $url = $_SERVER['REQUEST_URI'];
        $url = parse_url($url);
        return $url['path'] . '?' . $this->getInitGetKey() . '=' . $end;
    }

    abstract public static function renderAllLogs(HttpData $httpData, array $logDatas): string;
}
