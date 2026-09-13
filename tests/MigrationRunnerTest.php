<?php

declare(strict_types=1);

namespace Shawware\Yakb\Tests;

use PHPUnit\Framework\TestCase;
use Shawware\Yakb\Storage\MigrationRunner;

final class MigrationRunnerTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=yakb_test;charset=utf8mb4';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') ?: '';

        try {
            $this->pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException $e) {
            self::markTestSkipped('No test MySQL connection available: ' . $e->getMessage());
        }

        // Start from a clean slate so this test proves a fresh apply, not
        // just a no-op against tables another test already created.
        $this->pdo->exec('DROP TABLE IF EXISTS events');
        $this->pdo->exec('DROP TABLE IF EXISTS scores');
        $this->pdo->exec('DROP TABLE IF EXISTS schema_migrations');
    }

    public function testRunCreatesBothTablesWithTheExpectedIndex(): void
    {
        $runner = new MigrationRunner($this->pdo, __DIR__ . '/../storage/migrations');
        $applied = $runner->run();

        $this->assertSame(['001_create_scores.sql', '002_create_events.sql'], $applied);

        $tables = $this->pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('scores', $tables);
        $this->assertContains('events', $tables);

        $indexes = $this->pdo->query('SHOW INDEX FROM events WHERE Key_name = \'idx_events_timestamp\'')
            ->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($indexes, 'events.timestamp must have an index');
    }

    public function testRunTwiceIsIdempotent(): void
    {
        $runner = new MigrationRunner($this->pdo, __DIR__ . '/../storage/migrations');

        $firstRun = $runner->run();
        $secondRun = $runner->run();

        $this->assertSame(['001_create_scores.sql', '002_create_events.sql'], $firstRun);
        $this->assertSame([], $secondRun, 'a second run should apply nothing new');

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        $this->assertSame(2, $count);
    }
}
