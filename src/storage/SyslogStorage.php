<?php

namespace Xakki\PhpErrorCatcher\storage;

use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\LogData;

/**
 * Class SyslogStorage
 * @package Xakki\PhpErrorCatcher\storage
 * @method string getLogPath()
 * @method string getLogDir()
 * @method string getBackUpDir()
 * @method int getLimitFileSize()
 * @method string getByDir()
 * @method string getByFile()
 */
class SyslogStorage extends BaseStorage
{
    /* Config */
    protected $syslogFacility = LOG_LOCAL7;
    protected $syslogFlags = LOG_PID;
    protected $syslogPrefix = 'json';
    protected $sock;
    protected $remoteIp = '127.0.0.1';
    protected $remotePort = 451;
    protected $pid;
    protected $hostname;
    protected $logSize = 1400;


    function __construct(PhpErrorCatcher $owner, $config = [])
    {
        parent::__construct($owner, $config);
//        openlog($this->syslogPrefix, $this->syslogFlags, $this->syslogFacility);
        $this->sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        $this->pid = getmypid();
        if (false === $this->pid) {
            $this->pid = '-';
        }

        if (!$this->hostname) {
            $this->hostname = (string) gethostname();
        }
    }

    function __destruct()
    {
        parent::__destruct();
        socket_close($this->sock);
    }

    function write(LogData $logData) {

        $log = [
            'ver' => PhpErrorCatcher::VERSION,
            'message' => $logData->message,
            'level_name' => $logData->level,
            'level_php' => $logData->levelInt,
            'extra' => $this->getDataHttp(),
            'context' => $logData->fields,
            'tags' => implode(',', $logData->tags),
        ];
        $log['context']['trace'] = $logData->trace;
        $log['context']['file'] = $logData->file;
        $log['context']['logType'] = $logData->type;
        $date = \DateTime::createFromFormat('U.u', $logData->microtime);
        $log['context']['timestamp'] = $date->format('Y-m-d H:i:s.u');

        $log = $this->trimLog($log);
        //fwrite(STDOUT, $log);
        //syslog($logData->levelInt, $log);
        $priority = $logData->levelInt + $this->syslogFacility;
        $log = "<$priority>1 " .
            date(\DateTime::RFC3339) . ' ' .
            $this->hostname . ' ' .
            'php ' . $this->pid . ' - - ' .
            $log;
        socket_sendto($this->sock, $log, strlen($log), 0, $this->remoteIp, (int) $this->remotePort);
    }

    /**
     * @param array<string, mixed> $recordData
     * @return string
     */
    protected function trimLog(array $recordData)
    {
        $log = PhpErrorCatcher::safe_json_encode($recordData);//JSON_UNESCAPED_UNICODE

        if (json_last_error()) {
            fwrite(\STDERR, '[' . date('Y-m-d h:i:s') . '] CustomFormatter json error [' . json_last_error() . '] '
                . json_last_error_msg() . PHP_EOL);
            return '';
        }
        $len = strlen($log);
        if (!$this->logSize || $len < $this->logSize) {
            return $log;
        }

        $limit = $this->logSize * 0.8;
        $i = 0;
        while ($len > $this->logSize) {
            $i++;
            self::trimData($recordData['message'], $limit);

            foreach ($recordData['context'] as &$v) {
                if (!is_int($v) && !is_float($v) && !is_bool($v)) {
                    $v = (string) $v;
                }
                if (is_string($v)) {
                    self::trimData($v, $limit * 0.6);
                }
            }

            foreach ($recordData['extra'] as &$v) {
                if (!is_int($v) && !is_float($v) && !is_bool($v)) {
                    $v = (string) $v;
                }
                if (is_string($v)) {
                    self::trimData($v, $limit * 0.6);
                }
            }
            $len = self::getLen($recordData);
            $limit = $limit * 0.7;
            if ($i > 4) break;
        }

        return PhpErrorCatcher::safe_json_encode($recordData);
    }

    /**
     * @param string $str
     * @param float|int $limit
     * @return void
     */
    protected function trimData(&$str, $limit)
    {
        if (strlen($str) > $limit) {
            $str = substr($str, 0, (int) $limit) . '…';// Multibyte
        }
    }

    /**
     * @param mixed $v
     * @return int
     */
    protected function getLen($v)
    {
        return strlen(PhpErrorCatcher::safe_json_encode($v));
    }
}