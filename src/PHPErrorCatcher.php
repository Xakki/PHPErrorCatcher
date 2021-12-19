<?php

namespace xakki\phperrorcatcher;

ini_set('display_errors', 1);
error_reporting(E_ALL);
if(!defined('STDIN'))  define('STDIN',  fopen('php://stdin',  'rb'));
if(!defined('STDOUT')) define('STDOUT', fopen('php://stdout', 'wb'));
if(!defined('STDERR')) define('STDERR', fopen('php://stderr', 'wb'));

class PHPErrorCatcher implements \Psr\Log\LoggerInterface
{
    const VERSION = '0.8.0';

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
        FIELD_NO_TRICE = 'trice_no_fake',
        FIELD_ERR_CODE = 'error_code';

    protected static $triggerLevel = [
        E_ERROR => self::LEVEL_ERROR,
        E_WARNING => self::LEVEL_WARNING,
        E_PARSE => self::LEVEL_CRITICAL,
        E_NOTICE => self::LEVEL_NOTICE,
        E_DEPRECATED => self::LEVEL_WARNING,
        E_CORE_ERROR => self::LEVEL_ERROR,
        E_CORE_WARNING => self::LEVEL_WARNING,
        E_COMPILE_ERROR => self::LEVEL_CRITICAL,
        E_COMPILE_WARNING => self::LEVEL_ERROR,
        E_USER_ERROR => self::LEVEL_ERROR,
        E_USER_WARNING => self::LEVEL_WARNING,
        E_USER_DEPRECATED => self::LEVEL_WARNING,
        E_USER_NOTICE => self::LEVEL_NOTICE,
        E_STRICT => self::LEVEL_ERROR,
        E_RECOVERABLE_ERROR => self::LEVEL_ERROR,
    ];


    /************************************/
    // Config
    /************************************/

    public $debugMode = false;//ERROR_DEBUG_MODE
    public $enableSessionLog = false;

    protected $logTimeProfiler = false;// time execute log
    protected $logCookieKey = '';
    protected $dirRoot = '';
    protected $limitTrace = 10;
    protected $limitString = 1024;
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
        'pass',
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
    protected $stopRules = [
        //        ['level' => self::LEVEL_NOTICE, 'type' => self::TYPE_TRIGGER]
    ];
    /**
     * @var array|\Memcached
     */
    protected $memcacheServers = [
        ['localhost', 11211],
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
    public static function init($config = [])
    {
        if (!static::$_obj) {
            new self($config);
        }
        return static::$_obj;
    }

    protected $_configOrigin = [];

    public function __construct($config = [])
    {
        static::$_obj = $this;
        try {
            $this->_configOrigin = $config;
            if (empty($config['storage'])) throw new \Exception('Storages config is require');

            $this->applyConfig($config);
            $this->_time_start = microtime(true);
            if (!$this->dirRoot) $this->dirRoot = $_SERVER['DOCUMENT_ROOT'];
            if (ini_get('max_execution_time'))
                $this->lifeTime = ini_get('max_execution_time') * 2;

            $this->initStorage($config['storage']);

            register_shutdown_function([
                $this,
                'handleShutdown',
            ]);
            set_error_handler([
                $this,
                'handleTrigger',
            ], E_ALL);
            set_exception_handler([
                $this,
                'handleException',
            ]);

            if (!empty($config['plugin']))
                $this->initPlugins($config['plugin']);

            if (!empty($config['viewer'])) {
                $this->initViewer($config['viewer']);
            }

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

    protected function applyConfig($config)
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key) && substr($key, 0, 1) != '_') {
                $this->$key = $value;
            }
        }
    }

    /**
     * @deprecated
     */
    public static function setConfig($key, $value)
    {
        if (property_exists(static::$_obj, $key) && substr($key, 0, 1) != '_') {
            static::$_obj->{$key} = $value;
        }
    }

    /**
     * @deprecated
     */
    public function get($key)
    {
        if (property_exists($this, $key)) {
            return $this->{$key};
        }
        return null;
    }

    public function getViewer()
    {
        return $this->_viewer;
    }

    /**
     * @param $class
     * @return storage\BaseStorage|null
     */
    public function getStorage($class)
    {
        return (isset($this->_storages[$class]) ? $this->_storages[$class] : null);
    }

    public function getStorages()
    {
        return $this->_storages;
    }

    /**
     * @return \Generator|LogData[]
     * @throws \Exception
     */
    public function getDataLogsGenerator()
    {
        foreach ($this->_logData as $key => $logData) {
            if (self::$_userCatchLogFlag && !isset(self::$_userCatchLogKeys[$key])) {
                continue;
            }
            if (!is_object($logData)) {
                $logData = $this->getLogDataFromMemcache($key);
                if (!$logData) {
                    // cache expired
                    continue;
                }
            }
            yield $logData;
        }
    }

    public function successSaveLog()
    {
        $this->_successSaveLog = true;
    }

    /**
     * @return \Memcached|null
     */
    public function memcache($restore = false)
    {
        if (is_null(static::$_MEMCACHE)) {
            static::$_MEMCACHE = false;
            if (is_object($this->memcacheServers)) {
                static::$_MEMCACHE = $this->memcacheServers;
            } elseif (extension_loaded('memcached')) {
                static::$_MEMCACHE = new \Memcached;
                foreach ($this->memcacheServers as $server) {
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
    public function handleShutdown()
    {
        $this->_time_end = (microtime(true) - $this->_time_start) * 1000;

        $error = error_get_last();
        if ($error) {
            $fields = [
                self::FIELD_FILE => $this->getRelativeFilePath($error['file']) . ':' . $error['line'],
                self::FIELD_LOG_TYPE => self::TYPE_FATAL,
                self::FIELD_ERR_CODE => $error['type'],
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

    public function needSaveLog()
    {
        return (!$this->_successSaveLog && count($this->_logData) && (!$this->saveLogIfHasError || $this->_errCount));
    }

    /**
     * Обработчик исключений
     * @param $e \Exception
     */
    public function handleException($e)
    {
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
    public function handleTrigger($errno, $errstr, $errfile, $errline, $vars = null)
    {
        if (!(error_reporting() & $errno)) {
            // также игнорируются ошибки помеченные @
            return true;
        }

        $fields = [
            self::FIELD_FILE => $this->getRelativeFilePath($errfile) . ':' . $errline,
            self::FIELD_LOG_TYPE => self::TYPE_TRIGGER,
            self::FIELD_ERR_CODE => $errno,
            self::FIELD_TRICE => array_slice(debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, $this->limitTrace + 1), 1),
        ];

        $this->log(self::$triggerLevel[$errno], $errstr, [], $fields);

        if (function_exists('error_clear_last'))
            \error_clear_last();

        return true; /* Не запускаем внутренний обработчик ошибок PHP */
    }

    /****************************************************/

    protected function initStorage($configs)
    {
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

    protected function initPlugins($configs)
    {
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

    protected function initViewer($config)
    {

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

        if (isset($_GET[$config['initGetKey']])) {
            $this->_isViewMode = true;
            ini_set("memory_limit", "128M");

            ob_start();
            $html = $this->getViewer()
                ->renderView();
            $html .= ob_get_contents();
            ob_end_clean();
            echo $html;
            exit();
        }

        return $this->_viewer;
    }

    protected function getSessionKey()
    {
        if (!$this->_sessionKey) {
            $this->_sessionKey = md5(
                (!empty($_SERVER['REQUEST_TIME_FLOAT']) ? $_SERVER['REQUEST_TIME_FLOAT'] : '')
                . (!empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '')
                . (!empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'cli')
                . (!empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '')
                . (!empty($_SERVER['PWD']) ? $_SERVER['PWD'] : '')
                . (!empty($_SERVER['argv']) ? json_encode($_SERVER['argv']) : '')
            );
        }
        return $this->_sessionKey;
    }

    protected function ifStopRules(LogData $logData)
    {
        if (count($this->stopRules)) {
            foreach ($this->stopRules as $rule) {
                $i = count($rule);
                if (!empty($rule['level']) && $rule['level'] == $logData->level) $i--;
                if (!empty($rule['type']) && $rule['type'] == $logData->type) $i--;
                if (!empty($rule['message']) && $rule['message'] == $logData->message) $i--;
                if ($i == 0) return true;
            }
        }
        return false;
    }

    protected function add(LogData $logData)
    {
        $this->consolePrint($logData);

        $result = false;
        $key = $logData->logKey;
        if ($this->memcache()) {
            if (isset($this->_logData[$key])) {
                $logData->count = $this->_logData[$key];
            }
            try {
                if ($this->ifStopRules($logData)) {
                    // TODO: option & cli print
//                    echo PHP_EOL;
//                    print_r($logData);
//                    echo PHP_EOL;
                    exit('Fatal');
                }

                $str = $logData->__toString();
                if ($str) {
                    $result = $this->memcache()->set($key, $str, $this->lifeTime);
                    if ($result) {
                        if (isset($this->_logs[$key])) {
                            $this->_logData[$key]++;
                        } else {
                            $this->_logData[$key] = 1;
                        }
                    }
                }

            } catch (\Throwable $e) {
                echo '<p>Error: ' . $e->__toString() . '</p>';
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

    const COLOR_GREEN = '0;32',
        COLOR_GRAY = '0;37',
        COLOR_GRAY2 = '1;37',
        COLOR_YELLOW = '1;33',
        COLOR_RED = '0;31',
        COLOR_WHITE = '1;37',
        COLOR_LIGHT_BLUE = '1;34',
        COLOR_BLUE = '0;34',
        COLOR_BLUE2 = '1;36';

    const CLI_LEVEL_COLOR = [
        self::LEVEL_CRITICAL => self::COLOR_RED,
        self::LEVEL_ERROR => self::COLOR_RED,
        self::LEVEL_WARNING => self::COLOR_YELLOW,
        self::LEVEL_NOTICE => self::COLOR_BLUE,
        self::LEVEL_INFO => self::COLOR_GREEN,
        self::LEVEL_TIME => self::COLOR_LIGHT_BLUE,
        self::LEVEL_DEBUG => self::COLOR_BLUE2,
    ];

    private static function cliColor(string $text, $colorId)
    {
        if (!isset($_SERVER['TERM'])) return $text;
        return "\033[" . $colorId . "m" . $text . "\033[0m";
    }

    protected function consolePrint(LogData $logData)
    {
        if (empty($_SERVER['argv'])) return;

        if ($logData->level === self::LEVEL_DEBUG && !$this->debugMode) return;

        $output = PHP_EOL . rtrim(\DateTime::createFromFormat('U.u', $logData->timestamp)->format('H:i:s.u'), '0')
            . ' ' . self::cliColor($logData->level, self::CLI_LEVEL_COLOR[$logData->level]);
        if ($logData->tags)
            $output .= self::cliColor(' [' . implode(', ', $logData->tags) . ']', self::COLOR_GRAY);
        if (isset(self::$_errorLevel[$logData->level])) {
            if ($logData->fields) {
                $output .= ' ' . self::cliColor(json_encode($logData->fields), self::COLOR_GRAY);
            }
            if ($logData->file) {
                $output .= PHP_EOL . "\t" . $logData->file;
            }
        }
        $output .= PHP_EOL . "\t" . str_replace(PHP_EOL, "\n\t", self::cliColor($logData->message, self::COLOR_GRAY2)) . PHP_EOL;

        if (isset($_SERVER['TERM'])) {
            fwrite(\STDERR, $output); // Ошибки в консоли можно залогировать толкьо так `&2 >>`
        } else {
            // Для кронов, чтобы все логи по умолчанию выводились дефолтно черезе `>>`
            fwrite(\STDOUT, $output);
        }
    }

    protected function getLogFromObject($object)
    {
        $fields = [];
        $mess = get_class($object);

        if (method_exists($object, 'getCode')) {
            $fields['exception_code'] = $object->getCode();
        }

        if (method_exists($object, 'getMessage') && $object->getMessage()) {
            $mess .= PHP_EOL . $object->getMessage();
        } elseif (method_exists($object, '__toString')) {
            $mess .= $object->__toString();
        } else {
            $mess .= get_class($object);
        }

        if (method_exists($object, 'getPrevious') && $object->getPrevious()) {
            $mess .= PHP_EOL . 'Prev: ' . $object->getPrevious()->getMessage();
        }
        if (method_exists($object, 'getFile')) {
            $fields[self::FIELD_FILE] = $this->getRelativeFilePath($object->getFile()) . ':' . $object->getLine();
        }

        if (method_exists($object, 'getTrace')) {
            $fields[self::FIELD_TRICE] = $this->renderDebugTrace($object->getTrace(), 0, $this->limitTrace);
        }

        return [$mess, $fields];
    }

    protected static function checkTraceExclude($row, $lineExclude = null)
    {
        if ($lineExclude) {
            if (!empty($row['file']) && strpos($row['file'], $lineExclude) !== false)
                return true;
            //            if (!empty($row['class']) && strpos($row['class'], $lineExclude)!==false)
            //                return true;
        }
        if (!empty($row['file']) && strpos($row['file'], 'phperrorcatcher') !== false)
            return true;
        return false;
    }

    /*****************************************************/
    /*****************************************************/

    public static function addGlobalTag($tag)
    {
        self::init()->logTags[] = $tag;
    }

    public static function addGlobalTags(array $tags)
    {
        self::init()->logTags = array_merge(self::init()->logTags, $tags);
    }

    public static function addGlobalField($key, $val)
    {
        self::init()->logFields[$key] = $val;
    }


    public static function setViewAlert($mess)
    {
        self::$_viewAlert[] = $mess;
    }

    public static function getErrCount()
    {
        return static::$_obj->_errCount;
    }

    public static function startCatchLog()
    {
        self::$_userCatchLogFlag = true;
    }

    public static function flushCatchLog()
    {
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

    public static function getRenderLogs()
    {
        $logger = self::init();
        return $logger->getViewer()
            ->renderAllLogs(storage\FileStorage::getDataHttp(), $logger->getDataLogsGenerator());

    }

    /**
     * Профилирование алгоритмов и функций отдельно
     * @param null $timeOut
     */
    public static function funcStart($timeOut)
    {
        static::$_functionStartTime = microtime(true);
        static::$_functionTimeOut = $timeOut;
    }

    public static function funcEnd($name, $info = null, $simple = true)
    {
        // TODO
        $timer = (microtime(true) - static::$_functionStartTime) * 1000;
        if (static::$_functionTimeOut && $timer < static::$_functionTimeOut) {
            return null;
        }
        return static::$_obj->saveStatsProfiler($name, $info, $simple);
    }

    protected static $tick;

    public static function tickTrace($slice = 1, $limit = 3)
    {
        // TODO
        self::$tick = array_slice(debug_backtrace(), $slice, $limit);
    }

    /**
     * Экранирование
     * @param $value string
     * @return string
     */
    public static function _e($value)
    {
        if (!is_string($value)) {
            $value = self::safe_json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return htmlspecialchars((string)$value, ENT_NOQUOTES | ENT_IGNORE, 'utf-8');
    }

    /**
     * @param $arg
     * @return array|string
     */
    public static function arrayToLower($arg)
    {
        if (is_array($arg)) {
            $arg = array_map('mb_strtolower', $arg);
            $arg = array_change_key_case($arg, CASE_LOWER);
            return $arg;
        } else {
            return mb_strtolower($arg);
        }
    }

    public static function renderDebugArray($arr, $arrLen = 6, $strLen = 256)
    {
        if (!is_array($arr)) return $arr;
        $i = $arrLen;
        $args = [];

        foreach ($arr as $k => $v) {
            if ($i == 0) {
                $args[] = '...' . (count($arr) - 6) . ']';
                break;
            }
            $prf = '';
            if (!is_numeric($k))
                $prf = $k . ':';
            if (is_null($v))
                $args[] = $prf . 'NULL';
            else if (is_array($v))
                $args[] = $prf . '[' . implode(', ', self::renderDebugArray($v, $arrLen, $strLen)) . ']';
            else if (is_object($v))
                $args[] = $prf . get_class($v);
            else if (is_bool($v))
                $args[] = $prf . ($v ? 'T' : 'F');
            else if (is_resource($v))
                $args[] = $prf . 'RESOURCE';
            else if (is_numeric($v))
                $args[] = $prf . $v;
            else if (is_string($v)) {
                $l = mb_strlen($v);
                if ($l > $strLen) {
                    $v = mb_substr($v, 0, $strLen - 30) . '...(' . $l . ')...' . mb_substr($v, $l - 20);
                }
                $args[] = $prf . '"' . preg_replace(["/\n+/u", "/\r+/u", "/\s+/u"], ['', '', ' '], $v) . '"';
            } else {
                $args[] = $prf . 'OVER';
            }
            $i--;
        }
        return $args;
    }

    /***************************************************/

    public function setSafeParams()
    {
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
    public function renderDebugTrace($trace = null, $start = 0, $limit = 10, $lineExclude = '')
    {
        if (is_string($trace)) return $trace;
        if (!$trace) $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, $limit + 3);
        $res = '';

        foreach ($trace as $i => $row) {
            if ($i < $start) continue;
            if (is_array($row) && (!empty($row['file']) || !empty($row['function']))) {
                if (self::checkTraceExclude($row, $lineExclude)) continue;
                $args = '';
                if (!empty($row['args'])) {
                    $args = implode(', ', self::renderDebugArray($row['args']));
                }
                $res .= '#' . $i . ' ' . (!empty($row['file']) ? $this->getRelativeFilePath($row['file']) . ':' . $row['line'] . ' ' : '')
                    . (!empty($row['class']) ? $row['class'] . '::' : '')
                    . (!empty($row['function']) ? $row['function'] . '(' . $args . ')' : '') . PHP_EOL;
            } else {
                $res .= mb_substr('@ ' . (is_string($row) ? $row : json_encode($row, JSON_UNESCAPED_UNICODE)), 0, 1024) . PHP_EOL;
            }
            if ($i >= $limit) break;
        }
        return $res;
    }


    public function getFileLineByTrace($start = 2, $lineExclude = null)
    {
        $res = '';
        foreach (array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6), $start) as $row) {
            if (self::checkTraceExclude($row, $lineExclude))
                continue;
            if (!empty($row['file']))
                return $this->getRelativeFilePath($row['file']) . ':' . $row['line'];
            return (!empty($row['class']) ? $row['class'] . '::' : '')
                . (!empty($row['function']) ? $row['function'] . '()' : '');
        }
        return '-';
    }

    public function getRelativeFilePath($file)
    {
        return str_replace($this->dirRoot, '.', $file);
    }

    public function getRawLogFile()
    {
        return $this->dirRoot . '/raw.' . storage\FileStorage::FILE_EXT;
    }

    public static function renderVars($vars, $sl = 0)
    {
        $limitString = static::init()->limitString;
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

        } elseif (is_resource($vars)) {
            $vars = 'RESOURCE: ' . get_resource_type($vars);

        } elseif (null === $vars) {
            $vars = 'NULL';
        } elseif (!is_int($vars)) {
            $vars = gettype($vars) . ': "' . static::_e(var_export($vars, true)) . '"';
        }
        $vars .= PHP_EOL;

        return $slr . $vars;
    }

    public function getLogDataFromMemcache($key)
    {
        $raw = json_decode($this->memcache()->get($key), true);
        $logData = new LogData;
        if (!is_array($raw)) {
            echo 'LOG: ' . $this->memcache()->get($key) . PHP_EOL;
            return null;
        }
        foreach ($raw as $prop => $val) {
            $logData->{$prop} = $val;
        }
        return $logData;
    }

    /***************************************************/

    public function emergency($message, array $tags = [], array $fields = [])
    {
        $level = self::LEVEL_CRITICAL;
        $tags[] = 'emergency';
        return $this->log($level, $message, $tags, $fields);
    }

    public function alert($message, array $tags = [], array $fields = [])
    {
        $level = self::LEVEL_CRITICAL;
        $tags[] = 'alert';
        return $this->log($level, $message, $tags, $fields);
    }

    public function critical($message, array $tags = [], array $fields = [])
    {
        $level = self::LEVEL_CRITICAL;
        return $this->log($level, $message, $tags, $fields);
    }

    public function error($message, array $tags = [], array $fields = [])
    {
        $level = self::LEVEL_ERROR;
        return $this->log($level, $message, $tags, $fields);
    }

    public function warning($message, array $tags = [], array $fields = [])
    {
        $level = self::LEVEL_WARNING;
        return $this->log($level, $message, $tags, $fields);
    }

    public function notice($message, array $tags = [], array $fields = [])
    {
        $level = self::LEVEL_NOTICE;
        return $this->log($level, $message, $tags, $fields);
    }

    public function info($message, array $tags = [], array $fields = [])
    {
        $level = self::LEVEL_INFO;
        return $this->log($level, $message, $tags, $fields);
    }

    public function debug($message, array $tags = [], array $fields = [])
    {
        if (!$this->debugMode) return true;
        $level = self::LEVEL_DEBUG;
        return $this->log($level, $message, $tags, $fields);
    }

    public static function logger($level, $message, $tags = [], array $fields = [])
    {
        return static::init()->log($level, $message, $tags, $fields);
    }

    protected static $_byteMemoryLimit;

    public static function isMemoryOver()
    {
        $limitMemory = ini_get('memory_limit');
        if ($limitMemory > 0) {
            if (!self::$_byteMemoryLimit)
                self::$_byteMemoryLimit = (int)$limitMemory * (strpos($limitMemory, 'K') ? 1024 : (strpos($limitMemory, 'M') ? 1024 * 1024 : (strpos($limitMemory, 'G') ? 1024 * 1024 * 1024 : 1))) * 0.9;
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
    public function log($level, $msg, array $tags = [], array $fields = [])
    {
        if (!isset($fields[self::FIELD_LOG_TYPE])) $fields[self::FIELD_LOG_TYPE] = self::TYPE_LOGGER;

        if (count($this->ignoreRules)) {
            foreach ($this->ignoreRules as $rule) {
                $i = count($rule);
                if (!empty($rule['level']) && $rule['level'] == $level) $i--;
                if (!empty($rule['type']) && $rule['type'] == $fields[self::FIELD_LOG_TYPE]) $i--;
                if (!empty($rule['message']) && is_string($msg) && $rule['message'] == $msg) $i--;
                if ($i == 0) return true;
            }
        }

        if (isset(self::$_errorLevel[$level])) {
            $this->_errCount++;
        }
        $this->_count++;

        if ($this->_count == 1000) {
            //TODO: cli print
            echo '<p>To many Logs</p>';
        } elseif ($this->_count > 100) {
            // TODO: print in cli mode
            return;
        }

        if (is_object($msg)) {
            // если это эксепшн и другие подобные объекты
            [$msg, $fields2] = $this->getLogFromObject($msg);
            if ($fields2) $fields = array_merge($fields, $fields2);
        } elseif (!is_string($msg)) {
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

        if (isset($this->logTraceByLevel[$level]) && empty($fields[self::FIELD_NO_TRICE])) {
            $logData->trace = $this->renderDebugTrace(isset($fields[self::FIELD_TRICE]) ? $fields[self::FIELD_TRICE] : null, 0, (int)$this->logTraceByLevel[$level]);
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
        $logData->timestamp = microtime(true);
        $logData->logKey = 'phpeErCh_' . $this->getSessionKey() . '_' . md5($logData->message . $logData->file);

        $this->add($logData);

        return true;
    }

    static function safe_json_encode($value, $options = 0, $depth = 512)
    {
        $encoded = json_encode($value, $options, $depth);
        switch (json_last_error()) {
            case JSON_ERROR_NONE:
                return $encoded;
            case JSON_ERROR_DEPTH:
                throw new \Exception('json_encode: Maximum stack depth exceeded');
            case JSON_ERROR_STATE_MISMATCH:
                throw new \Exception('json_encode: Underflow or the modes mismatch');
            case JSON_ERROR_CTRL_CHAR:
                throw new \Exception('json_encode: Unexpected control character found');
            case JSON_ERROR_SYNTAX:
                throw new \Exception('json_encode: Syntax error, malformed JSON');
            case JSON_ERROR_UTF8:
                return self::safe_json_encode(self::utf8ize($value), $options, $depth);
            default:
                throw new \Exception('json_encode: Unknown error');
        }
    }

    static function utf8ize($mixed)
    {
        if (is_array($mixed)) {
            foreach ($mixed as $key => $value) {
                $mixed[$key] = self::utf8ize($value);
            }
        } else if (is_string($mixed)) {
            $mixed = mb_convert_encoding($mixed, 'UTF-8');
        }
        return $mixed;
    }
}

class LogData
{
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

    public function __toString()
    {
        $data = [];
        foreach (get_object_vars($this) as $k => $v) {
            $data[$k] = $v;
        }
        return PHPErrorCatcher::safe_json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}

class HttpData
{
    public $ip_addr;
    public $host;
    public $method;
    public $url;
    public $referrer;
    public $scheme;
    public $user_agent;
    public $overMemory;
}
