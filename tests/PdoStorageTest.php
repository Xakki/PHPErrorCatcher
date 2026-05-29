<?php

declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\Tests;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\storage\PdoStorage;

class PdoStorageTest extends TestCase
{
    /** @var PhpErrorCatcher */
    private $owner;

    protected function setUp(): void
    {
        $ref = new ReflectionClass(PhpErrorCatcher::class);
        $this->owner = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('obj');
        $prop->setValue(null, $this->owner);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     */
    private function makeStorage(array $config): PdoStorage
    {
        return new PdoStorage($this->owner, $config);
    }

    private function makeLogData(
        int $levelInt = LOG_ERR,
        string $level = 'error',
        string $message = 'test error'
    ): LogData {
        return LogData::init([
            'logKey'    => 'k1',
            'message'   => $message,
            'level'     => $level,
            'levelInt'  => $levelInt,
            'type'      => 'logger',
            'trace'     => '#0 /app/x.php(10)',
            'file'      => '/app/x.php:10',
            'fields'    => ['request_host' => 'example.com', 'request_url' => '/test'],
            'tags'      => [],
            'timestamp' => 1714210000.123456,
            'count'     => 1,
        ]);
    }

    private function makeSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAllRows(PDO $pdo, string $table = 'php_error_log'): array
    {
        $stmt = $pdo->query('SELECT * FROM ' . $table);
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    // -------------------------------------------------------------------------
    // SQLite — always runs (pdo_sqlite is built-in)
    // -------------------------------------------------------------------------

    public function testSqliteWritesRowViaReadyPdo(): void
    {
        $pdo = $this->makeSqlitePdo();
        $storage = $this->makeStorage(['pdo' => $pdo]);
        $storage->write($this->makeLogData());

        $rows = $this->fetchAllRows($pdo);
        $this->assertCount(1, $rows);
    }

    public function testSqliteWritesRowViaArrayConfig(): void
    {
        $storage = $this->makeStorage(['pdo' => ['engine' => 'sqlite', 'path' => ':memory:']]);
        $storage->write($this->makeLogData());

        $resolvedPdo = $storage->getPdo();
        $rows = $this->fetchAllRows($resolvedPdo);
        $this->assertCount(1, $rows);
    }

    public function testSqliteWritesRowViaCallable(): void
    {
        $pdo = $this->makeSqlitePdo();
        $storage = $this->makeStorage(['pdo' => static fn() => $pdo]);
        $storage->write($this->makeLogData());

        $rows = $this->fetchAllRows($pdo);
        $this->assertCount(1, $rows);
    }

    public function testSqliteRowHasExpectedColumns(): void
    {
        $pdo = $this->makeSqlitePdo();
        $storage = $this->makeStorage(['pdo' => $pdo]);
        $storage->write($this->makeLogData());

        $rows = $this->fetchAllRows($pdo);
        $row = $rows[0];

        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('datetime', $row);
        $this->assertArrayHasKey('level', $row);
        $this->assertArrayHasKey('level_name', $row);
        $this->assertArrayHasKey('channel', $row);
        $this->assertArrayHasKey('message', $row);
        $this->assertArrayHasKey('context', $row);
        $this->assertArrayHasKey('extra', $row);
        $this->assertArrayHasKey('host', $row);
        $this->assertArrayHasKey('url', $row);
    }

    public function testSqliteRowValuesMatchBuildRecord(): void
    {
        $pdo = $this->makeSqlitePdo();
        $storage = $this->makeStorage(['pdo' => $pdo]);
        $storage->write($this->makeLogData(LOG_ERR, 'error', 'my message'));

        $rows = $this->fetchAllRows($pdo);
        $row = $rows[0];

        $this->assertSame('my message', $row['message']);
        $this->assertSame('400', (string) $row['level']);
        $this->assertSame('ERROR', $row['level_name']);
        $this->assertSame('php', $row['channel']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T/',
            (string) $row['datetime']
        );
        $this->assertNotEmpty($row['context']);
        // context is JSON
        $context = json_decode((string) $row['context'], true);
        $this->assertIsArray($context);
    }

    public function testSqliteTableAutoCreateIsIdempotent(): void
    {
        $pdo = $this->makeSqlitePdo();
        $storage = $this->makeStorage(['pdo' => $pdo]);

        $storage->write($this->makeLogData(LOG_INFO, 'info', 'first'));
        $storage->write($this->makeLogData(LOG_WARNING, 'warning', 'second'));

        $rows = $this->fetchAllRows($pdo);
        $this->assertCount(2, $rows);
    }

    public function testSqliteMinLevelIntFiltersLowSeverity(): void
    {
        $pdo = $this->makeSqlitePdo();
        $storage = $this->makeStorage([
            'pdo' => $pdo,
            'minLevelInt' => LOG_WARNING,
        ]);

        $storage->write($this->makeLogData(LOG_DEBUG, 'debug', 'verbose'));
        $storage->write($this->makeLogData(LOG_ERR, 'error', 'real error'));

        $rows = $this->fetchAllRows($pdo);
        $this->assertCount(1, $rows);
        $this->assertSame('real error', $rows[0]['message']);
    }

    public function testSqliteCustomTableName(): void
    {
        $pdo = $this->makeSqlitePdo();
        $storage = $this->makeStorage([
            'pdo' => $pdo,
            'pdoTableName' => 'custom_errors',
        ]);
        $storage->write($this->makeLogData());

        $rows = $this->fetchAllRows($pdo, 'custom_errors');
        $this->assertCount(1, $rows);
    }

    /**
     * @return array<string, array<int, int|string>>
     */
    public static function levelMappingProvider(): array
    {
        return [
            'debug'     => [LOG_DEBUG,   100, 'DEBUG'],
            'info'      => [LOG_INFO,    200, 'INFO'],
            'notice'    => [LOG_NOTICE,  250, 'NOTICE'],
            'warning'   => [LOG_WARNING, 300, 'WARNING'],
            'error'     => [LOG_ERR,     400, 'ERROR'],
            'critical'  => [LOG_CRIT,    500, 'CRITICAL'],
            'alert'     => [LOG_ALERT,   550, 'ALERT'],
            'emergency' => [LOG_EMERG,   600, 'EMERGENCY'],
        ];
    }

    #[DataProvider('levelMappingProvider')]
    public function testSqliteLevelMapping(int $syslog, int $expectedLevel, string $expectedName): void
    {
        $pdo = $this->makeSqlitePdo();
        $storage = $this->makeStorage(['pdo' => $pdo]);
        $storage->write($this->makeLogData($syslog, 'x'));

        $rows = $this->fetchAllRows($pdo);
        $this->assertSame((string) $expectedLevel, (string) $rows[0]['level']);
        $this->assertSame($expectedName, $rows[0]['level_name']);
    }

    // -------------------------------------------------------------------------
    // MySQL — skipped unless PEC_TEST_MYSQL_DSN is set
    // -------------------------------------------------------------------------

    private function getMysqlPdo(): ?PDO
    {
        $dsn  = getenv('PEC_TEST_MYSQL_DSN');
        $user = getenv('PEC_TEST_MYSQL_USER');
        $pass = getenv('PEC_TEST_MYSQL_PASS');

        if ($dsn === false || $dsn === '') {
            return null;
        }

        $pdo = new PDO(
            $dsn,
            $user !== false ? $user : 'root',
            $pass !== false ? $pass : ''
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    public function testMysqlWritesRow(): void
    {
        $pdo = $this->getMysqlPdo();
        if ($pdo === null) {
            $this->markTestSkipped('PEC_TEST_MYSQL_DSN not set');
        }

        $table = 'pec_test_' . bin2hex(random_bytes(4));
        $storage = $this->makeStorage(['pdo' => $pdo, 'pdoTableName' => $table]);

        try {
            $storage->write($this->makeLogData(LOG_ERR, 'error', 'mysql test'));
            $storage->write($this->makeLogData(LOG_INFO, 'info', 'second row'));

            $rows = $this->fetchAllRows($pdo, $table);
            $this->assertCount(2, $rows);
            $this->assertSame('mysql test', $rows[0]['message']);
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }

    public function testMysqlRowHasExpectedColumns(): void
    {
        $pdo = $this->getMysqlPdo();
        if ($pdo === null) {
            $this->markTestSkipped('PEC_TEST_MYSQL_DSN not set');
        }

        $table = 'pec_test_' . bin2hex(random_bytes(4));
        $storage = $this->makeStorage(['pdo' => $pdo, 'pdoTableName' => $table]);

        try {
            $storage->write($this->makeLogData());
            $rows = $this->fetchAllRows($pdo, $table);
            $row = $rows[0];

            foreach (['id', 'datetime', 'level', 'level_name', 'channel', 'message', 'context', 'extra', 'host', 'url'] as $col) {
                $this->assertArrayHasKey($col, $row, "Missing column: $col");
            }
            $context = json_decode((string) $row['context'], true);
            $this->assertIsArray($context);
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }

    // -------------------------------------------------------------------------
    // PostgreSQL — skipped unless PEC_TEST_PGSQL_DSN is set
    // -------------------------------------------------------------------------

    private function getPgsqlPdo(): ?PDO
    {
        $dsn  = getenv('PEC_TEST_PGSQL_DSN');
        $user = getenv('PEC_TEST_PGSQL_USER');
        $pass = getenv('PEC_TEST_PGSQL_PASS');

        if ($dsn === false || $dsn === '') {
            return null;
        }

        $pdo = new PDO(
            $dsn,
            $user !== false ? $user : 'postgres',
            $pass !== false ? $pass : ''
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    public function testPgsqlWritesRow(): void
    {
        $pdo = $this->getPgsqlPdo();
        if ($pdo === null) {
            $this->markTestSkipped('PEC_TEST_PGSQL_DSN not set');
        }

        $table = 'pec_test_' . bin2hex(random_bytes(4));
        $storage = $this->makeStorage(['pdo' => $pdo, 'pdoTableName' => $table]);

        try {
            $storage->write($this->makeLogData(LOG_ERR, 'error', 'pgsql test'));
            $storage->write($this->makeLogData(LOG_INFO, 'info', 'second row'));

            $rows = $this->fetchAllRows($pdo, $table);
            $this->assertCount(2, $rows);
            $this->assertSame('pgsql test', $rows[0]['message']);
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }

    public function testPgsqlRowHasExpectedColumns(): void
    {
        $pdo = $this->getPgsqlPdo();
        if ($pdo === null) {
            $this->markTestSkipped('PEC_TEST_PGSQL_DSN not set');
        }

        $table = 'pec_test_' . bin2hex(random_bytes(4));
        $storage = $this->makeStorage(['pdo' => $pdo, 'pdoTableName' => $table]);

        try {
            $storage->write($this->makeLogData());
            $rows = $this->fetchAllRows($pdo, $table);
            $row = $rows[0];

            foreach (['id', 'datetime', 'level', 'level_name', 'channel', 'message', 'context', 'extra', 'host', 'url'] as $col) {
                $this->assertArrayHasKey($col, $row, "Missing column: $col");
            }
            $context = json_decode((string) $row['context'], true);
            $this->assertIsArray($context);
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
