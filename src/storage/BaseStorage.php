<?php

namespace Xakki\PhpErrorCatcher\storage;

use Xakki\PhpErrorCatcher\Base;

abstract class BaseStorage extends Base
{
    public function getViewMenu()
    {
        return [];
    }
//    function __construct(PhpErrorCatcher $owner, $config = []) {
//        parent::__construct($owner, $config);
//
//    }

//    abstract function __destruct();
}
