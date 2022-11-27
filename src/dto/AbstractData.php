<?php
declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\dto;

class AbstractData
{
    public static function init(array $data): self
    {
        $logData = new self();
        foreach ($data as $prop => $val) {
            $logData->{$prop} = $val;
        }
        return $logData;
    }
}