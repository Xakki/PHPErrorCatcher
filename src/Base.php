<?php

namespace Xakki\PhpErrorCatcher;

/**
 * @method PhpErrorCatcher getOwner()
 */
abstract class Base
{
    protected PhpErrorCatcher $owner;

    /**
     * @param PhpErrorCatcher $owner
     * @param array<string, string|int> $config
     */
    public function __construct(PhpErrorCatcher $owner, array $config = [])
    {
        $this->owner = $owner;
        $this->applyConfig($config);
    }

    /**
     * @param array<string, string|int> $config
     * @return void
     */
    public function applyConfig(array $config): void
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    /**
     * @param string $method
     * @param ?array<int, mixed> $arguments
     * @return mixed
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

    public function mkdir(string $fileName): bool
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
