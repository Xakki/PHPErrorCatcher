<?php
declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\plugin;

use Xakki\PhpErrorCatcher\PhpErrorCatcher;

class JsLogPlugin extends BasePlugin
{

    protected string $catcherLogName;
    protected string $level = PhpErrorCatcher::LEVEL_NOTICE;

    /**
     * @param PhpErrorCatcher $owner
     * @param mixed[]         $config
     */
    function __construct(PhpErrorCatcher $owner, array $config = [])
    {
        parent::__construct($owner, $config);

        if ($this->initGetKey && isset($_GET[$this->initGetKey])) {
            $this->initLogRequest($owner);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'ok']);
            exit();
        }
    }

    /**
     * Use catche.js for log error in javascript
     */
    public function initLogRequest(PhpErrorCatcher $owner): void
    {
        if (!count($_POST)) {
            $_POST = json_decode((string) file_get_contents('php://input'), true);
        }
        if (!isset($_POST['m']) || !isset($_POST['u']) || !isset($_POST['r'])) exit();
        $mess = str_replace('||', PHP_EOL, $_POST['m']);
        $size = mb_strlen(serialize((array)$mess), '8bit');
        if ($size > 1000)
            $mess = mb_substr($mess, 0, 1000) . '...(' . $size . 'b)...';
        $vars = [
            PhpErrorCatcher::FIELD_NO_TRICE => true,
            PhpErrorCatcher::FIELD_FILE => '',
            'ver' => $_POST['v'],
            'url' => $_POST['u'],
            'referrer' => $_POST['r'],
            'userAgent' => isset($_POST['ua']) ? $_POST['ua'] : '',
            'js',
            $this->catcherLogName,
        ];
        if (!empty($_POST['s'])) $vars[PHPErrorCatcher::FIELD_TRICE] = str_replace('||', PHP_EOL, $_POST['s']);
        if (!empty($_POST['l'])) $vars[PHPErrorCatcher::FIELD_FILE] = $_POST['l'];

        $owner->log($this->level, $mess, $vars);

    }

}
