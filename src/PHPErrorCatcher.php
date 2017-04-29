<?php

namespace xakki\phperrorcatcher;

if (
    !defined('ERROR_VIEW_GET')      // url view logs http://exaple.com/?showMeLogs=1
    || !defined('ERROR_VIEW_PATH')  // absolute path for view dir
    || !defined('ERROR_BACKUP_DIR') // Backup relative dir / Only inside ERROR_VIEW_PATH
    || !defined('ERROR_LOG_DIR') // Backup relative dir / Only inside ERROR_LOG_DIR
) {
    exit('Not defined constants for PHPErrorCatcher!!!');
}

// Дописываем параметры для консольного запуска скрипта
if (!isset($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = 'console';
if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '*';
if (!isset($_SERVER['REMOTE_ADDR'])) $_SERVER['REMOTE_ADDR'] = 'localhost';

ini_set('display_errors', 1);
error_reporting(E_ALL);

defined('E_EXCEPTION_ERROR') || define('E_EXCEPTION_ERROR', 2); // custom error

/**
 * User: xakki
 * Date: 18.08.16
 * Time: 12:00
 */
class PHPErrorCatcher
{

    /**
     * If true, all data log into file
     * @var bool
     */
    public static $hasError = false;

    /**
     * Debug notice , if has error
     * Attention - slow function
     * @var bool
     */
    public static $showNotice = false;

    /**
     * Сохранять сессию и куки в логи
     * @var bool
     */
    public static $enableSessionLog = true;

    /**
     * Параметры(POST,SESSION,COOKIE) затираемы при записи в логи
     * @var array
     */
    public static $safeParamsKey = array(
        'password', 'input_password', 'pass'
    );

    /*******************************/

    /**
     * @var null|callback|\PDO
     */
    public static $pdo = null;
    public static $pdoTableName = '_myprof';

    /**
     * Включает отправку писем (если это PHPMailer)
     * @var null|callback|\PHPMailer\PHPMailer\PHPMailer
     */
    public static $mailer = null;
    /**
     * Шаблон темы
     * @var string
     */
    public static $mailerSubjectPrefix = '{SITE} - DEV ERROR {DATE}';



    public static $xhprofEnable = false;
    public static $minTimeProfiled = 4000999999;

    /**
     * location source profiler xhprof
     * @var null|string
     */
    public static $xhprofDir = null;

    /**
     * profiler namespace (xhprof)
     * @var string
     */
    public static $profiler_namespace = 'slow';

    private $profilerId = null;
    private $profilerUrl = null;
    private $profilerStatus = false;
    /*******************************/

    const LIMIT_COUNT = 100;

    /**
     * singleton object
     * @var static
     */
    private static $obj;

    /**
     * Профилирование вручную
     * microsec
     * @var null
     */
    private static $functionTimeOut = null;

    private $postData=null, $cookieData=null, $sessionData=null;

    /**
     * Counter
     * @var int
     */
    private $count = 0;
    private $errCount = 0;
    private $time_cr = null;
    private $time_run = null;

    /**
     * Print all logs for output
     * @var string
     */
    private $allLogs = '';
    private $overMemory = false;

    /**
     * Custom function Time
     * @var
     */
    private static $functionStartTime;

    /**
     * Flag for view mode
     * @var bool
     */
    private static $isViewMode = false;
    private static $popover = array();
    /**
     * Error list
     * @var array
     */
    private static $errorListView = array(
        0 => array(
            'type' => '[@]',
            'color' => 'black',
            'debug' => 0
        ),
        E_ERROR => array( //1
            'type' => '[Fatal Error]',
            'color' => 'red',
            'debug' => 1
        ),
        E_PARSE => array( //4
            'type' => '[Parse Error]',
            'color' => 'red',
            'debug' => 1
        ),
        E_CORE_ERROR => array( //16
            'type' => '[Fatal Core Error]',
            'color' => 'red',
            'debug' => 1
        ),
        E_COMPILE_ERROR => array( //64
            'type' => '[Compilation Error]',
            'color' => 'red',
            'debug' => 1
        ),
        E_USER_ERROR => array( //256
            'type' => '[Triggered Error]',
            'color' => 'red',
            'debug' => 1
        ),
        E_RECOVERABLE_ERROR => array( //4096
            'type' => '[Catchable Fatal Error]',
            'color' => 'red',
            'debug' => 1
        ),

        E_WARNING => array( //2
            'type' => '[Warning]',
            'color' => '#F18890',
            'debug' => 1
        ),
        E_CORE_WARNING => array( //32
            'type' => '[Core Warning]',
            'color' => '#F18890',
            'debug' => 1
        ),
        E_COMPILE_WARNING => array( //128
            'type' => '[Compilation Warning]',
            'color' => '#F18890',
            'debug' => 1
        ),
        E_USER_WARNING => array( //512
            'type' => '[Triggered Warning]',
            'color' => '#F18890',
            'debug' => 1
        ),

        E_STRICT => array( //2048
            'type' => '[Deprecation Notice]',
            'color' => 'brown',
            'debug' => 0
        ),

        E_NOTICE => array( //8
            'type' => '[Notice]',
            'color' => '#858585',
            'debug' => 0
        ),
        E_USER_NOTICE => array( //1024
            'type' => '[Triggered Notice]',
            'color' => '#858585',
            'debug' => 0
        ),

        E_DEPRECATED => array( // 8192
            'type' => '[DEPRECATED Error]',
            'color' => 'brown',
            'debug' => 1
        ),
        E_USER_DEPRECATED => array( // 16384
            'type' => '[DEPRECATED User Error]',
            'color' => 'brown',
            'debug' => 1
        ),

        E_EXCEPTION_ERROR => array( // 2
            'type' => '[Exception]',
            'color' => 'red',
            'debug' => 1
        ),
    );


    public function __construct()
    {
        register_shutdown_function(array($this, 'shutdown'));
        set_error_handler(array($this, 'handleError') , E_ALL);
        set_exception_handler(array($this, 'handleException'));

    }


    /**
     * @return PHPErrorCatcher
     * @throws Exception
     */
    public static function init()
    {
        if (!static::$obj) {
            static::$obj = new self();
            static::$obj->time_cr = microtime(true);
            static::$obj->initProfiler();
        }
        return static::$obj ;
    }

    public static function initLogView()
    {
        if (isset($_GET[ERROR_VIEW_GET])) {
            static::$isViewMode = true;
            PHPErrorCatcher::logView();
            exit();
        }
    }

    /**
     * Процедура завершения скрипта
     */
    public function shutdown()
    {
        $this->time_run = (microtime(true) - $this->time_cr) * 1000;

        $error = error_get_last();
        if ($error) {
            $this->handleError($error['type'], $error['message'], $error['file'], $error['line']);
        }

        if (isset($_GET['whereExit'])) {
            $this->allLogs .= static::renderBackTrace(0);
            static::$hasError = true;
        }

        try {
            $this->endProfiler();
            if ($this->time_run <= static::$minTimeProfiled) {
                // выполнение скрипта в пределах нормы
            } else if (!static::getPdo()) {
                // если нет связи с БД
            } else {
                $this->saveStatsProfiler();
            }
        } catch (\Exception $e) {
            $this->handleException($e);
        }

        if (($this->allLogs || $this->overMemory) && static::$hasError) {
            $path = ERROR_VIEW_PATH . ERROR_LOG_DIR . '/' . date('Y.m.d');
            if (!file_exists($path)) {
                mkdir($path, 0775, true);
            }

            $fileName = $path . '/' . date('H') . '.html';

            if (!file_exists($fileName)) $flagNew = true;
            else $flagNew = false;

            $fileLog = $this->renderLogs();

            ob_start();
            $mailStatus = $this->sendErrorMail($fileLog);
            $errorMailLog = ob_get_contents();
            ob_end_clean();
            if ($mailStatus) {
                $fileLog .= '<div class="bg-success">Выслано письмо на почту</div>';
            }
            elseif ($errorMailLog) {
                $fileLog .= '<div><pre class="bg-danger">Ошибка отправки письма'.PHP_EOL.static::_e($errorMailLog).'</pre></div>';
            }

            file_put_contents($fileName, $fileLog, FILE_APPEND);
            if ($flagNew) chmod($fileName, 0777);


        }
        $this->renderToolbar();
    }


    /**
     * Обработчик ошибок
     * @param $errno
     * @param $errstr
     * @param $errfile
     * @param $errline
     * @param null $vars
     * @param null $trace
     * @param int $slice
     * @return bool
     */
    public function handleError($errno, $errstr, $errfile, $errline, $vars = null, $trace = null, $slice = 2)
    {

        if (!static::$showNotice && ($errno == E_NOTICE || $errno == E_USER_NOTICE)) {
            return true;
        }

        if ($this->count > static::LIMIT_COUNT) {
            return true;
        }

        $this->count++;

        $debug = static::renderErrorItem($errno, $errstr, $errfile, $errline, $vars, $trace, $slice);

        if (static::$errorListView[$errno]['debug']) { // для нотисов подробности ни к чему
            static::$hasError = true;
            $this->errCount++;
        }
        if (static::$isViewMode || isset($_GET[ERROR_VIEW_GET])) {
            echo $debug;
        }

        $limitMemory = ini_get('memory_limit');
        if ($limitMemory > 0) {
            $limitMemory = (int)$limitMemory * (strpos($limitMemory, 'M') ? 1024 * 1024 : 1024) * 0.8;
            if (memory_get_usage() < $limitMemory) {
                $this->allLogs .= $debug;
            } else {
                $this->overMemory = true;
            }
        } else {
            $this->allLogs .= $debug;
        }

        return true;
    }

    /**
     * Обработчик исключений
     * @param $e \Exception
     * @param string $mess
     */
    public function handleException($e, $mess = '')
    {
        $this->handleError(E_EXCEPTION_ERROR, $e->getMessage() . ($mess ? '<br/>' . $mess : ''), $e->getFile(), $e->getLine(), $e->getCode(), $e->getTrace());
    }

    /**
     * Получение рендера исключения
     * для малых нужд
     * @param $e Exception
     * @return string
     */
    public static function getExceptionRender($e)
    {
        return static::renderErrorItem(E_EXCEPTION_ERROR, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getCode(), $e->getTrace());
    }

    /**
     * Получаем PDO соединение с БД
     * @return \PDO|null
     */
    private static function getPdo()
    {
        if (static::$pdo && is_callable(static::$pdo)) {
            static::$pdo = call_user_func_array(static::$pdo, array());
        }
        return (static::$pdo instanceof \PDO ? static::$pdo : false);
    }

    private function setSafeParams() {
        if ($this->postData !== null) return;
        $this->postData = ($_POST ? $_POST : $_SERVER['argv']);
        $this->cookieData = $_COOKIE;
        $this->sessionData = (static::$enableSessionLog ? $_SESSION : null);
        foreach(static::$safeParamsKey as $key) {
            if (isset($this->postData[$key])) {
                $this->postData[$key] = '***';
            }
            if (isset($this->cookieData[$key])) {
                $this->cookieData[$key] = '***';
            }
            if (isset($this->sessionData[$key])) {
                $this->sessionData[$key] = '***';
            }
        }
    }

    /*****************************************************/

    /**
     * Получаем PHPMailer
     * @return \PHPMailer\PHPMailer\PHPMailer|null
     */
    private static function getMailer()
    {
        if (static::$mailer && is_callable(static::$mailer)) {
            static::$mailer = call_user_func_array(static::$mailer, array());
        }
        return (is_object(static::$mailer) ? static::$mailer : null);
    }

    /**
     * Отправка письма об ошибках
     * @param $message
     */
    private function sendErrorMail($message)
    {
        $cmsmailer = static::getMailer();
        if (!$cmsmailer) return false;

        $cmsmailer->Body = $message;
        $cmsmailer->Subject = str_replace(array('{SITE}', '{DATE}'), array($_SERVER['HTTP_HOST'], date(' Y-m-d H:i:s')), static::$mailerSubjectPrefix);
        return $cmsmailer->Send();
    }

    /*****************************************************/

    /**
     * Запуск профайлера
     * @return null|string
     */
    public function initProfiler()
    {
        if (!static::$xhprofDir || $this->profilerStatus) return;

        $lib1 = static::$xhprofDir . '/xhprof_lib/utils/xhprof_lib.php';
        $lib2 = static::$xhprofDir . '/xhprof_lib/utils/xhprof_runs.php';
        $tmpDir = ERROR_VIEW_PATH . '/xhprof';
        if (extension_loaded('xhprof') && file_exists($lib1) && file_exists($lib2)) {
            if (!file_exists($tmpDir)) {
                if (!mkdir($tmpDir, 0775, true)) {
                    self::setPopover('Cant create dir ' . $tmpDir);
                    return;
                }
            }
            ini_set("xhprof.output_dir", $tmpDir);
            include_once $lib1;
            include_once $lib2;
            xhprof_enable(XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
            $this->profilerStatus = true;
        }
        else {
            self::setPopover('Cant INIT profiler :'.(!extension_loaded('xhprof')?'Not load xhprof php modul':'').(!file_exists($lib1) || !file_exists($lib2)?'Cant load xhprof libs':''));
        }
    }
    /**
     * Завершение и сохранение профайлера
     * @return null|string
     */
    public function endProfiler()
    {
        if ($this->profilerStatus) {
            $xhprof_data = xhprof_disable();
            if ($this->time_run > static::$minTimeProfiled || static::$xhprofEnable) {
                $xhprof_runs = new \XHProfRuns_Default();
                $this->profilerId = $xhprof_runs->save_run($xhprof_data, static::$profiler_namespace);
                $this->profilerUrl = null;
                if ($this->profilerId) {
                    $this->profilerUrl = static::getXhprofUrl($this->profilerId);
                }
                return $this->profilerId;
            }
        }
        return null;
    }

    public static function getXhprofUrl($id = '') {
        return '?' . ERROR_VIEW_GET . '=PROF&source=' . static::$profiler_namespace . '&run='.$id;
    }

    /**
     * Сохранить статистику профалера в БД и ссылку на него
     * @param null $script
     * @param null $info
     * @param bool|false $simple
     * @return bool
     */
    public function saveStatsProfiler($script = null, $info = null, $simple = false) {
        $this->setSafeParams();
        if (is_null($script)) {
            $script = $_SERVER['SCRIPT_NAME'];
        }
        $data = array(
            //            'name' => ($_SERVER['REDIRECT_URL'] ? $_SERVER['REDIRECT_URL'] : ($_SERVER['DOCUMENT_URI'] ? $_SERVER['DOCUMENT_URI'] : $_SERVER['SCRIPT_NAME'])),
            'name' => $_SERVER['REQUEST_URI'],
            'time_cr' => $this->time_cr,
            'time_run' => $this->time_run,
            'info' => $info,
            'json_post' => (count($this->postData) ? json_encode($this->postData) : null),
            'json_cookies' => ((count($this->cookieData) && !$simple) ? json_encode($this->cookieData) : null),
            'json_session' => ((count($this->sessionData) && !$simple) ? json_encode($this->sessionData) : null),
            'is_secure' => ((isset($_SERVER['HTTPS']) and $_SERVER['HTTPS'] !== 'off') ? true : false),
            'ref' => (!$simple ? $_SERVER['HTTP_REFERER'] : null),
            'script' => $script,
            'host' => $_SERVER['HTTP_HOST'],
        );
        if ($this->profilerId) {
            $data['profiler_id'] = $this->profilerId;
        }

        $stmt = static::getPdo()->prepare('INSERT INTO ' . static::$pdoTableName . ' (' . implode(',', array_keys($data)) . ') VALUES(' . str_repeat('?,', (count($data) - 1)) . '?)');
        $res = $stmt->execute(array_values($data));
        $err = $stmt->errorInfo();
        if ($err[1]) {
            $this->allLogs .= '<p class="alert alert-danger">INSERT BD: '.$err[1].', '.$err[2].'</p>';
            static::$hasError = true;
        }
        return $res;
    }

    /*****************************************************/
    /*****************************************************/
    /*****************************************************/


    /**
     * Финальный рендер лога
     * @return string
     */
    private function renderLogs() {
        $this->setSafeParams();
        $profilerUrlTag = '';
        if (static::$enableSessionLog) {
            $profilerUrlTag .= '<div class="bug_cookie"><b>COOKIE:</b>' . htmlspecialchars(json_encode($this->cookieData)) . '</div>' .
                '<div class="bug_session"><b>SESSION:</b> ' . htmlspecialchars(json_encode($this->sessionData)) . '</div>';
        }
        if ($this->profilerUrl) {
            $profilerUrlTag = '<div class="bugs_prof">XHPROF: <a href="' . $this->profilerUrl . '">' . $this->profilerId . '</a></div>';
        }

        return '<div class="bugs">' .
            '<span class="bugs_host">' . $_SERVER['HTTP_HOST'] . '</span> ' .
            '<span class="bugs_uri">' . $_SERVER['REQUEST_URI'] . '</span> ' .
            '<span class="bugs_ip">' . $_SERVER['REMOTE_ADDR'] . '</span> ' .
            ($_SERVER['HTTP_REFERER'] ? '<span class="bugs_ref">' . $_SERVER['HTTP_REFERER'] . '</span> ' : '') .
            (count($this->postData) ? PHP_EOL . '<span class="bugs_post">' . json_encode($this->postData) . '</span>' : '') .
            $profilerUrlTag .
            $this->allLogs .
            ($this->overMemory ? '<hr>Over memory limit' : '').
            '</div>';
    }

    /**
     * Рендер одной ошибки
     * рендерим сразу, вконце сохраним
     * @param $errno
     * @param $errstr
     * @param $errfile
     * @param $errline
     * @param null $vars
     * @param null $tace
     * @return string
     */
    public static function renderErrorItem($errno, $errstr, $errfile, $errline, $vars = null, $tace = null, $slice = 1)
    {
        $micro_date = microtime();
        $micro_date = explode(" ", $micro_date);
        $debug = '<div class="bug_item bug_' . $errno . '">' .
            '<span class="bug_time">' . date('Y-m-d H:i:s P', $micro_date[1]) . '</span> ' .
            '<span class="bug_mctime">' . $micro_date[0] . '</span> ' .
            '<span class="bug_type">' . static::$errorListView[$errno]['type'] . '</span> ' .
            '<span class="bug_vars">' . (is_int($vars) ? $vars : '') . '</span> ' .
            '<span class="bug_str">' . htmlspecialchars($errstr, ENT_NOQUOTES , 'UTF-8') . '</span> ' .
            '<div class="bug_file"> File <a href="file:/' . $errfile . ':' . $errline . '">' . $errfile . ':' . $errline . '</a></div>';

        if (static::$errorListView[$errno]['debug'] && empty($GLOBALS['skipRenderBackTrace'])) { // для нотисов подробности ни к чему
            $debug .= static::renderBackTrace($slice, $tace);
        } else {
            unset($GLOBALS['skipRenderBackTrace']);
            // if (isset($arr['line']) and $arr['file'])
        }

        $debug .= '</div>';
        return $debug;
    }


    /**
     * Функция трасировки ошибок
     * @param int $slice
     * @param array $traceArr
     * @return string
     */
    public static function renderBackTrace($slice = 1, $traceArr = null)
    {
        $MAXSTRLEN = 1024;
        $s = '<div class="xdebug">';
        if (!$traceArr) {
            $traceArr = debug_backtrace();
            if ($slice > 0) {
                $traceArr = array_slice($traceArr, $slice);
            }
        }
        $i = 0;
        foreach ($traceArr as $arr) {
            $s .= '<div class="xdebug-item" style="margin-left:' . (10 * $i) . 'px;">' .
                '<span class="xdebug-item-file">';
            if (isset($arr['line']) and $arr['file'])
                $s .= ' in <a href="file:/' . $arr['file'] . '">' . $arr['file'] . ':' . $arr['line'] . '</a> , ';
            if (isset($arr['class']))
                $s .= '#class <b>' . $arr['class'] . '-></b>';
            $s .= '</span>';
            //$s .= '<br/>';
            $args = array();
            if (isset($arr['args'])) {
                foreach ($arr['args'] as $v) {
                    if (is_null($v)) $args[] = '<b class="xdebug-item-arg-null">NULL</b>';
                    else if (is_array($v)) $args[] = '<b class="xdebug-item-arg-arr">Array[' . sizeof($v) . ']</b>';
                    else if (is_object($v)) $args[] = '<b class="xdebug-item-arg-obj">Object:' . get_class($v) . '</b>';
                    else if (is_bool($v)) $args[] = '<b class="xdebug-item-arg-bool">' . ($v ? 'true' : 'false') . '</b>';
                    else {
                        $v = (string)@$v;
                        $str = static::_e(substr($v, 0, $MAXSTRLEN));
                        if (strlen($v) > $MAXSTRLEN) $str .= '...';
                        $args[] = '<b class="xdebug-item-arg">' . $str . '</b>';
                    }
                }
            }
            if (isset($arr['function'])) {
                $s .= '<span class="xdebug-item-func"><b>' . $arr['function'] . '</b>(' . implode(',', $args) . ')</span>';
            }
            $s .= '</div>';
            $i++;
        }
        $s .= '</div>';
        return $s;
    }


    /******************************************************************************************/
    /******************************************************************************************/
    /******************************************************************************************/

    public static function setPopover($mess) {
        static::$popover[] = $mess;
    }

    public static function renderToolbar() {
        if (!static::$isViewMode && defined('ERROR_DEBUG_MODE') && ERROR_DEBUG_MODE) {
            echo '<div class="x-debug-toolbar"><style>
.x-debug-toolbar {position: fixed; z-index:9999; top: 5px; left: 5px; max-width: 300px;}
</style>';
            echo '<p class="alert alert-info"><a href="?'.ERROR_VIEW_GET.'=/">View Logs</a></p>';
            if (static::init()->profilerStatus && static::init()->profilerId) {
                echo '<p class="alert alert-info"><a href="'.static::init()->getProfilerUrl().'">Profiler ' . static::init()->profilerId . '</a></p>';
            }
            if (static::$hasError) {
                echo '<p class="alert alert-danger"><a href="?'.ERROR_VIEW_GET.'=/">Error ' . static::getErrCount() . '</a></p>';
            }
            foreach (static::$popover as $r) {
                echo '<p class="alert alert-warning">'.$r.'</p>';
            }
            echo '</div>';
        }
    }
    /**
     * Просмотр логов
     */
    public static function logView()
    {
        $url = str_replace(array('\\', '\/\/', '\.\/', '\.\.'), '', $_GET[ERROR_VIEW_GET]);

        $file = trim($url, '/');
        $tabs = array(
            'BD' => '',
            'PROF' => '',
            'PHPINFO' => '',
        );

        if (!isset($_GET['only'])) {
            echo static::viewRenderHead($file) . '<body>';

            echo '<ul class="nav nav-tabs">'.
                '<li' . (!isset($tabs[$file]) ? ' class="active"' : '') . '><a href="?' . ERROR_VIEW_GET . '=/">Логи</a></li>'.
                (static::getPdo() ? '<li' . ($file == 'BD' ? ' class="active"' : '') . '><a href="?' . ERROR_VIEW_GET . '=BD">BD</a></li>' : '').
                (static::init()->profilerStatus ? '<li' . ($file == 'PROF' ? ' class="active"' : '') . '><a href="?' . ERROR_VIEW_GET . '=PROF&source=' . static::$profiler_namespace .'&run=">Профаилер</a></li>' : '').
                '<li' . ($file == 'PHPINFO' ? ' class="active"' : '') . '><a href="?' . ERROR_VIEW_GET . '=PHPINFO">PHPINFO</a></li>'.
                '</ul>';
        }
        if (!empty($_GET['download'])) {
            header('Content-Type: application/octet-stream');
        }


        if ($file == 'BD') {
            echo static::viewRenderBD();
        }
        elseif ($file == 'PROF') {
            echo static::viewRenderPROF();
        }
        elseif ($file == 'PHPINFO') {
            ob_start();
            phpinfo();
            $html = ob_get_contents();
            // flush the output buffer
            ob_end_clean();
            echo $html;
        }
        else {
            $file = ERROR_VIEW_PATH . '/' . $file;

            if (file_exists($file)) {

                if (is_dir($file)) {
                    if (isset($_GET['backup'])) {
                        static::viewCreateBackUpDir($file);
                        exit();
                    }
                    echo static::viewRenderBreadCrumb($url);
                    echo static::viewRenderDirList(static::viewGetDirList($url));
                } else {
                    if (isset($_GET['backup'])) {
                        static::viewCreateBackUp($file);
                        exit();
                    }

                    if (!isset($_GET['only'])) {
                        echo static::viewRenderBreadCrumb($url);

                        if (!self::checkIsBackUp($file)) {
                            echo ' [<a href="' . $_SERVER['REQUEST_URI'] . '&only=1&download=1" class="linkSource">Download</a> <a href="' . $_SERVER['REQUEST_URI'] . '&only=1" class="linkSource">Source</a> <a href="' . $_SERVER['REQUEST_URI'] . '&backup=do">Бекап</a> <a href="' . $_SERVER['REQUEST_URI'] . '&backup=del">Удалить</a>]';
                        }
                        echo '</h3>';
                    }
                    //chmod($file, 0777);
                    echo static::getFileContent($file);
                }
            } else {
                echo '<h3>Logs</h3>';
                echo static::viewRenderDirList(static::viewGetDirList());
            }
        }

        if (!isset($_GET['only'])) {
            echo '</body>';
        }
    }

    /**
     * рендер заголовка HTML
     * @return string
     */
    public static function viewRenderHead($file)
    {
        $html = '<html>
            <head>
                <title>LogView' . ($file ? ':' . $file : '') . '</title>
                <meta http-equiv="Cache-Control" content="no-cache">
                <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
                <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.4/css/bootstrap.min.css">
                <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.4/css/bootstrap-theme.min.css">
                <script src="https://yastatic.net/jquery/2.1.4/jquery.min.js"></script>
                <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.4/js/bootstrap.min.js"></script>
                <script>
                    selectText = function(e) {
                        if(window.getSelection){
                        var s=window.getSelection();
                        if(s.setBaseAndExtent){
                        s.setBaseAndExtent(e,0,e,e.innerText.length-1);
                        }else{
                        var r=document.createRange();
                        r.selectNodeContents(e);
                        s.removeAllRanges();
                        s.addRange(r);}
                        }else if(document.getSelection){
                        var s=document.getSelection();
                        var r=document.createRange();
                        r.selectNodeContents(e);
                        s.removeAllRanges();
                        s.addRange(r);
                        }else if(document.selection){
                        var r=document.body.createTextRange();
                        r.moveToElementText(e);
                        r.select();}
                    }

                    $(document).ready(function() {
                        $(\'.linkDel\').on(\'click\', function() {
                            if (confirm(\'Удалить?\'))
                                return true;
                             return false;
                        });
                        $(\'.xdebug-item-file a, .bug_file a\').on(\'click\', function() {
                            selectText(this);
                            return false;
                        });
                    });
                </script>
                <style>
                .bugs_host {padding:0 10px 0 0;}
                .bugs_uri {padding:0 10px 0 0;}
                .bugs_ip {padding:0 10px 0 0;}
                .bugs_ref {padding:0 10px 0 0;}
                .bugs_prof {}
                .bugs_prof.alert {color:red;}
                .bugs { border-top:3px gray solid;}
                .bug_item .xdebug {
                    background-color: #e1e1e1; margin: 5px 0;
                }
                .bug_item .xdebug-item {
                    border-top: solid 1px #a1a1a1;
                }
                ';
        foreach (static::$errorListView as $errno => $error) {
            $html .= '.bug_' . $errno . ' .bug_type {color:' . $error['color'] . ';}';
        }
        $html .= '</style></head>';
        return $html;
    }

    /**
     * Просмотр Директории логов
     * @param string $path
     * @return array
     */
    public static function viewGetDirList($path = '')
    {
        $dirList1 = $dirList2 = array();
        $path = trim($path, '/.');
        $fullPath = ERROR_VIEW_PATH . ($path ? '/' . $path : '');

        if (!file_exists($fullPath)) {
            if (!mkdir($fullPath, 0775, true)) {
                exit(' Cant create dir ' . $fullPath);
            }
        }
        $isBackUpDir = self::checkIsBackUp($fullPath);
        $dir = dir($fullPath);
        $url = $_SERVER['REQUEST_URI'];
        $url = parse_url($url);
        $pathUrl = rtrim($url['path'], '/');
        while (false !== ($entry = $dir->read())) {
            if ($entry != '.' && $entry != '..') {
                $fileUrl = $pathUrl . '/?' . ERROR_VIEW_GET . '=' . $path  .'/'. $entry;
                $filePath = $fullPath . '/' . $entry;


                if (is_dir($filePath)) {
                    $size = $create = '';
                    $createTime = 0;
                    if (!is_readable($filePath)) {
                        $dirList1[$entry] = array($path .'/'. $entry, '', '', '', '');
                        continue;
                    }
                }
                else {
                    if (!is_readable($filePath)) {
                        $dirList2[$entry] = array($path .'/'. $entry, '', '', '', '');
                        continue;
                    }
                    //                    trigger_error(ERROR_VIEW_PATH . ' * ' . $path . ' * ' . $entry. '=> '.$filePath, E_USER_DEPRECATED);
                    $size = filesize($filePath);
                    $createTime = filemtime($filePath);
                    $create = date("Y-m-d H:i:s", $createTime);
                    $size = number_format($size, 0, '', ' ') . ' б.';
                }

                $tmp = array(
                    '<a href="' . $fileUrl . '" style="' . (is_dir($filePath) ? 'font-weight:bold;' : '') . '">' . $path .'/'. $entry . '</a> ',
                    $size,
                    $create,
                    ($size ? ' <a href="' . $fileUrl . '&only=1" class="linkSource">Source</a>' : '') .
                    ( (!$isBackUpDir && ($path || !static::checkIsBackUp($filePath))) ? ' <a href="' . $fileUrl . '&backup=do">Бекап</a> <a href="' . $fileUrl . '&backup=del" class="linkDel">Удалить</a>' : ''),
                    $createTime
                );
                // glyphicon glyphicon-hdd
                // glyphicon glyphicon-trash
                if ($size === '') {
                    $dirList1[$entry] = $tmp;
                } else {
                    $dirList2[$entry] = $tmp;
                }
            }
        }
        krsort($dirList1);
        krsort($dirList2);
        $dirList = $dirList1 + $dirList2;
        return $dirList;
    }

    private static function checkIsBackUp($file) {
        return (strpos($file, ERROR_VIEW_PATH . ERROR_BACKUP_DIR) !== false);
    }

    /**
     * Рендер директории логов
     * @param $dirList
     * @return string
     */
    public static function viewRenderDirList($dirList)
    {
        $html = '<table class="table table-striped" style="width: auto;">';
        $html .= '<thead>
            <tr>
              <th>name</th>
              <th>size</th>
              <th>Modify time</th>
              <th></th>
            </tr>
          </thead>
          <tbody>';
        foreach ($dirList as $row) {
            $html .= '<tr><td>' . $row[0] . '<td>' . $row[1] . '<td>' . $row[2] . '<td>' . $row[3] . '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    public static function getFileContent($file)
    {
        // if ()
        // mime_content_type
        $pathinfo = pathinfo($file);
        $is_img = @getimagesize($file);
        if ($is_img) {
            return '<img src="' . str_replace($_SERVER['DOCUMENT_ROOT'], '', $file) . '" alt="' . $pathinfo['basename'] . '"/>';
        } elseif ($pathinfo['extension'] == 'html' || isset($_GET['only'])) {
            return file_get_contents($file);
        } else {
            return '<pre>' . file_get_contents($file) . '</pre>';
        }
    }

    /**
     * Делаем бекап фаила и ссылку на него
     * @param $file
     */
    private static function viewCreateBackUp($file)
    {

        if (is_dir($file)) {
            echo 'Is Dir';
        }
        elseif (static::checkIsBackUp($file)) {
            echo 'Is BackUp Dir: is protect  dir';
        }
        if (defined('ERROR_NO_BACKUP')) {
            unlink($file);
            header('Location: ' . $_SERVER['HTTP_REFERER']);
        } else {

            $backUpFile = str_replace(ERROR_VIEW_PATH, ERROR_VIEW_PATH . ERROR_BACKUP_DIR, $file);
            $backUpFileDir = dirname($backUpFile);
            if (!file_exists($backUpFileDir)) {
                mkdir($backUpFileDir, 0775, true);
            }

            if (file_exists($backUpFile)) {
                $i = pathinfo($backUpFile);
                $backUpFile = $i['dirname'] . '/' . $i['filename'] . '.' . time() . '.' . $i['extension'];
            }
            if (file_exists($backUpFile)) {
                exit('Error!');
            }

            if (copy($file, $backUpFile)) {
                $loc = str_replace(array('&backup=do', '&backup=del'), '', $_SERVER['REQUEST_URI']);
                $backUpFileUrl = str_replace($_GET[ERROR_VIEW_GET], str_replace(ERROR_VIEW_PATH, '', $backUpFile), $loc);

                // add info
                file_put_contents($backUpFile, '<a href="' . $loc . '">This backup file in ' . date('Y-m-d H:i:s') . ' from origin</a><hr/>' . PHP_EOL . file_get_contents($backUpFile));
                if ($_GET['backup'] == 'del' and strpos($file, ERROR_VIEW_PATH . ERROR_BACKUP_DIR) === false) {
                    unlink($file);
                    $i = pathinfo($file);
                    header('Location: ' . str_replace('/'.$i['filename'].'.'.$i['extension'], '', $_SERVER['HTTP_REFERER']));
                } else {
                    // add info
                    file_put_contents($file, '... <a href="' . $backUpFileUrl . '">This file was backup ' . date('Y-m-d H:i:s') . '</a><hr/>' . PHP_EOL);
                    header('Location: ' . $_SERVER['HTTP_REFERER']);
                }

            } else {
                echo "не удалось скопировать $file...\n";
            }
        }
    }

    /**
     * Бекапи логи
     * @param $dir
     */
    private static function viewCreateBackUpDir($dir)
    {
        if (!is_dir($dir)) {
            echo 'Is not Dir';
        }
        if (defined('ERROR_NO_BACKUP')) {
            static::delTree($dir);
        } else {
            $backUpFileDir = str_replace(ERROR_VIEW_PATH, ERROR_VIEW_PATH . ERROR_BACKUP_DIR, $dir);
            if (file_exists($backUpFileDir)) {
                $backUpFileDir = rtrim($backUpFileDir, '/') . '_' . time();
            }
            else {
                $parentDir = dirname($backUpFileDir);
                if (!file_exists($parentDir)) {
                    mkdir($parentDir,0774, true);
                }
            }
            rename($dir, $backUpFileDir);
        }
        if (!headers_sent()) {
            header('Location: ' . $_SERVER['HTTP_REFERER']);
        }
    }

    /**
     * Хлебные крошки
     * @param $url
     * @return string
     */
    private static function viewRenderBreadCrumb($url)
    {
        $temp = preg_split('/\//', $url, -1, PREG_SPLIT_NO_EMPTY);
        $ctr = '';
        $url = $_SERVER['REQUEST_URI'];
        $url = parse_url($url);
        $basePath = $fullPath = rtrim($url['path'], '/') . '/?'.ERROR_VIEW_GET.'=/';
        foreach ($temp as $r) {
            $fullPath .= '/' . $r;
            $ctr .= '<li><a href="' . $fullPath . '">' . $r . '</a>';
        }
        return '<ul class="breadcrumb"><li><a href="' . $basePath . '">Home</a>' . $ctr . '</ul>';
    }


    /**
     * Печатаем то что выдает профаилер
     * @return mixed|string
     */
    public static function viewRenderPROF()
    {
        $allowInc = array(
            'callgraph' => 1,
            'typeahead' => 1,
        );

        // xhprof настолько древний, что до сих пор общается с глобальными переменными (
        foreach($_GET as $k=>$r) {
            global $$k;
            $$k = $r;
//                $GLOBALS[$k] = $r;
        }

        if (isset($_GET['viewSrc'])) {
            $file = static::$xhprofDir."/xhprof_html/".trim($_GET['viewSrc'], '/\\.');
            if (file_exists($file)) {
                $ext = substr($_GET['viewSrc'], -3);
                if ($ext=='css') header('Content-Type: text/css');
                elseif ($ext=='.js') header('Content-Type: application/javascript');
                exit(file_get_contents($file));
            }
            exit('File not found');
        }
        ini_set("xhprof.output_dir", ERROR_VIEW_PATH . '/xhprof');
        ob_start();
        include static::$xhprofDir.'/xhprof_html/'.((isset($_GET['viewInc']) && isset($allowInc[$_GET['viewInc']])) ? $_GET['viewInc'] : 'index').'.php';
        $html = ob_get_contents();
        if ($_GET['viewInc']=='callgraph') {
            exit($html);
        }
        else {
            $html = str_replace(array(
                'link href=\'/',
                'script src=\'/',
                'a href="' . htmlentities($_SERVER['SCRIPT_NAME']) . '?',
                'a href="/?',
                'a href="/callgraph.php?',
                'a href="/typeahead.php?'
            ), array(
                'link href=\'?' . ERROR_VIEW_GET . '=PROF&only=1&viewSrc=',
                'script src=\'?' . ERROR_VIEW_GET . '=PROF&only=1&viewSrc=',
                'a href="?' . ERROR_VIEW_GET . '=PROF&',
                'a href="?' . ERROR_VIEW_GET . '=PROF&',
                'a href="?' . ERROR_VIEW_GET . '=PROF&only=1&viewInc=callgraph&',
                'a href="?' . ERROR_VIEW_GET . '=PROF&&viewInc=typeahead&'
            ), $html);
        }
        ob_end_clean();
        return $html;
    }

    public static function checkBD() {
        $sql = 'CREATE TABLE `'.static::$pdoTableName.'` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `time_cr` int(11) NOT NULL,
  `time_run` int(9) NOT NULL,
  `info` text,
  `json_post` text,
  `json_cookies` text,
  `json_session` text,
  `is_secure` tinyint(1) NOT NULL DEFAULT \'0\',
  `ref` varchar(255) DEFAULT NULL,
  `profiler_id` varchar(255) DEFAULT NULL,
  `script` varchar(255) DEFAULT NULL,
  `host` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;';
        $stmt = static::getPdo()->prepare($sql);
        $stmt->execute();
    }

    public static function viewRenderBD($flag= false)
    {
        $fields = array(
            'id' => array('ID', 'filter' => 1, 'sort' => 1),
            'host' => array('Host', 'filter' => 1, 'sort' => 1),
            'name' => array('Name', 'filter' => 1, 'sort' => 1, 'url' => true),
            'script' => array('Script', 'filter' => 1, 'sort' => 1),
            'time_cr' => array('Create', 'filter' => 1, 'sort' => 1, 'type' => 'date'),
            'time_run' => array('Time(ms)', 'filter' => 1, 'sort' => 1),
            'profiler_id' => array('Prof', 'url' => static::getXhprofUrl()),
            'ref' => array('Ref', 'filter' => 1, 'url' => true),
            'info' => array('Info', 'filter' => 1, 'spoiler' => 1),
            'json_post' => array('Post', 'filter' => 1, 'spoiler' => 1),
            'json_cookies' => array('Cookies', 'filter' => 1, 'spoiler' => 1),
            'json_session' => array('Session', 'filter' => 1, 'spoiler' => 1),
            'is_secure' => array('HTTPS', 'filter' => 1, 'sort' => 1, 'spoiler' => 1, 'type' => 'bool'),
        );

        $itemsOnPage = 40;
        $stmt = static::getPdo()->prepare('SELECT count(*) as cnt FROM ' . static::$pdoTableName);
        $stmt->execute();
        $err = $stmt->errorInfo();
        if ($err[1]) {
            if ($err[1]==1146) {
                if (!$flag) {
                    static::checkBD();
                    return static::viewRenderBD(true);
                }
            }
            return '<p class="alert alert-danger">'.$err[1].': '.$err[2].'</p>';
        }
        $counts = $stmt->fetch(\PDO::FETCH_ASSOC);
        $counts = $counts['cnt'];
        // значение максимальной страницы
        $max_page = ceil($counts / $itemsOnPage);
        $paginator = range(1, $max_page ? $max_page : 1);

        $page = (($_GET['page'] && $_GET['page'] <= $max_page) ? $_GET['page'] : 1);

        $query = 'SELECT * FROM ' . static::$pdoTableName;

        $where = array();
        $param = array();

        if (!empty($_GET['fltr'])) {
            foreach ($_GET['fltr'] as $k => $r) {
                $pair = false;
                if (substr($k, -2) == '_2') {
                    $pair = true;
                    $k = substr($k, 0, -2);
                    exit($k);
                }
                if (isset($fields[$k]) && $fields[$k]['filter']) {
                    if (isset($fields[$k]['type']) && $fields[$k]['type'] == 'date') {
                        if ($pair) {
                            $where[] = $k . ' <= ?';
                        } else {
                            $where[] = $k . ' >= ?';
                        }
                        $param[] = strtotime($r);
                    } elseif (isset($fields[$k]['type']) && $fields[$k]['type'] == 'bool') {
                        if ($r !== '') {
                            $where[] = $k . ' = ?';
                            $param[] = $r;
                        }
                    } else {
                        $where[] = $k . ' LIKE ? ';
                        $param[] = $r;
                    }

                }
            }

        }
        if (!empty($_GET['sort'])) {
            $sort = $_GET['sort'];
            $ord = '';
            if (substr($sort, 0, 1) == '-') {
                $ord = ' DESC';
                $sort = substr($sort, 1);
            }

            if (isset($fields[$sort]) && $fields[$sort]['sort']) {
                $query .= ' ORDER BY ' . $sort . $ord;
            }
        } else {
            $query .= ' ORDER BY id DESC';
        }
        $query .= ' LIMIT ' . (($page - 1) * $itemsOnPage) . ',' . $itemsOnPage;

        $stmt = static::getPdo()->prepare($query);
        $stmt->execute();
        $dataList = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $html = '';

        $html .= '<div style="float:left;">Кол-во:' . $counts . '</div>';
        if (count($paginator) > 1):
            $html .= '<ul class="pagination pagination-sm" style="margin:0 0 0 10px;">';
            $getParam = $_GET;
            foreach ($paginator as $i):
                $getParam['page'] = $i;
                $html .= '<li' . ($i == $page ? ' class="active"' : '') . '><a href="?' . http_build_query($getParam) . '">' . $i . '</a>';
            endforeach;
            $html .= '</ul>';
        endif;

        $html .= '<table class="table table-striped" style="width: auto;"><thead><tr class="thead">';
        $tmp = '</tr> <tr class="filter">';

        foreach ($fields as $k => $r) {
            if (!empty($r['sort']) && $r['sort']) {
                $html .= '<th data-field="' . $k . '" class="sort"><i>+</i>' . $r[0] . '<i>-</i>';
            } else {
                $html .= '<th data-field="' . $k . '">' . $r[0] . '';
            }
            if (!empty($r['filter']) && $r['filter']) {
                $val = (!empty($_GET['fltr'][$k]) ? $_GET['fltr'][$k] : '');
                if (!empty($r['type']) && $r['type'] == 'date') {
                    $tmp .= '<th class="filter-date"><input type="text" name="fltr[' . $k . ']" value="' . $val . '" class="form-control">' .
                        '<input type="text" name="fltr[' . $k . '_2]" value="' . (!empty($_GET['fltr'][$k . '_2']) ? $_GET['fltr'][$k . '_2'] : '') . '" class="form-control">';
                } elseif (!empty($r['type']) && $r['type'] == 'bool') {
                    $sel1 = $sel2 = '';
                    if ($val === '1') {
                        $sel1 = ' selected="selected"';
                    } elseif ($val === '0') {
                        $sel2 = ' selected="selected"';
                    }
                    $tmp .= '<th class="filter-bool"><select name="filter[' . $k . ']" class="form-control"><option value="" name=" - "><option value="1" name="YES"' . $sel1 . '><option value="0" name="no"' . $sel2 . '></select>';
                } else {
                    $tmp .= '<th><input type="text" name="filter[' . $k . ']" value="' . $val . '" class="form-control">';
                }
            } else {
                $tmp .= '<th>';
            }

        }

        $html .= $tmp . '</tr></thead><tbody>';

        foreach ($dataList as $row) {
            $html .= '<tr>';
            foreach ($fields as $k => $r) {
                if (!empty($r['type']) && $r['type'] == 'date') {
                    $row[$k] = date('Y-m-d H:i:s', $row[$k]);
                } elseif (!empty($r['type']) && $r['type'] == 'bool') {
                    $row[$k] = ($row[$k] ? '+' : '');
                } elseif (!empty($r['url']) && $row[$k] && $row[$k] != '*') {
                    if ($r['url'] === true) {
                        $row[$k] = ($row[$k] ? '<a href="' . (strpos($r['host'], '://') !== false ? '' : $r['host']) . $row[$k] . '" target="_blank">' . substr($row[$k], 0, 15) . '...</a>' : '');
                    } else {
                        $row[$k] = ($row[$k] ? '<a href="' . $r['url'] . $row[$k] . '" target="_blank">' . $row[$k] . '</a>' : '');
                    }
                }

                if (!empty($r['spoiler'])) {
                    $html .= '<td' . (strlen($row[$k]) > 10 ? ' class="spoiler"><i>+</i>' : '>') . '<span>' . $row[$k] . '</span>';
                } else {
                    $html .= '<td>' . $row[$k];
                }
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<style>
            table td {
                max-width: 250px;
                overflow: auto;
            }
            th.sort {
            }
            th.sort i {
                cursor: pointer;
                color: blue;
                margin: 0 4px;
            }
            th.sort i:hover {
                color: red;
            }
            /*******/
            .spoiler span {
                display: none;
            }
            .spoiler i {
                color: blue;
                cursor: pointer;
            }
            .filter-date input {
                width: 49%;
                display: inline-block;
            }
            .bugs_post {
                word-wrap: break-word;
            }
        </style>
        <script>

        $(document).ready(function() {
            $(document).on("click", ".spoiler i", function() {
                $(this).hide().next().show();
            });
            $(document).on("click", "th.sort i", function() {
                locationSearch("sort", ($(this).text()=="-" ? "-" : "") + $(this).parent().attr("data-field"));
            });

            $(document).on("change", "tr.filter input", function() {
                var val = $(this).val();
                if ($(this).attr("type") == "checkbox" && !$(this).is(":checked")) {
                    val = 0;
                }
                locationSearch($(this).attr("name"), val);
            });
        });

        function locationSearch(name, val) {
            var gets = window.location.search.replace(/&amp;/g, "&").substring(1).split("&");
            var newAdditionalURL = "";
            var temp = "?";
            for (var i=0; i<gets.length; i++)
            {
                if(gets[i].split("=")[0] != name)
                {
                    newAdditionalURL += temp + gets[i];
                    temp = "&";
                }
            }
            window.location.search = newAdditionalURL + "&" + name + "=" + encodeURIComponent(val);
        }
        </script>';
        return $html;
    }

    /**
     * Рекурсивно удаляем директорию
     * @param $dir
     * @return bool
     */
    public static function delTree($dir)
    {
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? static::delTree("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }


    public static function getErrCount()
    {
        return static::$obj->errCount;
    }

    /**
     * Получить все текущие логи и ошибки скрипта
     * @return string
     */
    public function getAllLogs()
    {
        return $this->allLogs;
    }

    /**
     * Получить ссылку на профалер текщего скрипта
     * @return null
     */
    public function getProfilerUrl()
    {
        return $this->profilerUrl;
    }

    /**
     * Экранирование
     * @param $value string
     * @return string
     */
    public static function _e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_IGNORE, 'utf-8');
    }


    /***************************************/
    /***************************************/
    /***************************************/

    /**
     * Профилирование алгоритмов и функций отдельно
     * @param null $timeOut
     */
    public static function funcStart($timeOut = null)
    {
        static::$functionStartTime = microtime(true);
        static::$functionTimeOut = $timeOut;
    }

    public static function funcEnd($name, $info = null, $simple = true)
    {
        if (!static::getPdo()) return null;
        static::init()->time_run = (microtime(true) - static::$functionStartTime) * 1000;
        if (static::$functionTimeOut && static::init()->time_run < static::$functionTimeOut) {
            return null;
        }
        //PHPErrorCatcher::$minTimeProfiled += static::init()->time_run;
        return static::init()->saveStatsProfiler($name, $info, $simple);
    }

}

