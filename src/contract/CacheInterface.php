<?php

namespace Xakki\PhpErrorCatcher\contract;

interface CacheInterface
{
    /**
     * @param string $key
     * @return mixed
     */
    public function get($key);

    /**
     * @param string $key
     * @param mixed $value
     * @param int|null $duration
     * @return bool
     */
    public function set($key, $value, $duration = null);
}
