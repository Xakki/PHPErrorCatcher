<?php

declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\storage;

use Exception;
use Generator;
use Xakki\PhpErrorCatcher\dto\HttpData;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\Tools;

/**
 * @method string getLogPath()
 * @method string getLogDir()
 * @method string getBackUpDir()
 * @method int getLimitFileSize()
 * @method string getByDir()
 * @method string getByFile()
 */
class FileStorage extends BaseStorage
{
    /* Config */
    protected bool $enableLogging = true;
    protected int $maxLevelInt = LOG_DEBUG;
    protected string $logPath = '';
    protected string $tplPath = '%Y.%m/%d';
    protected string $logDir = '/logsError';
    protected string $backUpDir = '/_backUp';
    protected int $limitFileSize = 10485760;
    protected string $tmpFile;
    protected bool $isEmptyTmpFile = true;
    /** @var array<string, int> */
    protected array $logKeys = [];

    public const FILE_EXT = 'plog';

    public function __construct(PhpErrorCatcher $owner, $config = [])
    {
        parent::__construct($owner, $config);
        $this->tmpFile = $this->getFullLogDir() . ':' . uniqid('tmp_', true) . rand(1, 1000000) . ':' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
    }

    public function __destruct()
    {
        if ($this->owner->needSaveLog()) {
            $this->finishSave();
        }

        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function write(LogData $logData): void
    {
        if (!$this->enableLogging) {
            return;
        }
        if ($this->maxLevelInt && $logData->levelInt > $this->maxLevelInt) {
            return;
        }
        $this->mkdir($this->getFullLogDir());

        if (isset($this->logKeys[$logData->logKey])) {
            file_put_contents($this->tmpFile, ', "' . $logData->logKey . '"', FILE_APPEND);
            return;
        }
        $this->logKeys[$logData->logKey] = 1;
        $res = file_put_contents($this->tmpFile, ($this->isEmptyTmpFile ? '' : ',') . $logData->__toString(), FILE_APPEND);
        if ($this->isEmptyTmpFile) {
            if ($res) {
                chmod($this->tmpFile, 0666);
            }
        }

        $this->isEmptyTmpFile = false;
    }

    private function getFullLogDir(): string
    {
        return rtrim($this->logPath, '/') . '/' . trim($this->logDir, '/') . '/';
    }

    private function finishSave(): void
    {
        if (!file_exists($this->tmpFile)) {
            return;
        }
        $lastSlash = strrpos($this->tplPath, '/');
        if ($lastSlash === false) {
            $lastSlash = strlen($this->tplPath);
        }
        $fileName = strftime(substr($this->tplPath, 0, $lastSlash));

        $fileName = $this->getFullLogDir() . $fileName;

        if (!file_exists($fileName)) {
            if (!$this->mkdir($fileName)) {
                fwrite(STDERR, 'Fail create log dir: ' . $fileName);
                return;
            }
        }

        $suffix = substr($this->tplPath, $lastSlash);
        $fileName = $fileName . '/' . trim((string) strftime($suffix), '/');
        $maxLevel = $this->owner->getHighLevelLogs();
        if ($maxLevel) {
            $fileName .= '.' . $maxLevel;
        }
        if ($tag = $this->owner->getGlobalTag()) {
            $fileName .= '.' . $tag;
        }
        $fileName .= '.' . self::FILE_EXT;

        $flagNew = true;
        if (file_exists($fileName)) {
            if (filesize($fileName) > $this->limitFileSize) {
                $i = 0;
                while (true) {
                    $prevFile = str_replace('.' . self::FILE_EXT, '_' . $i++ . '.' . self::FILE_EXT, $fileName);
                    if (!file_exists($prevFile)) {
                        break;
                    }
                }
                if (!rename($fileName, $prevFile)) {
                    $flagNew = false;
                }
            } else {
                $flagNew = false;
            }
        }
        $data = [
            'http' => self::getDataHttp(),
            'logs' => 1,
        ];

        file_put_contents($fileName, mb_substr($this->toString($data), 0, -2) . '[', FILE_APPEND);
        file_put_contents($fileName, file_get_contents($this->tmpFile) . ']}' . PHP_EOL, FILE_APPEND);
        if ($flagNew) {
            chmod($fileName, 0666);
        }
    }

    protected function toString(mixed $data): string
    {
        return str_replace(PHP_EOL, '\\n', \Xakki\PhpErrorCatcher\Tools::safeJsonEncode($data, JSON_UNESCAPED_UNICODE));
    }

    public function checkIsBackUp(string $file): bool
    {
        return str_contains($file, $this->getLogPath() . $this->getBackUpDir());
    }

    /**
     * @return Generator<array{http: HttpData, logs: Generator<LogData>}>
     * @throws Exception
     */
    public static function iterateFileLog(string $file): Generator
    {
        if (!file_exists($file)) {
            throw new Exception('No file');
        }

        $fileHandle = fopen($file, 'r');
        if ($fileHandle === false) {
            throw new Exception('Cannot open file');
        }
        try {
            while (!feof($fileHandle)) {
                $line = fgets($fileHandle);
                if (!$line) {
                    continue;
                }
                yield self::unSerializeFileLine($line);

                if (Tools::isMemoryOver()) {
                    throw new Exception('Is memory over');
                }
            }
        } finally {
            fclose($fileHandle);
        }
    }

    /**
     * @return array{http: HttpData, logs: Generator<LogData>}
     * @throws Exception
     */
    protected static function unSerializeFileLine(string $line): array
    {
        $data = json_decode($line, true);
        if (!is_array($data) || empty($data['http']) || empty($data['logs'])) {
            throw new Exception('Not support line');
        }
        return [
            'http' => HttpData::init($data['http']),
            'logs' => self::getLogGenerator($data['logs']),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $logs
     * @return Generator<LogData>
     */
    protected static function getLogGenerator(array $logs): Generator
    {
        foreach ($logs as $logData) {
            yield LogData::init($logData);
        }
    }
}
