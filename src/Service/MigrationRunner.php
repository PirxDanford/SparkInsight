<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final class MigrationRunner
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function getCurrentVersion(): int
    {
        try {
            $result = $this->connection->executeQuery('SELECT MAX(version) FROM schema_version');
            return (int) $result->fetchOne();
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function runMigrations(string $migrationsDir): array
    {
        $currentVersion = $this->getCurrentVersion();
        $executed = [];

        $files = glob($migrationsDir . '/*.sql');
        sort($files);

        foreach ($files as $file) {
            $filename = basename($file);
            if (!preg_match('/^(\d+)_/', $filename, $matches)) {
                continue;
            }
            $version = (int) $matches[1];

            if ($version <= $currentVersion) {
                continue;
            }

            $content = file_get_contents($file);
            $parts = explode('-- DOWN', $content);
            $upSql = trim($parts[0]);

            $this->executeSqlStatements($upSql);
            $executed[] = $filename;
        }

        return $executed;
    }

    public function getMigrationFiles(string $migrationsDir): array
    {
        $files = glob($migrationsDir . '/*.sql');
        sort($files);

        return $files;
    }

    public function getPendingMigrations(string $migrationsDir): array
    {
        $currentVersion = $this->getCurrentVersion();
        $pending = [];

        foreach ($this->getMigrationFiles($migrationsDir) as $file) {
            $filename = basename($file);
            if (!preg_match('/^(\d+)_/', $filename, $matches)) {
                continue;
            }
            $version = (int) $matches[1];

            if ($version > $currentVersion) {
                $pending[] = $filename;
            }
        }

        return $pending;
    }

    public function rollbackLastMigration(string $migrationsDir): ?string
    {
        $currentVersion = $this->getCurrentVersion();
        if ($currentVersion <= 0) {
            return null;
        }

        $rollbackFile = null;
        foreach ($this->getMigrationFiles($migrationsDir) as $file) {
            $filename = basename($file);
            if (!preg_match('/^(\d+)_/', $filename, $matches)) {
                continue;
            }
            $version = (int) $matches[1];

            if ($version === $currentVersion) {
                $rollbackFile = $file;
                break;
            }
        }

        if ($rollbackFile === null) {
            return null;
        }

        [$upSql, $downSql] = $this->parseMigrationFile($rollbackFile);
        if ($downSql === '') {
            throw new \RuntimeException('No rollback section found for migration ' . basename($rollbackFile));
        }

        $this->executeSqlStatements($downSql);
        $this->connection->executeStatement('DELETE FROM schema_version WHERE version = ?', [$currentVersion]);

        return basename($rollbackFile);
    }

    private function parseMigrationFile(string $file): array
    {
        $content = file_get_contents($file);
        $parts = preg_split('/^--\s*DOWN\s*$/mi', $content, 2);

        $upSql = trim($parts[0]);
        $downSql = isset($parts[1]) ? trim($parts[1]) : '';

        return [$upSql, $downSql];
    }

    private function executeSqlStatements(string $sql): void
    {
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        foreach ($statements as $statement) {
            if ($statement === '') {
                continue;
            }

            $this->connection->executeStatement($statement);
        }
    }
}
