<?php

declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\storage;

use PDO;
use RuntimeException;
use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\Tools;

class PdoStorage extends BaseStorage
{
    /**
     * @var null|PDO|array<string, mixed>|callable(): mixed
     */
    protected mixed $pdo = null;

    protected string $pdoTableName = 'php_error_log';

    protected int $minLevelInt = LOG_DEBUG;

    private bool $tableCreated = false;

    public function write(LogData $logData): void
    {
        if ($this->minLevelInt && $logData->levelInt > $this->minLevelInt) {
            return;
        }

        $pdo = $this->getPdo();
        $record = $this->buildRecord($logData);

        $this->ensureTable($pdo);

        $context = $record['context'];
        $host = null;
        $url = null;
        if (is_array($context)) {
            $host = isset($context['request_host']) ? (string) $context['request_host'] : null;
            $url = isset($context['request_url']) ? (string) $context['request_url'] : null;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO ' . $this->pdoTableName
            . ' (datetime, level, level_name, channel, message, context, extra, host, url)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            (string) $record['datetime'],
            (int) $record['level'],
            (string) $record['level_name'],
            (string) $record['channel'],
            (string) $record['message'],
            Tools::safeJsonEncode($context),
            Tools::safeJsonEncode($record['extra']),
            $host,
            $url,
        ]);
    }

    public function getPdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        if (is_callable($this->pdo)) {
            $resolved = ($this->pdo)();
            if (!$resolved instanceof PDO) {
                throw new RuntimeException('PdoStorage: callable did not return a PDO instance');
            }
            $this->pdo = $resolved;
            return $resolved;
        }

        if (is_array($this->pdo)) {
            $params = array_merge([
                'engine'   => 'mysql',
                'host'     => 'localhost',
                'port'     => 3306,
                'dbname'   => 'test',
                'username' => 'root',
                'passwd'   => '',
                'path'     => ':memory:',
                'options'  => [],
            ], $this->pdo);

            $engine = (string) $params['engine'];

            if ($engine === 'sqlite') {
                $dsn = 'sqlite:' . (string) $params['path'];
                $pdo = new PDO($dsn);
            } else {
                $dsn = $engine
                    . ':host=' . (string) $params['host']
                    . ';port=' . (string) $params['port']
                    . ';dbname=' . (string) $params['dbname'];
                $pdo = new PDO(
                    $dsn,
                    (string) $params['username'],
                    (string) $params['passwd'],
                    is_array($params['options']) ? $params['options'] : []
                );
            }

            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo = $pdo;
            return $pdo;
        }

        throw new RuntimeException('PdoStorage: pdo config is not set or invalid');
    }

    private function ensureTable(PDO $pdo): void
    {
        if ($this->tableCreated) {
            return;
        }

        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $idCol = 'id INTEGER PRIMARY KEY AUTOINCREMENT';
        } elseif ($driver === 'pgsql') {
            $idCol = 'id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY';
        } else {
            // mysql / mariadb
            $idCol = 'id INT AUTO_INCREMENT PRIMARY KEY';
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . $this->pdoTableName . ' ('
            . $idCol . ','
            . ' datetime VARCHAR(40) NOT NULL,'
            . ' level INT NOT NULL,'
            . ' level_name VARCHAR(20) NOT NULL,'
            . ' channel VARCHAR(100) NOT NULL,'
            . ' message TEXT NOT NULL,'
            . ' context TEXT,'
            . ' extra TEXT,'
            . ' host VARCHAR(255),'
            . ' url TEXT'
            . ')'
        );

        $this->tableCreated = true;
    }
}
