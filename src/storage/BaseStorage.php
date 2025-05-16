<?php
declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\storage;

use Xakki\PhpErrorCatcher\Base;
use Xakki\PhpErrorCatcher\dto\HttpData;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\Tools;

abstract class BaseStorage extends Base
{
    /**
     * @return array<string, string>
     */
    public function getViewMenu(): array
    {
        return [];
    }

    abstract function write(LogData $logData);

    public static function getDataHttp(): HttpData
    {
        $data = new HttpData();
        $serverData = $_SERVER;
        if (!empty($serverData['REMOTE_ADDR'])) {
            $data->ipAddr = $serverData['REMOTE_ADDR'];
        }
        if (!empty($serverData['HTTP_HOST'])) {
            $data->host = substr($serverData['HTTP_HOST'], 0, 500);
        } else {
            $data->host = isset($serverData['HTTP_X_SERVER_NAME']) ? $serverData['HTTP_X_SERVER_NAME'] : (isset($serverData['SERVER_NAME']) ? $serverData['SERVER_NAME'] : '');
        }
        if (!empty($_SERVER['SHELL']))
            $data->shell = implode(' ', $_SERVER['argv']);

        if (!empty($serverData['REQUEST_METHOD'])) {
            $data->method = $serverData['REQUEST_METHOD'];
        }
        if (!empty($serverData['REQUEST_URI'])) {
            $data->url = substr($serverData['REQUEST_URI'], 0, 500);
        }
        if (!empty($serverData['HTTP_REFERER'])) {
            $data->referrer = substr($serverData['HTTP_REFERER'], 0, 500);
        }
        if (!empty($serverData['REQUEST_SCHEME'])) {
            $data->scheme = $serverData['REQUEST_SCHEME'];
        }
        if (!empty($serverData['HTTP_USER_AGENT'])) {
            $data->userAgent = substr($serverData['HTTP_USER_AGENT'], 0, 500);
        }
        if (Tools::isMemoryOver()) {
            $data->overMemory = true;
        }
        return $data;
    }
}