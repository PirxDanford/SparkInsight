<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Command;

use PHPUnit\Framework\TestCase;
use SparkInsight\Command\MigrateDbCommand;
use Symfony\Component\Console\Tester\CommandTester;

class MigrateDbCommandTest extends TestCase
{
    private string $dbFile;
    private string $migrationDir;

    protected function setUp(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $_ENV['DB_DRIVER'] = 'pdo_sqlite';
        $_ENV['DB_USER'] = '';
        $_ENV['DB_PASSWORD'] = '';
        $_ENV['DB_CHARSET'] = 'utf8mb4';

        $this->migrationDir = sys_get_temp_dir() . '/sparkinsight_migrations_' . bin2hex(random_bytes(8));
        mkdir($this->migrationDir);
        $this->dbFile = sys_get_temp_dir() . '/sparkinsight_db_' . bin2hex(random_bytes(8)) . '.sqlite';
        $_ENV['DB_NAME'] = $this->dbFile;
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_ENV']);
        unset($_ENV['APP_URL']);
        unset($_ENV['DB_DRIVER']);
        unset($_ENV['DB_NAME']);
        unset($_ENV['DB_USER']);
        unset($_ENV['DB_PASSWORD']);
        unset($_ENV['DB_CHARSET']);

        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }

        if (is_dir($this->migrationDir)) {
            $files = glob($this->migrationDir . '/*');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->migrationDir);
        }
    }

    public function testCommandNameAndDescription(): void
    {
        $command = new MigrateDbCommand();

        $this->assertSame('db:migrate', $command->getName());
        $this->assertStringContainsString('Run database migrations', $command->getDescription());
    }

    public function testExecuteRunsPendingMigrations(): void
    {
        file_put_contents($this->migrationDir . '/001_initial_schema.sql', <<<'SQL'
-- Migration: 001_initial_schema
-- UP
CREATE TABLE schema_version (version INT PRIMARY KEY, applied_at DATETIME NOT NULL);
CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
INSERT INTO schema_version (version, applied_at) VALUES (1, datetime('now'));
-- DOWN
DROP TABLE test_table;
DROP TABLE schema_version;
SQL
        );

        file_put_contents($this->migrationDir . '/002_add_audit_log.sql', <<<'SQL'
-- Migration: 002_add_audit_log
-- UP
CREATE TABLE audit_log (id INTEGER PRIMARY KEY, test_table_id INT, event TEXT NOT NULL);
INSERT INTO schema_version (version, applied_at) VALUES (2, datetime('now'));
-- DOWN
DROP TABLE audit_log;
SQL
        );

        $command = new MigrateDbCommand($this->migrationDir);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Executed migrations: 001_initial_schema.sql, 002_add_audit_log.sql', $tester->getDisplay());

        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='audit_log'");
        $this->assertNotFalse($result);
        $this->assertSame('audit_log', $result->fetchColumn());
    }

    public function testStatusShowsPendingMigrations(): void
    {
        file_put_contents($this->migrationDir . '/001_initial_schema.sql', <<<'SQL'
-- Migration: 001_initial_schema
-- UP
CREATE TABLE schema_version (version INT PRIMARY KEY, applied_at DATETIME NOT NULL);
INSERT INTO schema_version (version, applied_at) VALUES (1, datetime('now'));
-- DOWN
DROP TABLE schema_version;
SQL
        );

        file_put_contents($this->migrationDir . '/002_add_audit_log.sql', <<<'SQL'
-- Migration: 002_add_audit_log
-- UP
CREATE TABLE audit_log (id INTEGER PRIMARY KEY, event TEXT NOT NULL);
INSERT INTO schema_version (version, applied_at) VALUES (2, datetime('now'));
-- DOWN
DROP TABLE audit_log;
SQL
        );

        $command = new MigrateDbCommand($this->migrationDir);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--status' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Current schema version: 0', $tester->getDisplay());
        $this->assertStringContainsString('Pending migrations:', $tester->getDisplay());
        $this->assertStringContainsString('001_initial_schema.sql', $tester->getDisplay());
    }

    public function testRollbackRemovesLastMigration(): void
    {
        file_put_contents($this->migrationDir . '/001_initial_schema.sql', <<<'SQL'
-- Migration: 001_initial_schema
-- UP
CREATE TABLE schema_version (version INT PRIMARY KEY, applied_at DATETIME NOT NULL);
INSERT INTO schema_version (version, applied_at) VALUES (1, datetime('now'));
-- DOWN
DROP TABLE schema_version;
SQL
        );

        file_put_contents($this->migrationDir . '/002_add_audit_log.sql', <<<'SQL'
-- Migration: 002_add_audit_log
-- UP
CREATE TABLE audit_log (id INTEGER PRIMARY KEY, event TEXT NOT NULL);
INSERT INTO schema_version (version, applied_at) VALUES (2, datetime('now'));
-- DOWN
DROP TABLE audit_log;
SQL
        );

        $command = new MigrateDbCommand($this->migrationDir);
        $tester = new CommandTester($command);

        $tester->execute([]);
        $exitCode = $tester->execute(['--rollback' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Rolled back migration: 002_add_audit_log.sql', $tester->getDisplay());

        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='audit_log'");
        $this->assertFalse($result->fetchColumn());

        $versionCount = (int) $pdo->query('SELECT COUNT(*) FROM schema_version')->fetchColumn();
        $this->assertSame(1, $versionCount);
    }
}
