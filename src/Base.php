<?php

namespace xakki\phperrorcatcher;

abstract class Base
{
    protected PHPErrorCatcher $owner;

    function __construct(PHPErrorCatcher $owner, $config = [])
    {
        $this->owner = $owner;
        $this->applyConfig($config);
    }

//    function __destruct()
//    {
//
//    }

    public function applyConfig(array $config): void
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key) && str_starts_with($key, '_')) {
                $this->$key = $value;
            }
        }
    }

    public function __call($method, $arguments = null)
    {
        $action = substr($method, 0, 3);
        $property = lcfirst(substr($method, 3));

        if ($action === 'get' && property_exists($this, $property) && str_starts_with($property, '_')) {
            return $this->$property;
        }
//        elseif ($action === 'set') {
//        }
        else {
            return null;
        }
    }

    public function mkdir(string $fileName): bool
    {
        if (file_exists($fileName)) return true;
        $oldUmask = umask(0);
        $res = mkdir($fileName, 0775, true);
        umask($oldUmask);
        return $res;
    }
}