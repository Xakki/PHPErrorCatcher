<?php

declare(strict_types=1);

namespace Xakki\PhpErrorCatcher;

use DateTime;
use Exception;
use Generator;
use Psr\Log\LoggerInterface;
use Throwable;
use Xakki\PhpErrorCatcher\contract\CacheInterface;
use Xakki\PhpErrorCatcher\dto\LogData;

use function error_clear_last;

use const STDERR;
use const STDOUT;

class PhpErrorCatcher implements LoggerInterface
{
    public const string VERSION = '0.8.3';

    public const string LEVEL_DEBUG = 'debug',
        LEVEL_TIME = 'time',
        LEVEL_INFO = 'info',
        LEVEL_NOTICE = 'notice',
        LEVEL_WARNING = 'warning',
        LEVEL_ERROR = 'error',
        LEVEL_CRITICAL = 'critical',
        LEVEL_ALERT = 'alert';

    public const string TYPE_LOGGER = 'logger',
        TYPE_TRIGGER = 'trigger',
        TYPE_EXCEPTION = 'exception',
        TYPE_FATAL = 'fatal';

    public const string FIELD_LOG_TYPE = 'log_type',
        FIELD_FILE = 'file',
        FIELD_TRACE = 'trace',
        FIELD_NO_TRACE = 'trace_no_fake',
        FIELD_ERR_CODE = 'error_code',
        FIELD_EXC_CODE = 'exception_code';

    protected const array LOG_FIELDS = [
        self::FIELD_LOG_TYPE,
        self::FIELD_FILE,
        self::FIELD_TRACE,
        self::FIELD_NO_TRACE,
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

    public const array CLI_LEVEL_COLOR = [
        self::LEVEL_ALERT => Tools::COLOR_RED,
        self::LEVEL_CRITICAL => Tools::COLOR_RED,
        self::LEVEL_ERROR => Tools::COLOR_RED,
        self::LEVEL_WARNING => Tools::COLOR_YELLOW,
        self::LEVEL_NOTICE => Tools::COLOR_BLUE,
        self::LEVEL_INFO => Tools::COLOR_GREEN,
        self::LEVEL_TIME => Tools::COLOR_LIGHT_BLUE,
        self::LEVEL_DEBUG => Tools::COLOR_BLUE2,
    ];

    /** @var string[] */
    protected static array $logLevel = [
        LOG_EMERG => self::LEVEL_ALERT,
        LOG_ALERT => self::LEVEL_ALERT,
        LOG_CRIT => self::LEVEL_CRITICAL,
        LOG_ERR => self::LEVEL_ERROR,
        LOG_WARNING => self::LEVEL_WARNING,
        LOG_NOTICE => self::LEVEL_NOTICE,
        LOG_INFO => self::LEVEL_INFO,
        LOG_DEBUG => self::LEVEL_DEBUG,
    ];

    /**
     * @var array<string, int>
     */
    protected static array $levelToSyslog = [
        self::LEVEL_ALERT => LOG_ALERT,
        self::LEVEL_CRITICAL => LOG_CRIT,
        self::LEVEL_ERROR => LOG_ERR,
        self::LEVEL_WARNING => LOG_WARNING,
        self::LEVEL_NOTICE => LOG_NOTICE,
        self::LEVEL_INFO => LOG_INFO,
        self::LEVEL_TIME => LOG_INFO,
        self::LEVEL_DEBUG => LOG_DEBUG,
    ];

    /************************************/
    // Config
    /************************************/

    protected static string $dirRoot = '';
    public static bool $debugMode = false;//ERROR_DEBUG_MODE
    public static bool $traceShowArgs = false;

    protected static bool $logTimeProfiler = false;// time execute log
    protected static string $logCookieKey = '';
    protected static int $limitTrace = 10;
    protected static int $maxLenMessage = 5000;

    /**
     * @var string[]
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
        self::LEVEL_ALERT => 10, // trace deep level
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
        ['level' => self::LEVEL_ALERT],
        ['level' => self::LEVEL_CRITICAL],
    ];

    /**
     * @var array<array{level:string}>
     */
    protected static array $printConsoleRules = [
        ['level' => self::LEVEL_ALERT],
        ['level' => self::LEVEL_CRITICAL],
        ['level' => self::LEVEL_ERROR],
    ];

    protected ?CacheInterface $cache = null;
    protected static int $cacheLifeTime = 600;
    protected static bool $saveLogIfHasError = false;

    // Flood guard: once `$maxLogsPerRequest` is reached further logs are dropped;
    // a single warning is written to STDERR on the first overflow.
    // 0 — limit disabled.
    protected static int $maxLogsPerRequest = 100;

    /************************************/
    // Variable
    /************************************/

    protected int $count = 0;
    protected int $errCount = 0;
    protected string $globalTag = '';
    /** @var array<string, int> */
    protected array $countByLevel = [];
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
        self::LEVEL_ALERT => 1,
        self::LEVEL_CRITICAL => 1,
        self::LEVEL_ERROR => 1,
        self::LEVEL_WARNING => 1,
    ];

    protected static ?self $obj = null;

    /**
     * @deprecated Use `new PhpErrorCatcher(storage: [...])` instead.
     *
     * @param mixed[] $config
     * @return self
     * @throws Throwable
     */
    public static function init(array $config = []): self
    {
        if (static::$obj !== null) {
            return static::$obj;
        }

        // Map only known keys to avoid "unknown named argument" fatal on stale configs.
        $known = [
            'storage',
            'plugin',
            'viewer',
            'dirRoot',
            'debugMode',
            'traceShowArgs',
            'logTimeProfiler',
            'logCookieKey',
            'limitTrace',
            'maxLenMessage',
            'logTags',
            'logFields',
            'logTraceByLevel',
            'ignoreRules',
            'stopRules',
            'printHttpRules',
            'printConsoleRules',
            'cacheLifeTime',
            'saveLogIfHasError',
            'maxLogsPerRequest',
        ];
        $args = array_intersect_key($config, array_flip($known));

        // @phpstan-ignore new.static
        return new static(...$args);
    }

    /**
     * @param array<mixed>                                           $storage         Storage backend configs (required).
     * @param array<mixed>|null                                      $plugin          Plugin configs.
     * @param array<mixed>|null                                      $viewer          Viewer config.
     * @param string|null                                            $dirRoot         Project root for relative paths.
     * @param bool|null                                              $debugMode       Echo errors instead of swallowing.
     * @param bool|null                                              $traceShowArgs   Include args in backtraces.
     * @param bool|null                                              $logTimeProfiler Log request execution time.
     * @param string|null                                            $logCookieKey    Cookie name that enables logging.
     * @param int|null                                               $limitTrace      Max backtrace depth.
     * @param int|null                                               $maxLenMessage   Truncate messages at this length.
     * @param string[]|null                                          $logTags         Default tags on every log entry.
     * @param array<string, string|int|float|bool>|null             $logFields       Default fields on every log entry.
     * @param array<string, int>|null                                $logTraceByLevel Per-level trace depth override.
     * @param array<array{level:string,type:string}>|null           $ignoreRules     Entries matching these are dropped.
     * @param array<array{level:string,type:string}>|null           $stopRules       Entries matching these halt logging.
     * @param array<array{level:string}>|null                       $printHttpRules  Which levels echo to HTTP response.
     * @param array<array{level:string}>|null                       $printConsoleRules Which levels echo to STDERR.
     * @param int|null                                               $cacheLifeTime   Log cache TTL in seconds.
     * @param bool|null                                              $saveLogIfHasError Only persist when errors occurred.
     * @param int|null                                               $maxLogsPerRequest Drop entries after this many per request (0 = unlimited).
     * @throws Throwable
     */
    public function __construct(
        array $storage,
        ?array $plugin = null,
        ?array $viewer = null,
        ?string $dirRoot = null,
        ?bool $debugMode = null,
        ?bool $traceShowArgs = null,
        ?bool $logTimeProfiler = null,
        ?string $logCookieKey = null,
        ?int $limitTrace = null,
        ?int $maxLenMessage = null,
        ?array $logTags = null,
        ?array $logFields = null,
        ?array $logTraceByLevel = null,
        ?array $ignoreRules = null,
        ?array $stopRules = null,
        ?array $printHttpRules = null,
        ?array $printConsoleRules = null,
        ?int $cacheLifeTime = null,
        ?bool $saveLogIfHasError = null,
        ?int $maxLogsPerRequest = null,
    ) {
        $this->timeStart = microtime(true);

        if (!static::$dirRoot) {
            static::$dirRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        }
        if (ini_get('max_execution_time')) {
            self::$cacheLifeTime = (int) ini_get('max_execution_time') * 2;
        }

        // Apply nullable config args — only override the static default when explicitly supplied.
        if ($dirRoot !== null) {
            static::$dirRoot = $dirRoot;
        }
        if ($debugMode !== null) {
            static::$debugMode = $debugMode;
        }
        if ($traceShowArgs !== null) {
            static::$traceShowArgs = $traceShowArgs;
        }
        if ($logTimeProfiler !== null) {
            static::$logTimeProfiler = $logTimeProfiler;
        }
        if ($logCookieKey !== null) {
            static::$logCookieKey = $logCookieKey;
        }
        if ($limitTrace !== null) {
            static::$limitTrace = $limitTrace;
        }
        if ($maxLenMessage !== null) {
            static::$maxLenMessage = $maxLenMessage;
        }
        if ($logTags !== null) {
            static::$logTags = $logTags;
        }
        if ($logFields !== null) {
            static::$logFields = $logFields;
        }
        if ($logTraceByLevel !== null) {
            static::$logTraceByLevel = $logTraceByLevel;
        }
        if ($ignoreRules !== null) {
            static::$ignoreRules = $ignoreRules;
        }
        if ($stopRules !== null) {
            static::$stopRules = $stopRules;
        }
        if ($printHttpRules !== null) {
            static::$printHttpRules = $printHttpRules;
        }
        if ($printConsoleRules !== null) {
            static::$printConsoleRules = $printConsoleRules;
        }
        if ($cacheLifeTime !== null) {
            static::$cacheLifeTime = $cacheLifeTime;
        }
        if ($saveLogIfHasError !== null) {
            static::$saveLogIfHasError = $saveLogIfHasError;
        }
        if ($maxLogsPerRequest !== null) {
            static::$maxLogsPerRequest = $maxLogsPerRequest;
        }

        try {
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

            // Register the singleton only after successful construction, so a
            // throwing init (e.g. bad storage) leaves $obj null and a retry works.
            if (static::$obj !== null && static::$debugMode) {
                trigger_error('PhpErrorCatcher: overwriting existing instance', E_USER_WARNING);
            }
            static::$obj = $this;
        } catch (Throwable $e) {
            if (static::$debugMode) {
                echo 'Cant init logger: ' . $e->__toString();
                exit('ERR');
            } else {
                throw $e;
            }
        }
    }

    private function __clone(): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    final public function __serialize(): array
    {
        return [];
    }

    final public function __wakeup(): void
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
            // Config sets static properties only (shared across all instances).
            // Instance properties are runtime state and are not configurable.
            if (property_exists(static::class, $key)) {
                static::${$key} = $value;
            }
        }
    }

    /**
     * @deprecated
     *
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

        if (self::$logTimeProfiler) {
            $this->log(self::LEVEL_TIME, (string) $this->timeEnd, ['execution']);
        }
    }

    public function needSaveLog(): bool
    {
        if (static::$debugMode) {
            return true;
        }
        if (!static::$saveLogIfHasError) {
            return true;
        }
        return $this->errCount > 0;
    }

    public function handleException(Throwable $e): void
    {
        $context = [self::FIELD_LOG_TYPE => self::TYPE_EXCEPTION, 'unhandle'];
        $this->log(self::LEVEL_CRITICAL, $e, $context);
    }

    /**
     * Handler for triggered errors (set_error_handler).
     *
     * @param int        $errno
     * @param string     $errstr
     * @param string     $errfile
     * @param int        $errline
     * @param mixed[]|null $vars
     * @return bool
     */
    // phpcs:ignore WebimpressCodingStandard.Functions.ReturnType.ReturnValue
    public function handleTrigger(int $errno, string $errstr, string $errfile, int $errline, ?array $vars = null): bool
    {
        if (!(error_reporting() & $errno)) {
            // also skips errors suppressed with @
            return true;
        }

        // Capture the stack right here so it points at the error site, not at
        // frames inside PhpErrorCatcher::log()/createLogData().
        $trace = debug_backtrace(
            static::$traceShowArgs ? DEBUG_BACKTRACE_PROVIDE_OBJECT : DEBUG_BACKTRACE_IGNORE_ARGS,
            static::$limitTrace + 1
        );
        $fields = [
            self::FIELD_FILE => $this->getRelativeFilePath($errfile) . ':' . $errline,
            self::FIELD_LOG_TYPE => self::TYPE_TRIGGER,
            self::FIELD_ERR_CODE => $errno,
            self::FIELD_TRACE => array_slice($trace, 1),
        ];

        $this->log(self::$triggerLevel[$errno], $errstr, $fields);

        if (function_exists('error_clear_last')) {
            error_clear_last();
        }

        return true; /* Do not run PHP's internal error handler */
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
     * @param string $level
     * @param mixed $message
     * @param mixed[] $context
     * @return LogData
     */
    protected function createLogData(string $level, mixed $message, array $context = []): LogData
    {
        if (isset(self::$errorLevel[$level])) {
            $this->errCount++;
        }
        if (isset(self::$errorLevel[$level])) {
            if (!isset($this->countByLevel[$level])) {
                $this->countByLevel[$level] = 0;
            }
            $this->countByLevel[$level]++;
        }
        $this->count++;

        [$tags, $fields] = $this->collectTagsAndFields($context);

        if (is_object($message)) {
            // an exception or another object-like message
            [$message, $fields2] = $this->getLogFromObject($message);
            if ($fields2) {
                $fields = array_merge($fields, $fields2);
            }
        }

        $logData = new LogData();
        $logData->message = Tools::prepareMessage($message, self::$maxLenMessage);
        $logData->level = $level;
        // levelInt — syslog priority (LOG_*) consumed by the storages
        // (StreamStorage::$monologLevel, SyslogStorage facility, Stream/FileStorage
        // filters). Taken from the explicit $levelToSyslog map; an unknown level →
        // LOG_DEBUG (not 0/EMERGENCY, as the old array_search over $logLevel gave).
        $logData->levelInt = self::$levelToSyslog[$logData->level] ?? LOG_DEBUG;
        $logData->type = $fields[self::FIELD_LOG_TYPE];

        if (isset($context[self::FIELD_TRACE])) {
            if (is_string($context[self::FIELD_TRACE])) {
                $logData->trace = $context[self::FIELD_TRACE];
            } elseif (is_array($context[self::FIELD_TRACE])) {
                $logData->trace = $this->renderDebugTrace($context[self::FIELD_TRACE]);
            } else {
                $logData->trace = (string) json_encode($context[self::FIELD_TRACE]);
            }
        } elseif (isset(self::$logTraceByLevel[$level]) && empty($fields[self::FIELD_NO_TRACE])) {
            $logData->trace = $this->renderDebugTrace(
                $fields[self::FIELD_TRACE] ?? null,
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
        $logData->logKey = 'phpErr:' . $this->getSessionKey() . ':' . md5($logData->message . $logData->file);
        return $logData;
    }

    protected function add(LogData $logData): void
    {
        $key = $logData->logKey;

        $cached = false;
        if ($this->cache()) {
            if (isset($this->logCached[$key])) {
                $logData->count += $this->logCached[$key];
            }
            try {
                $str = $logData->__toString();
                if ($str) {
                    // @phpstan-ignore-next-line
                    $this->cache()?->set($key, $str, self::$cacheLifeTime);
                    $cached = true;
                }
            } catch (Throwable $e) {
                $this->printLog($logData);
                $this->printLog($this->createLogData(self::LEVEL_CRITICAL, $e));
            }
        }

        if (!$cached) {
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

        // In startCatchLog() mode — only mark the keys, do not write to storage
        // (the logs are returned via endCatchLog()).
        if (self::$userCatchLogFlag) {
            self::$userCatchLogKeys[$key] = true;
        } else {
            foreach (static::$storages as $store) {
                $store->write($logData);
            }
        }

        $this->printLog($logData);
        if ($this->checkRules($logData, static::$stopRules)) {
            exit();
        }
    }

    protected function printLog(LogData $logData): void
    {
        if ($logData->level === self::LEVEL_DEBUG && !static::$debugMode) {
            return;
        }
        if (empty($_SERVER['argv']) && !defined('CLI')) {
            if ($this->checkRules($logData, static::$printHttpRules)) {
                $this->printHttp($logData);
            }
        } else {
            if ($this->checkRules($logData, static::$printConsoleRules)) {
                $this->printConsole($logData);
            }
        }
    }

    protected function printHttp(LogData $logData): void
    {
        $this->getViewer()?->renderItemLog($logData);
    }

    protected function printConsole(LogData $logData): void
    {
        $dt = DateTime::createFromFormat('U.u', (string) $logData->timestamp);
        $output = PHP_EOL . ($dt ? rtrim($dt->format('H:i:s.u'), '0') : '')
            . ' ' . Tools::cliColor($logData->level, self::CLI_LEVEL_COLOR[$logData->level]);
        if ($logData->tags) {
            $output .= Tools::cliColor(' [' . implode(', ', $logData->tags) . ']', Tools::COLOR_GRAY);
        }

        if ($logData->fields) {
            $output .= ' ' . Tools::cliColor(Tools::safeJsonEncode($logData->fields), Tools::COLOR_GRAY);
        }

        if ($logData->levelInt <= LOG_NOTICE) {
            if ($logData->file) {
                $output .= PHP_EOL . "\t" . $logData->file;
            }
        }
        $output .= PHP_EOL . "\t" . str_replace(PHP_EOL, "\n\t", Tools::cliColor($logData->message, Tools::COLOR_GRAY2)) . PHP_EOL;

        if (isset($_SERVER['TERM'])) {
            fwrite(STDERR, $output); // errors can only be captured in a terminal via `2>>`
        } else {
            // for cron jobs, so all logs go to the default output via `>>`
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
            $fields[self::FIELD_TRACE] = $this->renderDebugTrace($object->getTrace(), 0, static::$limitTrace);
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
        return static::$obj !== null ? static::$obj->errCount : 0;
    }

    public static function startCatchLog(): void
    {
        if (self::$userCatchLogFlag) {
            return;
        }
        self::$userCatchLogFlag = true;
        self::$userCatchLogKeys = [];
    }

    public function getCatchLogCount(): int
    {
        return count(self::$userCatchLogKeys);
    }

    /**
     * @return LogData[]
     * @throws Exception
     */
    public function endCatchLog(): array
    {
        if (!self::$userCatchLogFlag) {
            return [];
        }
        $logs = [];
        foreach ($this->getDataLogsGenerator() as $log) {
            $logs[] = $log;
        }
        self::$userCatchLogFlag = false;
        self::$userCatchLogKeys = [];
        return $logs;
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

    /***************************************************/

    /**
     * Trace print
     *
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
            if (is_null($v) || $v === '') {
                continue;
            }
            if (is_numeric($k)) {
                $tags[] = $v;
            } else {
                $fields[$k] = $v;
            }
        }

        if (static::$logCookieKey && !empty($_COOKIE[static::$logCookieKey])) {
            // Written as a field (not a tag) so external indexing can find these
            // logs — it lands in the storages' context/fields (Stream/Elastic).
            $fields['logCookieKey'] = $_COOKIE[static::$logCookieKey];
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
     * @param mixed[] $context
     * @return void
     */
    public function emergency(\Stringable|string $message, array $context = []): void
    {
        $context[] = 'emergency';
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * @param mixed[] $context
     * @return void
     */
    public function alert(\Stringable|string $message, array $context = []): void
    {
        $context[] = 'alert';
        $this->log(self::LEVEL_ALERT, $message, $context);
    }

    /**
     * @param mixed[] $context
     * @return void
     */
    public function critical(\Stringable|string $message, array $context = []): void
    {
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * @param mixed[] $context
     * @return void
     */
    public function error(\Stringable|string $message, array $context = []): void
    {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * @param mixed[] $context
     * @return void
     */
    public function warning(\Stringable|string $message, array $context = []): void
    {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * @param mixed[] $context
     * @return void
     */
    public function notice(\Stringable|string $message, array $context = []): void
    {
        $this->log(self::LEVEL_NOTICE, $message, $context);
    }

    /**
     * @param mixed[] $context
     * @return void
     */
    public function info(\Stringable|string $message, array $context = []): void
    {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * @param mixed[] $context
     * @return void
     */
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * @param mixed $level
     * @param mixed[] $context
     * @return void
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!static::$debugMode && $level === self::LEVEL_DEBUG) {
            return;
        }

        if (static::$maxLogsPerRequest > 0 && $this->count >= static::$maxLogsPerRequest) {
            // createLogData() normally increments $count, but it is not called
            // here — count manually, otherwise we never catch the exact boundary.
            $this->count++;
            if ($this->count === static::$maxLogsPerRequest + 1) {
                fwrite(
                    STDERR,
                    '[' . date('Y-m-d H:i:s') . '] PhpErrorCatcher: too many logs in single request ('
                    . static::$maxLogsPerRequest . '), dropping the rest' . PHP_EOL
                );
            }
            return;
        }

        $logData = $this->createLogData($level, $message, $context);

        if ($this->checkRules($logData, static::$ignoreRules)) {
            return;
        }

        $this->add($logData);
    }
}
