<?php

namespace Xakki\PhpErrorCatcher\storage;

use Xakki\PhpErrorCatcher\Base;
use Xakki\PhpErrorCatcher\dto\HttpData;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;

abstract class BaseStorage extends Base
{
    /**
     * @return array<string, string>
     */
    public function getViewMenu()
    {
        return [];
    }

    /**
     * @param LogData $logData
     * @return void
     */
    abstract public function write(LogData $logData);

    /**
     * @return array<string, mixed>
     */
    public static function getDataHttp()
    {
        $data = [];
        $serverData = $_SERVER;
        if (!empty($serverData['REMOTE_ADDR'])) {
            $data['ip_addr'] = $serverData['REMOTE_ADDR'];
        }
        if (!empty($serverData['HTTP_HOST'])) {
            $data['host'] = substr($serverData['HTTP_HOST'], 0, 500);
        } else {
            $data['host'] = defined('MAIN_HOST') ? constant('MAIN_HOST') : '-';
        }
        if (!empty($serverData['REQUEST_METHOD'])) {
            $data['method'] = $serverData['REQUEST_METHOD'];
        }
        if (!empty($serverData['REQUEST_URI'])) {
            $data['url'] = substr($serverData['REQUEST_URI'], 0, 500);
        }
        if (!empty($serverData['HTTP_REFERER'])) {
            $data['referrer'] = substr($serverData['HTTP_REFERER'], 0, 500);
        }
        if (!empty($serverData['REQUEST_SCHEME'])) {
            $data['scheme'] = $serverData['REQUEST_SCHEME'];
        }
        if (!empty($serverData['HTTP_USER_AGENT'])) {
            $data['user_agent'] = substr($serverData['HTTP_USER_AGENT'], 0, 500);
        }
        if (!empty($_SERVER['argv'])) {
            // Same 500-char cap as user_agent above; argv can carry arbitrarily
            // long payloads (e.g. serialized job args) that would otherwise
            // exceed Lucene's 32766-byte term limit and drop the whole document.
            // Presence of this field IS the console-run signal — a real boolean
            // `is_console` is deliberately deferred until the OpenSearch index
            // mapping is updated (or the index set rotates) to avoid colliding
            // with the pre-existing string-typed `is_console` field on already-
            // mapped indices (e.g. saprod_24). Do not add it back without that.
            $data['console_argv'] = substr(implode(' ', $_SERVER['argv']), 0, 500);
        }
        if (PhpErrorCatcher::init()->get('_overMemory')) {
            $data['overMemory'] = true;
        }
        return $data;
    }
}
