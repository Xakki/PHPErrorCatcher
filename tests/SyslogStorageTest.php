<?php
declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\storage\SyslogStorage;

/**
 * Verifies that SyslogStorage sends the record in the same format as
 * StreamStorage (Monolog JsonFormatter shape) over a syslog UDP frame.
 */
class SyslogStorageTest extends TestCase
{
    private PhpErrorCatcher $owner;

    /** @var \Socket */
    private $receiver;
    private int $port = 0;

    protected function setUp(): void
    {
        $ref = new ReflectionClass(PhpErrorCatcher::class);
        $this->owner = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('obj');
        $prop->setValue(null, $this->owner);

        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false || !@socket_bind($sock, '127.0.0.1', 0)) {
            $this->markTestSkipped('Cannot bind local UDP socket');
        }
        // Keep the test from hanging if the datagram never arrives.
        socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);
        $addr = '';
        $port = 0;
        socket_getsockname($sock, $addr, $port);
        $this->receiver = $sock;
        $this->port = $port;
    }

    protected function tearDown(): void
    {
        if (isset($this->receiver)) {
            socket_close($this->receiver);
        }
    }

    private function makeLogData(): LogData
    {
        return LogData::init([
            'logKey' => 'k1',
            'message' => 'boom',
            'level' => 'error',
            'levelInt' => LOG_ERR,
            'type' => 'logger',
            'trace' => '#0 /app/x.php(10)',
            'file' => '/app/x.php:10',
            'fields' => ['extra_field' => 'v'],
            'tags' => ['payments', 'api'],
            'timestamp' => 1714210000.123456,
            'count' => 1,
        ]);
    }

    public function testWriteSendsCanonicalMonologRecord(): void
    {
        $storage = new SyslogStorage($this->owner, [
            'remoteIp' => '127.0.0.1',
            'remotePort' => $this->port,
            'logSize' => 0, // do not truncate
        ]);
        $storage->write($this->makeLogData());
        unset($storage);

        $buf = '';
        $from = '';
        $fromPort = 0;
        $bytes = @socket_recvfrom($this->receiver, $buf, 65535, 0, $from, $fromPort);
        $this->assertNotFalse($bytes, 'No datagram received from SyslogStorage');

        // Frame: "<pri>1 <ts> <host> php <pid> - - <json>" — take the JSON from the first `{`.
        $jsonStart = strpos($buf, '{');
        $this->assertNotFalse($jsonStart);
        $rec = json_decode(substr($buf, (int) $jsonStart), true);
        $this->assertIsArray($rec);

        // The same 7 root keys as StreamStorage (order does not matter).
        $this->assertEqualsCanonicalizing(
            ['message', 'context', 'level', 'level_name', 'channel', 'datetime', 'extra'],
            array_keys($rec)
        );
        $this->assertSame('boom', $rec['message']);
        $this->assertSame(400, $rec['level']);
        $this->assertSame('ERROR', $rec['level_name']);
        $this->assertSame('php', $rec['channel']);

        $this->assertSame('logger', $rec['context']['log_type']);
        $this->assertSame(1, $rec['context']['log_count']);
        $this->assertSame('payments,api', $rec['context']['tags']);
        $this->assertSame('v', $rec['context']['extra_field']);
        $this->assertSame('/app/x.php:10', $rec['context']['file']);

        $this->assertSame(PhpErrorCatcher::VERSION, $rec['extra']['log_ver']);
    }
}
