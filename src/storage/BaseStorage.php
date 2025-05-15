<?php

namespace Xakki\PhpErrorCatcher\storage;

use Xakki\PhpErrorCatcher\Base;
use Xakki\PhpErrorCatcher\HttpData;
use Xakki\PhpErrorCatcher\LogData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;

abstract class BaseStorage extends Base
{
    public function getViewMenu()
    {
        return [];
    }

    abstract function write(LogData $logData);

    public static function getDataHttp()
    {
        /** @var HttpData|array $data */
        $data = [];
        $serverData = $_SERVER;
        if (!empty($serverData['REMOTE_ADDR']))
            $data['ip_addr'] = $serverData['REMOTE_ADDR'];
        if (!empty($serverData['HTTP_HOST']))
            $data['host'] = substr($serverData['HTTP_HOST'], 0, 500);
        else
            $data['host'] = getOriginSiteName();
        if (!empty($serverData['REQUEST_METHOD']))
            $data['method'] = $serverData['REQUEST_METHOD'];
        if (!empty($serverData['REQUEST_URI']))
            $data['url'] = substr($serverData['REQUEST_URI'], 0, 500);
        if (!empty($serverData['HTTP_REFERER']))
            $data['referrer'] = substr($serverData['HTTP_REFERER'], 0, 500);
        if (!empty($serverData['REQUEST_SCHEME']))
            $data['scheme'] = $serverData['REQUEST_SCHEME'];
        if (!empty($serverData['HTTP_USER_AGENT']))
            $data['user_agent'] = substr($serverData['HTTP_USER_AGENT'], 0, 500);
        if (!empty($_SERVER['argv']))
            $data['is_console'] = implode(' ', $_SERVER['argv']);
        if (PhpErrorCatcher::init()->get('_overMemory'))
            $data['overMemory'] = true;
        return $data;
    }
}