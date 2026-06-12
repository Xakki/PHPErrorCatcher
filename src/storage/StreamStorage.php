<?php

declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\storage;

use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\Tools;

/**
 * Writes logs to STDOUT/STDERR in a format compatible with Monolog
 * \Monolog\Formatter\JsonFormatter (BATCH_MODE_NEWLINES, NDJSON).
 * Intended for collecting Docker logs via fluent-bit and forwarding them
 * to Graylog over GELF.
 *
 * The record is built by the shared BaseStorage::buildRecord() — one line per
 * record, separated by `\n`:
 * {"message":"...","context":{...},"level":400,"level_name":"ERROR",
 *  "channel":"php","datetime":"2026-04-27T10:00:00.123456+00:00","extra":{...}}
 *
 * @method string getStream()
 * @method bool getSplitByLevel()
 * @method int getMinLevelInt()
 */
class StreamStorage extends BaseStorage
{
    /** Where to write: 'php://stderr', 'php://stdout' or a file path. */
    protected string $stream = 'php://stderr';
    /** If true: warning+ → stderr, lower → stdout. Overrides $stream. */
    protected bool $splitByLevel = false;
    /** Ignore anything below this syslog level (LOG_DEBUG = 7). */
    protected int $minLevelInt = LOG_DEBUG;
    /** json_encode flags. */
    protected int $jsonFlags = 0;
    /** Maximum length of the resulting JSON line, 0 — unlimited. */
    protected int $maxLineLength = 0;

    /** @var resource|null */
    private $sStdout;
    /** @var resource|null */
    private $sStderr;
    /** @var resource|null */
    private $sCustom;

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
