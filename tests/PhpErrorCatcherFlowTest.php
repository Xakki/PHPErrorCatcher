<?php
declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\storage\BaseStorage;

/**
 * Covers the updated flow PhpErrorCatcher::log() → add() → storage->write():
 *  - the storage really receives LogData;
 *  - userCatchLog disables the storage write;
 *  - logCookieKey lands in fields, not tags;
 *  - the maxLogsPerRequest guard trips on excess logs.
 */
class PhpErrorCatcherFlowTest extends TestCase
{
    private PhpErrorCatcher $owner;

    /** @var ReflectionClass<PhpErrorCatcher> */
    private ReflectionClass $ref;

    protected function setUp(): void
    {
        $this->ref = new ReflectionClass(PhpErrorCatcher::class);
        $this->owner = $this->ref->newInstanceWithoutConstructor();

        $obj = $this->ref->getProperty('obj');
        $obj->setValue(null, $this->owner);

        $this->setStatic('storages', []);
        $this->setStatic('plugins', []);
        $this->setStatic('viewer', null);
        $this->setStatic('logTags', []);
        $this->setStatic('logFields', []);
        $this->setStatic('logCookieKey', '');
        $this->setStatic('ignoreRules', []);
        $this->setStatic('stopRules', []);
        $this->setStatic('printHttpRules', []);
        $this->setStatic('printConsoleRules', []);
        $this->setStatic('userCatchLogKeys', []);
        $this->setStatic('userCatchLogFlag', false);
        $this->setStatic('maxLogsPerRequest', 100);
        $this->setStatic('dirRoot', '');
        $this->setStatic('logTimeProfiler', false);
    }

    protected function tearDown(): void
    {
        // Reset singleton so tests are isolated.
        $this->ref->getProperty('obj')->setValue(null, null);
        $_COOKIE = [];
    }

    /**
     * @param mixed $value
     */
    private function setStatic(string $name, $value): void
    {
        $prop = $this->ref->getProperty($name);
        $prop->setValue(null, $value);
    }

    private function installStorage(): InMemoryStorage
    {
        $storage = new InMemoryStorage($this->owner);
        $this->setStatic('storages', ['stub' => $storage]);
        return $storage;
    }

    public function testLogDispatchesToStorageWrite(): void
    {
        $storage = $this->installStorage();

        $this->owner->error('boom', ['payments']);

        $this->assertCount(1, $storage->written);
        $log = $storage->written[0];
        $this->assertSame('boom', $log->message);
        $this->assertSame('error', $log->level);
        $this->assertSame('logger', $log->type);
        $this->assertSame(['payments'], $log->tags);
    }

    public function testLogCookieKeyEndsUpInFieldsNotTags(): void
    {
        $this->setStatic('logCookieKey', 'X-Debug');
        $_COOKIE['X-Debug'] = 'cookie-token-42';

        $storage = $this->installStorage();

        $this->owner->warning('wat');

        $this->assertCount(1, $storage->written);
        $log = $storage->written[0];
        $this->assertSame('cookie-token-42', $log->fields['logCookieKey'] ?? null);
        $this->assertNotContains('cookie-token-42', $log->tags);
    }

    public function testStartCatchLogSkipsStorageWrite(): void
    {
        $storage = $this->installStorage();

        PhpErrorCatcher::startCatchLog();
        try {
            $this->owner->error('caught');
            $this->assertCount(0, $storage->written);
            $this->assertSame(1, $this->owner->getCatchLogCount());
        } finally {
            $this->owner->endCatchLog();
        }
    }

    public function testMaxLogsPerRequestGuardDropsExcess(): void
    {
        $this->setStatic('maxLogsPerRequest', 3);
        $storage = $this->installStorage();

        for ($i = 0; $i < 10; $i++) {
            $this->owner->info('msg-' . $i, ['t' . $i]);
        }

        // the first 3 pass, the rest are dropped
        $this->assertCount(3, $storage->written);
    }

    public function testTimeLevelMapsToSyslogInfoNotEmergency(): void
    {
        $storage = $this->installStorage();

        $this->owner->log(PhpErrorCatcher::LEVEL_TIME, '42', ['execution']);

        $this->assertCount(1, $storage->written);
        // Regression: 'time' is absent from $logLevel → the old array_search gave
        // false → 0 → LOG_EMERG (logged as EMERGENCY). Now LOG_INFO.
        $this->assertSame(LOG_INFO, $storage->written[0]->levelInt);
    }

    public function testAlertMapsToSyslogAlertNotEmergency(): void
    {
        $storage = $this->installStorage();

        $this->owner->alert('boom');

        $log = $storage->written[0];
        $this->assertSame(PhpErrorCatcher::LEVEL_ALERT, $log->level);
        // 'alert' — a duplicate value in $logLevel (LOG_EMERG and LOG_ALERT); the
        // explicit map yields LOG_ALERT, not the first matching key 0.
        $this->assertSame(LOG_ALERT, $log->levelInt);
    }

    public function testApplyConfigSetsStaticConfigProps(): void
    {
        $owner = $this->ref->newInstanceWithoutConstructor();
        $this->ref->getMethod('applyConfig')->invoke($owner, [
            'dirRoot' => '/tmp/pec-root',
            'logTimeProfiler' => true,
        ]);

        $this->assertSame('/tmp/pec-root', $this->ref->getProperty('dirRoot')->getValue());
        $this->assertTrue($this->ref->getProperty('logTimeProfiler')->getValue());
    }

    /**
     * Helper: run a callable that constructs a real PhpErrorCatcher instance,
     * restoring error/exception handlers afterwards so PHPUnit stays clean.
     *
     * @param callable(): mixed $fn
     * @return mixed
     */
    private function withRealInstance(callable $fn): mixed
    {
        $this->ref->getProperty('obj')->setValue(null, null);
        $this->setStatic('storages', []);
        try {
            return $fn();
        } finally {
            // Undo the handlers registered by the constructor.
            restore_error_handler();
            restore_exception_handler();
        }
    }

    public function testPublicConstructorRegistersSingleton(): void
    {
        $instance = $this->withRealInstance(
            fn() => new PhpErrorCatcher(storage: [InMemoryStorage::class => []])
        );

        $this->assertSame($instance, $this->ref->getProperty('obj')->getValue());
    }

    public function testInitDeprecatedPathWorksSameAsConstructor(): void
    {
        $instance = $this->withRealInstance(
            fn() => PhpErrorCatcher::init(['storage' => [InMemoryStorage::class => []]])
        );

        $this->assertInstanceOf(PhpErrorCatcher::class, $instance);
        $this->assertSame($instance, $this->ref->getProperty('obj')->getValue());
    }

    public function testInitIgnoresUnknownConfigKeys(): void
    {
        // Unknown key must not throw a fatal "unknown named argument" error.
        $instance = $this->withRealInstance(fn() => PhpErrorCatcher::init([
            'storage' => [InMemoryStorage::class => []],
            'unknownKeyThatDoesNotExist' => 'should-be-silently-dropped',
        ]));

        $this->assertInstanceOf(PhpErrorCatcher::class, $instance);
    }

    public function testInitIsIdempotent(): void
    {
        $first = $this->withRealInstance(
            fn() => PhpErrorCatcher::init(['storage' => [InMemoryStorage::class => []]])
        );
        $second = PhpErrorCatcher::init(['storage' => [InMemoryStorage::class => []]]);

        $this->assertSame($first, $second);
    }

    public function testAbsentNamedArgKeepsStaticDefault(): void
    {
        $defaultLimit = $this->ref->getProperty('limitTrace')->getValue();

        $this->withRealInstance(
            fn() => new PhpErrorCatcher(storage: [InMemoryStorage::class => []])
        );

        $this->assertSame($defaultLimit, $this->ref->getProperty('limitTrace')->getValue());
    }

    public function testFailedConstructLeavesSingletonNullAndAllowsRetry(): void
    {
        $this->ref->getProperty('obj')->setValue(null, null);
        $this->setStatic('storages', []);

        // Empty storage makes initStorage() throw before any handler is registered;
        // $obj must stay null so a retry works. No handler restore needed here.
        try {
            new PhpErrorCatcher(storage: []);
            $this->fail('Expected construction to throw on empty storage');
        } catch (Throwable) {
            // expected
        }

        $this->assertNull($this->ref->getProperty('obj')->getValue());

        $instance = $this->withRealInstance(
            fn() => PhpErrorCatcher::init(['storage' => [InMemoryStorage::class => []]])
        );

        $this->assertInstanceOf(PhpErrorCatcher::class, $instance);
        $this->assertSame($instance, $this->ref->getProperty('obj')->getValue());
    }
}

class InMemoryStorage extends BaseStorage
{
    /** @var LogData[] */
    public array $written = [];

    public function write(LogData $logData): void
    {
        $this->written[] = clone $logData;
    }
}
