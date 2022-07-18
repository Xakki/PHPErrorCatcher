<?php

namespace xakki\phperrorcatcher\contract;

interface CacheInterface
{
    public function get($key);
    public function set($key, $value, $duration = null);
}