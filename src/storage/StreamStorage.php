<?php

declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\storage;

use DateTime;
use DateTimeZone;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\Tools;

/**
 * Пишет логи в STDOUT/STDERR в формате, совместимом с Monolog
 * \Monolog\Formatter\JsonFormatter (BATCH_MODE_NEWLINES, NDJSON).
 * Предназначено для подбора Docker-логов через fluent-bit и пересылки
 * в Graylog по GELF.
 *
 * Запись имеет вид (по одной на строку, разделитель `\n`):
 * {"message":"...","context":{...},"level":400,"level_name":"ERROR",
 *  "channel":"php","datetime":"2026-04-27T10:00:00.123456+00:00","extra":{...}}
 *
 * @method string getStream()
 * @method bool getSplitByLevel()
 * @method int getMinLevelInt()
 * @method string getChannel()
 * @method array<string, mixed> getExtraFields()
 * @method bool getIncludeStacktraces()
 */
class StreamStorage extends BaseStorage
{
    /** Куда писать: 'php://stderr', 'php://stdout' или путь к файлу. */
    protected string $stream = 'php://stderr';
    /** Если true: warning+ → stderr, ниже → stdout. Перекрывает $stream. */
    protected bool $splitByLevel = false;
    /** Игнорировать всё, что ниже этого syslog-уровня (LOG_DEBUG = 7). */
    protected int $minLevelInt = LOG_DEBUG;
    /** Имя канала Monolog (поле "channel"). */
    protected string $channel = 'php';
    /**
     * Статические поля, добавляются в "extra" каждой записи.
     *
     * @var array<string, mixed>
     */
    protected array $extraFields = [];
    /** Сохранять trace в context.trace полностью. */
    protected bool $includeStacktraces = true;
    /** Флаги json_encode. */
    protected int $jsonFlags = 0;
    /** Максимальная длина итоговой JSON-строки, 0 — без ограничения. */
    protected int $maxLineLength = 0;

    /** @var resource|null */
    private $sStdout;
    /** @var resource|null */
    private $sStderr;
    /** @var resource|null */
    private $sCustom;

    /**
     * Маппинг syslog → Monolog level.
     *
     * @var array<int, int>
     */
    private static array $monologLevel = [
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
     * Имя уровня Monolog.
     *
     * @var array<int, string>
     */
    private static array $monologLevelName = [
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
     * @param array<string, string|int> $config
     */
    public function __construct(PhpErrorCatcher $owner, array $config = [])
    {
        parent::__construct($owner, $config);

        if ($this->jsonFlags === 0) {
            $this->jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        }

        if ($this->splitByLevel) {
            $this->sStdout = $this->openStream('php://stdout');
            $this->sStderr = $this->openStream('php://stderr');
        } elseif ($this->stream === 'php://stdout') {
            $this->sStdout = $this->openStream('php://stdout');
        } elseif ($this->stream === 'php://stderr') {
            $this->sStderr = $this->openStream('php://stderr');
        } else {
            $this->sCustom = $this->openStream($this->stream);
        }
    }

    public function __destruct()
    {
        if ($this->sCustom !== null && is_resource($this->sCustom)) {
            fclose($this->sCustom);
        }
    }

    public function write(LogData $logData): void
    {
        if ($this->minLevelInt && $logData->levelInt > $this->minLevelInt) {
            return;
        }

        $record = $this->buildRecord($logData);
        $line = Tools::safeJsonEncode($record, $this->jsonFlags);

        if (json_last_error()) {
            fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] StreamStorage json error ['
                . json_last_error() . '] ' . json_last_error_msg() . PHP_EOL);
            return;
        }
        if ($this->maxLineLength > 0 && strlen($line) > $this->maxLineLength) {
            $line = substr($line, 0, $this->maxLineLength - 1) . '…';
        }
        $line .= "\n";

        $target = $this->resolveTarget($logData->levelInt);
        if ($target !== null && is_resource($target)) {
            fwrite($target, $line);
        }
    }

    /**
     * Собирает запись в формате Monolog\Formatter\JsonFormatter.
     *
     * @return array<string, mixed>
     */
    protected function buildRecord(LogData $logData): array
    {
        $monoLevel = self::$monologLevel[$logData->levelInt] ?? 100;
        $monoLevelName = self::$monologLevelName[$monoLevel] ?? 'DEBUG';

        $context = $logData->fields;
        if (isset($logData->type) && $logData->type !== '') {
            $context['log_type'] = $logData->type;
        }
        if (isset($logData->file) && $logData->file !== '') {
            $context['file'] = $logData->file;
        }
        if (isset($logData->logKey) && $logData->logKey !== '') {
            $context['log_key'] = $logData->logKey;
        }
        if ($this->includeStacktraces && $logData->trace) {
            $context['trace'] = $logData->trace;
        }

        $extra = self::getDataHttp()->__toArray();
        $extra['ver'] = PhpErrorCatcher::VERSION;
        $pid = getmypid();
        if ($pid !== false) {
            $extra['pid'] = $pid;
        }
        $hostname = gethostname();
        if ($hostname !== false) {
            $extra['hostname'] = $hostname;
        }
        if ($this->extraFields) {
            $extra = array_merge($extra, $this->extraFields);
        }

        return [
            'message' => $logData->message,
            'context' => $context,
            'level' => $monoLevel,
            'level_name' => $monoLevelName,
            'channel' => $this->channel,
            'datetime' => $this->formatDateTime($logData->timestamp),
            'extra' => $extra,
        ];
    }

    /**
     * ISO 8601 с микросекундами и таймзоной — формат Monolog по умолчанию ("Y-m-d\TH:i:s.uP").
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

    /**
     * @return resource|null
     */
    private function openStream(string $url)
    {
        $fp = @fopen($url, 'ab');
        if (!$fp) {
            return null;
        }
        return $fp;
    }

    /**
     * @return resource|null
     */
    private function resolveTarget(int $levelInt)
    {
        if ($this->splitByLevel) {
            return $levelInt <= LOG_WARNING ? $this->sStderr : $this->sStdout;
        }
        if ($this->sCustom !== null) {
            return $this->sCustom;
        }
        return $this->sStderr ?? $this->sStdout;
    }
}
