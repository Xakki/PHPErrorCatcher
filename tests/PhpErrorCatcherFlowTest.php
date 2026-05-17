<?php
declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\storage\BaseStorage;

/**
 * Покрывает обновлённый поток PhpErrorCatcher::log() → add() → storage->write():
 *  - storage действительно получает LogData;
 *  - userCatchLog отключает storage write;
 *  - logCookieKey попадает в fields, а не tags;
 *  - предохранитель maxLogsPerRequest срабатывает на лишних логах.
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
        $obj->setAccessible(true);
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
    }

    protected function tearDown(): void
    {
        // PhpErrorCatcher::$obj не nullable, обнулить через setValue нельзя —
        // полагаемся на пересоздание в setUp() следующего теста.
        $_COOKIE = [];
    }

    /**
     * @param mixed $value
     */
    private function setStatic(string $name, $value): void
    {
        $prop = $this->ref->getProperty($name);
        $prop->setAccessible(true);
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

        // первые 3 проходят, остальные дропаются
        $this->assertCount(3, $storage->written);
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
