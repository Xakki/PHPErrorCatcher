<?php
declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\contract;

interface CacheInterface
{
    public function get($key);

    public function set($key, $value, $duration = null);
}