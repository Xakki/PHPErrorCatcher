<?php

declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\storage;

use DateTime;
use DateTimeZone;
use Xakki\PhpErrorCatcher\Base;
use Xakki\PhpErrorCatcher\dto\HttpData;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\Tools;

/**
 * @method string getChannel()
 * @method array<string, mixed> getExtraFields()
 * @method bool getIncludeStacktraces()
 */
abstract class BaseStorage extends Base
{
    /**
     * Canonical log record format — shared by every storage that emits a
     * structured record (StreamStorage, SyslogStorage…).
     * Compatible with \Monolog\Formatter\JsonFormatter: exactly 7 top-level keys
     *   {message, context, level, level_name, channel, datetime, extra}
     * Custom fields are placed the Monolog way: request/log data (http, fields,
     * file, trace, log_type, log_count, tags) goes into context; process/env
     * data (log_ver, pid, extraFields) goes into extra. See BaseStorage::buildRecord().
     */

    /** Monolog channel name (the "channel" field). */
    protected string $channel = 'php';
    /**
     * Static fields appended to the "extra" of every record.
     *
     * @var array<string, mixed>
     */
    protected array $extraFields = [];
    /** Store the full trace in context.trace. */
    protected bool $includeStacktraces = true;

    /**
     * Map of syslog priority (LOG_*) → numeric Monolog level.
     *
     * @var array<int, int>
     */
    protected static array $monologLevel = [
        LOG_DEBUG => 100,
        LOG_INFO => 200,
        LOG_NOTICE => 250,
        LOG_WARNING => 300,
        LOG_ERR => 400,
        LOG_CRIT => 500,
        LOG_ALERT => 550,
        LOG_EMERG => 600,
    ];

    /**
     * Numeric Monolog level → level name.
     *
     * @var array<int, string>
     */
    protected static array $monologLevelName = [
        100 => 'DEBUG',
        200 => 'INFO',
        250 => 'NOTICE',
        300 => 'WARNING',
        400 => 'ERROR',
        500 => 'CRITICAL',
        550 => 'ALERT',
        600 => 'EMERGENCY',
    ];

    /**
     * @return array<string, string>
     */
    public function getViewMenu(): array
    {
        return [];
    }

    abstract public function write(LogData $logData): void;

    /**
     * Builds the record in the canonical Monolog\Formatter\JsonFormatter format.
     *
     * @return array<string, mixed>
     */
    protected function buildRecord(LogData $logData): array
    {
        $monoLevel = self::$monologLevel[$logData->levelInt] ?? 100;
        $monoLevelName = self::$monologLevelName[$monoLevel] ?? 'DEBUG';

        $context = self::getDataHttp()->__toArray() + $logData->fields;
        if (isset($logData->file) && $logData->file !== '') {
            $context['file'] = $logData->file;
        }
        if ($this->includeStacktraces && $logData->trace) {
            $context['trace'] = $logData->trace;
        }
        $context['log_type'] = $logData->type;
        $context['log_count'] = $logData->count;
        if ($logData->tags) {
            $context['tags'] = implode(',', $logData->tags);
        }

        $extra = [];
        $extra['log_ver'] = PhpErrorCatcher::VERSION;
        $pid = getmypid();
        if ($pid !== false) {
            $extra['pid'] = $pid;
        }
        if ($this->extraFields) {
            $extra = array_merge($extra, $this->extraFields);
        }

        return [
            'datetime' => $this->formatDateTime($logData->timestamp),
            'level' => $monoLevel,
            'level_name' => $monoLevelName,
            'channel' => $this->channel,
            'message' => $logData->message,
            'context' => $context,
            'extra' => $extra,
        ];
    }

    /**
     * ISO 8601 with microseconds and timezone — Monolog's default format ("Y-m-d\TH:i:s.uP").
     */
    protected function formatDateTime(float $timestamp): string
    {
        $dt = DateTime::createFromFormat('U.u', sprintf('%.6f', $timestamp));
        if (!$dt) {
            $dt = new DateTime();
        }
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $dt->format('Y-m-d\TH:i:s.uP');
    }

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
        if (!empty($_SERVER['SHELL'])) {
            $data->consoleArgv = implode(' ', $_SERVER['argv']);
        }

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
