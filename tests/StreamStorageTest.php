<?php

namespace Xakki\PhpErrorCatcher\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\storage\StreamStorage;

class StreamStorageTest extends TestCase
{
    /** @var string */
    private $tmpFile;

    /** @var PhpErrorCatcher */
    private $owner;

    public function setUp()
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'pec-stream-');

        $ref = new ReflectionClass('Xakki\\PhpErrorCatcher\\PhpErrorCatcher');
        $this->owner = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('_obj');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->owner);
    }

    public function tearDown()
    {
        if ($this->tmpFile && file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        $ref = new ReflectionClass('Xakki\\PhpErrorCatcher\\PhpErrorCatcher');
        $prop = $ref->getProperty('_obj');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    /**
     * @param array $config
     * @return StreamStorage
     */
    private function makeStorage(array $config = array())
    {
        $config = array_merge(array('stream' => $this->tmpFile), $config);
        return new StreamStorage($this->owner, $config);
    }

    /**
     * @param int $levelInt
     * @param string $level
     * @param string $message
     * @return LogData
     */
    private function makeLogData($levelInt = LOG_ERR, $level = 'error', $message = 'boom')
    {
        return LogData::init(array(
            'logKey' => 'k1',
            'message' => $message,
            'level' => $level,
            'levelInt' => $levelInt,
            'type' => 'logger',
            'trace' => '#0 /app/x.php(10)',
            'file' => '/app/x.php:10',
            'fields' => array('tag' => 'payments', 'extra_field' => 'v'),
            'microtime' => 1714210000.123456,
            'count' => 1,
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readLines()
    {
        $content = file_get_contents($this->tmpFile);
        if ($content === '' || $content === false) {
            return array();
        }
        $out = array();
        foreach (explode("\n", trim($content, "\n")) as $line) {
            if ($line === '') {
                continue;
            }
            $out[] = json_decode($line, true);
        }
        return $out;
    }

    public function testWriteProducesMonologCompatibleJson()
    {
        $storage = $this->makeStorage();
        $storage->write($this->makeLogData());
        unset($storage);

        $records = $this->readLines();
        $this->assertCount(1, $records);
        $rec = $records[0];

        $this->assertSame(
            array('message', 'context', 'level', 'level_name', 'channel', 'datetime', 'extra'),
            array_keys($rec)
        );
        $this->assertSame('boom', $rec['message']);
        $this->assertSame(400, $rec['level']);
        $this->assertSame('ERROR', $rec['level_name']);
        $this->assertSame('php', $rec['channel']);
        $this->assertSame('payments', $rec['context']['tag']);
        $this->assertSame('v', $rec['context']['extra_field']);
        $this->assertSame('logger', $rec['context']['log_type']);
        $this->assertSame('/app/x.php:10', $rec['context']['file']);
        $this->assertSame('k1', $rec['context']['log_key']);
        $this->assertArrayHasKey('trace', $rec['context']);
        $this->assertArrayHasKey('hostname', $rec['extra']);
        $this->assertArrayHasKey('pid', $rec['extra']);
        $this->assertSame(PhpErrorCatcher::VERSION, $rec['extra']['ver']);
    }

    public function testDatetimeIsIso8601WithMicroseconds()
    {
        $storage = $this->makeStorage();
        $storage->write($this->makeLogData());
        unset($storage);

        $records = $this->readLines();
        $this->assertCount(1, $records);
        $this->assertRegExp(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+\-]\d{2}:\d{2}$/',
            $records[0]['datetime']
        );
    }

    /**
     * @return array<string, array<int, int|int|string>>
     */
    public function levelMappingProvider()
    {
        return array(
            'debug'    => array(LOG_DEBUG,   100, 'DEBUG'),
            'info'     => array(LOG_INFO,    200, 'INFO'),
            'notice'   => array(LOG_NOTICE,  250, 'NOTICE'),
            'warning'  => array(LOG_WARNING, 300, 'WARNING'),
            'error'    => array(LOG_ERR,     400, 'ERROR'),
            'critical' => array(LOG_CRIT,    500, 'CRITICAL'),
            'alert'    => array(LOG_ALERT,   550, 'ALERT'),
            'emerg'    => array(LOG_EMERG,   600, 'EMERGENCY'),
        );
    }

    /**
     * @dataProvider levelMappingProvider
     */
    public function testSyslogToMonologLevelMapping($syslog, $expectedLevel, $expectedName)
    {
        $storage = $this->makeStorage();
        $storage->write($this->makeLogData($syslog, 'x'));
        unset($storage);

        $records = $this->readLines();
        $this->assertSame($expectedLevel, $records[0]['level']);
        $this->assertSame($expectedName, $records[0]['level_name']);
    }

    public function testMinLevelIntFiltersOutVerbose()
    {
        $storage = $this->makeStorage(array('minLevelInt' => LOG_WARNING));
        $storage->write($this->makeLogData(LOG_DEBUG, 'debug'));
        $storage->write($this->makeLogData(LOG_ERR, 'error'));
        unset($storage);

        $records = $this->readLines();
        $this->assertCount(1, $records);
        $this->assertSame(400, $records[0]['level']);
    }

    public function testExtraFieldsAreMergedIntoExtra()
    {
        $storage = $this->makeStorage(array(
            'channel' => 'app',
            'extraFields' => array('service' => 'my-app', 'env' => 'prod'),
        ));
        $storage->write($this->makeLogData());
        unset($storage);

        $records = $this->readLines();
        $this->assertSame('app', $records[0]['channel']);
        $this->assertSame('my-app', $records[0]['extra']['service']);
        $this->assertSame('prod', $records[0]['extra']['env']);
    }

    public function testIncludeStacktracesFalseStripsTrace()
    {
        $storage = $this->makeStorage(array('includeStacktraces' => false));
        $storage->write($this->makeLogData());
        unset($storage);

        $records = $this->readLines();
        $this->assertArrayNotHasKey('trace', $records[0]['context']);
    }

    public function testEachLineEndsWithNewline()
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
