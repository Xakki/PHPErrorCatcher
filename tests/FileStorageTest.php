<?php
declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Xakki\PhpErrorCatcher\dto\HttpData;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\storage\FileStorage;
use Xakki\PhpErrorCatcher\Tools;

/**
 * Covers the main storage: write() accumulates logs in a temp file,
 * __destruct()→finishSave() flushes them into a dated .plog, and iterateFileLog()
 * reads them back. Also pins the move away from strftime() (removed in PHP 9):
 * the path must be built via date() from the default template '%Y.%m/%d'.
 */
class FileStorageTest extends TestCase
{
    private string $logDir = '';

    /** @var ReflectionClass<PhpErrorCatcher> */
    private ReflectionClass $ref;

    private PhpErrorCatcher $owner;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/pec-file-' . uniqid('', true);
        if (!mkdir($base, 0777, true) && !is_dir($base)) {
            $this->fail('Cannot create tmp dir');
        }
        $this->logDir = $base;

        $this->ref = new ReflectionClass(PhpErrorCatcher::class);
        $this->owner = $this->ref->newInstanceWithoutConstructor();
        $this->ref->getProperty('obj')->setValue(null, $this->owner);

        $this->setStatic('debugMode', false);
        $this->setStatic('saveLogIfHasError', false);
        $this->setStatic('dirRoot', '');
    }

    protected function tearDown(): void
    {
        if ($this->logDir && is_dir($this->logDir)) {
            Tools::delTree($this->logDir);
        }
    }

    /**
     * @param mixed $value
     */
    private function setStatic(string $name, $value): void
    {
        $this->ref->getProperty($name)->setValue(null, $value);
    }

    private function makeLogData(string $key, string $message): LogData
    {
        return LogData::init([
            'logKey' => $key,
            'message' => $message,
            'level' => 'error',
            'levelInt' => LOG_ERR,
            'type' => 'logger',
            'trace' => '',
            'file' => '/app/x.php:10',
            'fields' => [],
            'tags' => [],
            'timestamp' => 1714210000.123456,
            'count' => 1,
        ]);
    }

    /**
     * @return string[]
     */
    private function findPlogFiles(): array
    {
        $dir = $this->logDir . '/logsError';
        if (!is_dir($dir)) {
            return [];
        }
        $found = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === FileStorage::FILE_EXT) {
                $found[] = $f->getPathname();
            }
        }
        return $found;
    }

    public function testWriteThenFlushRoundTrips(): void
    {
        $storage = new FileStorage($this->owner, ['logPath' => $this->logDir]);
        $storage->write($this->makeLogData('k1', 'first'));
        $storage->write($this->makeLogData('k2', 'second'));
        unset($storage); // __destruct → finishSave flushes into .plog

        $messages = [];
        foreach ($this->findPlogFiles() as $file) {
            foreach (FileStorage::iterateFileLog($file) as $entry) {
                $this->assertInstanceOf(HttpData::class, $entry['http']);
                foreach ($entry['logs'] as $log) {
                    $messages[] = $log->message;
                }
            }
        }

        $this->assertContains('first', $messages);
        $this->assertContains('second', $messages);
    }

    public function testFlushUsesDateBasedPathNotStrftime(): void
    {
        $storage = new FileStorage($this->owner, ['logPath' => $this->logDir]);
        $storage->write($this->makeLogData('k1', 'x'));
        unset($storage);

        // Default '%Y.%m/%d' → date('Y.m')/date('d'); strftime() is gone here.
        $expectedDir = $this->logDir . '/logsError/' . date('Y.m');
        $this->assertDirectoryExists($expectedDir);
        $this->assertNotEmpty(glob($expectedDir . '/' . date('d') . '*.' . FileStorage::FILE_EXT));
    }
}
