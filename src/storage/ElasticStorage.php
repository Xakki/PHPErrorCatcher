<?php

namespace xakki\phperrorcatcher\storage;

use Generator;
use xakki\phperrorcatcher\LogData;
use xakki\phperrorcatcher\PhpErrorCatcher;

class ElasticStorage extends BaseStorage
{
    protected $index = 'phplogs';
    protected $url = '';//http://localhost:9200
    protected $file = null; //'/var/log/app.log'    // OR fo filebeat
    protected $auth = ''; // user:pass

    public function __destruct()
    {
        if ($this->owner->needSaveLog()) {
//            $this->initLogIndex();
            if ($this->putData($this->owner->getDataLogsGenerator(), $_SERVER)) {
                $this->owner->successSaveLog();
            }
        }
    }

    public function getViewMenu()
    {
        $menu = [];
        if ($this->url) {
            $menu['IndexMapping'] = 'Elastic Mapping';
        }
        return $menu;
    }

    /**
     * @param Generator|LogData[] $logsData
     * @return bool
     */
    protected function putData(Generator|array $logsData, array $serverData): bool
    {
        if ($this->file && substr($this->file, 0, 1) == '/') {
            if (!$this->mkdir(dirname($this->file))) {
                return false;
            }
            foreach ($logsData as $key => $logData) {
                file_put_contents(
                    $this->file,
                    PhpErrorCatcher::safe_json_encode($this->collectLogData($logData, $serverData), JSON_UNESCAPED_UNICODE) . PHP_EOL,
                    FILE_APPEND
                );
            }
            return true;
        }

        if (!$this->url) {
            return false;
        }

        $data = [];
        $meta = json_encode(['index' => ["_index" => $this->index . '-' . date('Y-m')]]);
//        $meta = '{"index":{}}';
        foreach ($logsData as $key => $logData) {
            $data[] = $meta;
            $data[] = PhpErrorCatcher::safe_json_encode($this->collectLogData($logData, $serverData), JSON_UNESCAPED_UNICODE);
        }
        // . '/' . $this->index . '/' . $this->type
        return $this->sendDataToElastic(implode(PHP_EOL, $data) . PHP_EOL, $this->url . '/_bulk', 'POST');
    }

    public function getParceUserAgent(string $userAgent): array
    {
        return [
            'original' => $userAgent,
        ];
    }

    protected function collectLogData(LogData $logData, array $serverData): array
    {
        $data = [
            "@timestamp" => $logData->timestamp,
            "message" => $logData->message,
            'level' => $logData->level, // info, notice, warning, error, critical
            'type' => $logData->type, // exception, trigger, log, fatal
        ];

        if ($logData->tags) {
            $data['tags'] = $logData->tags;
        }
        if ($logData->fields) {
            $data['fields'] = $logData->fields;
        }
        if ($logData->trace) {
            $data['trace'] = $logData->trace;
        }
        if ($logData->file) {
            $data['file'] = $logData->file;
        }

        if (!empty($serverData['REMOTE_ADDR'])) {
            $data['http']['ip_addr'] = $serverData['REMOTE_ADDR'];
        }
        if (!empty($serverData['HTTP_HOST'])) {
            $data['http']['host'] = $serverData['HTTP_HOST'];
        }
        if (!empty($serverData['REQUEST_METHOD'])) {
            $data['http']['method'] = $serverData['REQUEST_METHOD'];
        }
        if (!empty($serverData['REQUEST_URI'])) {
            $data['http']['url'] = $serverData['REQUEST_URI'];
        }
        if (!empty($serverData['HTTP_REFERER'])) {
            $data['http']['referrer'] = $serverData['HTTP_REFERER'];
        }
        if (!empty($serverData['REQUEST_SCHEME'])) {
            $data['http']['scheme'] = $serverData['REQUEST_SCHEME'];
        }
        if (!empty($serverData['HTTP_USER_AGENT'])) {
            $data['user_agent'] = $this->getParceUserAgent($serverData['HTTP_USER_AGENT']);
        }
        if (!empty($serverData['argv'])) {
            $data['http']['argv'] = $serverData['argv'];
        }
        return $data;
    }

    public function actionIndexMapping(): string
    {
        $data = [
            'version' => 1,
            'priority' => 1,
            '_meta' => [
                'description' => 'PhpErrorCatcher v' . PhpErrorCatcher::VERSION,
            ],
            "index_patterns" => [$this->index . '-*'],
            'template' => [
                'settings' => [
                    'index' => [
//                        'lifecycle' => ['name' => 'logs'],
                        'refresh_interval' => '5s',
                        'query' => [
                            'default_field' => ['message'],
                        ],
                    ],
                ],
                "mappings" => [
                    "dynamic_templates" => [
                        [
                            "fields" => [
                                "path_match" => "fields.*",
                                "match_mapping_type" => "string",
                                "mapping" => [
                                    "type" => "keyword",
                                ],
                            ],
                        ],
                    ],
                    'properties' => [
                        "@timestamp" => [
                            "type" => "date",
                        ],
                        'level' => ['type' => 'keyword'],
                        'type' => ['type' => 'keyword'],
                        "file" => [
                            "type" => "text",
                            "norms" => false,
                        ],
                        "fields" => [
                            "properties" => [
                                "env" => [
                                    "type" => "keyword",
                                ],
                            ],
                        ],
                        "tags" => [
                            "type" => "keyword",
                            'boost' => 2,
                        ],
                        "http" => [
                            "properties" => [
                                "ip_addr" => [
                                    "type" => "ip",
                                ],
                                "host" => [
                                    "type" => "keyword",
                                    "ignore_above" => 128,
                                ],
                                "method" => [
                                    "type" => "keyword",
                                    "ignore_above" => 8,
                                ],
                                "url" => [
                                    "type" => "keyword",
                                    "ignore_above" => 1024,
                                ],
                                "referrer" => [
                                    "type" => "keyword",
                                    "ignore_above" => 1024,
                                ],
                                "scheme" => [
                                    "type" => "keyword",
                                    "ignore_above" => 8,
                                ],
                            ],
                        ],
                        'user_agent' => [
                            'properties' => [
                                "device" => [
                                    "type" => "keyword",
                                    "ignore_above" => 32,
                                ],
                                "name" => [
                                    "type" => "keyword",
                                    "ignore_above" => 32,
                                ],
                                "original" => [
                                    "type" => "keyword",
                                    "ignore_above" => 256,
                                    'index' => false,
                                ],
                                "os" => [
                                    "properties" => [
                                        "full" => [
                                            "type" => "keyword",
                                            "ignore_above" => 32,
                                            'index' => false,
                                        ],
                                        "name" => [
                                            "type" => "keyword",
                                            "ignore_above" => 16,
                                        ],
                                        "version" => [
                                            "type" => "keyword",
                                            "ignore_above" => 8,
                                        ],
                                    ],
                                ],
                                "version" => [
                                    "type" => "keyword",
                                    "ignore_above" => 32,
                                ],
                            ],
                        ],
                        "message" => [
                            "type" => "text",
                            "norms" => false,
                        ],
                        "trace" => [
                            "type" => "text",
                            "norms" => false,
                            'index' => false,
                        ],
                        "count" => [
                            "type" => "integer",
                        ],
                    ],
                ],
            ],
        ];
        if ($this->sendDataToElastic($data, $this->url . '/_index_template/phplogs', 'PUT')) {
            return 'Success update mapping';
        } else {
            return 'Error update mapping';
        }
    }

    protected function sendDataToElastic(mixed $data, string $url, string $method): bool
    {
        if (!is_string($data)) {
            $data = PhpErrorCatcher::safe_json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        $params = [
            CURLINFO_HEADER_OUT => false,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_VERBOSE => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: ' . (strpos($url, '/_bulk') ? 'application/x-ndjson' : 'application/json'),
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERAGENT => 'PhpErrorCatcher v0.2',
            CURLOPT_HEADER => false, //не включать заголовки ответа сервера в вывод
            CURLOPT_RETURNTRANSFER => true, //вернуть ответ сервера в виде строки
        ];
        if ($this->auth) {
            $params[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $params[CURLOPT_USERPWD] = $this->auth;
        }
        if ($this->owner::$debugMode) {
            $params[CURLINFO_HEADER_OUT] = true;
            $params[CURLOPT_VERBOSE] = true;
//            $params[CURLOPT_HEADER] = true;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url); //задаём url
        foreach ($params as $k => $r) {
            curl_setopt($ch, $k, $r);
        }
        $text = curl_exec($ch);
        $info = curl_getinfo($ch);
        $isErr = $info['http_code'] != 200;
        if (!$isErr) {
            $res = json_decode($text, true);
            if ($res) {
                if ($res['errors']) {
                    $isErr = true;
                }
            } else {
                $isErr = true;
            }
        }

        if ($isErr) {
            if ($this->owner::$debugMode) {
                print_r('<pre>');
                print_r($text);
                print_r(json_decode($text, true));
                print_r($info);
                print_r('</pre>');
            }
            $this->owner->error($text, ['elastic'], ['http_code' => $info['http_code'], ['trace' => []]]);
            return false;
        }
        return true;
    }
}
