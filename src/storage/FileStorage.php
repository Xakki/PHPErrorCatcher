<?php

namespace xakki\phperrorcatcher\storage;

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
class FileStorage extends BaseStorage {
    /* Config */
    protected $logPath = '';
    protected $logDir = '/logsError';
    protected $backUpDir = '/_backUp'; 
    protected $limitFileSize = 10485760;
    protected $byDir = 'Y.m'; // Y.m.d
    protected $byFile = 'd'; // H

    const FILE_EXT = 'plog';


    function __destruct() {

        if ($this->_owner->needSaveLog()) {
            if($this->putData($this->getSerializeLogs($this->_owner->getDataLogsGenerator()))) {
                $this->_owner->successSaveLog();
            }
        }
    }

    /**
     * @param \Generator $logs
     * @return mixed
     */
    public static function getSerializeLogs($logs) {
        $data = [
            'http' => self::getDataHttp(),
            'logs' => ($logs instanceof \Traversable ? iterator_to_array($logs) : $logs)
        ];
        return str_replace(PHP_EOL, '\\n', PHPErrorCatcher::safe_json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public static function getDataHttp() {
        $data = [];
        $serverData = $_SERVER;
        if (!empty($serverData['REMOTE_ADDR']))
            $data['ip_addr'] = $serverData['REMOTE_ADDR'];
        if (!empty($serverData['HTTP_HOST']))
            $data['host'] = substr($serverData['HTTP_HOST'],0, 500);
        if (!empty($serverData['REQUEST_METHOD']))
            $data['method'] = $serverData['REQUEST_METHOD'];
        if (!empty($serverData['REQUEST_URI']))
            $data['url'] = substr($serverData['REQUEST_URI'],0,500);
        if (!empty($serverData['HTTP_REFERER']))
            $data['referrer'] = substr($serverData['HTTP_REFERER'],0,500);
        if (!empty($serverData['REQUEST_SCHEME']))
            $data['scheme'] = $serverData['REQUEST_SCHEME'];
        if (!empty($serverData['HTTP_USER_AGENT']))
            $data['user_agent'] = substr($serverData['HTTP_USER_AGENT'],0,500);
        if (PHPErrorCatcher::init()->get('_overMemory'))
            $data['overMemory'] = true;
        return $data;
    }

    private function putData($fileLog) {
        $path = $this->logPath . $this->logDir . '/' . date($this->byDir);
        if (!file_exists($path)) {
            $oldUmask = umask(0);
            mkdir($path, 0775, true);
            umask($oldUmask);
        }
        $fileName = $path . '/' . date($this->byFile) . '.'.self::FILE_EXT;

        $flagNew = true;
        if (file_exists($fileName)) {
            if (filesize($fileName) > $this->limitFileSize) {
                $i = 0;
                $preiosFile = '';
                while (true) {
                    $preiosFile = str_replace('.'.self::FILE_EXT, '_' . $i++ . '.'.self::FILE_EXT, $fileName);
                    if (!file_exists($preiosFile)) break;
                }
                if (!rename($fileName, $preiosFile)) {
//                    $fileLog .= '***** Cant rename big file';
                    $flagNew = false;
                }
                else {
                    //  href="' . $this->getFileUrl($preiosFile) . '"
//                    $fileLog = '<a>Part # ' . $i . '. Previos file was to big</a><hr/>' . PHP_EOL . $fileLog;
                }
            }
            else {
                $flagNew = false;
            }
        }
        file_put_contents($fileName, $fileLog.PHP_EOL, FILE_APPEND);
        if ($flagNew) chmod($fileName, 0777);
    }


}