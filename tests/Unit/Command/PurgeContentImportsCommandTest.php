<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Command;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use SparkInsight\Command\PurgeContentImportsCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeContentImportsCommandTest extends TestCase
{
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/sparkinsight_purge_' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    public function testExecuteRequiresForceOption(): void
    {
        $connection = $this->createConnection();
        $command = new PurgeContentImportsCommand($connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Refusing to delete imports without --force.', $tester->getDisplay());
    }

    public function testExecutePurgesImportedContent(): void
    {
        $connection = $this->createConnection();
        $connection->executeStatement("INSERT INTO content_versions (title, version_label, status, import_batch_id, imported_at, created_at, updated_at) VALUES ('Test Chapter', 'v1', 'ready', 'imp_1', datetime('now'), datetime('now'), datetime('now'))");
        $contentVersionId = (int) $connection->lastInsertId();
        $connection->executeStatement("INSERT INTO review_assignments (content_version_id, reviewer_id, priority, created_at, updated_at) VALUES (?, 1, 'normal', datetime('now'), datetime('now'))", [$contentVersionId]);
        $connection->executeStatement("INSERT INTO reviews (content_version_id, reviewer_id, title, status, created_at, updated_at) VALUES (?, 1, 'Note', 'open', datetime('now'), datetime('now'))", [$contentVersionId]);

        $command = new PurgeContentImportsCommand($connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--force' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Purged 1 imported content version(s).', $tester->getDisplay());

        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM content_versions'));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM reviews'));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM review_assignments'));
    }

    public function testExecutePurgesSpecificImportIdsOnly(): void
    {
        $connection = $this->createConnection();
        $connection->executeStatement("INSERT INTO content_versions (title, version_label, status, import_batch_id, imported_at, created_at, updated_at) VALUES ('Keep Chapter', 'v1', 'ready', 'imp_keep', datetime('now'), datetime('now'), datetime('now'))");
        $keepId = (int) $connection->lastInsertId();

        $connection->executeStatement("INSERT INTO content_versions (title, version_label, status, import_batch_id, imported_at, created_at, updated_at) VALUES ('Purge Chapter', 'v2', 'ready', 'imp_purge', datetime('now'), datetime('now'), datetime('now'))");
        $purgeId = (int) $connection->lastInsertId();

        $connection->executeStatement("INSERT INTO review_assignments (content_version_id, reviewer_id, priority, created_at, updated_at) VALUES (?, 1, 'normal', datetime('now'), datetime('now'))", [$purgeId]);
        $connection->executeStatement("INSERT INTO reviews (content_version_id, reviewer_id, title, status, created_at, updated_at) VALUES (?, 1, 'Note', 'open', datetime('now'), datetime('now'))", [$purgeId]);

        $command = new PurgeContentImportsCommand($connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--force' => true, '--id' => ['imp_purge']]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Purged 1 imported content version(s) from import batch(es): imp_purge', $tester->getDisplay());

        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM content_versions WHERE id = ?', [$keepId]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM content_versions WHERE id = ?', [$purgeId]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM reviews WHERE content_version_id = ?', [$purgeId]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM review_assignments WHERE content_version_id = ?', [$purgeId]));
    }

    private function createConnection(): \Doctrine\DBAL\Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->dbFile,
        ]);

        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $connection->executeStatement('CREATE TABLE content_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, version_label TEXT NOT NULL, source TEXT NULL, content_rtf TEXT NULL, content_text TEXT NULL, status TEXT NOT NULL, import_batch_id TEXT NULL, imported_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $connection->executeStatement('CREATE TABLE review_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, content_version_id INTEGER NOT NULL, reviewer_id INTEGER NOT NULL, priority TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, FOREIGN KEY(content_version_id) REFERENCES content_versions(id) ON DELETE CASCADE)');
        $connection->executeStatement('CREATE TABLE reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, content_version_id INTEGER NOT NULL, reviewer_id INTEGER, title TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, FOREIGN KEY(content_version_id) REFERENCES content_versions(id) ON DELETE CASCADE)');

        return $connection;
    }
}