<?php

namespace Xakki\PhpErrorCatcher\viewer;

use Xakki\PhpErrorCatcher\Base;

/**
 * @method string getInitGetKey()
 */
abstract class BaseViewer extends Base
{
    /**
     * @var string
     */
    protected $initGetKey;

    /**
     * @param string $end
     * @return string
     */
    public function getHomeUrl($end = '/')
    {
        $url = $_SERVER['REQUEST_URI'];
        $url = parse_url($url);
        return $url['path'] . '?' . $this->getInitGetKey() . '=' . $end;
    }

}
