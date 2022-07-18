<?php

namespace xakki\phperrorcatcher\storage;

use xakki\phperrorcatcher\Base;

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
