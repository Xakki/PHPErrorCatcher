<?php

namespace xakki\phperrorcatcher\storage;

use xakki\phperrorcatcher\LogData;

class ElasticStorage extends BaseStorage {

    protected $index = 'phplogs';
    protected $url = 'http://localhost:9200';
    protected $auth = ''; // user:pass

    function __destruct() {
        if ($this->_owner->needSaveLog()) {
//            $this->initLogIndex();
            if ($this->putData($this->_owner->getDataLogsGenerator(), $_SERVER)) {
                $this->_owner->successSaveLog();
            }
        }
    }

    /**
     * @param \Generator|LogData[] $logData
     */
    protected function putData($logsData, $serverData) {
        $data = [];
        $meta = json_encode(['index' => [ "_index" => $this->index]]);
//        $meta = '{"index":{}}';
        foreach($logsData as $key => $logData) {
            $data[] = $meta;
            $data[] = json_encode($this->collectLogData($logData, $serverData), JSON_UNESCAPED_UNICODE);
        }
        // . '/' . $this->index . '/' . $this->type
        return $this->sendDataToElastic(implode(PHP_EOL, $data).PHP_EOL, $this->url .'/_bulk', 'POST');
    }

    public function getParceUserAgent($user_agent) {
        return [
            'original' => $user_agent
        ];
    }

    /**
     * @param LogData $logData
     */
    protected function collectLogData($logData, $serverData) {

        $data = [
            "@timestamp" => $logData->timestamp,
            "message" => $logData->message,
            'level' => $logData->level, // info, notice, warning, error, critical
            'type' => $logData->type, // exception, trigger, log, fatal
        ];

        if ($logData->tags)
            $data['tags'] = $logData->tags;
        if ($logData->fields)
            $data['fields'] = $logData->fields;
        if ($logData->trace)
            $data['trace'] = $logData->trace;
        if ($logData->file)
            $data['file'] = $logData->file;
        
        if (!empty($serverData['REMOTE_ADDR']))
            $data['http']['ip_addr'] = $serverData['REMOTE_ADDR'];
        if (!empty($serverData['HTTP_HOST']))
            $data['http']['host'] = $serverData['HTTP_HOST'];
        if (!empty($serverData['REQUEST_METHOD']))
            $data['http']['method'] = $serverData['REQUEST_METHOD'];
        if (!empty($serverData['REQUEST_URI']))
            $data['http']['url'] = $serverData['REQUEST_URI'];
        if (!empty($serverData['HTTP_REFERER']))
            $data['http']['referrer'] = $serverData['HTTP_REFERER'];
        if (!empty($serverData['REQUEST_SCHEME']))
            $data['http']['scheme'] = $serverData['REQUEST_SCHEME'];
        if (!empty($serverData['HTTP_USER_AGENT']))
            $data['user_agent'] = $this->getParceUserAgent($serverData['HTTP_USER_AGENT']);
        return $data;
    }

    protected function initLogIndex() {
        $data = [
            "settings" => ['number_of_shards' => 1],
            "mappings" => [
                "_meta" => [
                    "beat" => "PHPErrorCatcher",
                    "version" => "0.3" // \xakki\phperrorcatcher\PHPErrorCatcher::VERSION
                ],
                "dynamic_templates" => [
                    [
                        "fields" => [
                            "path_match" => "fields.*",
                            "match_mapping_type" => "string",
                            "mapping" => [
                                "type" => "keyword"
                            ]
                        ]
                    ],
                ],
                'properties' => [
                    "@timestamp" => [
                        "type" => "date"
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
                                "type" => "keyword"
                            ]
                        ]
                    ],
                    "tags" => [
                        "type" => "keyword",
                        'boost' => 2
                    ],
                    "http" => [
                        "properties" => [
                            "ip_addr" => [
                                "type" => "ip"
                            ],
                            "host" => [
                                "type" => "keyword",
                                "ignore_above" => 128
                            ],
                            "method" => [
                                "type" => "keyword",
                                "ignore_above" => 8
                            ],
                            "url" => [
                                "type" => "keyword",
                                "ignore_above" => 1024
                            ],
                            "referrer" => [
                                "type" => "keyword",
                                "ignore_above" => 1024
                            ],
                            "scheme" => [
                                "type" => "keyword",
                                "ignore_above" => 8
                            ]
                        ]
                    ],
                    'user_agent' => [
                        'properties' => [
                            "device" => [
                                "type" => "keyword",
                                "ignore_above" => 32
                            ],
                            "name" => [
                                "type" => "keyword",
                                "ignore_above" => 32
                            ],
                            "original" => [
                                "type" => "keyword",
                                "ignore_above" => 256,
                                'index' => false
                            ],
                            "os" => [
                                "properties" => [
                                    "full" => [
                                        "type" => "keyword",
                                        "ignore_above" => 32,
                                        'index' => false
                                    ],
                                    "name" => [
                                        "type" => "keyword",
                                        "ignore_above" => 16
                                    ],
                                    "version" => [
                                        "type" => "keyword",
                                        "ignore_above" => 8
                                    ]
                                ]
                            ],
                            "version" => [
                                "type" => "keyword",
                                "ignore_above" => 32
                            ]
                        ]
                    ],
                    "message" => [
                        "type" => "text",
                        "norms" => false,
                    ],
                    "trace" => [
                        "type" => "text",
                        "norms" => false,
                        'index' => false
                    ],
                    "count" => [
                        "type" => "integer",
                    ],
                ]
            ]
        ];
        $this->sendDataToElastic($data, $this->url . '/' . $this->index, 'PUT');
    }

    protected function sendDataToElastic($data, $url, $method) {

        if (!is_string($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        $params = [
            CURLINFO_HEADER_OUT => false,
            CURLOPT_VERBOSE => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: '.((strpos($url, '/_bulk')) ? 'application/x-ndjson' : 'application/json'),
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERAGENT => 'PHPErrorCatcher v0.2',
            CURLOPT_HEADER => false,  //не включать заголовки ответа сервера в вывод
            CURLOPT_RETURNTRANSFER => true, //вернуть ответ сервера в виде строки
        ];
        if ($this->auth) {
            $params[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $params[CURLOPT_USERPWD] = $this->auth;
        }
        if ($this->_owner->get('debugMode')) {
            $params[CURLINFO_HEADER_OUT] = true;
            $params[CURLOPT_VERBOSE] = true;
//            $params[CURLOPT_HEADER] = true;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url); //задаём url
        foreach($params as $k=>$r) {
            curl_setopt($ch, $k, $r);
        }
        $text = curl_exec($ch);
        $info = curl_getinfo($ch);
        $isErr = ($info['http_code'] != 200);
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
            if ($this->_owner->get('debugMode')) {
                print_r('<pre>');
                print_r($text);
                print_r($info);
                print_r('</pre>');
            }
            $this->_owner->error($text, ['elastic'], ['http_code' => $info['http_code'], ['trace' => []]]);
            return false;
        }
        return true;
    }

}
