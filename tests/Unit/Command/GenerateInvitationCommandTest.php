<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use SparkInsight\Command\GenerateInvitationCommand;

class GenerateInvitationCommandTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $_ENV['DB_DRIVER'] = 'invalid_driver';
        $_ENV['DB_NAME'] = 'testdb';
        $_ENV['DB_USER'] = 'user';
        $_ENV['DB_PASSWORD'] = 'password';
        $_ENV['DB_CHARSET'] = 'utf8mb4';
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
    }

    public function testExecuteReturnsFailureWhenDatabaseConnectionFails(): void
    {
        $command = new GenerateInvitationCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--reviewer' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Database connection failed', $tester->getDisplay());
    }

    public function testCommandName(): void
    {
        $command = new GenerateInvitationCommand();
        $this->assertSame('invite:generate', $command->getName());
    }

    public function testCommandDescription(): void
    {
        $command = new GenerateInvitationCommand();
        $this->assertStringContainsString('invitation', $command->getDescription());
    }

    public function testCommandHasAdminOption(): void
    {
        $command = new GenerateInvitationCommand();
        $definition = $command->getDefinition();
        
        $this->assertTrue($definition->hasOption('admin'));
        $this->assertTrue($definition->hasOption('reviewer'));
        $this->assertTrue($definition->hasOption('author'));
        $this->assertTrue($definition->hasOption('email'));
        $this->assertTrue($definition->hasOption('hours'));
    }

    public function testHoursOptionDefaultsToNull(): void
    {
        $command = new GenerateInvitationCommand();
        $definition = $command->getDefinition();

        $this->assertNull($definition->getOption('hours')->getDefault());
    }

    public function testExecuteSucceedsWithInjectedConnectionAndDefaultRoles(): void
    {
        $dbFile = sys_get_temp_dir() . '/sparkinsight-invite-command-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $dbFile);
        $pdo->exec(<<<'SQL'
CREATE TABLE invitations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    email TEXT,
    roles TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE app_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key TEXT NOT NULL UNIQUE,
    value TEXT NOT NULL
);
SQL
        );
        $pdo->prepare('INSERT INTO app_settings (key, value) VALUES (?, ?)')->execute(['invitation_default_hours', '24']);

        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbFile]);
        $command = new GenerateInvitationCommand($connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--reviewer' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Invitation generated successfully', $tester->getDisplay());

        $pdo = null;
        $connection->close();
        if (file_exists($dbFile)) {
            unlink($dbFile);
        }
    }

    public function testExecuteUsesFallbackRolesAndHoursWhenNoRoleOrHoursProvided(): void
    {
        $dbFile = sys_get_temp_dir() . '/sparkinsight-invite-command-fallback-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $dbFile);
        $pdo->exec(<<<'SQL'
CREATE TABLE invitations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    email TEXT,
    roles TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE app_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key TEXT NOT NULL UNIQUE,
    value TEXT NOT NULL
);
SQL
        );
        $pdo->prepare('INSERT INTO app_settings (key, value) VALUES (?, ?)')->execute(['invitation_default_hours', '7']);

        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbFile]);
        $command = new GenerateInvitationCommand($connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--email' => 'user@example.com']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Roles: reviewer', $tester->getDisplay());
        $this->assertStringContainsString('Expires in: 168 hours', $tester->getDisplay());

        $pdo = null;
        $connection->close();
        if (file_exists($dbFile)) {
            unlink($dbFile);
        }
    }
}
