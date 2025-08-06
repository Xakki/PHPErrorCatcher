<?php

namespace Xakki\PhpErrorCatcher;

/**
 * Class Base
 * @package Xakki\PhpErrorCatcher
 */
abstract class Base
{
    /**
     * @var PhpErrorCatcher
     */
    public $owner;

    /**
     * @param PhpErrorCatcher $owner
     * @param array $config
     */
    public function __construct(PhpErrorCatcher $owner, $config = [])
    {
        $this->owner = $owner;
        $this->applyConfig($config);
    }

    public function __destruct()
    {
    }

    /**
     * @param array $config
     * @return void
     */
    public function applyConfig(array $config)
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    /**
     * @param string $method
     * @param array|null $arguments
     * @return mixed|null
     */
    public function __call($method, $arguments = null)
    {
        $action = substr($method, 0, 3);
        $property = lcfirst(substr($method, 3));

        if ($action === 'get' && property_exists($this, $property)) {
            return $this->$property;
        } else {
            return null;
        }
    }

    /**
     * @param string $fileName
     * @return bool
     */
    public function mkdir($fileName)
    {
        if (file_exists($fileName)) {
            return true;
        }
        $oldUmask = umask(0);
        $res = mkdir($fileName, 0775, true);
        umask($oldUmask);
        return $res;
    }
}
