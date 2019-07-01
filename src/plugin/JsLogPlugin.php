<?php

namespace xakki\phperrorcatcher\plugin;

use xakki\phperrorcatcher\PHPErrorCatcher;

class JsLogPlugin extends BasePlugin {

    /**
     * If you want enable log-request, set this name
     * @var null
     */
    protected $catcherLogName = 'myCatcherLog';
    protected $catcherLogFileSeparate = true;

    function __construct(PHPErrorCatcher $owner, $config = []) {
        parent::__construct($owner, $config);

        if ($this->initGetKey && isset($_GET[$this->initGetKey])) {
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
    public function initLogRequest(PHPErrorCatcher $owner) {
        if (!count($_POST)) {
            $_POST = json_decode(file_get_contents('php://input'), true);
        }
        if (!isset($_POST['m']) || !isset($_POST['u']) || !isset($_POST['r'])) exit();
        $errstr = str_replace('||', PHP_EOL, $_POST['m']);
        $size = mb_strlen(serialize((array)$errstr), '8bit');
        if ($size > 1000) $errstr = mb_substr($errstr, 0, 1000) . '...(' . $size . 'b)...';
        $vars = [
            PHPErrorCatcher::FIELD_NO_TRICE => true,
            PHPErrorCatcher::FIELD_FILE => '',
            'ver' => $_POST['v'],
            'url' => $_POST['u'],
            'referrer' => $_POST['r'],
        ];
        if (!empty($_POST['s'])) $vars['errStack'] = str_replace('||', PHP_EOL, $_POST['s']);
        if (!empty($_POST['l'])) $vars['line'] = $_POST['l'];

        $GLOBALS['skipRenderBackTrace'] = 1;
        $owner->log($errstr, ['js', $this->catcherLogName], $vars, PHPErrorCatcher::LEVEL_WARNING);

    }

}