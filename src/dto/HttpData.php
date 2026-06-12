<?php

declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\dto;

class HttpData extends AbstractData
{
    public ?string $ipAddr = null;
    public ?string $host = null;
    public ?string $method = null;
    public ?string $url = null;
    public ?string $referrer = null;
    public ?string $scheme = null;
    public ?string $userAgent = null;
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
