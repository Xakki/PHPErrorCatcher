<?php

namespace Xakki\PhpErrorCatcher;

use DateTime;
use Exception;
use Generator;
use Psr\Log\LoggerInterface;
use Stringable;
use Xakki\PhpErrorCatcher\contract\CacheInterface;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\viewer\FileViewer;
use const STDERR;
use const STDOUT;

class PhpErrorCatcher implements LoggerInterface
{
    const VERSION = '0.8.1';

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
        FIELD_ERR_CODE = 'error_code',
        FIELD_EXC_CODE = 'exception_code';

    const LOG_FIELDS = [
        self::FIELD_LOG_TYPE,
        self::FIELD_FILE,
        self::FIELD_TRICE,
        self::FIELD_NO_TRICE,
        self::FIELD_ERR_CODE,
    ];

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

    const CLI_LEVEL_COLOR = [
        self::LEVEL_CRITICAL => Tools::COLOR_RED,
        self::LEVEL_ERROR => Tools::COLOR_RED,
        self::LEVEL_WARNING => Tools::COLOR_YELLOW,
        self::LEVEL_NOTICE => Tools::COLOR_BLUE,
        self::LEVEL_INFO => Tools::COLOR_GREEN,
        self::LEVEL_TIME => Tools::COLOR_LIGHT_BLUE,
        self::LEVEL_DEBUG => Tools::COLOR_BLUE2,
    ];

    /************************************/
    // Config
    /************************************/

    protected static $dirRoot = '';
    public static $debugMode = false;//ERROR_DEBUG_MODE
    public static $traceShowArgs = true;

    protected $logTimeProfiler = false;// time execute log
    protected static $logCookieKey = '';
    protected static $limitTrace = 10;
    protected static $maxLenMessage = 5000;

    protected static $logTags = [];
    protected static $logFields = [];

    protected $logTraceByLevel = [
        self::LEVEL_CRITICAL => 10, // trace deep level
        self::LEVEL_ERROR => 8,
        self::LEVEL_WARNING => 16,
    ];

    protected static $ignoreRules = [
        //['level' => self::LEVEL_NOTICE, 'type' => self::TYPE_TRIGGER]
    ];

    protected static $stopRules = [
        //['level' => self::LEVEL_NOTICE, 'type' => self::TYPE_TRIGGER]
    ];

    protected static $printHttpRules = [
        ['level' => self::LEVEL_CRITICAL],
    ];

    protected static $printConsoleRules = [
        ['level' => self::LEVEL_CRITICAL],
        ['level' => self::LEVEL_ERROR],
    ];

    /** @var CacheInterface  */
    protected $cache = null;
    protected $cacheLifeTime = 600;

    /************************************/
    // Variable
    /************************************/

    protected $count = 0;
    protected $errCount = 0;
    protected $timeStart = 0;
    protected $timeEnd = 0;
    protected $saveLogIfHasError = false;
    protected $sessionKey = '';

    /**
     * @var plugin\BasePlugin[]
     */
    protected static $plugins = [];
    /**
     * @var storage\BaseStorage[]
     */
    protected static $storages = [];

    /**
     * @var viewer\BaseViewer|null
     */
    protected static $viewer = null;

    /**
     * @var LogData[]
     */
    protected $logData = [];

    /**
     * @var int[]
     */
    protected $logCached = [];

    protected $successSaveLog = false;
    protected static $userCatchLogKeys = [];
    protected static $userCatchLogFlag = false;

    protected static $errorLevel = [
        self::LEVEL_CRITICAL => 1,
        self::LEVEL_ERROR => 1,
        self::LEVEL_WARNING => 1,
    ];

    /**
     * @var $this
     */
    protected static $obj;

    /**
     * Initialization
     * @param array $config
     * @return static
     * @throws Exception
     */
    public static function init(array $config = [])
    {
        if (empty(static::$obj)) {
            static::$obj = new static($config);
        }
        return static::$obj;
    }

    protected function __construct($config = [])
    {
        $this->timeStart = microtime(true);

        if (!static::$dirRoot) {
            static::$dirRoot = $_SERVER['DOCUMENT_ROOT'];
        }
        if (ini_get('max_execution_time')) {
            $this->cacheLifeTime = ini_get('max_execution_time') * 2;
        }

        try {
            if (empty($config['storage'])) {
                throw new Exception('Storages config is require');
            }

            $storage = $config['storage'];
            unset($config['storage']);

            $viewer = null;
            if (!empty($config['viewer'])) {
                $viewer = $config['viewer'];
                unset($config['viewer']);
            }

            $plugin = null;
            if (!empty($config['plugin'])) {
                $plugin = $config['plugin'];
                unset($config['plugin']);
            }

            $this->applyConfig($config);

            $this->initStorage($storage);

            if ($plugin) {
                $this->initPlugins($plugin);
            }

            if ($viewer) {
                $this->initViewer($viewer);
            }

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

        } catch (Exception $e) {
            if (static::$debugMode) {
                echo 'Cant init logger: ' . $e->__toString();
                exit('ERR');
            } else {
                throw $e;
            }
        }
    }

    private function __clone()
    {
    }

    final public function __serialize()
    {
    }

    final public function __wakeup()
    {
    }

    public function __destruct()
    {
        static::$plugins = [];
        static::$storages = [];
    }

    protected function applyConfig($config)
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                static::${$key} = $value;
            }
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

    /**
     * @return viewer\BaseViewer|null
     */
    public function getViewer()
    {
        return static::$viewer;
    }

    /**
     * @param string $class
     * @return storage\BaseStorage|null
     */
    public function getStorage($class)
    {
        return static::$storages[$class] ? : null;
    }

    /**
     * @return storage\BaseStorage[]
     */
    public function getStorages()
    {
        return static::$storages;
    }

    /**
     * @return Generator|LogData[]
     * @throws Exception
     */
    public function getDataLogsGenerator()
    {
        $data = count($this->logData) ? $this->logData : $this->logCached;
        foreach ($data as $key => $logData) {
            if (self::$userCatchLogFlag && !isset(self::$userCatchLogKeys[$key])) {
                // skip user catch logs
                continue;
            }
            if (!is_object($logData)) {
                $logData = $this->getLogDataFromCache($key);
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
        $this->successSaveLog = true;
    }

    /**
     * @return CacheInterface|null
     */
    public function cache()
    {
        return $this->cache;
    }

    /**
     * @return void
     */
    public function handleShutdown()
    {
        $this->timeEnd = (int)((microtime(true) - $this->timeStart) * 1000);

        $error = error_get_last();
        if ($error) {
            $context = [
                self::FIELD_FILE => $this->getRelativeFilePath($error['file']) . ':' . $error['line'],
                self::FIELD_LOG_TYPE => self::TYPE_FATAL,
                self::FIELD_ERR_CODE => $error['type'],
                'unhandle',
            ];
            $this->log(self::$triggerLevel[$error['type']], $error['message'], $context);
        }

        if ($this->logTimeProfiler) {
            $this->log(self::LEVEL_TIME, $this->timeEnd, ['execution']);
        }

        if (self::$userCatchLogFlag) {
            $this->log(self::LEVEL_WARNING, 'Miss flush userCatchLog');
            self::$userCatchLogFlag = false;
        }
    }

    /**
     * @return bool
     */
    public function needSaveLog()
    {
        return !$this->successSaveLog && $this->hasLog() && (!$this->saveLogIfHasError || $this->errCount);
    }

    /**
     * @param Exception $e
     * @return void
     */
    public function handleException($e)
    {
        $context = [self::FIELD_LOG_TYPE => self::TYPE_EXCEPTION, 'unhandle'];
        $this->log(self::LEVEL_CRITICAL, $e, $context);
    }

    /**
     * trigger_error catch
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
        ];

        $this->log(self::$triggerLevel[$errno], $errstr, $fields);

        if (function_exists('error_clear_last')) {
            error_clear_last();
        }

        return true; /* Не запускаем внутренний обработчик ошибок PHP */
    }

    /****************************************************/

    protected function initStorage(array $configs)
    {
        foreach ($configs as $storage => $config) {
            $keyStorage = $storage;

            if (class_exists(__NAMESPACE__ . '\\storage\\' . $storage)) {
                $storage = __NAMESPACE__ . '\\storage\\' . $storage;
            } elseif (!class_exists($storage)) {
                throw new Exception('Storage `' . $storage . '` cant find ');
            }
            if (!is_subclass_of($storage, __NAMESPACE__ . '\\storage\\BaseStorage')) {
                throw new Exception('Storage `' . $storage . '` must be implement from storage\BaseStorage');
            }

            static::$storages[$keyStorage] = new $storage($this, $config);
        }
        if (!static::$storages) {
            throw new Exception('Storages config is require');
        }
    }

    protected function initPlugins(array $configs)
    {
        foreach ($configs as $plugin => $config) {
            if (!empty($config['initGetKey']) && !isset($_GET[$config['initGetKey']])) {
                continue;
            }
            $keyStorage = $plugin;

            if (class_exists(__NAMESPACE__ . '\\plugin\\' . $plugin)) {
                $plugin = __NAMESPACE__ . '\\plugin\\' . $plugin;
            } elseif (!class_exists($plugin)) {
                throw new Exception('Plugins `' . $plugin . '` cant find ');
            }
            if (!is_subclass_of($plugin, __NAMESPACE__ . '\\plugin\\BasePlugin')) {
                throw new Exception('Plugins `' . $plugin . '` must be implement from plugin\BasePlugin');
            }

            static::$plugins[$keyStorage] = new $plugin($this, $config);
        }
    }

    /**
     * @param array $config
     * @return viewer\BaseViewer
     * @throws Exception
     */
    protected function initViewer(array $config)
    {
        if (class_exists(__NAMESPACE__ . '\\viewer\\' . $config['class'])) {
            $viewer = __NAMESPACE__ . '\\viewer\\' . $config['class'];
        } elseif (class_exists($config['class'])) {
            $viewer = $config['class'];
        } else {
            throw new Exception('Viewer `' . $config['class'] . '` cant find ');
        }
        if (!is_subclass_of($viewer, __NAMESPACE__ . '\\viewer\\BaseViewer')) {
            throw new Exception('Viewr `' . $viewer . '` must be implement from plugin\BasePlugin');
        }

        static::$viewer = new $viewer($this, $config);

        return static::$viewer;
    }

    /**
     * @return string
     */
    protected function getSessionKey()
    {
        if (!$this->sessionKey) {
            $this->sessionKey = md5(
                (!empty($_SERVER['REQUEST_TIME_FLOAT']) ? $_SERVER['REQUEST_TIME_FLOAT'] : '')
                . (!empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '')
                . (!empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'cli')
                . (!empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '')
                . (!empty($_SERVER['PWD']) ? $_SERVER['PWD'] : '')
                . (!empty($_SERVER['argv']) ? json_encode($_SERVER['argv']) : '')
            );
        }
        return $this->sessionKey;
    }

    /**
     * @param LogData $logData
     * @param array $rules
     * @return bool
     */
    protected function checkRules(LogData $logData, array $rules)
    {
        if (count($rules)) {
            foreach ($rules as $rule) {
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

    /**
     * @return bool
     */
    protected function hasLog()
    {
        return count($this->logData) || count($this->logCached);
    }

    /**
     * @param $level
     * @param mixed $message
     * @param array $context
     * @return LogData
     */
    protected function createLogData($level, $message, array $context = [])
    {
        if (isset(self::$errorLevel[$level])) {
            $this->errCount++;
        }
        $this->count++;

        list($tags, $fields) = $this->collectTagsAndFields($context);

        if (is_object($message)) {
            // если это эксепшн и другие подобные объекты
            list($message, $fields2) = $this->getLogFromObject($message);
            if ($fields2) {
                $fields = array_merge($fields, $fields2);
            }
        }

        $logData = new LogData();
        $logData->message = Tools::prepareMessage($message, self::$maxLenMessage);
        $logData->level = $level;
        $logData->type = $fields[self::FIELD_LOG_TYPE];

        if (isset($this->logTraceByLevel[$level]) && empty($fields[self::FIELD_NO_TRICE])) {
            $logData->trace = $this->renderDebugTrace(
                $fields[self::FIELD_TRICE] ?: null,
                0,
                (int)$this->logTraceByLevel[$level]
            );
        }

        if (isset($fields[self::FIELD_FILE])) {
            $logData->file = $fields[self::FIELD_FILE];
        } else {
            $logData->file = $this->getFileLineByTrace();
        }

        $logData->tags = Tools::prepareTags($tags);
        $logData->fields = Tools::prepareFields($fields, self::LOG_FIELDS);
        $logData->timestamp = microtime(true);
        $logData->logKey = 'phpeErCh_' . $this->getSessionKey() . '_' . md5($logData->message . $logData->file);
        return $logData;
    }

    protected function add(LogData $logData)
    {
        $result = false;
        $key = $logData->logKey;
        if ($this->cache()) {
            if (isset($this->logCached[$key])) {
                $logData->count += $this->logCached[$key];
            }
            try {
                if ($this->checkRules($logData, static::$stopRules)) {
                    $this->printLog($logData);
                    exit();
                }

                $str = $logData->__toString();
                if ($str) {
                    $result = $this->cache()->set($key, $str, $this->cacheLifeTime);
                }
            } catch (Exception $e) {
                $this->printLog($logData);
                $this->printLog($this->createLogData(self::LEVEL_CRITICAL, $e));
            }
        }

        if (!$result) {
            if (isset($this->logData[$key])) {
                $this->logData[$key]->count++;
            } else {
                $this->logData[$key] = $logData;
            }
        } else {
            if (isset($this->logCached[$key])) {
                $this->logCached[$key]++;
            } else {
                $this->logCached[$key] = 1;
            }
        }
        if (self::$userCatchLogFlag) {
            self::$userCatchLogKeys[$key] = true;
        }
    }

    protected function printLog(LogData $logData)
    {
        if ($logData->level === self::LEVEL_DEBUG && !static::$debugMode) {
            return;
        }
        if (empty($_SERVER['argv'])) {
            if ($this->checkRules($logData, static::$printHttpRules))
                $this->printHttp($logData);
        } else {
            if ($this->checkRules($logData, static::$printConsoleRules))
                $this->printConsole($logData);
        }
    }

    protected function printHttp(LogData $logData)
    {
        FileViewer::renderItemLog($logData);
    }

    /**
     * @param LogData $logData
     * @return void
     */
    protected function printConsole(LogData $logData)
    {
        $output = PHP_EOL . rtrim(DateTime::createFromFormat('U.u', $logData->timestamp)->format('H:i:s.u'), '0')
            . ' ' . Tools::cliColor($logData->level, self::CLI_LEVEL_COLOR[$logData->level]);
        if ($logData->tags) {
            $output .= Tools::cliColor(' [' . implode(', ', $logData->tags) . ']', Tools::COLOR_GRAY);
        }
        if (isset(self::$errorLevel[$logData->level])) {
            if ($logData->fields) {
                $output .= ' ' . Tools::cliColor(json_encode($logData->fields), Tools::COLOR_GRAY);
            }
            if ($logData->file) {
                $output .= PHP_EOL . "\t" . $logData->file;
            }
        }
        $output .= PHP_EOL . "\t" . str_replace(PHP_EOL, "\n\t", Tools::cliColor($logData->message, Tools::COLOR_GRAY2)) . PHP_EOL;

        if (isset($_SERVER['TERM'])) {
            fwrite(STDERR, $output); // Ошибки в консоли можно залогировать толкьо так `&2 >>`
        } else {
            // Для кронов, чтобы все логи по умолчанию выводились дефолтно черезе `>>`
            fwrite(STDOUT, $output);
        }
    }

    /**
     * @param object $object
     * @return array
     */
    public function getLogFromObject($object)
    {
        $fields = [];
        $mess = get_class($object);

        if (method_exists($object, 'getCode')) {
            $fields[self::FIELD_EXC_CODE] = $object->getCode();
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
            $fields[self::FIELD_TRICE] = $this->renderDebugTrace($object->getTrace(), 0, static::$limitTrace);
        }

        return [$mess, $fields];
    }

    /*****************************************************/
    /*****************************************************/

    public static function addGlobalTag($tag)
    {
        static::$logTags[] = $tag;
    }

    public static function addGlobalTags(array $tags)
    {
        foreach ($tags as $tag) {
            static::$logTags[] = $tag;
        }
    }

    public static function addGlobalField($key, $val)
    {
        static::$logFields[$key] = $val;
    }

    public static function getErrCount()
    {
        return static::$obj->errCount;
    }

    public static function startCatchLog()
    {
        self::$userCatchLogFlag = true;
    }

//    public static function flushCatchLog()
//    {
//        // TODO
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
//    }

    public static function renderLogs()
    {
        $logger = self::init();
        FileViewer::renderAllLogs(storage\FileStorage::createHttpData(), $logger->getDataLogsGenerator());
//        foreach ($logger->getDataLogsGenerator() as $logData) {
//            FileViewer::renderItemLog($logData);
//        }
    }

    /***************************************************/

    /**
     * Trace print
     * @param mixed|null $trace
     * @param int $start
     * @param int $limit
     * @param array $lineExclude
     * @return string
     */
    public function renderDebugTrace($trace = null, $start = 0, $limit = 10, array $lineExclude = [])
    {
        if (is_string($trace)) {
            return $trace;
        }
        if (!$trace) {
            $trace = debug_backtrace(static::$traceShowArgs ? DEBUG_BACKTRACE_PROVIDE_OBJECT : DEBUG_BACKTRACE_IGNORE_ARGS, $limit + 3);
        }
        $res = '';

        foreach ($trace as $i => $row) {
            if ($i < $start) {
                continue;
            }
            if (is_array($row) && (!empty($row['file']) || !empty($row['function']))) {
                if (Tools::isTraceHasExclude($row, $lineExclude)) {
                    continue;
                }
                $args = '';
                if (!empty($row['args'])) {
                    $args = implode(', ', Tools::renderDebugArray($row['args'], $i > 0 ? 6 : 15, $i > 0 ? 255 : 5000));
                }
                $res .= '#' . $i . ' ';
                if (!empty($row['file'])) {
                    $res .= '|' . $this->getRelativeFilePath($row['file']) . ':' . $row['line'] . '| ';
                }
                $res .= (!empty($row['class']) ? $row['class'] . '::' : '')
                    . (!empty($row['function']) ? $row['function'] . '(' . $args . ')' : '') . PHP_EOL;
            } else {
                if (!is_string($row)) {
                    $row = json_encode($row, JSON_UNESCAPED_UNICODE);
                }
                $res .= mb_substr('@ ' . $row, 0, 1024) . PHP_EOL;
            }
            if ($i >= $limit) {
                break;
            }
        }
        return $res;
    }

    /**
     * @param array $lineExclude
     * @return string
     */
    protected function getFileLineByTrace(array $lineExclude = [])
    {
        $trace = array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6), 2);
        $res = Tools::getFileLineByTrace($trace, $lineExclude);
        if ($res) {
            $res = $this->getRelativeFilePath($res);
        }
        return $res;
    }

    /**
     * @param string $file
     * @return string
     */
    public function getRelativeFilePath($file)
    {
        return str_replace(static::$dirRoot, '', $file);
    }

    /**
     * @param string $key
     * @return LogData|null
     */
    protected function getLogDataFromCache($key)
    {
        $data = $this->cache()->get($key);
        $raw = json_decode($data, true);
        if (!is_array($raw)) {
            return null;
        }
        return LogData::init($raw);
    }

    protected function collectTagsAndFields(array $context): array
    {
        $tags = $fields = [];
        foreach ($context as $k => $v) {
            if (is_numeric($k)) {
                $tags[] = $v;
            } else {
                $fields[$k] = $v;
            }
        }

        if (static::$logCookieKey && !empty($_COOKIE[static::$logCookieKey])) {
            // для поиска только нужных логов
            $tags[] = $_COOKIE[static::$logCookieKey];
        }

        if (count(static::$logTags)) {
            $tags = array_merge($tags, static::$logTags);
        }
        if (count(static::$logFields)) {
            $fields = array_merge(static::$logFields, $fields);
        }

        if (!isset($fields[self::FIELD_LOG_TYPE])) {
            $fields[self::FIELD_LOG_TYPE] = self::TYPE_LOGGER;
        }
        return [$tags, $fields];
    }

    /***************************************************/

    /**
     * @param Stringable|string $message
     * @param array $context
     * @return void
     */
    public function emergency($message, array $context = [])
    {
        $context[] = 'emergency';
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * @param Stringable|string $message
     * @param array $context
     * @return void
     */
    public function alert($message, array $context = [])
    {
        $context[] = 'alert';
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * @param Stringable|string $message
     * @param array $context
     * @return void
     */
    public function critical($message, array $context = [])
    {
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * @param Stringable|string $message
     * @param array $context
     * @return void
     */
    public function error($message, array $context = [])
    {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * @param Stringable|string $message
     * @param array $context
     * @return void
     */
    public function warning($message, array $context = [])
    {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * @param Stringable|string $message
     * @param array $context
     * @return void
     */
    public function notice($message, array $context = [])
    {
        $this->log(self::LEVEL_NOTICE, $message, $context);
    }

    /**
     * @param Stringable|string $message
     * @param array $context
     * @return void
     */
    public function info($message, array $context = [])
    {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * @param $message
     * @param array $context
     * @return void
     */
    public function debug($message, array $context = [])
    {
        if (!static::$debugMode) {
            return;
        }
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * @param $level
     * @param mixed $message
     * @param array $context
     * @return void
     */
    public function log($level, $message, array $context = [])
    {
        if ($this->count >= 100) {
            if ($this->count == 100) {
                echo '<p>To many Logs</p>';
            }
            return;
        }

        $logData = $this->createLogData($level, $message, $context);

        if ($this->checkRules($logData, static::$ignoreRules)) return;

        $this->add($logData);
    }

}
