<?php

declare(strict_types=1);

namespace SparkInsightTest\Integration\Command;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use SparkInsight\Command\GenerateInvitationCommand;

class GenerateInvitationCommandIntegrationTest extends TestCase
{
    private $connection;

    protected function setUp(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $_ENV['DB_DRIVER'] = 'pdo_sqlite';
        $_ENV['DB_NAME'] = ':memory:';
        $_ENV['DB_USER'] = '';
        $_ENV['DB_PASSWORD'] = '';
        $_ENV['DB_CHARSET'] = 'utf8mb4';
        $_ENV['DB_HOST'] = 'localhost';
        $_ENV['DB_PORT'] = 3306;

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => ':memory:',
        ]);

        // Create invitations table
        $this->connection->executeStatement(<<<SQL
            CREATE TABLE invitations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code VARCHAR(255) UNIQUE NOT NULL,
                roles TEXT NOT NULL,
                email VARCHAR(255),
                expires_at DATETIME NOT NULL,
                used_at DATETIME,
                user_id INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL);
    }

    protected function tearDown(): void
    {
        if ($this->connection) {
            $this->connection->close();
        }
        unset($_ENV['APP_ENV']);
        unset($_ENV['APP_URL']);
        unset($_ENV['DB_DRIVER']);
        unset($_ENV['DB_NAME']);
        unset($_ENV['DB_USER']);
        unset($_ENV['DB_PASSWORD']);
        unset($_ENV['DB_CHARSET']);
        unset($_ENV['DB_HOST']);
        unset($_ENV['DB_PORT']);
    }

    public function testGenerateInvitationWithReviewerRole(): void
    {
        $command = new GenerateInvitationCommand($this->connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--reviewer' => true]);
        $output = $tester->getDisplay();

        $this->assertSame(0, $exitCode, "Command failed with output:\n" . $output);
        $this->assertStringContainsString('Invitation generated successfully', $output);
        $this->assertStringContainsString('reviewer', $output);
    }

    public function testGenerateInvitationWithAuthorRole(): void
    {
        $command = new GenerateInvitationCommand($this->connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--author' => true]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('author', $output);
        $this->assertStringContainsString('reviewer', $output);
    }

    public function testGenerateInvitationWithAdminRole(): void
    {
        $command = new GenerateInvitationCommand($this->connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--admin' => true]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('admin', $output);
        $this->assertStringContainsString('author', $output);
        $this->assertStringContainsString('reviewer', $output);
    }

    public function testGenerateInvitationWithDefaultRole(): void
    {
        $command = new GenerateInvitationCommand($this->connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('reviewer', $output);
    }

    public function testGenerateInvitationWithEmailRestriction(): void
    {
        $command = new GenerateInvitationCommand($this->connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--email' => 'user@example.com']);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('user@example.com', $output);
    }

    public function testGenerateInvitationWithCustomHours(): void
    {
        $command = new GenerateInvitationCommand($this->connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--hours' => '48']);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('48 hours', $output);
    }

    public function testGenerateInvitationShowsGithubLink(): void
    {
        $command = new GenerateInvitationCommand($this->connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('/auth/github', $output);
    }

    public function testGenerateInvitationShowsGoogleLink(): void
    {
        $command = new GenerateInvitationCommand($this->connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('/auth/google', $output);
    }

    public function testGenerateInvitationWithAllOptions(): void
    {
        $command = new GenerateInvitationCommand($this->connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--author' => true,
            '--email' => 'test@example.com',
            '--hours' => '72',
        ]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('author', $output);
        $this->assertStringContainsString('test@example.com', $output);
        $this->assertStringContainsString('72 hours', $output);
    }
}
