<?php

namespace Xakki\PhpErrorCatcher\dto;

abstract class AbstractData
{
    /**
     * @param array<string, mixed> $data
     * @return static
     */
    public static function init(array $data)
    {
        $logData = new static();
        foreach ($data as $prop => $val) {
            if (property_exists($logData, $prop)) {
                $logData->{$prop} = $val;
            }
        }
        return $logData;
    }

    /**
     * @return array<string, mixed>
     */
    public function __toArray()
    {
        return get_object_vars($this);
    }
}
