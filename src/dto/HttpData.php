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
    public string $shell = '';

    /**
     * @return mixed[]
     */
    public function __toArray(): array
    {
        return get_object_vars($this);
    }
}