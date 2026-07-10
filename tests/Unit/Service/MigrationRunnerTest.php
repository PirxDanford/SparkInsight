<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use SparkInsight\Service\MigrationRunner;

class MigrationRunnerTest extends TestCase
{
    private Connection $connection;
    private MigrationRunner $runner;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->runner = new MigrationRunner($this->connection);
    }

    public function testGetCurrentVersion(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn('2');

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT MAX(version) FROM schema_version')
            ->willReturn($resultMock);

        $version = $this->runner->getCurrentVersion();

        $this->assertEquals(2, $version);
    }

    public function testGetCurrentVersionWhenNoTable(): void
    {
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willThrowException(new \Exception('Table not found'));

        $version = $this->runner->getCurrentVersion();

        $this->assertEquals(0, $version);
    }

    public function testGetPendingMigrationsReturnsFilesGreaterThanCurrentVersion(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn('1');

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT MAX(version) FROM schema_version')
            ->willReturn($resultMock);

        $tempDir = sys_get_temp_dir() . '/sparkinsight_migrations_' . bin2hex(random_bytes(8));
        mkdir($tempDir);
        file_put_contents($tempDir . '/001_initial_schema.sql', 'CREATE TABLE schema_version (version INT PRIMARY KEY); INSERT INTO schema_version (version) VALUES (1);');
        file_put_contents($tempDir . '/002_add_review.sql', 'CREATE TABLE reviews (id INT PRIMARY KEY); INSERT INTO schema_version (version) VALUES (2);');

        try {
            $pending = $this->runner->getPendingMigrations($tempDir);
            $this->assertSame(['002_add_review.sql'], $pending);
        } finally {
            unlink($tempDir . '/001_initial_schema.sql');
            unlink($tempDir . '/002_add_review.sql');
            rmdir($tempDir);
        }
    }

    public function testRollbackLastMigrationExecutesDownSectionAndDeletesVersion(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn('2');

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT MAX(version) FROM schema_version')
            ->willReturn($resultMock);

        $executedSql = [];
        $this->connection->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$executedSql) {
                $executedSql[] = trim($sql);
                return 1;
            });

        $tempDir = sys_get_temp_dir() . '/sparkinsight_migrations_' . bin2hex(random_bytes(8));
        mkdir($tempDir);
        file_put_contents($tempDir . '/001_initial_schema.sql', "CREATE TABLE schema_version (version INT PRIMARY KEY); INSERT INTO schema_version (version) VALUES (1);\n-- DOWN\nDROP TABLE schema_version;");
        file_put_contents($tempDir . '/002_add_review.sql', "CREATE TABLE reviews (id INT PRIMARY KEY); INSERT INTO schema_version (version) VALUES (2);\n-- DOWN\nDROP TABLE reviews;");

        try {
            $rolledBack = $this->runner->rollbackLastMigration($tempDir);
            $this->assertSame('002_add_review.sql', $rolledBack);
            $this->assertSame(['DROP TABLE reviews', 'DELETE FROM schema_version WHERE version = ?'], $executedSql);
        } finally {
            unlink($tempDir . '/001_initial_schema.sql');
            unlink($tempDir . '/002_add_review.sql');
            rmdir($tempDir);
        }
    }

    public function testRunMigrationsExecutesNewSqlFiles(): void
    {
        $resultMock = $this->createMock(\Doctrine\DBAL\Result::class);
        $resultMock->method('fetchOne')->willReturn(false);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT MAX(version) FROM schema_version')
            ->willReturn($resultMock);

        $expectedStatements = [
            'CREATE TABLE test_a (id INTEGER PRIMARY KEY)',
            'INSERT INTO test_a (id) VALUES (1)',
        ];
        $statementIndex = 0;

        $this->connection->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$statementIndex, $expectedStatements) {
                $this->assertSame($expectedStatements[$statementIndex], $sql);
                $statementIndex++;
                return 1;
            });

        $tempDir = sys_get_temp_dir() . '/sparkinsight_migrations_' . bin2hex(random_bytes(8));
        mkdir($tempDir);
        file_put_contents($tempDir . '/001_create_table.sql', 'CREATE TABLE test_a (id INTEGER PRIMARY KEY);');
        file_put_contents($tempDir . '/002_insert_data.sql', 'INSERT INTO test_a (id) VALUES (1);');

        try {
            $executed = $this->runner->runMigrations($tempDir);
            $this->assertEquals(['001_create_table.sql', '002_insert_data.sql'], $executed);
        } finally {
            unlink($tempDir . '/001_create_table.sql');
            unlink($tempDir . '/002_insert_data.sql');
            rmdir($tempDir);
        }
    }
    public function testRunMigrationsSkipsAlreadyAppliedVersions(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn('2');

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT MAX(version) FROM schema_version')
            ->willReturn($resultMock);

        $this->connection->expects($this->never())
            ->method('executeStatement');

        $tempDir = sys_get_temp_dir() . '/sparkinsight_migrations_' . bin2hex(random_bytes(8));
        mkdir($tempDir);
        file_put_contents($tempDir . '/001_create_table.sql', 'CREATE TABLE test_a (id INTEGER PRIMARY KEY);');
        file_put_contents($tempDir . '/002_insert_data.sql', 'INSERT INTO test_a (id) VALUES (1);');

        try {
            $executed = $this->runner->runMigrations($tempDir);
            $this->assertSame([], $executed);
        } finally {
            unlink($tempDir . '/001_create_table.sql');
            unlink($tempDir . '/002_insert_data.sql');
            rmdir($tempDir);
        }
    }

    public function testRunMigrationsSkipsFilesWithoutVersionNumber(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn(0);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT MAX(version) FROM schema_version')
            ->willReturn($resultMock);

        $this->connection->expects($this->exactly(1))
            ->method('executeStatement')
            ->with('CREATE TABLE test_a (id INTEGER PRIMARY KEY)');

        $tempDir = sys_get_temp_dir() . '/sparkinsight_migrations_' . bin2hex(random_bytes(8));
        mkdir($tempDir);
        file_put_contents($tempDir . '/001_create_table.sql', 'CREATE TABLE test_a (id INTEGER PRIMARY KEY);');
        file_put_contents($tempDir . '/invalid_file.sql', 'INVALID SQL');
        file_put_contents($tempDir . '/readme.txt', 'This is not a migration');

        try {
            $executed = $this->runner->runMigrations($tempDir);
            $this->assertEquals(['001_create_table.sql'], $executed);
        } finally {
            unlink($tempDir . '/001_create_table.sql');
            unlink($tempDir . '/invalid_file.sql');
            unlink($tempDir . '/readme.txt');
            rmdir($tempDir);
        }
    }

    public function testGetCurrentVersionReturnsZeroWhenTableDoesNotExist(): void
    {
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willThrowException(new \Exception('Table schema_version does not exist'));

        $version = $this->runner->getCurrentVersion();

        $this->assertSame(0, $version);
    }

    public function testRunMigrationsExecutesMixedVersions(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn(1);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT MAX(version) FROM schema_version')
            ->willReturn($resultMock);

        $this->connection->expects($this->exactly(1))
            ->method('executeStatement')
            ->with('INSERT INTO test_a (id) VALUES (1)');

        $tempDir = sys_get_temp_dir() . '/sparkinsight_migrations_' . bin2hex(random_bytes(8));
        mkdir($tempDir);
        file_put_contents($tempDir . '/001_create_table.sql', 'CREATE TABLE test_a (id INTEGER PRIMARY KEY);');
        file_put_contents($tempDir . '/002_insert_data.sql', 'INSERT INTO test_a (id) VALUES (1);');

        try {
            $executed = $this->runner->runMigrations($tempDir);
            $this->assertEquals(['002_insert_data.sql'], $executed);
        } finally {
            unlink($tempDir . '/001_create_table.sql');
            unlink($tempDir . '/002_insert_data.sql');
            rmdir($tempDir);
        }
    }

    public function testRunMigrationsWithMigrationContainingDownPart(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn(0);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT MAX(version) FROM schema_version')
            ->willReturn($resultMock);

        $this->connection->expects($this->exactly(1))
            ->method('executeStatement')
            ->with('CREATE TABLE test_a (id INTEGER PRIMARY KEY)');

        $tempDir = sys_get_temp_dir() . '/sparkinsight_migrations_' . bin2hex(random_bytes(8));
        mkdir($tempDir);

        $migrationContent = <<<SQL
CREATE TABLE test_a (id INTEGER PRIMARY KEY);
-- DOWN
DROP TABLE test_a;
SQL;
        file_put_contents($tempDir . '/001_create_table.sql', $migrationContent);

        try {
            $executed = $this->runner->runMigrations($tempDir);
            $this->assertEquals(['001_create_table.sql'], $executed);
        } finally {
            unlink($tempDir . '/001_create_table.sql');
            rmdir($tempDir);
        }
    }

    public function testRunMigrationsWithEmptyDirectory(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn(0);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT MAX(version) FROM schema_version')
            ->willReturn($resultMock);

        $this->connection->expects($this->never())
            ->method('executeStatement');

        $tempDir = sys_get_temp_dir() . '/sparkinsight_migrations_' . bin2hex(random_bytes(8));
        mkdir($tempDir);

        try {
            $executed = $this->runner->runMigrations($tempDir);
            $this->assertSame([], $executed);
        } finally {
            rmdir($tempDir);
        }
    }

    public function testRunMigrationsFilesAreSorted(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn(0);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT MAX(version) FROM schema_version')
            ->willReturn($resultMock);

        $expectedOrder = [
            '001_first.sql',
            '002_second.sql',
            '010_tenth.sql',
        ];
        $callIndex = 0;

        $this->connection->expects($this->exactly(3))
            ->method('executeStatement')
            ->willReturnCallback(function () use (&$callIndex, $expectedOrder) {
                $callIndex++;
                return 1;
            });

        $tempDir = sys_get_temp_dir() . '/sparkinsight_migrations_' . bin2hex(random_bytes(8));
        mkdir($tempDir);
        file_put_contents($tempDir . '/010_tenth.sql', 'CREATE TABLE ten (id INT);');
        file_put_contents($tempDir . '/001_first.sql', 'CREATE TABLE one (id INT);');
        file_put_contents($tempDir . '/002_second.sql', 'CREATE TABLE two (id INT);');

        try {
            $executed = $this->runner->runMigrations($tempDir);
            $this->assertEquals($expectedOrder, $executed);
        } finally {
            unlink($tempDir . '/010_tenth.sql');
            unlink($tempDir . '/001_first.sql');
            unlink($tempDir . '/002_second.sql');
            rmdir($tempDir);
        }
    }

    public function testRunMigrationsIncludesDevOnlyFilesBySchemaVersionOrder(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn(1);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT MAX(version) FROM schema_version')
            ->willReturn($resultMock);

        $this->connection->expects($this->exactly(4))
            ->method('executeStatement')
            ->willReturn(1);

        $tempDir = sys_get_temp_dir() . '/sparkinsight_migrations_' . bin2hex(random_bytes(8));
        mkdir($tempDir);
        file_put_contents($tempDir . '/001_initial_schema.sql', "CREATE TABLE schema_version (version INT PRIMARY KEY);\nINSERT INTO schema_version (version, applied_at) VALUES (1, NOW());");
        file_put_contents($tempDir . '/dev_only_add_user_tracking_fields.sql', "ALTER TABLE users ADD COLUMN last_login DATETIME NULL;\nINSERT INTO schema_version (version, applied_at) VALUES (2, NOW());");
        file_put_contents($tempDir . '/dev_only_add_content_versions_and_reviews.sql', "CREATE TABLE content_versions (id INT PRIMARY KEY);\nINSERT INTO schema_version (version, applied_at) VALUES (3, NOW());");

        try {
            $executed = $this->runner->runMigrations($tempDir);
            $this->assertSame([
                'dev_only_add_user_tracking_fields.sql',
                'dev_only_add_content_versions_and_reviews.sql',
            ], $executed);
        } finally {
            unlink($tempDir . '/001_initial_schema.sql');
            unlink($tempDir . '/dev_only_add_user_tracking_fields.sql');
            unlink($tempDir . '/dev_only_add_content_versions_and_reviews.sql');
            rmdir($tempDir);
        }
    }

    public function testGetPendingMigrationsIncludesDevOnlyFiles(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn('2');

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT MAX(version) FROM schema_version')
            ->willReturn($resultMock);

        $tempDir = sys_get_temp_dir() . '/sparkinsight_migrations_' . bin2hex(random_bytes(8));
        mkdir($tempDir);
        file_put_contents($tempDir . '/001_initial_schema.sql', 'CREATE TABLE schema_version (version INT PRIMARY KEY);');
        file_put_contents($tempDir . '/dev_only_add_content_versions_and_reviews.sql', "CREATE TABLE content_versions (id INT PRIMARY KEY);\nINSERT INTO schema_version (version, applied_at) VALUES (3, NOW());");

        try {
            $pending = $this->runner->getPendingMigrations($tempDir);
            $this->assertSame(['dev_only_add_content_versions_and_reviews.sql'], $pending);
        } finally {
            unlink($tempDir . '/001_initial_schema.sql');
            unlink($tempDir . '/dev_only_add_content_versions_and_reviews.sql');
            rmdir($tempDir);
        }
    }

    public function testGetPendingMigrationsIgnoresDevOnlyFileWithoutSchemaVersionMarker(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn('2');

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT MAX(version) FROM schema_version')
            ->willReturn($resultMock);

        $tempDir = sys_get_temp_dir() . '/sparkinsight_migrations_' . bin2hex(random_bytes(8));
        mkdir($tempDir);
        file_put_contents($tempDir . '/001_initial_schema.sql', 'CREATE TABLE schema_version (version INT PRIMARY KEY);');
        file_put_contents($tempDir . '/dev_only_missing_schema_marker.sql', 'ALTER TABLE reviews ADD COLUMN demo_flag TINYINT(1) NOT NULL DEFAULT 0;');
        file_put_contents($tempDir . '/dev_only_add_content_versions_and_reviews.sql', "CREATE TABLE content_versions (id INT PRIMARY KEY);\nINSERT INTO schema_version (version, applied_at) VALUES (3, NOW());");

        try {
            $pending = $this->runner->getPendingMigrations($tempDir);
            $this->assertSame(['dev_only_add_content_versions_and_reviews.sql'], $pending);
        } finally {
            unlink($tempDir . '/001_initial_schema.sql');
            unlink($tempDir . '/dev_only_missing_schema_marker.sql');
            unlink($tempDir . '/dev_only_add_content_versions_and_reviews.sql');
            rmdir($tempDir);
        }
    }

    public function testRollbackLastMigrationSupportsDevOnlyFile(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn('3');

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT MAX(version) FROM schema_version')
            ->willReturn($resultMock);

        $executedSql = [];
        $this->connection->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$executedSql) {
                $executedSql[] = trim($sql);
                return 1;
            });

        $tempDir = sys_get_temp_dir() . '/sparkinsight_migrations_' . bin2hex(random_bytes(8));
        mkdir($tempDir);
        file_put_contents($tempDir . '/001_initial_schema.sql', "CREATE TABLE schema_version (version INT PRIMARY KEY);\n-- DOWN\nDROP TABLE schema_version;");
        file_put_contents($tempDir . '/dev_only_add_content_versions_and_reviews.sql', "CREATE TABLE content_versions (id INT PRIMARY KEY);\nINSERT INTO schema_version (version, applied_at) VALUES (3, NOW());\n-- DOWN\nDROP TABLE content_versions;");

        try {
            $rolledBack = $this->runner->rollbackLastMigration($tempDir);
            $this->assertSame('dev_only_add_content_versions_and_reviews.sql', $rolledBack);
            $this->assertSame(['DROP TABLE content_versions', 'DELETE FROM schema_version WHERE version = ?'], $executedSql);
        } finally {
            unlink($tempDir . '/001_initial_schema.sql');
            unlink($tempDir . '/dev_only_add_content_versions_and_reviews.sql');
            rmdir($tempDir);
        }
    }
}


