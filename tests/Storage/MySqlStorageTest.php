<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Yakb\Tests\Storage;

use Shawware\Yakb\Storage\MigrationRunner;
use Shawware\Yakb\Storage\MySqlStorage;
use Shawware\Yakb\Storage\StorageInterface;

/**
 * Runs the shared StorageContractTestCase against a real MySQL connection,
 * proving MySqlStorage's SQL behaves the same as InMemoryStorage.
 *
 * Connects to a local MySQL instance by default (created for YAKB
 * development: `CREATE DATABASE yakb_test`). Override via TEST_DB_DSN /
 * TEST_DB_USER / TEST_DB_PASS to point at a different instance (e.g. a
 * DreamHost test database).
 */
final class MySqlStorageTest extends StorageContractTestCase
{
    private static \PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$pdo = self::connect();
        } catch (\PDOException $e) {
            self::markTestSkipped('No test MySQL connection available: ' . $e->getMessage());
        }

        (new MigrationRunner(self::$pdo, __DIR__ . '/../../storage/migrations'))->run();
    }

    protected function setUp(): void
    {
        self::$pdo->exec('TRUNCATE TABLE karma');
        self::$pdo->exec('TRUNCATE TABLE events');
    }

    protected function createStorage(): StorageInterface
    {
        return new MySqlStorage(self::$pdo);
    }

    private static function connect(): \PDO
    {
        $dsn = getenv('TEST_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=yakb_test;charset=utf8mb4';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') ?: '';

        return new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
