<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Command;

use PHPUnit\Framework\TestCase;
use SparkInsight\Command\ListUsersCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class ListUsersCommandTest extends TestCase
{
    private string $dbFile;

    protected function setUp(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $_ENV['DB_DRIVER'] = 'pdo_sqlite';
        $_ENV['DB_USER'] = '';
        $_ENV['DB_PASSWORD'] = '';
        $_ENV['DB_CHARSET'] = 'utf8mb4';

        $this->dbFile = sys_get_temp_dir() . '/sparkinsight_authors_' . bin2hex(random_bytes(8)) . '.sqlite';
        $_ENV['DB_NAME'] = $this->dbFile;

        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider TEXT,
    provider_id TEXT,
    email TEXT,
    name TEXT,
    avatar TEXT,
    roles TEXT NOT NULL,
    status TEXT NOT NULL,
    invitation_used TEXT,
    last_login TEXT,
    created_at TEXT,
    updated_at TEXT
);
SQL
        );

        $stmt = $pdo->prepare('INSERT INTO users (provider, provider_id, email, name, avatar, roles, status, invitation_used, last_login, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute(['google', 'author-1', 'active.author@example.com', 'Active Author', null, json_encode(['author', 'reviewer']), 'active', null, null, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $stmt->execute(['google', 'author-2', 'disabled.author@example.com', 'Disabled Author', null, json_encode(['author']), 'disabled', null, null, '2026-01-02 00:00:00', '2026-01-02 00:00:00']);
        $stmt->execute(['google', 'reviewer-1', 'reviewer@example.com', 'Reviewer', null, json_encode(['reviewer']), 'active', null, null, '2026-01-03 00:00:00', '2026-01-03 00:00:00']);
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
    }

    public function testCommandListsActiveUsersByDefault(): void
    {
        $tester = new CommandTester(new ListUsersCommand());

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('google', $display);
        $this->assertStringContainsString('Active Author', $display);
        $this->assertStringContainsString('active.author@example.com', $display);
        $this->assertStringContainsString('2 user(s) found.', $display);
        $this->assertStringNotContainsString('Disabled Author', $display);
        $this->assertStringContainsString('Reviewer', $display);
    }

    public function testCommandCanIncludeDisabledUsers(): void
    {
        $tester = new CommandTester(new ListUsersCommand());

        $exitCode = $tester->execute(['--all' => true]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('google', $display);
        $this->assertStringContainsString('Active Author', $display);
        $this->assertStringContainsString('Disabled Author', $display);
        $this->assertStringContainsString('3 user(s) found.', $display);
    }

    public function testCommandFiltersByRole(): void
    {
        $tester = new CommandTester(new ListUsersCommand());

        $exitCode = $tester->execute(['--role' => 'reviewer']);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('google', $display);
        $this->assertStringContainsString('Reviewer', $display);
        $this->assertStringContainsString('Showing users with role: reviewer.', $display);
        $this->assertStringContainsString('2 user(s) found.', $display);
        $this->assertStringContainsString('Active Author', $display);
        $this->assertStringContainsString('Reviewer', $display);
        $this->assertStringNotContainsString('Disabled Author', $display);
    }
}