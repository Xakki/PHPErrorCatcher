<?php

namespace Xakki\PhpErrorCatcher\storage;

use Generator;
use Traversable;
use Xakki\PhpErrorCatcher\HttpData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\LogData;

/**
 * Class FileStorage
 * @package Xakki\PhpErrorCatcher\storage
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
    protected $enableLogging = true;
    protected $maxLevelInt = LOG_DEBUG;
    protected $logPath = '';
    protected $tplPath = '%Y.%m/%d';
    protected $logDir = '/logsError';
    protected $backUpDir = '/_backUp';
    protected $limitFileSize = 10485760;
    protected $tmpFile;
    protected $isEmptyTmpFile = true;
    protected $logKeys = [];

    const FILE_EXT = 'plog';

    function __construct(PhpErrorCatcher $owner, $config = [])
    {
        parent::__construct($owner, $config);
        $this->tmpFile = $this->getFullLogDir() . 'tmp_' . microtime(true) . '_' . (isset($serverData['REMOTE_ADDR']) ? $serverData['REMOTE_ADDR'] : '');
    }

    function __destruct()
    {
        parent::__destruct();
        if ($this->owner->needSaveLog()) {
            $this->finishSave();
        }

        if (file_exists($this->tmpFile))  {
            unlink($this->tmpFile);
        }
    }


    function write(LogData $logData) {
        if (!$this->enableLogging) return;
        if ($this->maxLevelInt && $logData->levelInt > $this->maxLevelInt) {
            return;
        }
        $this->mkdir($this->getFullLogDir());

        if (isset($this->logKeys[$logData->logKey])) {
            file_put_contents($this->tmpFile, ', "'.$logData->logKey.'"', FILE_APPEND);
            return;
        }
        $this->logKeys[$logData->logKey] = 1;
        $res = file_put_contents($this->tmpFile, ($this->isEmptyTmpFile ? '' : ',') . $logData->__toString(), FILE_APPEND);
        if ($this->isEmptyTmpFile) {
            if ($res) chmod($this->tmpFile, 0666);
        }

        $this->isEmptyTmpFile = false;
    }

    private function getFullLogDir()
    {
        return rtrim($this->logPath, '/') . '/'.trim($this->logDir, '/') . '/';
    }

    /**
     * @return void
     */
    private function finishSave()
    {
        if (!file_exists($this->tmpFile)) return;
        $lastSlash = strrpos($this->tplPath, '/');
        $fileName = strftime(substr($this->tplPath, 0, $lastSlash));

        $fileName = $this->getFullLogDir() . $fileName;

        if (!file_exists($fileName)) {
            if(!$this->mkdir($fileName)) {
                fwrite(STDERR, 'Fail create log dir: ' . $fileName);
                return;
            }
        }

        $fileName = $fileName . '/' . trim(strftime(substr($this->tplPath, $lastSlash)), '/');
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
                    if (!file_exists($prevFile)) break;
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
            'logs' => 1
        ];

        file_put_contents($fileName, mb_substr($this->toString($data), 0, -2) . '[', FILE_APPEND);
        file_put_contents($fileName, file_get_contents($this->tmpFile) . ']}' . PHP_EOL, FILE_APPEND);
        if ($flagNew) chmod($fileName, 0666);
    }

    protected function toString($data)
    {
        return str_replace(PHP_EOL, '\\n', PhpErrorCatcher::safe_json_encode($data, JSON_UNESCAPED_UNICODE));
    }


}