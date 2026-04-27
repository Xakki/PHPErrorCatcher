<?php

namespace Xakki\PhpErrorCatcher\storage;

use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\Tools;
use Xakki\PhpErrorCatcher\dto\LogData;

/**
 * Class StreamStorage
 *
 * Пишет логи в STDOUT/STDERR в формате, совместимом с Monolog
 * \Monolog\Formatter\JsonFormatter (BATCH_MODE_NEWLINES, NDJSON).
 * Предназначено для подбора Docker-логов через fluent-bit и пересылки
 * в Graylog по GELF.
 *
 * Запись имеет вид (по одной на строку, разделитель `\n`):
 * {"message":"...","context":{...},"level":400,"level_name":"ERROR",
 *  "channel":"php","datetime":"2026-04-27T10:00:00.123456+00:00","extra":{...}}
 *
 * @package Xakki\PhpErrorCatcher\storage
 * @method string getStream()
 * @method bool getSplitByLevel()
 * @method int getMinLevelInt()
 * @method string getChannel()
 * @method array getExtraFields()
 * @method bool getIncludeStacktraces()
 */
class StreamStorage extends BaseStorage
{
    /** @var string Куда писать: 'php://stderr', 'php://stdout' или путь к файлу. */
    protected $stream = 'php://stderr';
    /** @var bool Если true: warning+ → stderr, ниже → stdout. Перекрывает $stream. */
    protected $splitByLevel = false;
    /** @var int Игнорировать всё, что ниже этого syslog-уровня (LOG_DEBUG = 7). */
    protected $minLevelInt = LOG_DEBUG;
    /** @var string Имя канала Monolog (поле "channel"). */
    protected $channel = 'php';
    /** @var array<string, mixed> Статические поля, добавляются в "extra" каждой записи. */
    protected $extraFields = [];
    /** @var bool Сохранять trace в context.exception.trace полностью. */
    protected $includeStacktraces = true;
    /** @var int Флаги json_encode. */
    protected $jsonFlags = 0;
    /** @var int Максимальная длина итоговой JSON-строки, 0 — без ограничения. */
    protected $maxLineLength = 0;

    /** @var resource|null */
    private $sStdout;
    /** @var resource|null */
    private $sStderr;
    /** @var resource|null */
    private $sCustom;

    /** @var array<int, int> Маппинг syslog → Monolog level. */
    private static $monologLevel = [
        LOG_DEBUG => 100,    // Logger::DEBUG
        LOG_INFO => 200,     // Logger::INFO
        LOG_NOTICE => 250,   // Logger::NOTICE
        LOG_WARNING => 300,  // Logger::WARNING
        LOG_ERR => 400,      // Logger::ERROR
        LOG_CRIT => 500,     // Logger::CRITICAL
        LOG_ALERT => 550,    // Logger::ALERT
        LOG_EMERG => 600,    // Logger::EMERGENCY
    ];

    /** @var array<int, string> Имя уровня Monolog. */
    private static $monologLevelName = [
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
     * @param PhpErrorCatcher $owner
     * @param array<string, mixed> $config
     */
    public function __construct(PhpErrorCatcher $owner, $config = [])
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

    /**
     * @return void
     */
    public function __destruct()
    {
        parent::__destruct();
        // STDOUT/STDERR закрывать не надо — это процессовые ресурсы.
        if ($this->sCustom && is_resource($this->sCustom)) {
            fclose($this->sCustom);
        }
    }

    /**
     * @param LogData $logData
     * @return void
     * @throws \Exception
     */
    public function write(LogData $logData)
    {
        if ($this->minLevelInt && $logData->levelInt > $this->minLevelInt) {
            return;
        }

        $record = $this->buildRecord($logData);
        $line = Tools::safeJsonEncode($record, $this->jsonFlags);

        if (json_last_error()) {
            fwrite(\STDERR, '[' . date('Y-m-d H:i:s') . '] StreamStorage json error ['
                . json_last_error() . '] ' . json_last_error_msg() . PHP_EOL);
            return;
        }
        if ($this->maxLineLength > 0 && strlen($line) > $this->maxLineLength) {
            $line = substr($line, 0, $this->maxLineLength - 1) . '…';
        }
        $line .= "\n";

        $target = $this->resolveTarget($logData->levelInt);
        if ($target && is_resource($target)) {
            fwrite($target, $line);
        }
    }

    /**
     * Собирает запись в формате Monolog\Formatter\JsonFormatter.
     *
     * @param LogData $logData
     * @return array<string, mixed>
     */
    protected function buildRecord(LogData $logData)
    {
        $monoLevel = isset(self::$monologLevel[$logData->levelInt])
            ? self::$monologLevel[$logData->levelInt]
            : 100;
        $monoLevelName = isset(self::$monologLevelName[$monoLevel])
            ? self::$monologLevelName[$monoLevel]
            : 'DEBUG';

        $context = is_array($logData->fields) ? $logData->fields : [];
        if ($logData->type !== null && $logData->type !== '') {
            $context['log_type'] = $logData->type;
        }
        if ($logData->file !== null && $logData->file !== '') {
            $context['file'] = $logData->file;
        }
        if ($logData->logKey !== null && $logData->logKey !== '') {
            $context['log_key'] = $logData->logKey;
        }
        if ($this->includeStacktraces && $logData->trace) {
            $context['trace'] = $logData->trace;
        }

        $extra = self::getDataHttp();
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
            'message' => (string) $logData->message,
            'context' => $context,
            'level' => $monoLevel,
            'level_name' => $monoLevelName,
            'channel' => $this->channel,
            'datetime' => $this->formatDateTime($logData->microtime),
            'extra' => $extra,
        ];
    }

    /**
     * ISO 8601 с микросекундами и таймзоной — формат Monolog по умолчанию ("Y-m-d\TH:i:s.uP").
     *
     * @param float $microtime
     * @return string
     */
    protected function formatDateTime($microtime)
    {
        $dt = \DateTime::createFromFormat('U.u', sprintf('%.6f', (float) $microtime));
        if (!$dt) {
            $dt = new \DateTime();
        }
        $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $dt->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * @param string $url
     * @return resource|null
     */
    private function openStream($url)
    {
        $fp = @fopen($url, 'ab');
        if (!$fp) {
            return null;
        }
        return $fp;
    }

    /**
     * @param int $levelInt
     * @return resource|null
     */
    private function resolveTarget($levelInt)
    {
        if ($this->splitByLevel) {
            return ($levelInt <= LOG_WARNING) ? $this->sStderr : $this->sStdout;
        }
        if ($this->sCustom) {
            return $this->sCustom;
        }
        return $this->sStderr ? $this->sStderr : $this->sStdout;
    }
}
