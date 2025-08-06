<?php

namespace Xakki\PhpErrorCatcher\dto;

class HttpData extends AbstractData
{
    /** @var string */
    public $ipAddr;
    /** @var string */
    public $host;
    /** @var string */
    public $method;
    /** @var string */
    public $url;
    /** @var string */
    public $referrer;
    /** @var string */
    public $scheme;
    /** @var string */
    public $userAgent;
    /** @var bool */
    public $overMemory = false;
    /** @var string */
    public $shell = '';
}
