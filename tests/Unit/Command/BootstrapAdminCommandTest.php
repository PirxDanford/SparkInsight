<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Command;

use PHPUnit\Framework\TestCase;
use SparkInsight\Command\BootstrapAdminCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class BootstrapAdminCommandTest extends TestCase
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

        $this->dbFile = sys_get_temp_dir() . '/sparkinsight_bootstrap_' . bin2hex(random_bytes(8)) . '.sqlite';
        $_ENV['DB_NAME'] = $this->dbFile;

        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider TEXT NOT NULL,
    provider_id TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    avatar TEXT,
    roles TEXT NOT NULL,
    status TEXT NOT NULL,
    invitation_used TEXT,
    last_login TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL
        );

        $stmt = $pdo->prepare('INSERT INTO users (provider, provider_id, email, name, avatar, roles, status, invitation_used, last_login, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute(['google', 'reviewer-1', 'user@example.com', 'Test User', null, json_encode(['reviewer']), 'active', null, null, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
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

    public function testBootstrapPromotesExistingUserToAdmin(): void
    {
        $tester = new CommandTester(new BootstrapAdminCommand());

        $exitCode = $tester->execute([
            '--email' => 'user@example.com',
        ]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Admin bootstrap completed successfully.', $display);
        $this->assertStringContainsString('admin, author, reviewer', $display);

        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $roles = $pdo->query('SELECT roles FROM users WHERE email = "user@example.com"')->fetchColumn();
        $status = $pdo->query('SELECT status FROM users WHERE email = "user@example.com"')->fetchColumn();

        $this->assertSame(['admin', 'author', 'reviewer'], json_decode((string) $roles, true));
        $this->assertSame('active', $status);
    }

    public function testBootstrapRefusesWhenAdminAlreadyExistsWithoutForce(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->prepare('INSERT INTO users (provider, provider_id, email, name, avatar, roles, status, invitation_used, last_login, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['github', 'admin-1', 'admin@example.com', 'Admin User', null, json_encode(['admin', 'author', 'reviewer']), 'active', null, null, '2026-01-02 00:00:00', '2026-01-02 00:00:00']);

        $tester = new CommandTester(new BootstrapAdminCommand());

        $exitCode = $tester->execute([
            '--email' => 'user@example.com',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('An admin user already exists', $tester->getDisplay());
    }

    public function testBootstrapFailsWhenEmailOptionMissing(): void
    {
        $tester = new CommandTester(new BootstrapAdminCommand());

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('The --email option is required.', $tester->getDisplay());
    }

    public function testBootstrapFailsWhenTargetUserDoesNotExist(): void
    {
        $tester = new CommandTester(new BootstrapAdminCommand());

        $exitCode = $tester->execute([
            '--email' => 'missing@example.com',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No user found with email missing@example.com.', $tester->getDisplay());
    }

    public function testBootstrapAllowsPromotionWithForceWhenAdminExists(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->prepare('INSERT INTO users (provider, provider_id, email, name, avatar, roles, status, invitation_used, last_login, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['github', 'admin-1', 'admin@example.com', 'Admin User', null, json_encode(['admin', 'author', 'reviewer']), 'active', null, null, '2026-01-02 00:00:00', '2026-01-02 00:00:00']);

        $tester = new CommandTester(new BootstrapAdminCommand());

        $exitCode = $tester->execute([
            '--email' => 'user@example.com',
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Admin bootstrap completed successfully.', $tester->getDisplay());
    }

    public function testBootstrapFailsWhenQueryingUsersThrows(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->exec('DROP TABLE users');

        $tester = new CommandTester(new BootstrapAdminCommand());

        $exitCode = $tester->execute([
            '--email' => 'user@example.com',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Failed to query users', $tester->getDisplay());
    }
}