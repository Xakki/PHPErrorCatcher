<?php

declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\dto;

class HttpData extends AbstractData
{
    public ?string $ipAddr;
    public ?string $host;
    public ?string $method;
    public ?string $url;
    public ?string $referrer;
    public ?string $scheme;
    public ?string $userAgent;
    public bool $overMemory = false;
    public string $consoleArgv = '';

    /**
     * @return mixed[]
     */
    public function __toArray(): array
    {
        return [
            'remote_ip' => $this->ipAddr,
            'request_host' => $this->host,
            'request_scheme' => $this->scheme,
            'request_method' => $this->method,
            'request_url' => $this->url,
            'request_referrer' => $this->referrer,
            'request_user_agent' => $this->userAgent,
            'console_argv' => $this->consoleArgv,
            'is_over_memory' => $this->overMemory,
        ];
    }
}
