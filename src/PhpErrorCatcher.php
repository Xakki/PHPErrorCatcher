<?php

namespace Xakki\PhpErrorCatcher;

use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\viewer\BaseViewer;

class PhpErrorCatcher implements \Psr\Log\LoggerInterface
{
    const VERSION = '0.6.0';

    const LEVEL_DEBUG = 'debug',
        LEVEL_TIME = 'time',
        LEVEL_INFO = 'info',
        LEVEL_NOTICE = 'notice',
        LEVEL_WARNING = 'warning',
        LEVEL_ERROR = 'error',
        LEVEL_CRITICAL = 'critical',
        LEVEL_ALERT = 'alert';

    const TYPE_LOGGER = 'logger',
        TYPE_TRIGGER = 'trigger',
        TYPE_EXCEPTION = 'exception',
        TYPE_FATAL = 'fatal';

    const FIELD_LOG_TYPE = 'log_type',
        FIELD_FILE = 'file',
        FIELD_TAG = 'tag',
        FIELD_TRICE = 'trice',
        FIELD_NO_TRICE = 'trice_no_fake',
        FIELD_ERR_CODE = 'error_code';

    /** @var string[] */
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

    /** @var string[] */
    protected static $logLevel = [
        LOG_EMERG => self::LEVEL_ALERT,
        LOG_ALERT => self::LEVEL_ALERT,
        LOG_CRIT => self::LEVEL_CRITICAL,
        LOG_ERR => self::LEVEL_ERROR,
        LOG_WARNING => self::LEVEL_WARNING,
        LOG_NOTICE => self::LEVEL_NOTICE,
        LOG_INFO => self::LEVEL_INFO,
        LOG_DEBUG => self::LEVEL_DEBUG,
    ];


    /************************************/
    // Config
    /************************************/

    /** @var bool */
    public $debugMode = false;//ERROR_DEBUG_MODE
    /** @var bool */
    public $enableSessionLog = false;
    /** @var string */
    public $globalTag = '';
    /** @var bool */
    public $isFullTrace = false;
    /** @var bool */
    protected $logTimeProfiler = false;// time execute log
    /** @var string */
    protected $logCookieKey = '';
    /** @var string */
    protected $dirRoot = '';
    /** @var bool */
    protected $showFullPath = true;
    /** @var int */
    protected $limitTrace = 10;
    /** @var int */
    protected $limitString = 1024;
    /** @var bool */
    protected $enableCookieLog = false;
    /** @var bool */
    protected $enablePostLog = true;
    /**
     * @var null|callable
     */
    protected $errorCallback = null;

    /**
     * @var string[]
     */
    protected $safeParamsKey = [
        'password',
        'input_password',
        'pass',
    ];

    /** @var int[] */
    protected $logTraceByLevel = [
        self::LEVEL_CRITICAL => 10, // trace deep level
        self::LEVEL_ERROR => 7,
        self::LEVEL_WARNING => 5,
    ];
    /** @var string[] */
    protected $saveLogIfHasError = [
        self::LEVEL_WARNING, self::LEVEL_ERROR, self::LEVEL_CRITICAL, self::LEVEL_ALERT
    ];
    /** @var array[] */
    protected $ignoreRules = [
        //        ['level' => self::LEVEL_NOTICE, 'type' => self::TYPE_TRIGGER]
    ];
    /** @var array[] */
    protected $stopRules = [
        //        ['level' => self::LEVEL_NOTICE, 'type' => self::TYPE_TRIGGER]
    ];
    /**
     * @var string[]|\Memcached
     */
    protected $cacheServers = [
        ['localhost', 11211],
    ];
    /** @var string */
    protected $memcacheId = 'phperr';
    /** @var float|int */
    protected $lifeTime = 600;//sec // ini_get('max_execution_time')
    /** @var array */
    protected $logFields = [];
    /************************************/
    // Variable
    /************************************/
    /** @var int */
    protected $_count = 0;
    /** @var int[] */
    protected $countByLevel = [];
    /** @var ?float */
    protected $_time_start = null;
    /** @var ?float */
    protected $_time_end = null;
    /** @var bool */
    protected $_isViewMode = false;
    /** @var ?string */
    protected $_sessionKey = null;
    /** @var bool */
    protected $_hasError = false;
    /** @var ?array */
    protected $_postData = null;
    /** @var ?array */
    protected $_cookieData = null;
    /** @var ?array */
    protected $_sessionData = null;
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
    /** @var bool */
    protected $_successSaveLog = false;

    /**
     * @var \Memcached|null
     */
    protected static $_MEMCACHE = null;
    /** @var string[] */
    protected static $_viewAlert = [];
    /** @var ?int */
    protected static $_functionTimeOut = null;
    /** @var ?float */
    protected static $_functionStartTime;
    /** @var LogData[] */
    protected static $_userCatchLogKeys = [];
    /** @var bool */
    protected static $_userCatchLogFlag = false;
    /** @var int[] */
    protected static $_errorLevel = [
        self::LEVEL_CRITICAL => 1,
        self::LEVEL_ERROR => 1,
        self::LEVEL_WARNING => 1,
    ];

    /*******************************/

    /**
     * @var static
     */
    protected static $_obj;

    /**
     * @param array $config
     * @return PhpErrorCatcher
     */
    public static function init($config = [])
    {
        if (!static::$_obj) {
            new self($config);
        }
        return static::$_obj;
    }
    /** @var array */
    protected $_configOrigin = [];

    /**
     * @param array $config
     */
    public function __construct($config = [])
    {
        static::$_obj = $this;
        try {
            $this->_configOrigin = $config;
            if (empty($config['storage'])) {
                throw new \Exception('Storages config is require');
            }

            $this->applyConfig($config);
            $this->_time_start = microtime(true);
            if (!$this->dirRoot) {
                $this->dirRoot = $_SERVER['DOCUMENT_ROOT'];
            }
            if (ini_get('max_execution_time')) {
                $this->lifeTime = (int) ini_get('max_execution_time') * 2;
            }

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

            if (!empty($config['plugin'])) {
                $this->initPlugins($config['plugin']);
            }

            if (!empty($config['viewer'])) {
                $this->initViewer($config['viewer']);
            }
        } catch (\Exception $e) {
            if ($this->debugMode) {
                echo 'Cant init logger: ' . $e->__toString();
                exit('ERR');
            }
        }
    }

    /**
     * @return void
     */
    public function __destruct()
    {
        $this->_plugins = [];
        $this->_storages = [];
    }

    /**
     * @param array $config
     */
    protected function applyConfig($config)
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key) && substr($key, 0, 1) != '_') {
                $this->$key = $value;
            }
        }
    }

    /**
     * @param string $key
     * @param mixed $value
     * @deprecated
     */
    public static function setConfig($key, $value)
    {
        if (property_exists(static::$_obj, $key) && substr($key, 0, 1) != '_') {
            static::$_obj->{$key} = $value;
        }
    }

    /**
     * @param string $key
     * @return mixed|null
     * @deprecated
     */
    public function get($key)
    {
        if (property_exists($this, $key)) {
            return $this->{$key};
        }
        return null;
    }

    /**
     * @return viewer\BaseViewer|null
     */
    public function getViewer()
    {
        return $this->_viewer;
    }

    /**
     * @param string $class
     * @return storage\BaseStorage|null
     */
    public function getStorage($class)
    {
        return (isset($this->_storages[$class]) ? $this->_storages[$class] : null);
    }

    /**
     * @return storage\BaseStorage[]
     */
    public function getStorages()
    {
        return $this->_storages;
    }

//    /**
//     * @return \Generator|LogData[]
//     * @throws \Exception
//     */
//    public function getDataLogsGenerator()
//    {
//        foreach ($this->_logData as $key => $logData) {
//            if (self::$_userCatchLogFlag && isset(self::$_userCatchLogKeys[$key])) {
//                continue;
//            }
//            if (!is_object($logData)) {
//                $logData = $this->getLogDataFromMemcache($key);
//                if (!$logData) {
//                    // cache expired
//                    continue;
//                }
//            }
//            yield $logData;
//        }
//    }

    /**
     * @param string $tag
     * @return void
     */
    public function setGlobalTag($tag)
    {
        $this->globalTag = $tag;
    }

    /**
     * @return string
     */
    public function getGlobalTag()
    {
        return $this->globalTag;
    }

    /**
     * @param bool $restore
     * @return false|\Memcached|null
     */
    public function memcache($restore = false)
    {
        static $mem;
        if (is_null($mem)) {
            $mem = false;
            if ($this->cacheServers instanceof \Memcached) {
                $mem = $this->cacheServers;
            } elseif (extension_loaded('memcached')) {
                $mem = new \Memcached($this->memcacheId);
                $mem->setOption(\Memcached::OPT_PREFIX_KEY, 'logger');
                //$mem->setOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
                $mem->setOption(\Memcached::OPT_NO_BLOCK, true);
                $mem->setOption(\Memcached::OPT_CONNECT_TIMEOUT, 4);

                foreach ($this->cacheServers as $serverStr) {
                    $server = explode(':', $serverStr);
                    if (!$mem->addServer($server[0], (int) $server[1])) {
                        trigger_error('Cant connect to memcached: ' . $serverStr, E_USER_WARNING);
                        return static::$_MEMCACHE;
                    }
                }
            }
        }
        return $mem;
    }

    /**
     * @return void
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
                self::FIELD_TAG => 'unhandle'
            ];
            $this->log(self::$triggerLevel[$error['type']], $error['message'], $fields);
        }

        if ($this->logTimeProfiler) {
            $this->log(self::LEVEL_TIME, $this->_time_end, [self::FIELD_TAG => 'execution']);
        }

        if (self::$_userCatchLogFlag) {
            $this->log(self::LEVEL_WARNING, 'Miss flush userCatchLog');
            self::$_userCatchLogFlag = false;
        }
    }

    /**
     * @return bool
     */
    public function needSaveLog()
    {
        if ($this->debugMode) {
            return true;
        }
        return $this->getCountByLevelAndLess($this->saveLogIfHasError) > 0;
    }

    /**
     * @param \Exception $e
     */
    public function handleException($e)
    {
        $fields = [
            self::FIELD_LOG_TYPE => self::TYPE_EXCEPTION,
            self::FIELD_TAG => 'unhandle'
        ];
        $this->log(self::LEVEL_CRITICAL, $e, $fields);
    }

    /**
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     * @param array|null $vars
     * @return bool
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

        $this->log(self::$triggerLevel[$errno], $errstr, $fields);

        if (function_exists('error_clear_last')) {
            \error_clear_last();
        }

        return true; /* Не запускаем внутренний обработчик ошибок PHP */
    }

    /****************************************************/

    /**
     * @param array $configs
     * @throws \Exception
     */
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

    /**
     * @param array $configs
     * @throws \Exception
     */
    protected function initPlugins($configs)
    {
        foreach ($configs as $plugin => $config) {
            if (!empty($config['initGetKey']) && !isset($_GET[$config['initGetKey']])) {
                continue;
            }

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

    /**
     * @param array $config
     * @return viewer\BaseViewer|void
     * @throws \Exception
     */
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
            $html = '';
            try {
                $html = $this->getViewer()
                    ->renderView();
                $html .= ob_get_contents();
            } catch (\Exception $e) {
                $html .= $e;
            }

            ob_end_clean();
            echo $html;
            exit();
        }

        return $this->_viewer;
    }

    /**
     * @return string
     */
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

    /**
     * @param LogData $logData
     * @return bool
     */
    protected function ifStopRules(LogData $logData)
    {
        if (count($this->stopRules)) {
            foreach ($this->stopRules as $rule) {
                $i = count($rule);
                if (!empty($rule['level']) && $rule['level'] == $logData->level) {
                    $i--;
                }
                if (!empty($rule['type']) && $rule['type'] == $logData->type) {
                    $i--;
                }
                if (!empty($rule['message']) && $rule['message'] == $logData->message) {
                    $i--;
                }
                if ($i == 0) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function isAjax()
    {
        if (!empty($_SERVER['is_json']))
            return true;
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
            return true;
        }
        if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            return true;
        }
        return false;
    }

    /**
     * @param LogData $logData
     */
    protected function add(LogData $logData)
    {
        if ($this->debugMode && !empty($_SERVER['argv'])) {
            $this->consolePrint($logData);
        } elseif ($this->debugMode && !self::isAjax()) {
            $this->htmlPrint($logData);
        }

        $key = $logData->logKey;

        if ($this->ifStopRules($logData)) {
// TODO: option & cli print
//                    echo PHP_EOL;
//                    print_r($logData);
//                    echo PHP_EOL;
            exit('Fatal error happen!');
        }

        if (self::$_userCatchLogFlag) {
            self::$_userCatchLogKeys[$key] = $logData;
        } else {
            foreach ($this->_storages as $store) {
                $store->write($logData);
            }
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

    /**
     * @param string $text
     * @param string $colorId
     * @return string
     */
    private static function cliColor($text, $colorId)
    {
        if (!isset($_SERVER['TERM'])) {
            return $text;
        }
        return "\033[" . $colorId . "m" . $text . "\033[0m";
    }

    /**
     * @param LogData $logData
     */
    protected function consolePrint(LogData $logData)
    {
        if ($logData->level === self::LEVEL_DEBUG && !$this->debugMode) {
            return;
        }

        $dt = \DateTime::createFromFormat('U.u', (string) $logData->microtime);
        if ($dt) {
            $dt = rtrim($dt->format('H:i:s.u'), '0');
        } else {
            $dt = '!!!';
        }
        $output = PHP_EOL . $dt
            . ' ' . self::cliColor($logData->level, self::CLI_LEVEL_COLOR[$logData->level]);

        if (isset(self::$_errorLevel[$logData->level])) {
            if ($logData->fields) {
                $output .= ' ' . self::cliColor(json_encode($logData->fields), self::COLOR_GRAY);
            }
            if ($logData->file) {
                $output .= PHP_EOL . "\t" . $logData->file;
            }
        }
        $output .= PHP_EOL . "\t" . str_replace(PHP_EOL, "\n\t", self::cliColor($logData->message, self::COLOR_GRAY2)) . PHP_EOL;

        if (isset($_SERVER['TERM']) && is_resource(\STDERR)) {
            fwrite(\STDERR, $output); // Ошибки в консоли можно залогировать толкьо так `&2 >>`
        } elseif (is_resource(\STDOUT)) {
            // Для кронов, чтобы все логи по умолчанию выводились дефолтно черезе `>>`
            fwrite(\STDOUT, $output);
        }
    }

    /**
     * @param LogData $logData
     */
    protected function htmlPrint(LogData $logData)
    {
        if (isset(self::$_errorLevel[$logData->level]) && $this->getViewer() instanceof BaseViewer) {
            echo $this->getViewer()->renderItemLog($logData);
        }
    }

    /**
     * @param object $object
     * @return array
     */
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

    /**
     * @param array $row
     * @param string|null $lineExclude
     * @return bool
     */
    protected static function checkTraceExclude($row, $lineExclude = null)
    {
        if ($lineExclude) {
            if (!empty($row['file']) && strpos($row['file'], $lineExclude) !== false) {
                return true;
            }
            //            if (!empty($row['class']) && strpos($row['class'], $lineExclude)!==false)
            //                return true;
        }
        if (!empty($row['file']) && strpos($row['file'], 'PhpErrorCatcher') !== false) {
            return true;
        }
        return false;
    }

    /*****************************************************/
    /*****************************************************/

    /**
     * @param string $tag
     */
    public static function addGlobalTag($tag)
    {
        self::init()->logFields[self::FIELD_TAG] = $tag;
    }

    /**
     * @param array $tags
     * @deprecated use only addGlobalTag
     */
    public static function addGlobalTags(array $tags)
    {
    }

    /**
     * @param string $key
     * @param mixed $val
     */
    public static function addGlobalField($key, $val)
    {
        self::init()->logFields[$key] = $val;
    }


    /**
     * @param string $mess
     * @return void
     */
    public static function setViewAlert($mess)
    {
        self::$_viewAlert[] = $mess;
    }

    /**
     * @param string[] $levels
     * @return int
     */
    public function getCountByLevelAndLess($levels)
    {
        $cnt = 0;
        foreach ($levels as $level) {
            $cnt += isset($this->countByLevel[$level]) ? $this->countByLevel[$level] : 0;
        }
        return $cnt;
    }

    /**
     * @return ?string
     */
    public function getHighLevelLogs()
    {
        for ($i = LOG_EMERG; $i <= LOG_DEBUG; $i++) {
            if (isset($this->countByLevel[self::$logLevel[$i]])) {
                return self::$logLevel[$i];
            }
        }
        return null;
    }

    /**
     * @return void
     */
    public static function startCatchLog()
    {
        self::$_userCatchLogFlag = true;
    }

    /**
     * @return void
     */
    public static function flushCatchLog()
    {
        // TODO
        //        $log = '';//self::init()->getRenderLogs();
        //        try {
        //            foreach (self::init()->getDataLogsGenerator() as $key => $logData) {
        //                //$logs .= $this->renderItemLog($logData);
        //            }
        //        } catch (\Exception $e) {
        //            $logs .= '<hr/><pre>'.$e->__toString().'</pre>!!!!';
        //        }
        //        self::$_userCatchLogFlag = false;
        //        return $log;
    }

//    public static function getRenderLogs()
//    {
//        $logger = self::init();
//        return $logger->getViewer()
//            ->renderAllLogs(storage\FileStorage::getDataHttp(), $logger->getDataLogsGenerator());
//
//    }

    /**
     * @return void
     */
    public static function funcStart()
    {
        static::$_functionStartTime = microtime(true);
//        static::$_functionTimeOut = $timeOut;
    }

    /**
     * @return float
     */
    public static function funcEnd()
    {
        // TODO
        $timer = (microtime(true) - static::$_functionStartTime) * 1000;
        self::funcStart();
        return $timer;
//        if (static::$_functionTimeOut && $timer < static::$_functionTimeOut) {
//            return null;
//        }
//        return static::$_obj->saveStatsProfiler($name, $info, $simple);
    }

    /** @var array|null */
    protected static $tick;

    /**
     * @param int $slice
     * @param int $limit
     */
    public static function tickTrace($slice = 1, $limit = 3)
    {
        // TODO
        self::$tick = array_slice(debug_backtrace(), $slice, $limit);
    }

    /**
     * @param string $value
     * @return string
     * @throws \Exception
     */
    public static function esc($value)
    {
        if (!is_string($value)) {
            $value = self::safe_json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return htmlspecialchars((string)$value, ENT_NOQUOTES | ENT_IGNORE, 'utf-8');
    }

    /**
     * @param mixed $arg
     * @return mixed
     */
    public static function arrayToLower($arg)
    {
        if (is_object($arg)) {
            return get_class($arg);
        }
        if (is_array($arg)) {
            $arg = array_change_key_case($arg, CASE_LOWER);
            $arg = array_map([self::class, 'arrayToLower'], $arg);
            return $arg;
        } elseif (is_string($arg)) {
            return mb_strtolower($arg);
        }
        return $arg;
    }

    /**
     * @param array $arr
     * @param int $arrLen
     * @param int $strLen
     * @return array|string
     */
    public static function renderDebugArray($arr, $arrLen = 6, $strLen = 256)
    {
        if (!is_array($arr)) {
            return $arr;
        }
        $i = $arrLen;
        $args = [];

        foreach ($arr as $k => $v) {
            if ($i == 0) {
                $args[] = '...' . (count($arr) - 6) . ']';
                break;
            }
            $prf = '';
            if (!is_numeric($k)) {
                $prf = $k . ':';
            }
            if (is_null($v)) {
                $args[] = $prf . 'NULL';
            } elseif (is_array($v)) {
                $args[] = $prf . '[' . implode(', ', self::renderDebugArray($v, $arrLen, $strLen)) . ']';
            } elseif (is_object($v)) {
                $args[] = $prf . get_class($v);
            } elseif (is_bool($v)) {
                $args[] = $prf . ($v ? 'T' : 'F');
            } elseif (is_resource($v)) {
                $args[] = $prf . 'RESOURCE';
            } elseif (is_numeric($v)) {
                $args[] = $prf . $v;
            } elseif (is_string($v)) {
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

    /**
     * @return void
     */
    public function setSafeParams()
    {
        if ($this->_postData !== null) {
            return;
        }
        $this->_postData = [];

        if ($this->enablePostLog) {
            if ($_POST) {
                foreach ($_POST as $k => $p) {
                    $size = mb_strlen(serialize((array)$this->_postData[$k]), '8bit');
                    if ($size > 1024) {
                        $this->_postData[$k] = '...(' . $size . 'b)...';
                    } else {
                        $this->_postData[$k] = $p;
                    }
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
     * @param array|string|null $trace
     * @param int $start
     * @param int $limit
     * @param string $lineExclude
     * @return string
     */
    public function renderDebugTrace($trace = null, $start = 0, $limit = 10, $lineExclude = '')
    {
        if (is_string($trace)) {
            return $trace;
        }
        if (!$trace) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, $limit + 3);
        }
        $res = '';

        foreach ($trace as $i => $row) {
            if ($i < $start) {
                continue;
            }
            if (is_array($row) && (!empty($row['file']) || !empty($row['function']))) {
                if (self::checkTraceExclude($row, $lineExclude)) {
                    continue;
                }
                $res .= '#' . $i . ' ' . (!empty($row['file']) ? $this->getRelativeFilePath($row['file']) . ':' . $row['line'] . ' ' : '');

                if ($this->isFullTrace) {
                    $args = '';
                    if ($this->debugMode && !empty($row['args'])) {
                        $args = implode(', ', self::renderDebugArray($row['args']));
                    }
                    $res .= (!empty($row['class']) ? $row['class'] . '::' : '')
                        . (!empty($row['function']) ? $row['function'] . '(' . $args . ')' : '');
                }
                $res .= PHP_EOL;
            } else {
                $res .= mb_substr('@ ' . (is_string($row) ? $row : json_encode($row, JSON_UNESCAPED_UNICODE)), 0, 1024) . PHP_EOL;
            }
            if ($i >= $limit) {
                break;
            }
        }
        return $res;
    }


    /**
     * @param int $start
     * @param string|null $lineExclude
     * @return string
     */
    public function getFileLineByTrace($start = 2, $lineExclude = null)
    {
        $res = '';
        foreach (array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6), $start) as $row) {
            if (self::checkTraceExclude($row, $lineExclude)) {
                continue;
            }
            if (!empty($row['file'])) {
                return $this->getRelativeFilePath($row['file']) . ':' . $row['line'];
            }
            return (!empty($row['class']) ? $row['class'] . '::' : '')
                . (!empty($row['function']) ? $row['function'] . '()' : '');
        }
        return '-';
    }

    /**
     * @param string $file
     * @return string
     */
    public function getRelativeFilePath($file)
    {
        if ($this->showFullPath) {
            return $file;
        }
        return str_replace($this->dirRoot, '.', $file);
    }

    /**
     * @return string
     */
    public function getRawLogFile()
    {
        return $this->dirRoot . '/raw.' . storage\FileStorage::FILE_EXT;
    }

    /**
     * @param mixed $var
     * @param int $depth
     * @param bool $highlight
     * @return string
     */
    public static function renderVars($var, $depth = 3, $highlight = false)
    {
        return self::dumpAsString($var, $depth = 3);
    }

    /** @var array */
    private static $objects = [];

    /**
     * @param mixed $var
     * @param int $depth
     * @param bool $highlight
     * @return string
     */
    public static function dumpAsString($var, $depth = 3, $highlight = false)
    {
        $output = self::dumpInternal($var, $depth, 0);
        if ($highlight) {
            $result = highlight_string("<?php\n" . $output, true);
            $output = preg_replace('/&lt;\\?php<br \\/>/', '', $result, 1);
        }
        self::$objects = [];
        return $output;
    }

    /**
     * @param mixed $var
     * @param int $depth
     * @param int $level
     * @return string
     * @throws \Exception
     */
    private static function dumpInternal($var, $depth, $level = 0)
    {
        $output = '';
        switch (gettype($var)) {
            case 'boolean':
                $output .= $var ? 'T' : 'F';
                break;
            case 'integer':
            case 'double':
                $output .= (string)$var;
                break;
            case 'string':
                $limitString = static::init()->limitString;
                $size = mb_strlen($var);
                if ($size > $limitString) {
                    $output = '"' . static::esc(mb_substr($var, 0, $limitString)) . '...(' . $size . 'b)"';
                } else {
                    $output = '"' . static::esc($var) . '"';
                }
                break;
            case 'resource':
                $output .= '{resource}';
                break;
            case 'NULL':
                $output .= 'null';
                break;
            case 'unknown type':
                $output .= '{unknown}';
                break;
            case 'array':
                if ($depth <= $level) {
                    $output .= '[...]';
                } elseif (empty($var)) {
                    $output .= '[]';
                } else {
                    $spaces = str_repeat(' ', $level * 4);
                    $output .= '[';
                    foreach ($var as $key => $val) {
                        $output .= "\n" . $spaces . '    ';
                        $output .= self::dumpInternal($key, $depth, 0);
                        $output .= ' => ';
                        $output .= self::dumpInternal($val, $depth, $level + 1);
                    }
                    $output .= "\n" . $spaces . ']';
                }
                break;
            case 'object':
                $id = array_search($var, self::$objects, true);
                if ($id !== false) {
                    $output .= get_class($var) . '#' . ($id + 1) . '(...)';
                } elseif ($depth <= $level) {
                    $output .= get_class($var) . '(...)';
                } else {
                    $id = array_push(self::$objects, $var);
                    $className = get_class($var);
                    $spaces = str_repeat(' ', $level * 4);
                    $output .= "$className#$id\n" . $spaces . '(';
                    if ('__PHP_Incomplete_Class' !== get_class($var) && method_exists($var, '__debugInfo')) {
                        $dumpValues = $var->__debugInfo();
                        if (!is_array($dumpValues)) {
                            throw new \Exception('__debuginfo() must return an array');
                        }
                    } else {
                        $dumpValues = (array) $var;
                    }
                    foreach ($dumpValues as $key => $value) {
                        $keyDisplay = strtr(trim($key), "\0", ':');
                        $output .= "\n" . $spaces . "    [$keyDisplay] => ";
                        $output .= self::dumpInternal($value, $depth, $level + 1);
                    }
                    $output .= "\n" . $spaces . ')';
                }
                break;
        }
        return $output;
    }

    /**
     * @param string $key
     * @return LogData|null
     */
    public function getLogDataFromMemcache($key)
    {
        $raw = $this->memcache()->get($key);
        $obj = json_decode($raw, true);
        $logData = new LogData();
        if (!is_array($obj)) {
            echo 'Error: ' . $key . ' : ' . $raw . PHP_EOL;
            return null;
        }
        foreach ($obj as $prop => $val) {
            $logData->{$prop} = $val;
        }
        return $logData;
    }

    /***************************************************/

    /**
     * @inheritDoc
     */
    public function emergency($message, array $context = [])
    {
        $level = self::LEVEL_ALERT;
        $context[self::FIELD_TAG] = 'emergency';
        $this->log($level, $message, $context);
    }

    /**
     * @inheritDoc
     */
    public function alert($message, array $context = [])
    {
        $level = self::LEVEL_ALERT;
        $context[self::FIELD_TAG] = 'alert';
        $this->log($level, $message, $context);
    }

    /**
     * @inheritDoc
     */
    public function critical($message, array $context = [])
    {
        $level = self::LEVEL_CRITICAL;
        $this->log($level, $message, $context);
    }

    /**
     * @inheritDoc
     */
    public function error($message, array $context = [])
    {
        $level = self::LEVEL_ERROR;
        $this->log($level, $message, $context);
    }

    /**
     * @inheritDoc
     */
    public function warning($message, array $context = [])
    {
        $level = self::LEVEL_WARNING;
        $this->log($level, $message, $context);
    }

    /**
     * @inheritDoc
     */
    public function notice($message, array $context = [])
    {
        $level = self::LEVEL_NOTICE;
        $this->log($level, $message, $context);
    }

    /**
     * @inheritDoc
     */
    public function info($message, array $context = [])
    {
        $level = self::LEVEL_INFO;
        $this->log($level, $message, $context);
    }

    /**
     * @inheritDoc
     */
    public function debug($message, array $context = [])
    {
        $level = self::LEVEL_DEBUG;
        $this->log($level, $message, $context);
    }

    /**
     * @param string $level
     * @param mixed $message
     * @param array $context
     * @return void
     */
    public static function logger($level, $message, $context = [])
    {
        static::init()->log($level, $message, $context);
    }

    /**
     * @var ?int
     */
    protected static $_byteMemoryLimit;

    /**
     * @return bool
     */
    public static function isMemoryOver()
    {
        $limitMemory = ini_get('memory_limit');
        if ($limitMemory > 0) {
            if (!self::$_byteMemoryLimit) {
                self::$_byteMemoryLimit = (int) ($limitMemory * (strpos($limitMemory, 'K') ? 1024 : (strpos($limitMemory, 'M') ? 1024 * 1024 : (strpos($limitMemory, 'G') ? 1024 * 1024 * 1024 : 1))) * 0.9);
            }
            return memory_get_usage() >= self::$_byteMemoryLimit;
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function log($level, $message, array $context = [])
    {
        if (!$this->debugMode && $level === self::LEVEL_DEBUG) {
            return;
        }
        foreach($context as $k => &$v) {
            if (!is_string($k)) {
                $context[self::FIELD_TAG] = $v;
                unset($context[$k]);
            }
        }
        if (!isset($context[self::FIELD_LOG_TYPE])) {
            $context[self::FIELD_LOG_TYPE] = self::TYPE_LOGGER;
        }

        if (count($this->ignoreRules)) {
            foreach ($this->ignoreRules as $rule) {
                $i = count($rule);
                if (!empty($rule['level']) && $rule['level'] == $level) {
                    $i--;
                }
                if (!empty($rule['type']) && $rule['type'] == $context[self::FIELD_LOG_TYPE]) {
                    $i--;
                }
                if (!empty($rule['message']) && is_string($message) && $rule['message'] == $message) {
                    $i--;
                }
                if ($i == 0) {
                    return;
                }
            }
        }

        if (isset(self::$_errorLevel[$level])) {
            if (!isset($this->countByLevel[$level])) {
                $this->countByLevel[$level] = 0;
            }
            $this->countByLevel[$level]++;
        }
        $this->_count++;

        if ($this->_count == 1000) {
            //TODO: cli print
            echo '<p>To many Logs</p>';
        } elseif ($this->_count > 100) {
            // TODO: print in cli mode
            return;
        }

        if (is_object($message)) {
            // если это эксепшн и другие подобные объекты
            list($message, $context2) = $this->getLogFromObject($message);
            if ($context2) {
                $context = array_merge($context, $context2);
            }
        } elseif (!is_string($message)) {
            $message = static::renderVars($message);
        }

        $logData = new LogData();

        if ($this->debugMode) {
            $logData->message = mb_substr($message, 0, 10000);
        } else {
            $logData->message = mb_substr($message, 0, 8000);
        }

        $logData->level = $level;
        $logData->levelInt = array_search($level, self::$logLevel);

        if (count($this->logFields)) {
            $context = array_merge($this->logFields, $context);
        }

        if ($this->logCookieKey && !empty($_COOKIE[$this->logCookieKey])) {
            // для поиска только нужных логов
            $context['logCookieKey'] = $_COOKIE[$this->logCookieKey];
        }

        if (isset($context[self::FIELD_TRICE]) && is_string($context[self::FIELD_TRICE])) {
            $logData->trace = $context[self::FIELD_TRICE];
        } elseif (isset($this->logTraceByLevel[$level]) && empty($context[self::FIELD_NO_TRICE])) {
            $logData->trace = $this->renderDebugTrace(
                isset($context[self::FIELD_TRICE]) ? $context[self::FIELD_TRICE] : null,
                0,
                $this->logTraceByLevel[$level]
            );
        }

        if (isset($context[self::FIELD_NO_TRICE])) {
            unset($context[self::FIELD_NO_TRICE]);
        }

        if (isset($context[self::FIELD_TRICE])) {
            unset($context[self::FIELD_TRICE]);
        }

        if (isset($context[self::FIELD_FILE])) {
            $logData->file = $context[self::FIELD_FILE];
            unset($context[self::FIELD_FILE]);
        } else {
            $logData->file = $this->getFileLineByTrace();
        }

        $logData->type = $context[self::FIELD_LOG_TYPE];
        unset($context[self::FIELD_LOG_TYPE]);

        $logData->fields = $context;
        $logData->microtime = microtime(true);
        $logData->logKey = 'phpeErCh_' . $this->getSessionKey() . '_' . md5($logData->message . $logData->file);

        $this->add($logData);
    }

    /**
     * @param mixed $value
     * @param int $options
     * @param int $depth
     * @return string
     * @throws \Exception
     */
    public static function safe_json_encode($value, $options = 0, $depth = 512)
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

    /**
     * @param mixed $mixed
     * @return mixed
     */
    public static function utf8ize($mixed)
    {
        if (is_array($mixed)) {
            foreach ($mixed as $key => $value) {
                $mixed[$key] = self::utf8ize($value);
            }
        } elseif (is_string($mixed)) {
            $mixed = mb_convert_encoding($mixed, 'UTF-8');
        }
        return $mixed;
    }
}


ini_set('display_errors', 1);
error_reporting(E_ALL);
if (!defined('STDIN')) {
    define('STDIN', fopen('php://stdin', 'rb'));
}
if (!defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'wb'));
}
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}
