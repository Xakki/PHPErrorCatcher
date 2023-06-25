<?php

namespace Xakki\PhpErrorCatcher\dto;

class HttpData extends AbstractData
{
    /** @var string|null */
    public $ipAddr;
    /** @var string|null */
    public $host;
    /** @var string|null */
    public $method;
    /** @var string|null */
    public $url;
    /** @var string|null */
    public $referrer;
    /** @var string|null */
    public $scheme;
    /** @var string|null */
    public $userAgent;
    /** @var bool */
    public $overMemory = false;
    /** @var string */
    public $shell = '';

    /**
     * @return array
     */
    public function __toArray()
    {
        return get_object_vars($this);
    }
}
