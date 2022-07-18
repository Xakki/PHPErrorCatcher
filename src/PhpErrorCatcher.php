<?php

namespace Xakki\PhpErrorCatcher;

use Xakki\PhpErrorCatcher\contract\CacheInterface;
use Psr\Log\LoggerInterface;
use Stringable;
use Xakki\PhpErrorCatcher\viewer\FileViewer;

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

class PhpErrorCatcher implements LoggerInterface
{
    public const VERSION = '0.8.1';

    public const LEVEL_DEBUG = 'debug',
        LEVEL_TIME = 'time',
        LEVEL_INFO = 'info',
        LEVEL_NOTICE = 'notice',
        LEVEL_WARNING = 'warning',
        LEVEL_ERROR = 'error',
        LEVEL_CRITICAL = 'critical';

    public const TYPE_LOGGER = 'logger',
        TYPE_TRIGGER = 'trigger',
        TYPE_EXCEPTION = 'exception',
        TYPE_FATAL = 'fatal';

    public const FIELD_LOG_TYPE = 'log_type',
        FIELD_FILE = 'file',
        FIELD_TRICE = 'trice',
        FIELD_NO_TRICE = 'trice_no_fake',
        FIELD_ERR_CODE = 'error_code';

    protected static array $triggerLevel = [
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

    public static string $realPath = '';
    public static bool $debugMode = false;//ERROR_DEBUG_MODE
    public static bool $enableSessionLog = false;
    public static bool $traceShowArgs = true;

    protected bool $logTimeProfiler = false;// time execute log
    protected static string $logCookieKey = '';
    protected static string $dirRoot = '';
    protected static int $limitTrace = 10;
    protected static int $limitString = 1024;
    protected static bool $enableCookieLog = false;
    protected static bool $enablePostLog = true;

    protected static array $logTags = [];
    protected static array $logFields = [];

    /**
     * Параметры(POST,SESSION,COOKIE) затираемы при записи в логи
     */
    protected array $safeParamsKey = [
        'password',
        'input_password',
        'pass',
    ];

    protected array $logTraceByLevel = [
        self::LEVEL_CRITICAL => 10, // trace deep level
        self::LEVEL_ERROR => 8,
        self::LEVEL_WARNING => 16,
    ];

    protected static bool $saveLogIfHasError = false;
    protected static array $ignoreRules = [
        //        ['level' => self::LEVEL_NOTICE, 'type' => self::TYPE_TRIGGER]
    ];

    protected static array $stopRules = [
        //        ['level' => self::LEVEL_NOTICE, 'type' => self::TYPE_TRIGGER]
    ];

    protected CacheInterface $cache;
    protected int $lifeTime = 600;//sec // ini_get('max_execution_time')

    /************************************/
    // Variable
    /************************************/

    protected int $count = 0;
    protected int $errCount = 0;
    protected int $timeStart = 0;
    protected int $timeEnd = 0;

    protected bool $isViewMode = false;
    protected string $sessionKey = '';
    protected ?array $postData = null;
    protected ?array $cookieData = null;
    protected ?array $sessionData = null;

    /**
     * @var plugin\BasePlugin[]
     */
    protected static array $plugins = [];
    /**
     * @var storage\BaseStorage[]
     */
    protected static array $storages = [];

    protected static ?viewer\BaseViewer $viewer = null;

    /**
     * @var LogData[]
     */
    protected array $logData = [];
    protected bool $successSaveLog = false;

    protected static array $viewAlert = [];
    protected static ?int $functionTimeOut = null;
    protected static ?int $functionStartTime = null;
    protected static array $userCatchLogKeys = [];
    protected static bool $userCatchLogFlag = false;

    protected static array $errorLevel = [
        self::LEVEL_CRITICAL => 1,
        self::LEVEL_ERROR => 1,
        self::LEVEL_WARNING => 1,
    ];

    /*******************************/

    /**
     * singleton object
     *
     * @var PhpErrorCatcher
     */
    protected static PhpErrorCatcher $obj;

    /**
     * Initialization
     */
    public static function init(array $config = []): self
    {
        if (!static::$obj) {
            new static($config);
        }
        return static::$obj;
    }

    protected array $configOrigin = [];

    public function __construct($config = [])
    {
        static::$obj = $this;
        try {
            $this->configOrigin = $config;
            if (empty($config['storage'])) {
                throw new \Exception('Storages config is require');
            }

            $this->applyConfig($config);
            $this->timeStart = microtime(true);
            if (!static::$dirRoot) {
                static::$dirRoot = $_SERVER['DOCUMENT_ROOT'];
            }
            if (ini_get('max_execution_time')) {
                $this->lifeTime = ini_get('max_execution_time') * 2;
            }

            $this->initStorage($config['storage']);

            if (!empty($config['plugin'])) {
                $this->initPlugins($config['plugin']);
            }

            if (!empty($config['viewer'])) {
                $this->initViewer($config['viewer']);
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
        } catch (\Throwable $e) {
            if (static::$debugMode) {
                echo 'Cant init logger: ' . $e->__toString();
                exit('ERR');
            } else {
                throw $e;
            }
        }
    }

    public function __destruct()
    {
        static::$plugins = [];
        static::$storages = [];
    }

    protected function applyConfig($config): void
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

    public function getViewer(): ?viewer\BaseViewer
    {
        return static::$viewer;
    }

    public function getStorage(string $class): ?storage\BaseStorage
    {
        return static::$storages[$class] ?? null;
    }

    public function getStorages(): array
    {
        return static::$storages;
    }

    /**
     * @return \Generator|LogData[]
     * @throws \Exception
     */
    public function getDataLogsGenerator()
    {
        foreach ($this->logData as $key => $logData) {
            if (self::$userCatchLogFlag && !isset(self::$userCatchLogKeys[$key])) {
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

    public function successSaveLog(): void
    {
        $this->successSaveLog = true;
    }

    public function cache(): CacheInterface
    {
        return $this->cache;
    }

    public function handleShutdown(): void
    {
        $this->timeEnd = (microtime(true) - $this->timeStart) * 1000;

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

    public function needSaveLog(): bool
    {
        return !$this->successSaveLog && count($this->logData) && (!static::$saveLogIfHasError || $this->errCount);
    }

    /**
     * Обработчик исключений
     *
     * @param $e \Exception
     */
    public function handleException($e): void
    {
        $context = [self::FIELD_LOG_TYPE => self::TYPE_EXCEPTION, 'unhandle'];
        $this->log(self::LEVEL_CRITICAL, $e, $context);
    }

    /**
     * Обработчик триггерных ошибок
     */
    public function handleTrigger(int $errno, string $errstr, string $errfile, int $errline, ?array $vars = null): bool
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
            \error_clear_last();
        }

        return true; /* Не запускаем внутренний обработчик ошибок PHP */
    }

    /****************************************************/

    protected function initStorage(array $configs): void
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
            static::$storages[$keyStorage] = new $storage($this, $config);
        }
        if (!static::$storages) {
            throw new \Exception('Storages config is require');
        }
    }

    protected function initPlugins(array $configs): void
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
            static::$plugins[$plugin] = new $plugin($this, $config);
        }
    }

    protected function initViewer(array $config): viewer\BaseViewer
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

        static::$viewer = new $viewer($this, $config);

        if (isset($_GET[$config['initGetKey']])) {
            $this->isViewMode = true;
            ini_set("memory_limit", "128M");

            ob_start();
            $html = $this->getViewer()->renderView();
            $html .= ob_get_contents();
            ob_end_clean();
            echo $html;
            exit('.');
        }

        return static::$viewer;
    }

    protected function getSessionKey(): string
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

    protected function ifStopRules(LogData $logData): bool
    {
        if (count(static::$stopRules)) {
            foreach (static::$stopRules as $rule) {
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

    protected function add(LogData $logData): void
    {
        $this->consolePrint($logData);

        $result = false;
        $key = $logData->logKey;
        if ($this->cache()) {
            if (isset($this->logData[$key])) {
                $logData->count = $this->logData[$key];
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
                    $result = $this->cache()->set($key, $str, $this->lifeTime);
                    if ($result) {
                        if (isset($this->_logs[$key])) {
                            $this->logData[$key]++;
                        } else {
                            $this->logData[$key] = 1;
                        }
                    }
                }
            } catch (\Throwable $e) {
                echo '<p>Error: ' . $e->__toString() . '</p>';
            }
        }
        if (!$result) {
            if (isset($this->logData[$key])) {
                $this->logData[$key]->count++;
            } else {
                $this->logData[$key] = $logData;
            }
        }
        if (self::$userCatchLogFlag) {
            self::$userCatchLogKeys[$key] = true;
        }
    }

    public const COLOR_GREEN = '0;32',
        COLOR_GRAY = '0;37',
        COLOR_GRAY2 = '1;37',
        COLOR_YELLOW = '1;33',
        COLOR_RED = '0;31',
        COLOR_WHITE = '1;37',
        COLOR_LIGHT_BLUE = '1;34',
        COLOR_BLUE = '0;34',
        COLOR_BLUE2 = '1;36';

    public const CLI_LEVEL_COLOR = [
        self::LEVEL_CRITICAL => self::COLOR_RED,
        self::LEVEL_ERROR => self::COLOR_RED,
        self::LEVEL_WARNING => self::COLOR_YELLOW,
        self::LEVEL_NOTICE => self::COLOR_BLUE,
        self::LEVEL_INFO => self::COLOR_GREEN,
        self::LEVEL_TIME => self::COLOR_LIGHT_BLUE,
        self::LEVEL_DEBUG => self::COLOR_BLUE2,
    ];

    private static function cliColor(string $text, string $colorId): string
    {
        if (!isset($_SERVER['TERM'])) {
            return $text;
        }
        return "\033[" . $colorId . "m" . $text . "\033[0m";
    }

    protected function consolePrint(LogData $logData): void
    {
        if (empty($_SERVER['argv'])) {
            return;
        }

        if ($logData->level === self::LEVEL_DEBUG && !static::$debugMode) {
            return;
        }

        $output = PHP_EOL . rtrim(\DateTime::createFromFormat('U.u', $logData->timestamp)->format('H:i:s.u'), '0')
            . ' ' . self::cliColor($logData->level, self::CLI_LEVEL_COLOR[$logData->level]);
        if ($logData->tags) {
            $output .= self::cliColor(' [' . implode(', ', $logData->tags) . ']', self::COLOR_GRAY);
        }
        if (isset(self::$errorLevel[$logData->level])) {
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

    public function getLogFromObject(object $object): array
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
            $fields[self::FIELD_TRICE] = $this->renderDebugTrace($object->getTrace(), 0, static::$limitTrace);
        }

        return [$mess, $fields];
    }

    protected static function checkTraceExclude(array $row, $lineExclude = null): bool
    {
        if ($lineExclude) {
            if (!empty($row['file']) && strpos($row['file'], $lineExclude) !== false) {
                return true;
            }
            //            if (!empty($row['class']) && strpos($row['class'], $lineExclude)!==false)
            //                return true;
        }
        if (!empty($row['file']) && strpos($row['file'], 'phperrorcatcher') !== false) {
            return true;
        }
        return false;
    }

    /*****************************************************/
    /*****************************************************/

    public static function addGlobalTag(string $tag): void
    {
        if (mb_strlen($tag) > 32) {
            $tag = mb_substr($tag, 32) . '...';
        }
        static::$logTags[] = $tag;
    }

    public static function addGlobalTags(array $tags): void
    {
        foreach ($tags as $v) {
            self::init()->addGlobalTag($v);
        }
    }

    public static function addGlobalField($key, $val): void
    {
        static::$logFields[$key] = $val;
    }

    public static function setViewAlert($mess): void
    {
        self::$viewAlert[] = $mess;
    }

    public static function getErrCount(): int
    {
        return static::$obj->errCount;
    }

    public static function startCatchLog(): void
    {
        self::$userCatchLogFlag = true;
    }

//    public static function flushCatchLog()
//    {
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
//    }

    public static function getRenderLogs(): string
    {
        $logger = self::init();
        return FileViewer::renderAllLogs(storage\FileStorage::getDataHttp(), $logger->getDataLogsGenerator());
    }

//    /**
//     * Профилирование алгоритмов и функций отдельно
//     * @param null $timeOutExceptionDbConnection
//     */
//    public static function funcStart($timeOut)
//    {
//        static::$functionStartTime = microtime(true);
//        static::$functionTimeOut = $timeOut;
//    }
//
//    public static function funcEnd($name, $info = null, $simple = true)
//    {
//        // TODO
//        $timer = (microtime(true) - static::$functionStartTime) * 1000;
//        if (static::$functionTimeOut && $timer < static::$functionTimeOut) {
//            return null;
//        }
//        return static::$obj->saveStatsProfiler($name, $info, $simple);
//    }

    protected static $tick;

    public static function tickTrace($slice = 1, $limit = 3): void
    {
        // TODO
        self::$tick = array_slice(debug_backtrace(), $slice, $limit);
    }

    /**
     * Экранирование
     *
     * @param $value string
     * @return string
     */
    public static function esc(string $value): string
    {
        if (!is_string($value)) {
            $value = self::safe_json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return htmlspecialchars((string)$value, ENT_NOQUOTES | ENT_IGNORE, 'utf-8');
    }

    public static function arrayToLower(string|array $arg): string|array
    {
        if (is_array($arg)) {
            $arg = array_map('mb_strtolower', $arg);
            $arg = array_change_key_case($arg, CASE_LOWER);
            return $arg;
        } else {
            return mb_strtolower($arg);
        }
    }

    public static function renderDebugArray(mixed $arr, $arrLen = 6, $strLen = 256): mixed
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

    public function setSafeParams(): void
    {
        if ($this->postData !== null) {
            return;
        }
        $this->postData = [];

        if (static::$enablePostLog) {
            if ($_POST) {
                foreach ($_POST as $k => $p) {
                    $size = mb_strlen(serialize((array)$this->postData[$k]), '8bit');
                    if ($size > 1024) {
                        $this->postData[$k] = '...(' . $size . 'b)...';
                    } else {
                        $this->postData[$k] = $p;
                    }
                }
            } elseif (isset($_SERVER['argv'])) {
                $this->postData = $_SERVER['argv'];
            }
        }

        if (static::$enableCookieLog) {
            $this->cookieData = $_COOKIE;
        }
        if (static::$enableSessionLog) {
            $this->sessionData = $_SESSION;
        }

        if ($this->safeParamsKey && ($this->postData || $this->cookieData || $this->sessionData)) {
            foreach ($this->safeParamsKey as $key) {
                if (isset($this->postData[$key])) {
                    $this->postData[$key] = '***';
                }
                if ($this->cookieData && isset($this->cookieData[$key])) {
                    $this->cookieData[$key] = '***';
                }
                if ($this->sessionData && isset($this->sessionData[$key])) {
                    $this->sessionData[$key] = '***';
                }
            }
        }
    }

    /**
     * Распечатка трейса
     */
    public function renderDebugTrace(mixed $trace = null, int $start = 0, int $limit = 10, string $lineExclude = ''): string
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
                if (self::checkTraceExclude($row, $lineExclude)) {
                    continue;
                }
                $args = '';
                if (!empty($row['args'])) {
                    $args = implode(', ', self::renderDebugArray($row['args'], $i > 0 ? 6 : 15, $i > 0 ? 255 : 5000));
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

    public function getFileLineByTrace(int $start = 2, ?string $lineExclude = null): string
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

    public function getRelativeFilePath(string $file): string
    {
        return str_replace(static::$dirRoot, '', $file);
    }

    public function getRawLogFile(): string
    {
        return static::$dirRoot . '/raw.' . storage\FileStorage::FILE_EXT;
    }

    private static $objects = [];

    public static function renderVars(mixed $var, int $depth = 3, bool $highlight = false): string
    {
        return self::dumpAsString($var, $depth = 3);
    }

    public static function dumpAsString(mixed $var, int $depth = 3, bool $highlight = false): string
    {
        $output = self::dumpInternal($var, $depth, 0);
        if ($highlight) {
            $result = highlight_string("<?php\n" . $output, true);
            $output = preg_replace('/&lt;\\?php<br \\/>/', '', $result, 1);
        }
        self::$objects = [];
        return $output;
    }

    private static function dumpInternal(mixed $var, int $depth, int $level = 0): string
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
                $limitString = static::$limitString;
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

    public function getLogDataFromCache($key)
    {
        $raw = json_decode($this->cache()->get($key), true);
        $logData = new LogData();
        if (!is_array($raw)) {
            echo 'LOG: ' . $this->cache()->get($key) . PHP_EOL;
            return null;
        }
        foreach ($raw as $prop => $val) {
            $logData->{$prop} = $val;
        }
        return $logData;
    }

    /***************************************************/

    public function emergency(Stringable|string $message, array $context = []): void
    {
        $context[] = 'emergency';
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    public function alert(Stringable|string $message, array $context = []): void
    {
        $context[] = 'alert';
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    public function error(Stringable|string $message, array $context = []): void
    {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->log(self::LEVEL_NOTICE, $message, $context);
    }

    public function info(Stringable|string $message, array $context = []): void
    {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        if (!static::$debugMode) {
            return;
        }
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }

    protected static $byteMemoryLimit;

    public static function isMemoryOver()
    {
        $limitMemory = ini_get('memory_limit');
        if ($limitMemory > 0) {
            if (!self::$byteMemoryLimit) {
                self::$byteMemoryLimit = (int)$limitMemory * (strpos($limitMemory, 'K') ? 1024 : (strpos($limitMemory, 'M') ? 1024 * 1024 : (strpos($limitMemory, 'G') ? 1024 * 1024 * 1024 : 1))) * 0.9;
            }
            return memory_get_usage() >= self::$byteMemoryLimit;
        }
        return false;
    }

    public function log(string $level, string|Stringable $message, array $context = []): void
    {
        $tags = $fields = [];
        foreach ($context as $k => $v) {
            if (is_numeric($k)) {
                $tags[] = $v;
            } else {
                $fields[$k] = $v;
            }
        }

        if (!isset($fields[self::FIELD_LOG_TYPE])) {
            $fields[self::FIELD_LOG_TYPE] = self::TYPE_LOGGER;
        }

        if (count(static::$ignoreRules)) {
            foreach (static::$ignoreRules as $rule) {
                $i = count($rule);
                if (!empty($rule['level']) && $rule['level'] == $level) {
                    $i--;
                }
                if (!empty($rule['type']) && $rule['type'] == $fields[self::FIELD_LOG_TYPE]) {
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

        if (isset(self::$errorLevel[$level])) {
            $this->errCount++;
        }
        $this->count++;

        if ($this->count == 1000) {
            //TODO: cli print
            echo '<p>To many Logs</p>';
            return;
        } elseif ($this->count > 100) {
            // TODO: print in cli mode
            return;
        }

        if (is_object($message)) {
            // если это эксепшн и другие подобные объекты
            [$message, $fields2] = $this->getLogFromObject($message);
            if ($fields2) {
                $fields = array_merge($fields, $fields2);
            }
        } elseif (!is_string($message)) {
            $message = static::dumpAsString($message);
        }

        $logData = new LogData();
        $logData->message = mb_substr($message, 0, 5000);
        $logData->level = $level;
        foreach ($tags as $k => $v) {
            if (is_numeric($k)) {
                $tags[$k] = (string) $v;
                if (mb_strlen($tags[$k]) > 32) {
                    $tags[$k] = mb_substr($tags[$k], 32) . '...';
                }
            } else {
                $fields[$k] = $v;
                unset($tags[$k]);
            }
        }

        if (count(static::$logTags)) {
            $tags = array_merge($tags, static::$logTags);
        }
        if (count(static::$logFields)) {
            array_walk($fields, function (&$v) {
                if (is_array($v) || is_object($v)) {
                    $v = json_encode($v);
                }
            });
            $fields = array_merge(static::$logFields, $fields);
        }

        if (static::$logCookieKey && !empty($_COOKIE[static::$logCookieKey])) {
            // для поиска только нужных логов
            $tags[] = $_COOKIE[static::$logCookieKey];
        }

        if (isset($this->logTraceByLevel[$level]) && empty($fields[self::FIELD_NO_TRICE])) {
            $logData->trace = $this->renderDebugTrace(isset($fields[self::FIELD_TRICE]) ? $fields[self::FIELD_TRICE] : null, 0, (int)$this->logTraceByLevel[$level]);
        }

        if (isset($fields[self::FIELD_NO_TRICE])) {
            unset($fields[self::FIELD_NO_TRICE]);
        }

        if (isset($fields[self::FIELD_TRICE])) {
            unset($fields[self::FIELD_TRICE]);
        }

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
    }

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

class LogData
{
    public string $logKey;
    public string $message;
    public string $level;
    public string $type;
    public ?string $trace = null;
    public string $file;
    public array $tags = [];
    public array $fields = [];
    public float $timestamp;
    public int $count = 1;

    public function __toString(): string
    {
        return PhpErrorCatcher::safe_json_encode(get_object_vars($this), JSON_UNESCAPED_UNICODE);
    }
}

class HttpData
{
    public ?string $ipAddr;
    public ?string $host;
    public ?string $method;
    public ?string $url;
    public ?string $referrer;
    public ?string $scheme;
    public ?string $userAgent;
    public bool $overMemory = false;

    public function __toArray(): array
    {
        return get_object_vars($this);
    }
}
