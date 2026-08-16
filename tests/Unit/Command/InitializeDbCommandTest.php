<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Command;

use PHPUnit\Framework\TestCase;
use SparkInsight\Command\InitializeDbCommand;
use Symfony\Component\Console\Tester\CommandTester;

class InitializeDbCommandTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['DB_DRIVER'] = 'invalid_driver';
        $_ENV['DB_HOST'] = 'localhost';
        $_ENV['DB_PORT'] = '3306';
        $_ENV['DB_NAME'] = 'sparkinsight_test';
        $_ENV['DB_USER'] = 'root';
        $_ENV['DB_PASSWORD'] = '';
        $_ENV['DB_CHARSET'] = 'utf8mb4';
    }

    protected function tearDown(): void
    {
        unset($_ENV['DB_DRIVER'], $_ENV['DB_HOST'], $_ENV['DB_PORT'], $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $_ENV['DB_CHARSET']);
    }

    public function testExecuteReturnsFailureWhenConnectionCannotBeCreated(): void
    {
        $command = new InitializeDbCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Cannot connect to database', $tester->getDisplay());
    }

    public function testCommandMetadata(): void
    {
        $command = new InitializeDbCommand();
        $this->assertSame('db:init', $command->getName());
        $this->assertStringContainsString('Initialize the database by running all migrations', $command->getDescription());
    }

    public function testExecuteReportsAlreadyInitializedDatabase(): void
    {
        $dbFile = sys_get_temp_dir() . '/sparkinsight-init-command-' . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbFile]);
        $connection->executeStatement('CREATE TABLE schema_version (version INTEGER NOT NULL PRIMARY KEY, applied_at TEXT NOT NULL)');
        $connection->executeStatement("INSERT INTO schema_version (version, applied_at) VALUES (20260101000000, '2026-01-01 00:00:00')");
        $connection->close();

        $_ENV['DB_DRIVER'] = 'pdo_sqlite';
        $_ENV['DB_NAME'] = $dbFile;

        $command = new InitializeDbCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Database already initialized', $tester->getDisplay());

        unlink($dbFile);
    }

    public function testExecuteCreatesDatabaseWhenItDoesNotExist(): void
    {
        $dbFile = sys_get_temp_dir() . '/sparkinsight-init-command-create-' . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbFile]);
        $connection->executeStatement('CREATE TABLE schema_version (version INTEGER NOT NULL PRIMARY KEY, applied_at TEXT NOT NULL)');
        $connection->executeStatement("INSERT INTO schema_version (version, applied_at) VALUES (999999999999, '2026-01-01 00:00:00')");
        $connection->close();

        $_ENV['DB_DRIVER'] = 'pdo_sqlite';
        $_ENV['DB_NAME'] = $dbFile;

        $command = new InitializeDbCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Database already initialized', $tester->getDisplay());

        if (file_exists($dbFile)) {
            unlink($dbFile);
        }
    }
}
