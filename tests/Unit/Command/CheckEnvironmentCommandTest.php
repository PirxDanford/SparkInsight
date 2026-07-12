<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use SparkInsight\Command\CheckEnvironmentCommand;

class CheckEnvironmentCommandTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $_ENV['OAUTH_GITHUB_CLIENT_ID'] = '123';
        $_ENV['OAUTH_GITHUB_CLIENT_SECRET'] = 'secret';
        $_ENV['DB_DRIVER'] = 'pdo_sqlite';
        $_ENV['DB_NAME'] = ':memory:';
        $_ENV['DB_USER'] = '';
        $_ENV['DB_PASSWORD'] = '';
        $_ENV['DB_CHARSET'] = 'utf8mb4';
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_ENV']);
        unset($_ENV['APP_URL']);
        unset($_ENV['OAUTH_GITHUB_CLIENT_ID']);
        unset($_ENV['OAUTH_GITHUB_CLIENT_SECRET']);
        unset($_ENV['DB_DRIVER']);
        unset($_ENV['DB_NAME']);
        unset($_ENV['DB_USER']);
        unset($_ENV['DB_PASSWORD']);
        unset($_ENV['DB_CHARSET']);
    }

    public function testExecuteReturnsSuccessWhenEnvironmentIsValid(): void
    {
        $command = new CheckEnvironmentCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Environment check completed successfully', $tester->getDisplay());
    }

    public function testCommandHasNameAndDescription(): void
    {
        $command = new CheckEnvironmentCommand();

        $this->assertSame('check-environment', $command->getName());
        $this->assertStringContainsString('development environment', $command->getDescription());
    }

    public function testExecuteShowsDatabaseConnectionSuccess(): void
    {
        $command = new CheckEnvironmentCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Database connection successful', $tester->getDisplay());
    }

    public function testExecuteShowsOAuthProvidersConfigured(): void
    {
        $command = new CheckEnvironmentCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('OAuth providers configured', $tester->getDisplay());
        $this->assertStringContainsString('github', $tester->getDisplay());
    }

    public function testExecuteShowsAppEnvironment(): void
    {
        $command = new CheckEnvironmentCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('App environment', $output);
        $this->assertStringContainsString('development', $output);
    }

    public function testExecuteShowsTitle(): void
    {
        $command = new CheckEnvironmentCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('SparkInsight Environment Check', $output);
    }

    public function testExecuteWithInvalidDatabaseDriver(): void
    {
        $_ENV['DB_DRIVER'] = 'invalid_driver';
        $_ENV['DB_NAME'] = 'testdb';

        $command = new CheckEnvironmentCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Database connection failed', $tester->getDisplay());

        // Restore for tearDown
        $_ENV['DB_DRIVER'] = 'pdo_sqlite';
        $_ENV['DB_NAME'] = ':memory:';
    }

    public function testExecuteWithProductionEnvironment(): void
    {
        $_ENV['APP_ENV'] = 'production';

        $command = new CheckEnvironmentCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('production', $output);
    }
}
