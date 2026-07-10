<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Command;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use SparkInsight\Command\ListContentImportsCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class ListContentImportsCommandTest extends TestCase
{
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/sparkinsight_list_imports_' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    public function testExecuteShowsNoImportsWhenTableIsEmpty(): void
    {
        $connection = $this->createConnection();
        $command = new ListContentImportsCommand($connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No imported content found.', $tester->getDisplay());
    }

    public function testExecuteListsImportsWithIds(): void
    {
        $connection = $this->createConnection();
        $connection->executeStatement(
            "INSERT INTO content_versions (title, book_title, version_label, status, import_batch_id, imported_at, created_at, updated_at) VALUES ('Chapter 1', 'Enterprise Community Management', 'v1', 'ready', 'imp_abc123', datetime('now', '-1 day'), datetime('now'), datetime('now'))"
        );
        $connection->executeStatement(
            "INSERT INTO content_versions (title, book_title, version_label, status, import_batch_id, imported_at, created_at, updated_at) VALUES ('Chapter 2', 'Enterprise Community Management', 'v2', 'placeholder', 'imp_abc123', datetime('now'), datetime('now'), datetime('now'))"
        );

        $command = new ListContentImportsCommand($connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--limit' => '10']);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Import batches', $display);
        $this->assertStringContainsString('imp_abc123', $display);
        $this->assertStringContainsString('2', $display);
        $this->assertStringContainsString('Listed 1 import(s).', $display);
        $this->assertStringContainsString('content:purge-imports --force --id=<id>', $display);
    }

    private function createConnection(): \Doctrine\DBAL\Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->dbFile,
        ]);

        $connection->executeStatement(
            'CREATE TABLE content_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, book_title TEXT NULL, version_label TEXT NOT NULL, status TEXT NOT NULL, import_batch_id TEXT NULL, imported_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)'
        );

        return $connection;
    }
}
