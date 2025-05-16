<?php

namespace Xakki\PhpErrorCatcher\storage;

use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\Tools;

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
    protected int $syslogFacility = LOG_LOCAL7;
    protected int $syslogFlags = LOG_PID;
    protected string $syslogPrefix = 'json';
    protected \Socket $sock;
    protected string $remoteIp = '127.0.0.1';
    protected int $remotePort = 451;
    protected int $pid;
    protected string $hostname;
    protected int $logSize = 1400;


    public function __construct(PhpErrorCatcher $owner, $config = [])
    {
        parent::__construct($owner, $config);
//        openlog($this->syslogPrefix, $this->syslogFlags, $this->syslogFacility);
        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$sock) {
            throw new \Exception('Unable to create socket: ' . socket_strerror(socket_last_error()));
        }
        $this->sock = $sock;
        $this->pid = getmypid() ?: 0;

        if (!$this->hostname) {
            $this->hostname = (string) gethostname();
        }
    }

    public function __destruct()
    {
        socket_close($this->sock);
    }

    public function write(LogData $logData): void
    {
        $log = [
            'ver' => PhpErrorCatcher::VERSION,
            'message' => $logData->message,
            'level_name' => $logData->level,
            'level_php' => $logData->levelInt,
            'extra' => $this->getDataHttp()->__toArray(),
            'context' => $logData->fields,
            'tags' => implode(',', $logData->tags),
        ];
        $log['context']['trace'] = $logData->trace;
        $log['context']['file'] = $logData->file;
        $log['context']['logType'] = $logData->type;
        $date = \DateTime::createFromFormat('U.u', (string) $logData->timestamp);
        $log['context']['timestamp'] = $date ? $date->format('Y-m-d H:i:s.u') : '';

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
    protected function trimLog(array $recordData): string
    {
        $log = Tools::safeJsonEncode($recordData);//JSON_UNESCAPED_UNICODE

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

        return Tools::safeJsonEncode($recordData);
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
        return strlen(Tools::safeJsonEncode($v));
    }
}