<?php

namespace xakki\phperrorcatcher\storage;

use Generator;
use Traversable;
use xakki\phperrorcatcher\PHPErrorCatcher;
use xakki\phperrorcatcher\LogData;

/**
 * Class FileStorage
 * @package xakki\phperrorcatcher\storage
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
    protected $logPath = '';
    protected $tplPath = 'Y.m/d';
    protected $logDir = '/logsError';
    protected $backUpDir = '/_backUp';
    protected $limitFileSize = 10485760;

    const FILE_EXT = 'plog';


    function __destruct()
    {

        if ($this->_owner->needSaveLog()) {
            if ($this->putData($this->_owner->getDataLogsGenerator())) {
                $this->_owner->successSaveLog();
            }
        }
    }

    /**
     * @param Generator $logs
     * @return mixed
     */
    public static function getSerializeLogs($logs)
    {
        $data = [
            'http' => self::getDataHttp(),
            'logs' => ($logs instanceof Traversable ? iterator_to_array($logs) : $logs)
        ];
        return str_replace(PHP_EOL, '\\n', PHPErrorCatcher::safe_json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public static function getDataHttp()
    {
        $data = [];
        $serverData = $_SERVER;
        if (!empty($serverData['REMOTE_ADDR']))
            $data['ip_addr'] = $serverData['REMOTE_ADDR'];
        if (!empty($serverData['HTTP_HOST']))
            $data['host'] = substr($serverData['HTTP_HOST'], 0, 500);
        else
            $data['host'] = (isset($serverData['HTTP_X_SERVER_NAME']) ? $serverData['HTTP_X_SERVER_NAME'] : (isset($serverData['SERVER_NAME']) ? $serverData['SERVER_NAME'] : ''));
        if (!empty($serverData['REQUEST_METHOD']))
            $data['method'] = $serverData['REQUEST_METHOD'];
        if (!empty($serverData['REQUEST_URI']))
            $data['url'] = substr($serverData['REQUEST_URI'], 0, 500);
        if (!empty($serverData['HTTP_REFERER']))
            $data['referrer'] = substr($serverData['HTTP_REFERER'], 0, 500);
        if (!empty($serverData['REQUEST_SCHEME']))
            $data['scheme'] = $serverData['REQUEST_SCHEME'];
        if (!empty($serverData['HTTP_USER_AGENT']))
            $data['user_agent'] = substr($serverData['HTTP_USER_AGENT'], 0, 500);
        if (PHPErrorCatcher::isMemoryOver())
            $data['overMemory'] = true;
        return $data;
    }

    /**
     * @param Generator|LogData[] $logData
     * @return bool
     */
    private function putData($fileLog)
    {
        $lastSlash = strrpos($this->tplPath, '/');
        $date = \DateTimeImmutable::createFromFormat('U', time());
        $fileName = $date->format(substr($this->tplPath, 0, $lastSlash));

        $fileName = rtrim($this->logPath, '/') . '/'.trim($this->logDir, '/') . '/' . $fileName;

        if (!file_exists($fileName)) {
            $this->mkdir($fileName);
        }
//\xakki\phperrorcatcher\PHPErrorCatcher::FIELD_LOG_NAME
        $fileName = $fileName . '/' . $date->format(substr($this->tplPath, $lastSlash));
        if ($this->_owner->getErrCount()) {
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
                    if (!file_exists($preiosFile)) break;
                }
                if (!rename($fileName, $preiosFile)) {
//                    $fileLog .= '***** Cant rename big file';
                    $flagNew = false;
                } else {
                    //  href="' . $this->getFileUrl($preiosFile) . '"
//                    $fileLog = '<a>Part # ' . $i . '. Previos file was to big</a><hr/>' . PHP_EOL . $fileLog;
                }
            } else {
                $flagNew = false;
            }
        }
        file_put_contents($fileName, $this->getSerializeLogs($fileLog) . PHP_EOL, FILE_APPEND);
        if ($flagNew) chmod($fileName, 0777);
    }


}