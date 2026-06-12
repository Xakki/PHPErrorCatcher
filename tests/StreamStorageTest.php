<?php
declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\storage\StreamStorage;

class StreamStorageTest extends TestCase
{
    /** @var string */
    private $tmpFile;

    /** @var PhpErrorCatcher */
    private $owner;

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pec-stream-');
        if ($tmp === false) {
            $this->fail('Cannot create tmp file');
        }
        $this->tmpFile = $tmp;

        $ref = new ReflectionClass(PhpErrorCatcher::class);
        $this->owner = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('obj');
        $prop->setValue(null, $this->owner);
    }

    protected function tearDown(): void
    {
        if ($this->tmpFile && file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function makeStorage(array $config = []): StreamStorage
    {
        $config = array_merge(['stream' => $this->tmpFile], $config);
        return new StreamStorage($this->owner, $config);
    }

    private function makeLogData(
        int $levelInt = LOG_ERR,
        string $level = 'error',
        string $message = 'boom'
    ): LogData {
        return LogData::init([
            'logKey' => 'k1',
            'message' => $message,
            'level' => $level,
            'levelInt' => $levelInt,
            'type' => 'logger',
            'trace' => '#0 /app/x.php(10)',
            'file' => '/app/x.php:10',
            'fields' => ['tag' => 'payments', 'extra_field' => 'v'],
            'tags' => ['payments', 'api'],
            'timestamp' => 1714210000.123456,
            'count' => 1,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readLines(): array
    {
        $content = file_get_contents($this->tmpFile);
        if ($content === '' || $content === false) {
            return [];
        }
        $out = [];
        foreach (explode("\n", trim($content, "\n")) as $line) {
            if ($line === '') {
                continue;
            }
            $out[] = json_decode($line, true);
        }
        return $out;
    }

    public function testWriteProducesMonologCompatibleJson(): void
    {
        $storage = $this->makeStorage();
        $storage->write($this->makeLogData());
        unset($storage);

        $records = $this->readLines();
        $this->assertCount(1, $records);
        $rec = $records[0];

        // Monolog JsonFormatter: exactly 7 top-level keys (order does not matter).
        $this->assertEqualsCanonicalizing(
            ['message', 'context', 'level', 'level_name', 'channel', 'datetime', 'extra'],
            array_keys($rec)
        );
        $this->assertSame('boom', $rec['message']);
        $this->assertSame(400, $rec['level']);
        $this->assertSame('ERROR', $rec['level_name']);
        $this->assertSame('php', $rec['channel']);

        // Custom fields live in context (the Monolog way), not at the root.
        $this->assertSame('payments', $rec['context']['tag']);
        $this->assertSame('v', $rec['context']['extra_field']);
        $this->assertSame('logger', $rec['context']['log_type']);
        $this->assertSame(1, $rec['context']['log_count']);
        $this->assertSame('payments,api', $rec['context']['tags']);
        $this->assertSame('/app/x.php:10', $rec['context']['file']);
        $this->assertArrayHasKey('trace', $rec['context']);
        // http data also goes into context.
        $this->assertArrayHasKey('remote_ip', $rec['context']);

        // extra — process/environment data.
        $this->assertArrayHasKey('pid', $rec['extra']);
        $this->assertSame(PhpErrorCatcher::VERSION, $rec['extra']['log_ver']);
    }

    public function testDatetimeIsIso8601WithMicroseconds(): void
    {
        $storage = $this->makeStorage();
        $storage->write($this->makeLogData());
        unset($storage);

        $records = $this->readLines();
        $this->assertCount(1, $records);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+\-]\d{2}:\d{2}$/',
            $records[0]['datetime']
        );
    }

    /**
     * @return array<string, array<int, int|string>>
     */
    public static function levelMappingProvider(): array
    {
        return [
            'debug'    => [LOG_DEBUG,   100, 'DEBUG'],
            'info'     => [LOG_INFO,    200, 'INFO'],
            'notice'   => [LOG_NOTICE,  250, 'NOTICE'],
            'warning'  => [LOG_WARNING, 300, 'WARNING'],
            'error'    => [LOG_ERR,     400, 'ERROR'],
            'critical' => [LOG_CRIT,    500, 'CRITICAL'],
            'alert'    => [LOG_ALERT,   550, 'ALERT'],
            'emerg'    => [LOG_EMERG,   600, 'EMERGENCY'],
        ];
    }

    #[DataProvider('levelMappingProvider')]
    public function testSyslogToMonologLevelMapping(int $syslog, int $expectedLevel, string $expectedName): void
    {
        $storage = $this->makeStorage();
        $storage->write($this->makeLogData($syslog, 'x'));
        unset($storage);

        $records = $this->readLines();
        $this->assertSame($expectedLevel, $records[0]['level']);
        $this->assertSame($expectedName, $records[0]['level_name']);
    }

    public function testMinLevelIntFiltersOutVerbose(): void
    {
        $storage = $this->makeStorage(['minLevelInt' => LOG_WARNING]);
        $storage->write($this->makeLogData(LOG_DEBUG, 'debug'));
        $storage->write($this->makeLogData(LOG_ERR, 'error'));
        unset($storage);

        $records = $this->readLines();
        $this->assertCount(1, $records);
        $this->assertSame(400, $records[0]['level']);
    }

    public function testExtraFieldsAreMergedIntoExtra(): void
    {
        $storage = $this->makeStorage([
            'channel' => 'app',
            'extraFields' => ['service' => 'my-app', 'env' => 'prod'],
        ]);
        $storage->write($this->makeLogData());
        unset($storage);

        $records = $this->readLines();
        $this->assertSame('app', $records[0]['channel']);
        $this->assertSame('my-app', $records[0]['extra']['service']);
        $this->assertSame('prod', $records[0]['extra']['env']);
    }

    public function testIncludeStacktracesFalseStripsTrace(): void
    {
        $storage = $this->makeStorage(['includeStacktraces' => false]);
        $storage->write($this->makeLogData());
        unset($storage);

        $records = $this->readLines();
        $this->assertArrayNotHasKey('trace', $records[0]['context']);
    }

    public function testEachLineEndsWithNewline(): void
    {
        $storage = $this->makeStorage();
        $storage->write($this->makeLogData());
        $storage->write($this->makeLogData(LOG_INFO, 'info', 'second'));
        unset($storage);

        $content = file_get_contents($this->tmpFile);
        $this->assertStringEndsWith("\n", $content);
        $this->assertSame(2, substr_count($content, "\n"));
    }
}
