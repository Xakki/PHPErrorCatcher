<?php

namespace xakki\phperrorcatcher;

use xakki\phperrorcatcher\PHPErrorCatcher;

abstract class Base
{
    /* @var PHPErrorCatcher */
    protected $_owner;

    function __construct(PHPErrorCatcher $owner, $config = [])
    {
        $this->_owner = $owner;
        $this->applyConfig($config);
    }

    function __destruct()
    {

    }

    public function applyConfig($config)
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key) && substr($key, 0, 1) != '_') {
                $this->$key = $value;
            }
        }
    }

    public function __call($method, $arguments = null)
    {
        $action = substr($method, 0, 3);
        $property = lcfirst(substr($method, 3));

        if ($action === 'get' && property_exists($this, $property) && substr($property, 0, 1) != '_') {
            return $this->$property;
        }
//        elseif ($action === 'set') {
//        }
        else {
            return null;
        }
    }

    /**
     * @param string $fileName
     * @return bool
     */
    public function mkdir($fileName)
    {
        if (file_exists($fileName)) return true;
        $oldUmask = umask(0);
        $res = mkdir($fileName, 0775, true);
        umask($oldUmask);
        return $res;
    }
}