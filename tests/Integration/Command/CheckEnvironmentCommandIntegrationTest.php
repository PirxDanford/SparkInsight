<?php

declare(strict_types=1);

namespace SparkInsightTest\Integration\Command;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use SparkInsight\Command\CheckEnvironmentCommand;

class CheckEnvironmentCommandIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $_ENV['OAUTH_GITHUB_CLIENT_ID'] = '123';
        $_ENV['OAUTH_GITHUB_CLIENT_SECRET'] = 'secret';
        $_ENV['DB_DRIVER'] = 'pdo_sqlite';
        $_ENV['DB_NAME'] = ':memory:';
        $_ENV['DB_USER'] = '';
        $_ENV['DB_PASSWORD'] = '';
        $_ENV['DB_CHARSET'] = 'utf8mb4';
        $_ENV['DB_HOST'] = 'localhost';
        $_ENV['DB_PORT'] = 3306;
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
        unset($_ENV['DB_HOST']);
        unset($_ENV['DB_PORT']);
    }

    public function testCheckEnvironmentWithValidConfiguration(): void
    {
        $command = new CheckEnvironmentCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Environment check completed successfully', $output);
    }

    public function testCheckEnvironmentDisplaysDatabaseStatus(): void
    {
        $command = new CheckEnvironmentCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Database', $output);
    }

    public function testCheckEnvironmentDisplaysOAuthProviders(): void
    {
        $command = new CheckEnvironmentCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('OAuth', $output);
    }

    public function testCheckEnvironmentDisplaysEnvironmentType(): void
    {
        $command = new CheckEnvironmentCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('testing', $output);
    }

    public function testCheckEnvironmentDisplaysTitle(): void
    {
        $command = new CheckEnvironmentCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('SparkInsight', $output);
    }

    public function testCheckEnvironmentReturnsSuccessCode(): void
    {
        $command = new CheckEnvironmentCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }
}
