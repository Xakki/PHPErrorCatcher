<?php

namespace xakki\phperrorcatcher\viewer;

use xakki\phperrorcatcher\HttpData;

/**
 * @method string getInitGetKey()
 */
abstract class BaseViewer extends \xakki\phperrorcatcher\Base
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
