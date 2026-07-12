<?php

declare(strict_types=1);

namespace SparkInsightTest\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => ':memory:',
        ]);

        // Run migrations
        $this->runMigrations();
    }

    private function runMigrations(): void
    {
        // Simple migration for tests
        $this->connection->executeStatement('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                provider TEXT NOT NULL,
                provider_id TEXT NOT NULL,
                email TEXT NOT NULL,
                name TEXT NOT NULL,
                display_name TEXT,
                avatar TEXT,
                roles TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "active",
                invitation_used TEXT,
                last_login DATETIME,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE invitations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT NOT NULL UNIQUE,
                email TEXT,
                roles TEXT NOT NULL,
                used_by INTEGER,
                used_at DATETIME,
                created_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL
            )
        ');
    }
}