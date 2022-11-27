<?php
declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\storage;

use Xakki\PhpErrorCatcher\Base;

abstract class BaseStorage extends Base
{
    public function getViewMenu()
    {
        return [];
    }
}
