<?php

namespace xakki\phperrorcatcher;

// Дописываем параметры для консольного запуска скрипта
if (!isset($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = 'console';
if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '*';
if (!isset($_SERVER['REMOTE_ADDR'])) $_SERVER['REMOTE_ADDR'] = 'local';

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
    const LIMIT_COUNT = 100;

    /************************************/
    // Config
    /************************************/

    private $logPath = '';//ERROR_VIEW_PATH
    private $logDir = '/logsError';//ERROR_LOG_DIR
    private $backUpDir = '/_backUp'; // ERROR_BACKUP_DIR // use in PHPErrorViewer
    private $viewKey = 'showLogs';//ERROR_VIEW_GET
    private $debugMode = false;//ERROR_DEBUG_MODE


    /**
     * If you want enable log-request, set this name
     * @var null
     */
    private $catcherLogName = null;
    private $catcherLogFileSeparate = true;

    /**
     * @var null|array|callback|\PDO
     */
    private $pdo = null;
    private $pdoTableName = '_myprof';

    /**
     * Включает отправку писем (если это PHPMailer)
     * @var null|callback|\PHPMailer\PHPMailer\PHPMailer
     */
    private $mailer = null;

    /**
     * Шаблон темы
     * @var string
     */
    private $mailerSubjectPrefix = '{SITE} - DEV ERROR {DATE}';


    /**
     * Enable xhprof profiler
     * @var bool
     */
    private $xhprofEnable = false;

    /**
     * If xhprofEnable=true then save only if time more this property
     * @var int
     */
    private $minTimeProfiled = 4000999999;

    /**
     * location source profiler xhprof
     * @var null|string
     */
    private $xhprofDir = null;

    /**
     * profiler namespace (xhprof)
     * @var string
     */
    private $profiler_namespace = 'slow';


    /**
     * Callback Error
     * @var null|callable
     */
    private $errorCallback = null;

    /**
     * Debug notice , if has error
     * Attention - slow function
     * @var bool
     */
    private $showNotice = false;

    /**
     * Сохранять сессию и куки в логи
     * @var bool
     */
    private $enableSessionLog = false;
    private $enableCookieLog = false;

    /**
     * Параметры(POST,SESSION,COOKIE) затираемы при записи в логи
     * @var array
     */
    private $safeParamsKey = array(
        'password', 'input_password', 'pass'
    );

    /************************************/
    // Variable
    /************************************/

    /**
     * @var null|PHPErrorViewer
     */
    private $_viewComponent = null;
    private $_hasError = false;
    private $_profilerId = null;
    private $_profilerUrl = null;
    private $_profilerStatus = false;
    private $_postData = null, $_cookieData = null, $_sessionData = null;

    /**
     * singleton object
     * @var static
     */
    private static $_obj;

    /**
     * Профилирование вручную
     * microsec
     * @var null
     */
    private static $_functionTimeOut = null;
    /**
     * Custom function Time
     * @var
     */
    private static $_functionStartTime;

    /**
     * Counter
     * @var int
     */
    private $_count = 0;
    private $_errCount = 0;
    private $_time_start = null;
    private $_time_end = null;

    /**
     * Print all logs for output
     * @var string
     */
    private $_allLogs = '';
    private $_overMemory = false;

    /**
     * Flag for view mode
     * @var bool
     */
    private $_isViewMode = false;
    private $_viewAlert = array();

    /*******************************/

    /**
     * Error list
     * @var array
     */
    private $_errorListView = array(
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

    /**
     * Initialization
     * @param array $config
     * @return PHPErrorCatcher
     */
    public static function init($config = []) {
        if (!static::$_obj) {
            static::$_obj = new self($config);
        }
        return static::$_obj;
    }

    public function __construct($config = []) {
        if (empty($config['logPath'])) exit('Empty logPath');

        register_shutdown_function(array($this, 'shutdown'));
        set_error_handler(array($this, 'handleError'), E_ALL);
        set_exception_handler(array($this, 'handleException'));

        $this->applyConfig($config);
        $this->_time_start = microtime(true);

        // Profiler init
        $this->initProfiler();

        // UI Method for log view
        $this->initLogRequest();

        // UI Method for log view
        $this->initLogView();
    }

    public function applyConfig($config) {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key) && substr($key, 0, 1) != '_') {
                $this->$key = $value;
            }
        }
    }

    public static function setConfig($key, $value) {
        if (property_exists(static::$_obj, $key) && substr($key, 0, 1) != '_') {
            static::$_obj->{$key} = $value;
        }
    }

    public function get($key) {
        if (property_exists($this, $key)) {
            return $this->{$key};
        }
        return null;
    }

    /**
     * Use catche.js for log error in javascript
     */
    public function initLogRequest() {
        if (isset($_GET[$this->catcherLogName])) {
            if (!count($_POST)) {
                $_POST = json_decode(file_get_contents('php://input'), true);
            }
            if (!isset($_POST['m']) || !isset($_POST['u']) || !isset($_POST['r'])) exit();
            $errstr = $_POST['m'];
            $size = mb_strlen(serialize((array)$errstr), '8bit');
            if ($size > 1500) $errstr = mb_substr($errstr, 0, 1000) . '...(' . $size . 'b)...';
            $vars = [
                'url' => $_POST['u'],
                'ua' => $_SERVER['HTTP_USER_AGENT'],
                'referrer' => $_POST['r'],
            ];
            if (!empty($_POST['s']))
                $vars['errStack'] = $_POST['s'];
            if (!empty($_POST['l']))
                $vars['line'] = $_POST['l'];

            $GLOBALS['skipRenderBackTrace'] = 1;
            $this->_allLogs .= $this->renderErrorItem(E_USER_ERROR, $errstr, '', '', $vars);
            $this->enableSessionLog = false;
            $_POST = null;
            $renderLog = $this->renderLogs();
            if ($this->catcherLogFileSeparate) {
                $this->putData($renderLog, $this->catcherLogName);
            } else {
                $this->putData($renderLog);
            }
//
//            header('Content-type: text/html; charset=UTF-8');
//            echo $renderLog;
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'ok']);
            exit();
        }
    }

    public function initLogView() {
        if (isset($_GET[$this->viewKey])) {
            $this->_isViewMode = true;
            $this->_viewComponent = new PHPErrorViewer($this);
            $this->_viewComponent->renderView();
            exit();
        }
    }

    /**
     * Процедура завершения скрипта
     */
    public function shutdown() {
        $this->_time_end = (microtime(true) - $this->_time_start) * 1000;

        $error = error_get_last();
        if ($error) {
            $this->handleError($error['type'], $error['message'], $error['file'], $error['line'], null, 2);
        }

        if (isset($_GET['whereExit'])) {
            $this->_allLogs .= static::renderBackTrace(0);
            $this->_hasError = true;
        }

        try {
            $this->endProfiler();
            if ($this->_time_end <= $this->minTimeProfiled) {
                // выполнение скрипта в пределах нормы
            } else if (!$this->getPdo()) {
                // если нет связи с БД
            } else {
                $this->saveStatsProfiler();
            }
        } catch (\Exception $e) {
            $this->handleException($e);
        }

        if (($this->_allLogs || $this->_overMemory) && $this->_hasError) {
            $fileLog = $this->renderLogs();
            $mailStatus = $errorMailLog = false;
            try {
                ob_start();
                $mailStatus = $this->sendErrorMail($fileLog);
                $errorMailLog = ob_get_contents();
                ob_end_clean();
            } catch (\Exception $e) {
                $this->handleException($e);
            }
            if ($mailStatus) {
                $fileLog .= '<div class="bg-success">Выслано письмо на почту</div>';
            } elseif ($errorMailLog) {
                $fileLog .= '<div><pre class="bg-danger">Ошибка отправки письма' . PHP_EOL . static::_e($errorMailLog) . '</pre></div>';
            }
            $this->putData($fileLog);

        }
        $this->renderToolbar();
    }

    private function putData($fileLog, $fileName = 'H') {
        $path = $this->logPath . $this->logDir . '/' . date('Y.m.d');
        if (!file_exists($path)) {
            mkdir($path, 0775, true);
        }

        if ($fileName === 'H')
            $fileName = $path . '/' . date('H') . '.html';
        elseif (is_string($fileName))
            $fileName = $path . '/' . $fileName . '.html';
        else
            $fileName = $path . '/error.html';

        if (!file_exists($fileName)) $flagNew = true;
        else $flagNew = false;
        file_put_contents($fileName, $fileLog, FILE_APPEND);
        if ($flagNew) chmod($fileName, 0777);
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
    public function handleError($errno, $errstr, $errfile, $errline, $vars = null, $trace = null, $slice = 3) {

        if (!$this->showNotice && ($errno == E_NOTICE || $errno == E_USER_NOTICE)) {
            return true;
        }

        if ($this->_count > static::LIMIT_COUNT) {
            return true;
        }

        $this->_count++;

        $debug = $this->renderErrorItem($errno, $errstr, $errfile, $errline, $vars, $trace, $slice);

        if ($this->errorCallback && is_callable($this->errorCallback)) {
            call_user_func_array($this->errorCallback, [$debug, $errno, $errstr, $errfile, $errline, $vars]);
        }

        if ($this->_errorListView[$errno]['debug']) { // для нотисов подробности ни к чему
            $this->_hasError = true;
            $this->_errCount++;
        }
        if ($this->_isViewMode) {
            echo $debug;
        }

        $limitMemory = ini_get('memory_limit');
        if ($limitMemory > 0) {
            $limitMemory = (int)$limitMemory * (strpos($limitMemory, 'M') ? 1024 * 1024 : 1024) * 0.8;
            if (memory_get_usage() < $limitMemory) {
                $this->_allLogs .= $debug;
            } else {
                $this->putData('<hr>overMemory ' . $limitMemory . ':<br/>' . $this->_allLogs . $debug);
                $this->_allLogs = '';
                $this->_overMemory = true;
            }
        } else {
            $this->_allLogs .= $debug;
        }

        return true;
    }

    public static function logError($mess, $data = null, $enableDebug = true, $slice = 1) {
        $traceArr = debug_backtrace();
        $errfile = $traceArr[$slice]['file'];
        $errline = $traceArr[$slice]['line'];
        if (!$enableDebug) {
            $GLOBALS['skipRenderBackTrace'] = 1;
        }
        $errno = E_USER_WARNING;
        self::init()->handleError($errno, $mess, $errfile, $errline, $data, array_slice($traceArr, $slice));
    }

    /**
     * Обработчик исключений
     * @param $e \Exception
     * @param string $mess
     */
    public function handleException($e, $mess = '') {
        $this->handleError(E_EXCEPTION_ERROR, $e->getMessage() . ($mess ? '<br/>' . $mess : ''), $e->getFile(), $e->getLine(), $e->getCode(), $e->getTrace(), 2);
    }

    public static function logException($e, $mess = '') {
        self::init()->handleException($e, $mess);
    }

    /**
     * Получение рендера исключения для малых нужд
     * @param $e \Exception
     * @return string
     */
    public static function getExceptionRender($e) {
        return self::init()->renderErrorItem(E_EXCEPTION_ERROR, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getCode(), $e->getTrace());
    }

    /*****************************************************/

    /**
     * Получаем PDO соединение с БД
     * @return \PDO|null
     */
    public function getPdo() {
        if ($this->pdo && is_array($this->pdo)) {
            $this->pdo = array_merge([
                'engine' => 'mysql',
                'host' => 'localhost',
                'port' => 3106,
                'dbname' => 'test',
                'username' => 'test',
                'passwd' => 'test',
                'options' => [],
            ], $this->pdo);
            $this->pdo = new \PDO($this->pdo['engine'] . ':host=' . $this->pdo['host'] . ';port=' . $this->pdo['port'] . ';dbname=' . $this->pdo['dbname'],
                $this->pdo['username'], $this->pdo['passwd'], $this->pdo['options']);
        } elseif ($this->pdo && is_callable($this->pdo)) {
            $this->pdo = call_user_func_array($this->pdo, array());
        }
        return ($this->pdo instanceof \PDO ? $this->pdo : null);
    }

    private function setSafeParams() {
        if ($this->_postData !== null) return;

        if ($_POST) {
            foreach ($_POST as $k => $p) {
                $size = mb_strlen(serialize((array)$this->_postData[$k]), '8bit');
                if ($size > 1024)
                    $this->_postData[$k] = '...(' . $size . 'b)...';
                else
                    $this->_postData[$k] = $p;
            }
        } else {
            $this->_postData = $_SERVER['argv'];
        }

        if ($this->enableCookieLog) {
            $this->_cookieData = $_COOKIE;
        }
        if ($this->enableSessionLog) {
            $this->_sessionData = $_SESSION;
        }

        if ($this->safeParamsKey && ($_POST || $this->_cookieData || $this->_sessionData)) {
            foreach ($this->safeParamsKey as $key) {
                if (isset($this->_postData[$key])) {
                    $this->_postData[$key] = '***';
                }
                if ($this->_cookieData && isset($this->_cookieData[$key])) {
                    $this->_cookieData[$key] = '***';
                }
                if ($this->_sessionData && isset($this->_sessionData[$key])) {
                    $this->_sessionData[$key] = '***';
                }
            }
        }
    }

    /*****************************************************/

    /**
     * Получаем PHPMailer
     * @return \PHPMailer\PHPMailer\PHPMailer|null
     */
    private function getMailer() {
        if ($this->mailer && is_callable($this->mailer)) {
            $this->mailer = call_user_func_array($this->mailer, array());
        }
        return (is_object($this->mailer) ? $this->mailer : null);
    }

    /**
     * Отправка письма об ошибках
     * @param $message
     * @return bool
     */
    private function sendErrorMail($message) {
        try {
            $cmsmailer = $this->getMailer();
            if (!$cmsmailer) return false;

            $cmsmailer->Body = $message;
            $cmsmailer->Subject = str_replace(array('{SITE}', '{DATE}'), array($_SERVER['HTTP_HOST'], date(' Y-m-d H:i:s')), $this->mailerSubjectPrefix);
            return $cmsmailer->Send();
        } catch (\Exception $e) {
            $this->handleException($e);
        }
        return false;
    }

    /*****************************************************/

    /**
     * Запуск профайлера
     */
    public function initProfiler() {
        if (!$this->xhprofDir || $this->_profilerStatus) return;

        $lib1 = $this->xhprofDir . '/xhprof_lib/utils/xhprof_lib.php';
        $lib2 = $this->xhprofDir . '/xhprof_lib/utils/xhprof_runs.php';
        $tmpDir = $this->logPath . '/xhprof';
        if (extension_loaded('xhprof') && file_exists($lib1) && file_exists($lib2)) {
            if (!file_exists($tmpDir)) {
                if (!mkdir($tmpDir, 0775, true)) {
                    $this->setViewAlert('Cant create dir ' . $tmpDir);
                    return;
                }
            }
            ini_set("xhprof.output_dir", $tmpDir);
            include_once $lib1;
            include_once $lib2;
            xhprof_enable(XHPROF_FLAGS_NO_BUILTINS | XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
            $this->_profilerStatus = true;
        } else {
            $this->setViewAlert('Cant INIT profiler :' . (!extension_loaded('xhprof') ? 'Not load xhprof php modul' : '') . (!file_exists($lib1) || !file_exists($lib2) ? 'Cant load xhprof libs' : ''));
        }
    }

    /**
     * Завершение и сохранение профайлера
     * @return null|string
     */
    public function endProfiler() {
        if ($this->_profilerStatus) {
            $xhprof_data = xhprof_disable();
            if ($this->_time_end > $this->minTimeProfiled || $this->xhprofEnable) {
                $xhprof_runs = new \XHProfRuns_Default();
                $this->_profilerId = $xhprof_runs->save_run($xhprof_data, $this->profiler_namespace);
                $this->_profilerUrl = null;
                if ($this->_profilerId) {
                    $this->_profilerUrl = $this->getXhprofUrl($this->_profilerId);
                }
                return $this->_profilerId;
            }
        }
        return null;
    }

    public function getXhprofUrl($id = '') {
        return '?' . $this->viewKey . '=PROF&source=' . $this->profiler_namespace . '&run=' . $id;
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
            'time_cr' => $this->_time_start,
            'time_run' => $this->_time_end,
            'info' => $info,
            'json_post' => (!empty($this->_postData) ? htmlspecialchars(json_encode($this->_postData, JSON_UNESCAPED_UNICODE)) : null),
            'json_cookies' => ((!empty($this->_cookieData) && !$simple) ? htmlspecialchars(json_encode($this->_cookieData, JSON_UNESCAPED_UNICODE)) : null),
            'json_session' => ((!empty($this->_sessionData) && !$simple) ? htmlspecialchars(json_encode($this->_sessionData, JSON_UNESCAPED_UNICODE)) : null),
            'is_secure' => ((isset($_SERVER['HTTPS']) and $_SERVER['HTTPS'] !== 'off') ? true : false),
            'ref' => (!$simple ? $_SERVER['HTTP_REFERER'] : null),
            'script' => $script,
            'host' => $_SERVER['HTTP_HOST'],
        );
        if ($this->_profilerId) {
            $data['profiler_id'] = $this->_profilerId;
        }

        $stmt = $this->getPdo()->prepare('INSERT INTO ' . $this->pdoTableName . ' (' . implode(',', array_keys($data)) . ') VALUES(' . str_repeat('?,', (count($data) - 1)) . '?)');
        $res = $stmt->execute(array_values($data));
        $err = $stmt->errorInfo();
        if ($err[1]) {
            $this->_allLogs .= '<p class="alert alert-danger">INSERT BD: ' . $err[1] . ', ' . $err[2] . '</p>';
            $this->_hasError = true;
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
        if ($this->enableSessionLog) {
            $profilerUrlTag .= '<div class="bug_cookie"><b>COOKIE:</b>' . htmlspecialchars(json_encode($this->_cookieData)) . '</div>' .
                '<div class="bug_session"><b>SESSION:</b> ' . htmlspecialchars(json_encode($this->_sessionData)) . '</div>';
        }
        if ($this->_profilerUrl) {
            $profilerUrlTag = '<div class="bugs_prof">XHPROF: <a href="' . $this->_profilerUrl . '">' . $this->_profilerId . '</a></div>';
        }
        $res = '<div class="bugs">' .
            '<span class="bugs_host">' . $_SERVER['HTTP_HOST'] . '</span> ' .
            '<span class="bugs_uri">' . mb_substr($_SERVER['REQUEST_URI'], 0, 500) . '</span> ' .
            '<span class="bugs_ip">' . $_SERVER['REMOTE_ADDR'] . '</span> ' .
            (!empty($_SERVER['HTTP_REFERER']) ? '<span class="bugs_ref">' . mb_substr($_SERVER['HTTP_REFERER'], 0, 500) . '</span> ' : '');
        if (!empty($this->_postData)) {
            $cntArr = count($this->_postData);
            if ($cntArr > 10) {
                $this->_postData = array_slice($this->_postData, 0, 10);
            }
            $this->_postData = array_map(function ($val) {
                if (is_array($val)) {
                    $cnt1 = count($val);
                    if ($cnt1 > 10) $val = array_slice($val, 0, 10);
                    return ' [<' . implode(', ', array_keys($val)) . '> ' . ($cnt1 > count($val) ? '...' . $cnt1 : '') . '] ';
                }
                elseif (mb_strlen($val) > 64) return mb_substr($val, 0, 64) . '...';
                return $val;
            }, $this->_postData);
            $res .= PHP_EOL . '<span class="bugs_post">' . self::_e(json_encode($this->_postData, JSON_UNESCAPED_UNICODE)) .
                ($cntArr > count($this->_postData) ? '...' . $cntArr : '') . '</span>';
        }
        return $res .
            $profilerUrlTag .
            $this->_allLogs .
            ($this->_overMemory ? '<hr>Over memory limit' : '') .
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
     * @param null $trace
     * @param int $slice
     * @return string
     */
    public function renderErrorItem($errno, $errstr, $errfile, $errline, $vars = null, $trace = null, $slice = 1) {
        $micro_date = microtime();
        $micro_date = explode(" ", $micro_date);

        $debug = '<div class="bug_item bug_' . $errno . '">' .
            '<span class="bug_time">' . date('Y-m-d H:i:s P', $micro_date[1]) . '</span>' .
            '<span class="bug_mctime">' . $micro_date[0] . '</span> ' .
            '<span class="bug_type">' . $this->_errorListView[$errno]['type'] . '</span>' .
            '<span class="bug_str">' . self::_e($errstr) . '</span>';
        if ($errfile) {
//        $debug .= '<div class="bug_file"> File <a href="file:/' . $errfile . ':' . $errline . '">' . $errfile . ':' . $errline . '</a></div>';
            $debug .= '<div class="bug_file"> File <a href="idea://open?url=file://' . $errfile . '&line=' . $errline . '">' . $errfile . ':' . $errline . '</a></div>';
        }
        if ($vars) {
            $vars = self::renderVars($vars);
            if (mb_strlen($vars) > 512) {
                $debug .= '<div class="bug_vars xsp"><div class="xsp-head" onclick="bugSp(this)">Vars</div><div class="xsp-body">' . $vars . '</div></div>';
            } else {
                $debug .= '<div class="bug_vars small">' . $vars . '</div>';
            }
        }

        if ($this->_errorListView[$errno]['debug'] && empty($GLOBALS['skipRenderBackTrace'])) { // для нотисов подробности ни к чему
            $debug .= static::renderBackTrace($slice, $trace);
        } else {
            unset($GLOBALS['skipRenderBackTrace']);
            // if (isset($arr['line']) and $arr['file'])
        }

        $debug .= '</div>';
        return $debug;
    }

    public static function renderVars($vars, $sl = 0) {
        $slr = str_repeat("\t", $sl);
        if (is_object($vars)) {
            $vars = 'Object: ' . get_class($vars);

        } elseif (is_string($vars)) {
            $size = mb_strlen($vars, '8bit');
            if ($size > 512) $vars = '"' . mb_substr(self::_e($vars), 0, 512) . '...(' . $size . 'b)"';
            else $vars = '"' . self::_e($vars).'"';

        } elseif (is_array($vars)) {
            $vv = '[' . PHP_EOL;
            foreach ($vars as $k => $r) {
                $vv .= $slr."\t" . (string)$k . ' => ';
                if (is_array($r)) {
                    $vv .= self::renderVars($r, ($sl+1)).PHP_EOL;
                } elseif (is_object($r)) {
                    $vv .= 'Object: ' . get_class($r) . PHP_EOL;
                } elseif (is_string($r)) {
                    $vv .= mb_substr($r, 0, 128) . PHP_EOL;
                } elseif (is_resource($r)) {
                    $vv .= 'Resource: ' . get_resource_type($r) . PHP_EOL;
                } else {
                    $vv .= 'Over: "' . $r .'"'. PHP_EOL;
                }
            }
            $vv .= $slr.']';
            $vars = $vv;
//                $size = mb_strlen(json_encode($vars), '8bit');
//                if ($size > 5048) $vars = 'ARRAY: ...(vars size = ' . $size . 'b)...';
//                elseif (!is_string($vars)) $vars = 'ARRAY: '.self::_e(var_export($vars, true));

        } elseif (is_resource($vars)) {
            $vars = 'RESOURCE: ' . get_resource_type($vars);

        } elseif (null === $vars)
            $vars = 'NULL';

        else {
            $vars = 'OVER: "' . self::_e(var_export($vars, true)).'"';
        }

        return $slr.$vars;
    }


    /**
     * Функция трасировки ошибок
     * @param int $slice
     * @param array $traceArr
     * @return string
     */
    public static function renderBackTrace($slice = 1, $traceArr = null) {
        $MAXSTRLEN = 1024;
        $s = '<div class="xdebug xsp"><div class="xsp-head" onclick="bugSp(this)">Trace</div><div class="xsp-body">';
        if (!$traceArr || !is_array($traceArr)) {
            $traceArr = debug_backtrace();
            if ($slice > 0) {
                $traceArr = array_slice($traceArr, $slice);
            }
        }
        $i = 0;
        foreach ($traceArr as $arr) {
            $s .= '<div class="xdebug-item" style="margin-left:' . (10 * $i) . 'px;">' .
                '<span class="xdebug-item-file">';
            if (isset($arr['line']) and $arr['file']) {
//                $s .= ' in <a href="file:/' . $arr['file'] . '">' . $arr['file'] . ':' . $arr['line'] . '</a> , ';
                $s .= ' in <a href="idea://open?url=file://' . $arr['file'] . '&line=' . $arr['line'] . '">' . $arr['file'] . ':' . $arr['line'] . '</a> , ';
            }
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
        $s .= '</div></div>';
        return $s;
    }


    /******************************************************************************************/
    /******************************************************************************************/
    /******************************************************************************************/

    public function setViewAlert($mess) {
        $this->_viewAlert[] = $mess;
    }

    public function renderToolbar() {
        if (!$this->_isViewMode && $this->debugMode) {
            if (empty($this->_viewAlert) && !$this->_hasError && (!$this->_profilerStatus || !static::init()->_profilerId))
                return;
            echo PHPErrorViewer::renderViewScript($this);
            ?>
            <div class="pecToolbar xsp">
                <button class="btn <?= ($this->_hasError ? 'btn-danger' : 'btn-primary') ?> xsp-head" onclick="bugSp(this)">Expand
                    Logs: <?= static::getErrCount() ?></button>
                <a class="btn btn-default" href="?<?= $this->viewKey ?>=/">View All Logs</a>
                <div class="xsp-body">
                    <? if ($this->_profilerStatus && static::init()->_profilerId): ?>
                        <p class="alert-info"><a href="<?= self::getProfilerUrl() ?>">Profiler <?= $this->_profilerId ?></a></p>
                    <? endif; ?>
                    <? if ($this->_hasError): ?>
                        <div class="alert-danger"><?= $this->_allLogs ?></div>
                    <? endif; ?>
                    <? if (count($this->_viewAlert)): ?>
                        <div class="alert-warning">
                            <? foreach ($this->_viewAlert as $r): ?>
                                <p><?= $r ?></p>
                            <? endforeach; ?>
                        </div>
                    <? endif; ?>
                </div>
            </div>
            <?php
        }
    }


    /***************************************/
    /***************************************/

    public static function getErrCount() {
        return static::$_obj->_errCount;
    }

    /**
     * Получить все текущие логи и ошибки скрипта
     * @return string
     */
    public static function getAllLogs() {
        return static::$_obj->_allLogs;
    }

    public static function setAllLogs($log) {
        return static::$_obj->_allLogs = $log;
    }

    public static function clearAllLogs() {
        return static::$_obj->_allLogs = '';
    }

    /**
     * Получить ссылку на профалер текщего скрипта
     * @return null
     */
    public static function getProfilerUrl() {
        return static::$_obj->_profilerUrl;
    }

    /**
     * Экранирование
     * @param $value string
     * @return string
     */
    public static function _e($value) {
        return htmlspecialchars((string)$value, ENT_NOQUOTES | ENT_IGNORE, 'utf-8');
    }

    /***************************************/

    /**
     * Профилирование алгоритмов и функций отдельно
     * @param null $timeOut
     */
    public static function funcStart($timeOut = null) {
        static::$_functionStartTime = microtime(true);
        static::$_functionTimeOut = $timeOut;
    }

    public static function funcEnd($name, $info = null, $simple = true) {
        if (!self::init()->getPdo()) return null;
        static::init()->_time_start = (microtime(true) - static::$_functionStartTime) * 1000;
        if (static::$_functionTimeOut && static::init()->_time_start < static::$_functionTimeOut) {
            return null;
        }
        //static::init()->minTimeProfiled += static::init()->time_run;
        return static::init()->saveStatsProfiler($name, $info, $simple);
    }

}

