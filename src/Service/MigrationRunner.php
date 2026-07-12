<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use Doctrine\DBAL\Connection;
use Exception;
use RuntimeException;

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
        } catch (Exception $e) {
            return 0;
        }
    }

    public function runMigrations(string $migrationsDir): array
    {
        $currentVersion = $this->getCurrentVersion();
        $executed = [];

        foreach ($this->getMigrationFiles($migrationsDir) as $file) {
            $filename = basename($file);
            $version = $this->getMigrationVersion($file);
            if ($version === null) {
                continue;
            }

            if ($version <= $currentVersion) {
                continue;
            }

            $content = file_get_contents($file);
            $parts = explode('-- DOWN', $content);
            $upSql = mb_trim($parts[0]);

            $this->executeSqlStatements($upSql);
            $executed[] = $filename;
        }

        return $executed;
    }

    public function getMigrationFiles(string $migrationsDir): array
    {
        $files = glob($migrationsDir . '/*.sql');

        usort($files, function (string $left, string $right): int {
            $leftVersion = $this->getMigrationVersion($left);
            $rightVersion = $this->getMigrationVersion($right);

            if ($leftVersion === null && $rightVersion === null) {
                return strcmp(basename($left), basename($right));
            }

            if ($leftVersion === null) {
                return 1;
            }

            if ($rightVersion === null) {
                return -1;
            }

            if ($leftVersion === $rightVersion) {
                return strcmp(basename($left), basename($right));
            }

            return $leftVersion <=> $rightVersion;
        });

        return $files;
    }

    public function getPendingMigrations(string $migrationsDir): array
    {
        $currentVersion = $this->getCurrentVersion();
        $pending = [];

        foreach ($this->getMigrationFiles($migrationsDir) as $file) {
            $version = $this->getMigrationVersion($file);
            if ($version === null) {
                continue;
            }
            $filename = basename($file);

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
            $version = $this->getMigrationVersion($file);
            if ($version === null) {
                continue;
            }

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
            throw new RuntimeException('No rollback section found for migration ' . basename($rollbackFile));
        }

        $this->executeSqlStatements($downSql);
        $this->connection->executeStatement('DELETE FROM schema_version WHERE version = ?', [$currentVersion]);

        return basename($rollbackFile);
    }

    private function parseMigrationFile(string $file): array
    {
        $content = file_get_contents($file);
        $parts = preg_split('/^--\s*DOWN\s*$/mi', $content, 2);

        $upSql = mb_trim($parts[0]);
        $downSql = isset($parts[1]) ? mb_trim($parts[1]) : '';

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

    private function getMigrationVersion(string $file): ?int
    {
        $filename = basename($file);
        if (preg_match('/^(\d+)_/', $filename, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^dev_only_.*\.sql$/', $filename) !== 1) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        if (
            preg_match('/INSERT\s+INTO\s+schema_version\s*\(\s*version\s*,\s*applied_at\s*\)\s*VALUES\s*\(\s*(\d+)\s*,/i', $content, $matches) === 1
        ) {
            return (int) $matches[1];
        }

        return null;
    }
}
