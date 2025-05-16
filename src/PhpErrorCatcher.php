<?php

namespace Xakki\PhpErrorCatcher;

use DateTime;
use Exception;
use Generator;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;
use Xakki\PhpErrorCatcher\contract\CacheInterface;
use Xakki\PhpErrorCatcher\dto\LogData;
use function error_clear_last;
use const STDERR;
use const STDOUT;

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
        FIELD_ERR_CODE = 'error_code',
        FIELD_EXC_CODE = 'exception_code';

    protected const LOG_FIELDS = [
        self::FIELD_LOG_TYPE,
        self::FIELD_FILE,
        self::FIELD_TRICE,
        self::FIELD_NO_TRICE,
        self::FIELD_ERR_CODE,
    ];

    /**
     * @var array<int, string>
     */
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
        E_RECOVERABLE_ERROR => self::LEVEL_ERROR,
    ];

    public const CLI_LEVEL_COLOR = [
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

    protected static string $dirRoot = '';
    public static bool $debugMode = false;//ERROR_DEBUG_MODE
    public static bool $traceShowArgs = true;

    protected bool $logTimeProfiler = false;// time execute log
    protected static string $logCookieKey = '';
    protected static int $limitTrace = 10;
    protected static int $maxLenMessage = 5000;

    /**
     * @var array<string, string>
     */
    protected static array $logTags = [];

    /**
     * @var array<string, string|int|float|bool>
     */
    protected static array $logFields = [];

    /**
     * @var array<string, int>
     */
    protected static array $logTraceByLevel = [
        self::LEVEL_CRITICAL => 10, // trace deep level
        self::LEVEL_ERROR => 8,
        self::LEVEL_WARNING => 16,
    ];

    /**
     * @var array<array{level:string,type:string}>
     */
    protected static array $ignoreRules = [
        //['level' => self::LEVEL_NOTICE, 'type' => self::TYPE_TRIGGER]
    ];

    /**
     * @var array<array{level:string,type:string}>
     */
    protected static array $stopRules = [
        //['level' => self::LEVEL_NOTICE, 'type' => self::TYPE_TRIGGER]
    ];

    /**
     * @var array<array{level:string}>
     */
    protected static array $printHttpRules = [
        ['level' => self::LEVEL_CRITICAL],
    ];

    /**
     * @var array<array{level:string}>
     */
    protected static array $printConsoleRules = [
        ['level' => self::LEVEL_CRITICAL],
        ['level' => self::LEVEL_ERROR],
    ];

    protected ?CacheInterface $cache = null;
    protected static int $cacheLifeTime = 600;
    protected static bool $saveLogIfHasError = false;

    /************************************/
    // Variable
    /************************************/

    protected int $count = 0;
    protected int $errCount = 0;
    protected float $timeStart = 0;
    protected int $timeEnd = 0;
    protected string $sessionKey = '';

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

    /**
     * @var int[]
     */
    protected array $logCached = [];

    protected bool $successSaveLog = false;
    /**
     * @var array<string, bool>
     */
    protected static array $userCatchLogKeys = [];
    protected static bool $userCatchLogFlag = false;

    /**
     * @var array<string, int>
     */
    protected static array $errorLevel = [
        self::LEVEL_CRITICAL => 1,
        self::LEVEL_ERROR => 1,
        self::LEVEL_WARNING => 1,
    ];

    protected static self $obj;

    /**
     * Initialization
     * @param mixed[] $config
     * @return self
     * @throws Throwable
     */
    public static function init(array $config = []): self
    {
        if (empty(static::$obj)) {
            // @phpstan-ignore-next-line
            static::$obj = new static($config);
        }
        return static::$obj;
    }

    /**
     * @param mixed[] $config
     * @throws Throwable
     */
    protected function __construct(array $config = [])
    {
        $this->timeStart = microtime(true);

        if (!static::$dirRoot) {
            static::$dirRoot = $_SERVER['DOCUMENT_ROOT'];
        }
        if (ini_get('max_execution_time')) {
            self::$cacheLifeTime = (int) ini_get('max_execution_time') * 2;
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

        } catch (Throwable $e) {
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
        return [];
    }

    final public function __wakeup()
    {
    }

    public function __destruct()
    {
        static::$plugins = [];
        static::$storages = [];
    }

    /**
     * @param mixed[] $config
     * @return void
     */
    protected function applyConfig(array $config): void
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                static::${$key} = $value;
            }
        }
    }

    /**
     * @deprecated
     * @param string $key
     * @return mixed
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

    /**
     * @return storage\BaseStorage[]
     */
    public function getStorages(): array
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

    public function successSaveLog(): void
    {
        $this->successSaveLog = true;
    }

    public function cache(): ?CacheInterface
    {
        return $this->cache;
    }

    public function handleShutdown(): void
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

    public function needSaveLog(): bool
    {
        return !$this->successSaveLog && $this->hasLog() && (!self::$saveLogIfHasError || $this->errCount);
    }

    public function handleException(Throwable $e): void
    {
        $context = [self::FIELD_LOG_TYPE => self::TYPE_EXCEPTION, 'unhandle'];
        $this->log(self::LEVEL_CRITICAL, $e, $context);
    }

    /**
     * Обработчик триггерных ошибок
     * @param int        $errno
     * @param string     $errstr
     * @param string     $errfile
     * @param int        $errline
     * @param mixed[]|null $vars
     * @return bool
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
            error_clear_last();
        }

        return true; /* Не запускаем внутренний обработчик ошибок PHP */
    }

    /****************************************************/

    /**
     * @param mixed[] $configs
     * @return void
     * @throws Exception
     */
    protected function initStorage(array $configs): void
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

    /**
     * @param mixed[] $configs
     * @return void
     * @throws Exception
     */
    protected function initPlugins(array $configs): void
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
     * @param mixed[] $config
     * @return viewer\BaseViewer
     * @throws Exception
     */
    protected function initViewer(array $config): viewer\BaseViewer
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

    /**
     * @param LogData $logData
     * @param array<array<string, string>>   $rules
     * @return bool
     */
    protected function checkRules(LogData $logData, array $rules): bool
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

    protected function hasLog(): bool
    {
        return count($this->logData) || count($this->logCached);
    }

    /**
     * @param int $level
     * @param mixed $message
     * @param mixed[] $context
     * @return LogData
     */
    protected function createLogData($level, mixed $message, array $context = []): LogData
    {
        if (isset(self::$errorLevel[$level])) {
            $this->errCount++;
        }
        $this->count++;

        [$tags, $fields] = $this->collectTagsAndFields($context);

        if (is_object($message)) {
            // если это эксепшн и другие подобные объекты
            [$message, $fields2] = $this->getLogFromObject($message);
            if ($fields2) {
                $fields = array_merge($fields, $fields2);
            }
        }

        $logData = new LogData();
        $logData->message = Tools::prepareMessage($message, self::$maxLenMessage);
        $logData->level = $level;
        $logData->levelInt = (int) array_search($logData->level, self::$triggerLevel);
        $logData->type = $fields[self::FIELD_LOG_TYPE];

        if (isset(self::$logTraceByLevel[$level]) && empty($fields[self::FIELD_NO_TRICE])) {
            $logData->trace = $this->renderDebugTrace(
                $fields[self::FIELD_TRICE] ?? null,
                0,
                (int)self::$logTraceByLevel[$level]
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

    protected function add(LogData $logData): void
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
                    // @phpstan-ignore-next-line
                    $this->cache()?->set($key, $str, self::$cacheLifeTime);
                    $result = true;
                }
            } catch (Throwable $e) {
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

    protected function printLog(LogData $logData): void
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

    protected function printHttp(LogData $logData): void
    {
        $this->getViewer()?->renderItemLog($logData);
    }

    protected function printConsole(LogData $logData): void
    {
        $output = PHP_EOL . rtrim(DateTime::createFromFormat('U.u', (string) $logData->timestamp)?->format('H:i:s.u'), '0')
            . ' ' . Tools::cliColor($logData->level, self::CLI_LEVEL_COLOR[$logData->level]);
        if ($logData->tags) {
            $output .= Tools::cliColor(' [' . implode(', ', $logData->tags) . ']', Tools::COLOR_GRAY);
        }
        if (isset(self::$errorLevel[$logData->level])) {
            if ($logData->fields) {
                $output .= ' ' . Tools::cliColor(Tools::safeJsonEncode($logData->fields), Tools::COLOR_GRAY);
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
     * @return array{0: string, 1: array<string, string>}
     */
    public function getLogFromObject(object $object): array
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
        if (method_exists($object, 'getFile') && method_exists($object, 'getLine')) {
            $fields[self::FIELD_FILE] = $this->getRelativeFilePath($object->getFile()) . ':' . $object->getLine();
        }

        if (method_exists($object, 'getTrace')) {
            $fields[self::FIELD_TRICE] = $this->renderDebugTrace($object->getTrace(), 0, static::$limitTrace);
        }

        return [$mess, $fields];
    }

    /*****************************************************/
    /*****************************************************/

    public static function addGlobalTag(string $tag): void
    {
        static::$logTags[] = $tag;
    }

    /**
     * @param string[] $tags
     * @return void
     */
    public static function addGlobalTags(array $tags): void
    {
        foreach ($tags as $tag) {
            static::$logTags[] = $tag;
        }
    }

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
     * @param string $key
     * @param string|int|float|bool $val
     * @return void
     */
    public static function addGlobalField(string $key, $val): void
    {
        static::$logFields[$key] = $val;
    }

    public static function getErrCount(): int
    {
        return static::$obj->errCount;
    }

    public static function startCatchLog(): void
    {
        self::$userCatchLogFlag = true;
    }

    /***************************************************/

    /**
     * Trace print
     * @param mixed|null $trace
     * @param int $start
     * @param int $limit
     * @param string[] $lineExclude
     * @return string
     */
    public function renderDebugTrace(mixed $trace = null, int $start = 0, int $limit = 10, array $lineExclude = []): string
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
     * @param string[] $lineExclude
     * @return string
     */
    protected function getFileLineByTrace(array $lineExclude = []): string
    {
        $trace = array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6), 2);
        $res = Tools::getFileLineByTrace($trace, $lineExclude);
        if ($res) {
            $res = $this->getRelativeFilePath($res);
        }
        return $res;
    }

    public function getRelativeFilePath(string $file): string
    {
        return str_replace(static::$dirRoot, '', $file);
    }

    public function getRawLogFile(): string
    {
        return static::$dirRoot . '/raw.' . storage\FileStorage::FILE_EXT;
    }

    protected function getLogDataFromCache(string $key): ?LogData
    {
        $data = $this->cache()?->get($key);
        $raw = json_decode($data, true);
        if (!is_array($raw)) {
            return null;
        }
        return LogData::init($raw);
    }

    /**
     * @param mixed[] $context
     * @return mixed[]
     */
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
     * @param mixed[]           $context
     * @return void
     */
    public function emergency(Stringable|string $message, array $context = []): void
    {
        $context[] = 'emergency';
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * @param Stringable|string $message
     * @param mixed[]           $context
     * @return void
     */
    public function alert(Stringable|string $message, array $context = []): void
    {
        $context[] = 'alert';
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * @param Stringable|string $message
     * @param mixed[]           $context
     * @return void
     */
    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * @param Stringable|string $message
     * @param mixed[]           $context
     * @return void
     */
    public function error(Stringable|string $message, array $context = []): void
    {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * @param Stringable|string $message
     * @param mixed[]           $context
     * @return void
     */
    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * @param Stringable|string $message
     * @param mixed[]           $context
     * @return void
     */
    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->log(self::LEVEL_NOTICE, $message, $context);
    }

    /**
     * @param Stringable|string $message
     * @param mixed[]           $context
     * @return void
     */
    public function info(Stringable|string $message, array $context = []): void
    {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param mixed[] $context
     * @return void
     */
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param mixed[] $context
     * @return void
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!static::$debugMode && $level === self::LEVEL_DEBUG) {
            return;
        }

        $logData = $this->createLogData($level, $message, $context);

        if ($this->checkRules($logData, static::$ignoreRules)) return;

        $this->add($logData);
    }

}
