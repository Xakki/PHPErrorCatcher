<?php
declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\dto;

class AbstractData
{
    /**
     * @param array<string, string|int> $data
     * @return static
     */
    public static function init(array $data): static
    {
        // @phpstan-ignore-next-line
        $logData = new static();
        foreach ($data as $prop => $val) {
            $logData->{$prop} = $val;
        }
        return $logData;
    }
}