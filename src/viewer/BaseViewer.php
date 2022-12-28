<?php
declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\viewer;

use Xakki\PhpErrorCatcher\Base;

/**
 * @method string getInitGetKey()
 */
abstract class BaseViewer extends Base
{
    protected string $initGetKey;

    public function getHomeUrl(string $end = '/'): string
    {
        $url = $_SERVER['REQUEST_URI'];
        $url = parse_url($url);
        return $url['path'] . '?' . $this->getInitGetKey() . '=' . $end;
    }

}
