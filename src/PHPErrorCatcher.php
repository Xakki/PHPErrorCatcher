<?php

namespace xakki\phperrorcatcher;

// Дописываем параметры для консольного запуска скрипта
if (!isset($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = 'cli';
if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '';
if (!isset($_SERVER['REMOTE_ADDR'])) $_SERVER['REMOTE_ADDR'] = '';

ini_set('display_errors', 1);
error_reporting(E_ALL);

defined('E_EXCEPTION_ERROR') || define('E_EXCEPTION_ERROR', -1); // custom error
defined('E_UNRECONIZE') || define('E_UNRECONIZE', 0); // custom error
defined('E_USER_INFO') || define('E_USER_INFO', -2); // custom error
defined('E_USER_ALERT') || define('E_USER_ALERT', -3); // custom error

/**
 * User: xakki
 * Date: 18.08.16
 * Time: 12:00
 */
class PHPErrorCatcher implements \Psr\Log\LoggerInterface {
    const VERSION = '0.4.8';

    const LEVEL_DEBUG = 'debug',
        LEVEL_TIME = 'time',
        LEVEL_INFO = 'info',
        LEVEL_NOTICE = 'notice',
        LEVEL_WARNING = 'warning',
        LEVEL_ERROR = 'error',
        LEVEL_CRITICAL = 'critical';

    const TYPE_LOGGER = 'logger',
        TYPE_TRIGGER = 'trigger',
        TYPE_EXCEPTION = 'exception',
        TYPE_FATAL = 'fatal';

    const FIELD_LOG_TYPE = 'log_type',
        FIELD_FILE = 'file',
        FIELD_TRICE = 'trice',
        FIELD_NO_TRICE = 'no-trice',
        FIELD_ERR_CODE = 'error_code';

    protected static $triggerLevel = array(
        E_ERROR          		=> self::LEVEL_ERROR,
        E_WARNING        		=> self::LEVEL_WARNING,
        E_PARSE          		=> self::LEVEL_CRITICAL,
        E_NOTICE         		=> self::LEVEL_NOTICE,
        E_DEPRECATED         	=> self::LEVEL_WARNING,
        E_CORE_ERROR     		=> self::LEVEL_ERROR,
        E_CORE_WARNING   		=> self::LEVEL_WARNING,
        E_COMPILE_ERROR  		=> self::LEVEL_CRITICAL,
        E_COMPILE_WARNING 		=> self::LEVEL_ERROR,
        E_USER_ERROR     		=> self::LEVEL_ERROR,
        E_USER_WARNING   		=> self::LEVEL_WARNING,
        E_USER_DEPRECATED   	=> self::LEVEL_WARNING,
        E_USER_NOTICE    		=> self::LEVEL_NOTICE,
        E_STRICT         		=> self::LEVEL_ERROR,
        E_RECOVERABLE_ERROR  	=> self::LEVEL_ERROR,
    );


    /************************************/
    // Config
    /************************************/

    protected $debugMode = false;//ERROR_DEBUG_MODE
    protected $logTimeProfiler = false;// time execute log
    protected $logCookieKey = '';
    protected $dirRoot = '';
    protected $limitTrace = 10;
    protected $limitString = 1024;
    protected $enableSessionLog = false;
    protected $enableCookieLog = false;
    protected $enablePostLog = true;
    /**
     * Callback Error
     * @var null|callable
     */
    protected $errorCallback = null;

    /**
     * Параметры(POST,SESSION,COOKIE) затираемы при записи в логи
     * @var array
     */
    protected $safeParamsKey = [
        'password',
        'input_password',
        'pass'
    ];

    protected $logTraceByLevel = [
        self::LEVEL_CRITICAL => 10, // trace deep level
        self::LEVEL_ERROR => 7,
        self::LEVEL_WARNING => 5,
    ];

    protected $saveLogIfHasError = false;
    protected $ignoreRules = [
//        ['level' => self::LEVEL_NOTICE, 'type' => self::TYPE_TRIGGER]
    ];
    /**
     * @var array|\Memcached
     */
    protected $memcacheServers = [
        ['localhost', 11211]
    ];
    protected $lifeTime = 600;//sec // ini_get('max_execution_time')

    protected $logTags = [];
    protected $logFields = [];
    /************************************/
    // Variable
    /************************************/

    protected $_count = 0;
    protected $_errCount = 0;
    protected $_time_start = null;
    protected $_time_end = null;
    protected $_overMemory = false;
    protected $_isViewMode = false;
    protected $_sessionKey = null;
    protected $_hasError = false;
    protected $_postData = null, $_cookieData = null, $_sessionData = null;
    /**
     * @var plugin\BasePlugin[]
     */
    protected $_plugins = [];
    /**
     * @var storage\BaseStorage[]
     */
    protected $_storages = [];
    /**
     * @var null|viewer\BaseViewer
     */
    protected $_viewer = null;

    /**
     * @var LogData[]
     */
    protected $_logData = [];
    protected $_successSaveLog = false;

    /**
     * @var \Memcached|null
     */
    protected static $_MEMCACHE = null;
    protected static $_viewAlert = [];
    protected static $_functionTimeOut = null;
    protected static $_functionStartTime;
    protected static $_userCatchLogKeys = [];
    protected static $_userCatchLogFlag = false;

    protected static $_errorLevel = [
        self::LEVEL_CRITICAL => 1,
        self::LEVEL_ERROR => 1,
        self::LEVEL_WARNING => 1,
    ];

    /*******************************/

    /**
     * singleton object
     * @var static
     */
    protected static $_obj;

    /**
     * Initialization
     * @param array $config
     * @return PHPErrorCatcher
     */
    public static function init($config = []) {
        if (!static::$_obj) {
            new self($config);
        }
        return static::$_obj;
    }

    public function __construct($config = []) {
        static::$_obj = $this;
        try {
            if (empty($config['storage'])) throw new \Exception('Storages config is require');

            $this->applyConfig($config);
            $this->_time_start = microtime(true);
            if (!$this->dirRoot) $this->dirRoot = $_SERVER['DOCUMENT_ROOT'];
            if (ini_get('max_execution_time'))
                $this->lifeTime = ini_get('max_execution_time')*2;

            $this->initStorage($config['storage']);

            register_shutdown_function([
                $this,
                'handleShutdown'
            ]);
            set_error_handler([
                $this,
                'handleTrigger'
            ], E_ALL);
            set_exception_handler([
                $this,
                'handleException'
            ]);

            if (!empty($config['plugin']))
                $this->initPlugins($config['plugin']);

            if (!empty($config['viewer']))
                $this->initViewer($config['viewer']);

        } catch (\Throwable $e) {
            if ($this->debugMode) {
                echo 'Cant init logger: ' . $e->__toString();
                exit('ERR');
            }
        }

    }

    public function __destruct()
    {
        $this->_plugins = [];
        $this->_storages = [];
    }

    public function applyConfig($config) {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key) && substr($key, 0, 1) != '_') {
                $this->$key = $value;
            }
        }
    }

    public static function setConfig($key, $value)
    {
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

    public function getViewer() {
        return $this->_viewer;
    }

    /**
     * @param $class
     * @return storage\BaseStorage|null
     */
    public function getStorage($class) {
        return (isset($this->_storages[$class]) ? $this->_storages[$class] : null);
    }

    /**
     * @return \Generator|LogData[]
     * @throws \Exception
     */
    public function getDataLogsGenerator() {
        foreach ($this->_logData as $key => $logData) {
            if (self::$_userCatchLogFlag && !isset(self::$_userCatchLogKeys[$key])) {
                continue;
            }
            if (!is_object($logData)) {
                $logData = $this->getLogDataFromMemcache($key);
                if (!$logData) {
                    throw new \Exception('Cant get log data from memcache');
                }
            }
            yield $logData;
        }
    }

    public function successSaveLog() {
        $this->_successSaveLog = true;
    }

    /**
     * @return \Memcached|null
     */
    public function memcache($restore = false) {
        if (is_null(static::$_MEMCACHE)) {
            static::$_MEMCACHE = false;
            if (is_object($this->memcacheServers)) {
                static::$_MEMCACHE = $this->memcacheServers;
            }
            elseif (extension_loaded('memcached')) {
                static::$_MEMCACHE = new \Memcached;
                foreach($this->memcacheServers as $server) {
                    if (!static::$_MEMCACHE->addServer($server[0], $server[1])) {
                        trigger_error('Memcached is down', E_USER_WARNING);
                        return static::$_MEMCACHE;
                    }
                }
            }
        }
        return static::$_MEMCACHE;
    }

    /**
     * When script stop
     */
    public function handleShutdown() {
        $this->_time_end = (microtime(true) - $this->_time_start) * 1000;

        $error = error_get_last();
        if ($error) {
            $fields = [
                self::FIELD_FILE => $this->getRelativeFilePath($error['file']).':'.$error['line'],
                self::FIELD_LOG_TYPE => self::TYPE_FATAL,
                self::FIELD_ERR_CODE => $error['type']
            ];
            $this->log(self::$triggerLevel[$error['type']], $error['message'], ['unhandle'], $fields);
        }

        if ($this->logTimeProfiler) {
            $this->log(self::LEVEL_TIME, $this->_time_end, ['execution'], []);
        }

        if (self::$_userCatchLogFlag) {
            $this->log(self::LEVEL_WARNING, 'Miss flush userCatchLog', [], []);
            self::$_userCatchLogFlag = false;
        }
    }

    public function needSaveLog() {
        return (!$this->_successSaveLog && count($this->_logData) && (!$this->saveLogIfHasError || $this->_errCount));
    }

    /**
     * Обработчик исключений
     * @param $e \Exception
     */
    public function handleException($e) {
        $fields = [self::FIELD_LOG_TYPE => self::TYPE_EXCEPTION];
        $this->log(self::LEVEL_CRITICAL, $e, ['unhandle'], $fields);
    }

    /**
     * Обработчик триггерных ошибок
     * @param $errno
     * @param $errstr
     * @param $errfile
     * @param $errline
     * @param null $vars
     */
    public function handleTrigger($errno, $errstr, $errfile, $errline, $vars = null) {
        $fields = [
            self::FIELD_FILE => $this->getRelativeFilePath($errfile).':'.$errline,
            self::FIELD_LOG_TYPE => self::TYPE_TRIGGER,
            self::FIELD_ERR_CODE => $errno,
            self::FIELD_TRICE => array_slice(debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, $this->limitTrace + 1), 1)
        ];
        $this->log(self::$triggerLevel[$errno], $errstr, [], $fields);
        if (function_exists('error_clear_last')) \error_clear_last();
    }

    /****************************************************/

    protected function initStorage($configs) {
        foreach ($configs as $storage => $config) {
            $keyStorage = $storage;
            if (class_exists(__NAMESPACE__ . '\\storage\\' . $storage)) {
                $storage = __NAMESPACE__ . '\\storage\\' . $storage;
            } elseif (!class_exists($storage)) {
                throw new \Exception('Storage `' . $storage . '` cant find ');
            }
            if (!is_subclass_of($storage, __NAMESPACE__ . '\\storage\\BaseStorage')) {
                throw new \Exception('Storage `' . $storage . '` must be implement from storage\BaseStorage');
            }
            $this->_storages[$keyStorage] = new $storage($this, $config);
        }
        if (!$this->_storages) {
            throw new \Exception('Storages config is require');
        }
    }

    protected function initPlugins($configs) {
        foreach ($configs as $plugin => $config) {
            if (!empty($config['initGetKey']) && !isset($_GET[$config['initGetKey']]))
                continue;

            if (class_exists(__NAMESPACE__ . '\\plugin\\' . $plugin)) {
                $plugin = __NAMESPACE__ . '\\plugin\\' . $plugin;
            } elseif (!class_exists($plugin)) {
                throw new \Exception('Plugins `' . $plugin . '` cant find ');
            }
            if (!is_subclass_of($plugin, __NAMESPACE__ . '\\plugin\\BasePlugin')) {
                throw new \Exception('Plugins `' . $plugin . '` must be implement from plugin\BasePlugin');
            }
            $this->_plugins[$plugin] = new $plugin($this, $config);
        }
    }

    protected function initViewer($config) {
        if (!isset($_GET[$config['initGetKey']])) return;

        if (class_exists(__NAMESPACE__ . '\\viewer\\' . $config['class'])) {
            $viewer = __NAMESPACE__ . '\\viewer\\' . $config['class'];
        } elseif (class_exists($config['class'])) {
            $viewer = $config['class'];
        } else {
            throw new \Exception('Viewer `' . $config['class'] . '` cant find ');
        }
        if (!is_subclass_of($viewer, __NAMESPACE__ . '\\viewer\\BaseViewer')) {
            throw new \Exception('Viewr `' . $viewer . '` must be implement from plugin\BasePlugin');
        }
        $this->_viewer = new $viewer($this, $config);
        $this->_isViewMode = true;

        return null;
    }

    protected function getSessionKey() {
        if (!$this->_sessionKey) {
            $this->_sessionKey = md5(
                $_SERVER['REQUEST_TIME_FLOAT'] . $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']
                . (!empty($_SERVER['PWD']) ? $_SERVER['PWD'] : '')
                . (!empty($_SERVER['argv']) ? json_encode($_SERVER['argv']) : '')
            );
        }
        return $this->_sessionKey;
    }

    protected function add(LogData $logData) {
        $result = false;
        $key = $logData->logKey;
        if ($this->memcache()) {
            if (isset($this->_logData[$key])) {
                $logData->count = $this->_logData[$key];
            }
            $result = $this->memcache()->set($key, $logData->__toString(), $this->lifeTime);
            if ($result) {
                if (isset($this->_logs[$key])) {
                    $this->_logData[$key]++;
                } else {
                    $this->_logData[$key] = 1;
                }
            }
        }
        if (!$result) {
            if (isset($this->_logData[$key])) {
                $this->_logData[$key]->count++;
            } else {
                $this->_logData[$key] = $logData;
            }
        }
        if (self::$_userCatchLogFlag) {
            self::$_userCatchLogKeys[$key] = true;
        }
    }

    protected function getLogFromObject($object) {
        $fields = [];
        $mess = '';
        if ($object instanceof \Throwable) {
            // Если это Exception
            $fields['exception_code'] = $object->getCode();

            $mess = get_class($object);
            if ($object->getMessage()) {
                $mess .= PHP_EOL . $object->getMessage();
            }
            if ($object->getPrevious()) {
                $mess .= PHP_EOL . 'Prev: ' . $object->getPrevious()->getMessage();
            }

            $fields[self::FIELD_FILE] = $this->getRelativeFilePath($object->getFile()).':'.$object->getLine();
        }
        elseif (method_exists($object, '__toString')) {
            $mess .= $object->__toString();
        }
        else {
            $mess .= get_class($object);
        }


        if (method_exists($object, 'getTrace')) {
            $fields[self::FIELD_TRICE]  = $this->renderDebugTrace($object->getTrace(), 0, $this->limitTrace);
        }

        return [$mess, $fields];
    }

    protected static function checkTraceExclude($row, $lineExclude = null) {
        if ($lineExclude) {
            if (!empty($row['file']) && strpos($row['file'], $lineExclude)!==false)
                return true;
//            if (!empty($row['class']) && strpos($row['class'], $lineExclude)!==false)
//                return true;
        }
        if (!empty($row['file']) && strpos($row['file'], 'phperrorcatcher')!==false)
            return true;
        return false;
    }

    /*****************************************************/
    /*****************************************************/

    public static function addGlobalTag($tag) {
        self::init()->logTags[] = $tag;
    }

    public static function addGlobalTags(array $tags) {
        self::init()->logTags = array_merge(self::init()->logTags, $tags);
    }

    public static function addGlobalField($key, $val) {
        self::init()->logFields[$key] = $val;
    }


    public static function setViewAlert($mess) {
        self::$_viewAlert[] = $mess;
    }

    public static function getErrCount() {
        return static::$_obj->_errCount;
    }

    public static function startCatchLog() {
        self::$_userCatchLogFlag = true;
    }

    public static function flushCatchLog() {
        // TODO
//        $log = '';//self::init()->getRenderLogs();
//        try {
//            foreach (self::init()->getDataLogsGenerator() as $key => $logData) {
//                //$logs .= $this->renderItemLog($logData);
//            }
//        } catch (\Throwable $e) {
//            $logs .= '<hr/><pre>'.$e->__toString().'</pre>!!!!';
//        }
//        self::$_userCatchLogFlag = false;
//        return $log;
    }

    /**
     * Профилирование алгоритмов и функций отдельно
     * @param null $timeOut
     */
    public static function funcStart($timeOut) {
        static::$_functionStartTime = microtime(true);
        static::$_functionTimeOut = $timeOut;
    }

    public static function funcEnd($name, $info = null, $simple = true) {
        // TODO
        $timer = (microtime(true) - static::$_functionStartTime) * 1000;
        if (static::$_functionTimeOut && $timer < static::$_functionTimeOut) {
            return null;
        }
        return static::$_obj->saveStatsProfiler($name, $info, $simple);
    }

    protected static $tick;
    public static function tickTrace($slice = 1, $limit = 3) {
        // TODO
        self::$tick = array_slice(debug_backtrace(), $slice, $limit);
    }

    /**
     * Экранирование
     * @param $value string
     * @return string
     */
    public static function _e($value) {
        if (!is_string($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        return htmlspecialchars((string)$value, ENT_NOQUOTES | ENT_IGNORE, 'utf-8');
    }

    /**
     * @param $arg
     * @return array|string
     */
    public static function arrayToLower($arg) {
        if (is_array($arg)) {
            $arg = array_map('mb_strtolower', $arg);
            $arg = array_change_key_case($arg, CASE_LOWER);
            return $arg;
        }
        else {
            return mb_strtolower($arg);
        }
    }

    public static function renderDebugArray($arr, $arrLen = 6, $strLen = 1024) {
        $i = $arrLen;
        $args = [];
        foreach ($arr as $v) {
            if ($i==0) {
                $args[] = '...' . (count($arr) - 6) . ']';
                break;
            }
            if (is_null($v))
                $args[] = 'NULL';
            else if (is_array($v))
                $args[] = '[' . implode(', ', self::renderDebugArray($v, $arrLen, $strLen)) . ']';
            else if (is_object($v))
                $args[] = get_class($v);
            else if (is_bool($v))
                $args[] = ($v ? 'T' : 'F');
            else if (is_resource($v))
                $args[] = 'RESOURCE';
            else if (is_numeric($v))
                $args[] = $v;
            else if (is_string($v)) {
                if (mb_strlen($v) > $strLen)
                    $v = mb_substr($v, 0, $strLen).'...';
                $args[] = '"'.preg_replace(["/\n+/u", "/\r+/u", "/\s+/u"], ['', '', ' '],$v).'"';
            }
            else {
                $args[] = 'OVER';
            }
            $i--;
        }
        return $args;
    }

    /***************************************************/

    public function setSafeParams() {
        if ($this->_postData !== null) return;
        $this->_postData = [];

        if ($this->enablePostLog) {
            if ($_POST) {
                foreach ($_POST as $k => $p) {
                    $size = mb_strlen(serialize((array)$this->_postData[$k]), '8bit');
                    if ($size > 1024)
                        $this->_postData[$k] = '...(' . $size . 'b)...';
                    else
                        $this->_postData[$k] = $p;
                }
            } elseif (isset($_SERVER['argv'])) {
                $this->_postData = $_SERVER['argv'];
            }
        }

        if ($this->enableCookieLog) {
            $this->_cookieData = $_COOKIE;
        }
        if ($this->enableSessionLog) {
            $this->_sessionData = $_SESSION;
        }

        if ($this->safeParamsKey && ($this->_postData || $this->_cookieData || $this->_sessionData)) {
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

    /**
     * Распечатка трейса
     * @param $trace
     * @param int $start
     * @param int $limit
     * @param string $lineExclude
     * @return string
     */
    public function renderDebugTrace($trace = null, $start = 0, $limit = 10, $lineExclude = '') {
        if (is_string($trace)) return $trace;
        if (!$trace) $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, $limit + 3);
        $res = '';

        foreach ($trace as $i=>$row) {
            if ($i<$start) continue;
            if (is_array($row) && (!empty($row['file']) || !empty($row['function']))) {
                if (self::checkTraceExclude($row, $lineExclude)) continue;
                $args = '';
                if (!empty($row['args'])) {
                    $args = implode(', ', self::renderDebugArray($row['args']));
                }
                $res .= '#' . $i . ' ' . (!empty($row['file']) ? $this->getRelativeFilePath($row['file']) . ':' . $row['line'] . ' ' : '')
                    . (!empty($row['class']) ? $row['class'] . '::' : '')
                    . (!empty($row['function']) ? $row['function'] . '(' . $args . ')' : '') . PHP_EOL;
            }
            else {
                $res .= mb_substr('@ '.(is_string($row) ? $row : json_encode($row, JSON_UNESCAPED_UNICODE)), 0, 1024).PHP_EOL;
            }
            if ($i>=$limit) break;
        }
        return $res;
    }


    public function getFileLineByTrace($start = 2, $lineExclude = null) {
        $res = '';
        foreach (array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6), $start) as $row) {
            if (self::checkTraceExclude($row, $lineExclude))
                continue;
            if (!empty($row['file']))
                return $this->getRelativeFilePath($row['file']) . ':' . $row['line'];
            return  (!empty($row['class']) ? $row['class'] . '::' : '')
                . (!empty($row['function']) ? $row['function'] . '()' : '');
        }
        return '-';
    }

    public function getRelativeFilePath($file) {
        return str_replace($this->dirRoot, '.', $file);
    }

    public function getRawLogFile() {
        return $this->dirRoot.'/raw.'.storage\FileStorage::FILE_EXT;
    }

    public static function renderVars($vars, $sl = 0) {
        $limitString = static::init()->get('limitString');
        $slr = str_repeat("\t", $sl);
        if (is_object($vars)) {
            $vars = 'Object: ' . get_class($vars);

        } elseif (is_string($vars)) {
            $size = mb_strlen($vars, '8bit');
            if ($size > $limitString) $vars = '"' . mb_substr(static::_e($vars), 0, $limitString) . '...(' . $size . 'b)"'; else $vars = '"' . static::_e($vars) . '"';

        } elseif (is_array($vars)) {
            if (isset($vars['_SERVER'])) return '';
            $vv = '[' . PHP_EOL;
            foreach ($vars as $k => $r) {
                $vv .= $slr . "\t" . static::_e($k) . ' => ';
                if (isset($GLOBALS[$k])) {
                    $vv .= 'GLOBAL VAR';
                } elseif (is_array($r)) {
                    $vv .= static::renderVars($r, ($sl + 1));
                } elseif (is_object($r)) {
                    $vv .= 'Object: ' . get_class($r);
                } elseif (is_string($r)) {
                    $vv .= static::_e(mb_substr($r, 0, $limitString));
                } elseif (is_resource($r)) {
                    $vv .= 'Resource: ' . get_resource_type($r);
                } elseif (is_int($r)) {
                    $vv .= $r;
                } else {
                    $vv .= gettype($r) . ': "' . static::_e(var_export($r, true)) . '"';
                }
                $vv .= PHP_EOL;
            }
            $vv .= $slr . ']';
            $vars = $vv;
            //                $size = mb_strlen(json_encode($vars), '8bit');
            //                if ($size > 5048) $vars = 'ARRAY: ...(vars size = ' . $size . 'b)...';
            //                elseif (!is_string($vars)) $vars = 'ARRAY: '.static::_e(var_export($vars, true));

        }
        elseif (is_resource($vars)) {
            $vars = 'RESOURCE: ' . get_resource_type($vars);

        }
        elseif (null === $vars) {
            $vars = 'NULL';
        }
        elseif (!is_int($vars)) {
            $vars = gettype($vars) . ': "' . static::_e(var_export($vars, true)) . '"';
        }
        $vars .= PHP_EOL;

        return $slr . $vars;
    }

//    public function getRenderLogs($renderCallback = null) {
//        if (!$renderCallback) $renderCallback = [storage\FileStorage::class, 'renderItemLog'];
//        $logs = '';
//        if (!is_callable($renderCallback)) return '<h3>$renderCallback is not calable '.json_encode($renderCallback).'</h3>';
//        try {
//            foreach ($this->getDataLogsGenerator() as $key => $logData) {
//                $logs .= call_user_func_array($renderCallback, [$logData]);
//            }
//        } catch (\Throwable $e) {
//            $logs .= '<hr/><pre>'.$e->__toString().'</pre>!!!!';
//        }
//        return $logs;
//    }

    public function getLogDataFromMemcache($key) {
        $raw = json_decode($this->memcache()->get($key), true);
        $logData = new LogData;
        if (!is_array($raw)) {
            return null;
        }
        foreach ($raw as $prop => $val) {
            $logData->{$prop} = $val;
        }
        return $logData;
    }

    /***************************************************/

    public function emergency($message, array $tags = array(), array $fields = array()) {
        $level = self::LEVEL_CRITICAL;
        $tags[] = 'emergency';
        return $this->log($level, $message, $tags, $fields);
    }

    public function alert($message, array $tags = array(), array $fields = array()) {
        $level = self::LEVEL_CRITICAL;
        $tags[] = 'alert';
        return $this->log($level, $message, $tags, $fields);
    }

    public function critical($msg, array $tags = array(), array $fields = array()) {
        $level = self::LEVEL_CRITICAL;
        return $this->log($level, $message, $tags, $fields);
    }

    public function error($message, array $tags = array(), array $fields = array()) {
        $level = self::LEVEL_ERROR;
        return $this->log($level, $message, $tags, $fields);
    }

    public function warning($message, array $tags = array(), array $fields = array()) {
        $level = self::LEVEL_WARNING;
        return $this->log($level, $message, $tags, $fields);
    }

    public function notice($message, array $tags = array(), array $fields = array()) {
        $level = self::LEVEL_NOTICE;
        return $this->log($level, $message, $tags, $fields);
    }

    public function info($message, array $tags = array(), array $fields = array()) {
        $level = self::LEVEL_INFO;
        return $this->log($level, $message, $tags, $fields);
    }

    public function debug($message, array $tags = array(), array $fields = array()) {
        $level = self::LEVEL_DEBUG;
        return $this->log($level, $message, $tags, $fields);
    }

    public static function logger($level, $msg, $tags = array(), array $fields = array()) {
        return static::init()->log($level, $msg, $tags, $fields);
    }

    protected static $_byteMemoryLimit;
    public static function isMemoryOver() {
        $limitMemory = ini_get('memory_limit');
        if ($limitMemory > 0) {
            if (!self::$_byteMemoryLimit)
                self::$_byteMemoryLimit =  (int) $limitMemory * (strpos($limitMemory, 'K') ? 1024 : (strpos($limitMemory, 'M') ? 1024 * 1024 : (strpos($limitMemory, 'G') ? 1024 * 1024 * 1024 : 1))) * 0.9;
            return memory_get_usage() >= self::$_byteMemoryLimit;
        }
        return false;
    }

    /**
     * @param mixed $msg
     * @param array $tags
     * @param array $fields
     * @param string $level
     * @return bool
     */
    public function log($level, $msg, array $tags = array(), array $fields = array()) {
        if (!isset($fields[self::FIELD_LOG_TYPE])) $fields[self::FIELD_LOG_TYPE] = self::TYPE_LOGGER;

        if (count($this->ignoreRules)) {
            foreach($this->ignoreRules as $rule) {
                $i = count($rule);
                if (!empty($rule['level']) && $rule['level'] == $level) $i--;
                if (!empty($rule['type']) && $rule['type'] == $fields[self::FIELD_LOG_TYPE]) $i--;
                if (!empty($rule['message']) && $rule['message'] == $msg) $i--;
                if ($i == 0) return true;
            }
        }

        if (isset(self::$_errorLevel[$level])) {
            $this->_errCount++;
        }
        $this->_count++;

        if (self::isMemoryOver()) {
            $this->_overMemory = true;
        }

        if (is_object($msg)) {
            // если это эксепшн и другие подобные объекты
            list($msg, $fields2) = $this->getLogFromObject($msg);
            if ($fields2) $fields = array_merge($fields, $fields2);
        }
        elseif (!is_string($msg)) {
            $msg = static::renderVars($msg);
        }

        $logData = new LogData;
        $logData->message = mb_substr($msg, 0, 5000);
        $logData->level = $level;

        if (count($this->logTags)) {
            $tags = array_merge($tags, $this->logTags);
        }
        if (count($this->logFields)) {
            $fields = array_merge($this->logFields, $fields);
        }

        if ($this->logCookieKey && !empty($_COOKIE[$this->logCookieKey])) {
            // для поиска только нужных логов
            array_push($tags, $_COOKIE[$this->logCookieKey]);
        }

        if (isset($this->logTraceByLevel[$level]) && empty($fields[self::FIELD_NO_TRICE]) ) {
            $logData->trace = $this->renderDebugTrace(isset($fields[self::FIELD_TRICE]) ? $fields[self::FIELD_TRICE] : null , 0, (int) $this->logTraceByLevel[$level]);
        }

        if (isset($fields[self::FIELD_NO_TRICE]))
            unset($fields[self::FIELD_NO_TRICE]);

        if (isset($fields[self::FIELD_TRICE]))
            unset($fields[self::FIELD_TRICE]);

        if (isset($fields[self::FIELD_FILE])) {
            $logData->file = $fields[self::FIELD_FILE];
            unset($fields[self::FIELD_FILE]);
        } else {
            $logData->file = $this->getFileLineByTrace();
        }

        $logData->type = $fields[self::FIELD_LOG_TYPE];
        unset($fields[self::FIELD_LOG_TYPE]);


        $logData->tags = array_values(array_unique(self::arrayToLower($tags)));
        $logData->fields = $fields;
        $logData->timestamp = (int) (microtime(true) * 1000);
        $logData->logKey = 'phpeErCh_'.$this->getSessionKey().'_'.md5($logData->message.$logData->file);

        $this->add($logData);

        return true;
    }


}

class LogData {
    public $logKey;
    public $message;
    public $level;
    public $type;
    public $trace;
    public $file;
    public $tags = [];
    public $fields = [];
    public $timestamp;
    public $count = 1;

    public function __toString() {
        $data = [];
        foreach (get_object_vars($this) as $k=>$v) {
            $data[$k] = $v;
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}

class HttpData {
    public $ip_addr;
    public $host;
    public $method;
    public $url;
    public $referrer;
    public $scheme;
    public $user_agent;
    public $overMemory;
}
