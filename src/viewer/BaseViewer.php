<?php

namespace xakki\phperrorcatcher\viewer;


/**
 * Class BaseViewer
 * @package xakki\phperrorcatcher\viewer
 * @method string getInitGetKey
 */
abstract class BaseViewer extends \xakki\phperrorcatcher\Base {

    protected $initGetKey;

    public function getHomeUrl($end = '/') {
        $url = $_SERVER['REQUEST_URI'];
        $url = parse_url($url);
        return $url['path'] . '?' . $this->getInitGetKey() . '=' . $end;
    }
}