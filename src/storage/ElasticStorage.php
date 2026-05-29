<?php

declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\storage;

use Generator;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\Tools;

class ElasticStorage extends BaseStorage
{
    protected string $index = 'phplogs';
    protected string $file = ''; //'/var/log/app.log'    // OR fo filebeat
    protected string $url = '';//http://localhost:9200
    protected string $auth = ''; // user:pass

    public function __destruct()
    {
        if ($this->owner->needSaveLog()) {
            if ($this->putData($this->owner->getDataLogsGenerator())) {
                $this->owner->successSaveLog();
            }
        }
    }

    // Accumulated in PhpErrorCatcher::$logData / $logCached, sent in bulk in __destruct().
    public function write(LogData $logData): void
    {
    }

    public function getViewMenu(): array
    {
        $menu = [];
        if ($this->url) {
            // Run actionIndexMapping()
            $menu['IndexMapping'] = 'Elastic Mapping';
        }
        return $menu;
    }

    /**
     * @param Generator<LogData> $logsData
     * @return bool
     */
    protected function putData(Generator $logsData): bool
    {
        if ($this->file && substr($this->file, 0, 1) == '/') {
            if (!$this->mkdir(dirname($this->file))) {
                return false;
            }
            foreach ($logsData as $logData) {
                file_put_contents(
                    $this->file,
                    Tools::safeJsonEncode($this->buildRecord($logData), JSON_UNESCAPED_UNICODE) . PHP_EOL,
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
        foreach ($logsData as $logData) {
            $data[] = $meta;
            $data[] = Tools::safeJsonEncode($this->buildRecord($logData), JSON_UNESCAPED_UNICODE);
        }
        // . '/' . $this->index . '/' . $this->type
        return $this->sendDataToElastic(implode(PHP_EOL, $data) . PHP_EOL, $this->url . '/_bulk', 'POST');
    }

    // The record uses the same format as StreamStorage — BaseStorage::buildRecord().

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
                // Structure matches BaseStorage::buildRecord() (Monolog shape):
                // message / level / level_name / channel / datetime + nested context and extra.
                "mappings" => [
                    "dynamic_templates" => [
                        [
                            "context_strings" => [
                                "path_match" => "context.*",
                                "match_mapping_type" => "string",
                                "mapping" => ["type" => "keyword", "ignore_above" => 1024],
                            ],
                        ],
                        [
                            "extra_strings" => [
                                "path_match" => "extra.*",
                                "match_mapping_type" => "string",
                                "mapping" => ["type" => "keyword", "ignore_above" => 1024],
                            ],
                        ],
                    ],
                    'properties' => [
                        "datetime" => ["type" => "date"],
                        "message" => [
                            "type" => "text",
                            "norms" => false,
                        ],
                        "level" => ["type" => "integer"],
                        "level_name" => ["type" => "keyword"],
                        "channel" => ["type" => "keyword"],
                        "context" => [
                            "properties" => [
                                "remote_ip" => ["type" => "ip"],
                                "request_host" => ["type" => "keyword", "ignore_above" => 128],
                                "request_method" => ["type" => "keyword", "ignore_above" => 8],
                                "request_url" => ["type" => "keyword", "ignore_above" => 1024],
                                "request_referrer" => ["type" => "keyword", "ignore_above" => 1024],
                                "request_scheme" => ["type" => "keyword", "ignore_above" => 8],
                                "request_user_agent" => ["type" => "keyword", "ignore_above" => 512],
                                "file" => ["type" => "text", "norms" => false],
                                "trace" => ["type" => "text", "norms" => false, "index" => false],
                                "log_type" => ["type" => "keyword"],
                                "log_count" => ["type" => "integer"],
                                "tags" => ["type" => "keyword"],
                            ],
                        ],
                        "extra" => [
                            "properties" => [
                                "log_ver" => ["type" => "keyword", "ignore_above" => 16],
                                "pid" => ["type" => "integer"],
                            ],
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
            $data = Tools::safeJsonEncode($data, JSON_UNESCAPED_UNICODE);
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
            CURLOPT_HEADER => false, //do not include the server response headers in the output
            CURLOPT_RETURNTRANSFER => true, //return the server response as a string
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
        curl_setopt($ch, CURLOPT_URL, $url); //set the url
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
            $this->owner->error($text . PHP_EOL . json_encode($info), [
                PhpErrorCatcher::FIELD_NO_TRACE => true,
                PhpErrorCatcher::FIELD_FILE => '',
                'elastic',
            ]);
            return false;
        }
        return true;
    }
}
