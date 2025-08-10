<?php

namespace Xakki\PhpErrorCatcher\storage;

use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\Tools;

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
    /** @var bool */
    protected $enableLogging = true;
    /** @var int */
    protected $maxLevelInt = LOG_DEBUG;
    /** @var string */
    protected $logPath = '';
    /** @var string */
    protected $tplPath = '%Y.%m/%d';
    /** @var string */
    protected $logDir = '/logsError';
    /** @var string */
    protected $backUpDir = '/_backUp';
    /** @var int */
    protected $limitFileSize = 10485760;
    /** @var string */
    protected $tmpFile;
    /** @var bool */
    protected $isEmptyTmpFile = true;
    /** @var int  */
    protected $lastTimeSave = 0;
    /** @var array<string, int> */
    protected $logKeys = [];

    /** @var string */
    const FILE_EXT = 'plog';

    /**
     * @param PhpErrorCatcher $owner
     * @param array<string, mixed> $config
     */
    public function __construct(PhpErrorCatcher $owner, $config = [])
    {
        parent::__construct($owner, $config);
        $this->tmpFile = $this->getFullLogDir() . 'tmp_' . microtime(true) . '_' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
    }

    /**
     * @return void
     */
    public function __destruct()
    {
        parent::__destruct();
        if ($this->owner->needSaveLog()) {
            $this->finishSave();
        }

        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    /**
     * @param LogData $logData
     * @return void
     * @throws \Exception
     */
    public function write(LogData $logData)
    {
        if (!$this->enableLogging) {
            return;
        }
        if ($this->maxLevelInt && $logData->levelInt > $this->maxLevelInt) {
            return;
        }
        $this->mkdir($this->getFullLogDir());

        if (isset($this->logKeys[$logData->logKey])) {
            $this->logKeys[$logData->logKey]++;
            return;
        }
        $this->logKeys[$logData->logKey] = 1;
        $res = file_put_contents($this->tmpFile, $logData->__toString() . PHP_EOL, FILE_APPEND);
        if ($this->isEmptyTmpFile) {
            if ($res) {
                chmod($this->tmpFile, 0666);
            }
        }

        if ((time() - $this->lastTimeSave) > 10) {
            if ($this->owner->needSaveLog()) {
                $this->finishSave();
            }
            if (file_exists($this->tmpFile)) {
                unlink($this->tmpFile);
            }
            $this->isEmptyTmpFile = true;
        } else {
            $this->isEmptyTmpFile = false;
            $this->lastTimeSave = time();
        }


    }

    /**
     * @return string
     */
    private function getFullLogDir()
    {
        return rtrim($this->logPath, '/') . '/' . trim($this->logDir, '/') . '/';
    }

    /**
     * @return void
     */
    private function finishSave()
    {
        if (!file_exists($this->tmpFile)) {
            return;
        }
        $lastSlash = (int) strrpos($this->tplPath, '/');
        $fileName = strftime(substr($this->tplPath, 0, $lastSlash));

        $fileName = $this->getFullLogDir() . $fileName;

        if (!file_exists($fileName)) {
            if (!$this->mkdir($fileName)) {
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
            'logs' => []
        ];

        $handle = fopen($this->tmpFile, "r");
        if ($handle) {
            while (($logData = fgets($handle)) !== false) {
                if (!$logData) continue;
                /** @var LogData $logData */
                $logData = json_decode($logData);
                if ($this->logKeys[$logData->logKey] > 1) {
                    $logData->count = $this->logKeys[$logData->logKey];
                }
                $data['logs'][] = $logData;
            }
            fclose($handle);
        }

        file_put_contents($fileName, Tools::safeJsonEncode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
        if ($flagNew) {
            chmod($fileName, 0666);
        }
    }

    /**
     * @param mixed $data
     * @return string
     * @throws \Exception
     */
    protected function toString($data)
    {
        return str_replace(PHP_EOL, '\\n', PhpErrorCatcher::safe_json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
