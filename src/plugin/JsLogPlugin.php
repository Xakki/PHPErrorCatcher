<?php

namespace Xakki\PhpErrorCatcher\plugin;

use Xakki\PhpErrorCatcher\PhpErrorCatcher;

class JsLogPlugin extends BasePlugin
{

    /**
     * If you want enable log-request, set this name
     * @var null
     */
    protected $catcherLogName = 'myCatcherLog';
    protected $catcherLogFileSeparate = true;
    protected $level = PhpErrorCatcher::LEVEL_WARNING;

    function __construct(PhpErrorCatcher $owner, $config = [])
    {
        parent::__construct($owner, $config);

        if ($this->initGetKey && isset($_GET[$this->initGetKey])) {
            $owner->setGlobalTag('JsLogPlugin');
            $this->initLogRequest($owner);
//            header('Content-type: text/html; charset=UTF-8');
//            echo $renderLog;
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'ok']);
            exit();
        }
    }

    /**
     * Use catche.js for log error in javascript
     */
    public function initLogRequest(PhpErrorCatcher $owner)
    {
        if (!count($_POST)) {
            $_POST = json_decode(file_get_contents('php://input'), true);
        }
        if (!isset($_POST['m']) || !isset($_POST['u']) || !isset($_POST['r'])) exit();
        $errstr = str_replace('||', PHP_EOL, $_POST['m']);
        $size = mb_strlen(serialize((array)$errstr), '8bit');
        if ($size > 1000) $errstr = mb_substr($errstr, 0, 1000) . '...(' . $size . 'b)...';
        $vars = [
            PhpErrorCatcher::FIELD_NO_TRICE => true,
            PhpErrorCatcher::FIELD_FILE => '',
            'ver' => $_POST['v'],
            'url' => $_POST['u'],
            'referrer' => $_POST['r'],
            'userAgent' => isset($_POST['ua']) ? $_POST['ua'] : '',
        ];
        if (!empty($_POST['s'])) $vars[PhpErrorCatcher::FIELD_TRICE] = str_replace('||', PHP_EOL, $_POST['s']);
        if (!empty($_POST['l'])) $vars[PhpErrorCatcher::FIELD_FILE] = $_POST['l'];

        $GLOBALS['skipRenderBackTrace'] = 1;
        $owner->log($this->level, $errstr, ['js'], $vars);

    }

}