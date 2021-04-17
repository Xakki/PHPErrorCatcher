<?php

namespace xakki\phperrorcatcher\storage;

use xakki\phperrorcatcher\Base;
use xakki\phperrorcatcher\PHPErrorCatcher;

abstract class BaseStorage extends Base
{
    public function getViewMenu()
    {
        return [];
    }
//    function __construct(PHPErrorCatcher $owner, $config = []) {
//        parent::__construct($owner, $config);
//
//    }


//    abstract function __destruct();

}