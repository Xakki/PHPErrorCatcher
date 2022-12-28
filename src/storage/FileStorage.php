<?php

namespace Xakki\PhpErrorCatcher\storage;

use DateTimeImmutable;
use Exception;
use Generator;
use Xakki\PhpErrorCatcher\dto\HttpData;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\Tools;

/**
 * @method string getLogPath()
 * @method string getBackUpDir()
 */
class FileStorage extends BaseStorage
{
    /* Config */
    protected string $logPath = '';
    protected string $tplPath = 'Y.m/d';
    protected string $logDir = '/logsError';
    protected string $backUpDir = '/_backUp';
    protected int $limitFileSize = 10485760;

    public const FILE_EXT = 'plog';

    public function __destruct()
    {
        if ($this->owner->needSaveLog()) {
            if ($this->putData($this->owner->getDataLogsGenerator())) {
                $this->owner->successSaveLog();
            }
        }
    }

    public static function serializeLogs(Generator $logs): string
    {
        $data = [
            'http' => self::createHttpData()->__toArray(),
            'logs' => iterator_to_array($logs),
        ];
        return str_replace(PHP_EOL, '\\n', Tools::safeJsonEncode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param string $line
     * @return array<HttpData, LogData>
     * @throws Exception
     */
    public static function unSerializeFileLine(string $line): array
    {
        $data = json_decode($line, true);
        if (!$data['http'] || !$data['logs']) {
            throw new Exception('Not support line');
        }
        return [
            'http' => HttpData::init($data['http']),
            'logs' => self::getGenerator($data['logs']),
        ];
    }

    public static function getGenerator(array $logs): Generator
    {
        foreach ($logs as $logData) {
            $logData = LogData::init($logData);
            yield $logData;
        }
    }

    public static function iterateFileLog(string $file): Generator
    {
        if (!file_exists($file)) {
            throw new Exception('No file');
        }

        $fileHandle = fopen($file, "r");
        while (!feof($fileHandle)) {
            $line = fgets($fileHandle);
            if (!$line) {
                continue;
            }

            yield FileStorage::unSerializeFileLine($line);

            if (Tools::isMemoryOver()) {
                fclose($fileHandle);
                throw new Exception('Is memory over');
            }
        }
        fclose($fileHandle);
    }

    public static function createHttpData(): HttpData
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

    /**
     * @param LogData[] $log
     * @return void
     */
    private function putData(Generator $log): bool
    {
        $lastSlash = strrpos($this->tplPath, '/');
        $date = DateTimeImmutable::createFromFormat('U', time());
        $fileName = $date->format(substr($this->tplPath, 0, $lastSlash));

        $fileName = rtrim($this->logPath, '/') . '/' . trim($this->logDir, '/') . '/' . $fileName;

        if (!file_exists($fileName)) {
            $this->mkdir($fileName);
        }
//\Xakki\PhpErrorCatcher\PhpErrorCatcher::FIELD_LOG_NAME
        $fileName = $fileName . '/' . $date->format(substr($this->tplPath, $lastSlash));
        if ($this->owner->getErrCount()) {
            $fileName .= '.err';
        }
        $fileName .= '.' . self::FILE_EXT;

        $flagNew = true;
        if (file_exists($fileName)) {
            if (filesize($fileName) > $this->limitFileSize) {
                $i = 0;
                $preiosFile = '';
                while (true) {
                    $preiosFile = str_replace('.' . self::FILE_EXT, '_' . $i++ . '.' . self::FILE_EXT, $fileName);
                    if (!file_exists($preiosFile)) {
                        break;
                    }
                }
                if (!rename($fileName, $preiosFile)) {
//                    $fileLog .= '***** Cant rename big file';
                    $flagNew = false;
                }
//                else {
                //  href="' . $this->getFileUrl($preiosFile) . '"
//                    $fileLog = '<a>Part # ' . $i . '. Previos file was to big</a><hr/>' . PHP_EOL . $fileLog;
//                }
            } else {
                $flagNew = false;
            }
        }
        file_put_contents($fileName, $this->serializeLogs($log) . PHP_EOL, FILE_APPEND);
        if ($flagNew) {
            chmod($fileName, 0777);
        }
        return true;
    }

    public function checkIsBackUp(string $file): bool
    {
        return strpos($file, $this->getLogPath() . $this->getBackUpDir()) !== false;
    }
}
