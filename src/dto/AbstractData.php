<?php

namespace Xakki\PhpErrorCatcher\dto;

class AbstractData
{
    /**
     * @param array $data
     * @return static
     */
    public static function init(array $data)
    {
        $logData = new self();
        foreach ($data as $prop => $val) {
            $logData->{$prop} = $val;
        }
        return $logData;
    }
}