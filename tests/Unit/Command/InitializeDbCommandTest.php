<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Command;

use PHPUnit\Framework\TestCase;
use SparkInsight\Command\InitializeDbCommand;
use Symfony\Component\Console\Tester\CommandTester;

class InitializeDbCommandTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['DB_DRIVER'] = 'invalid_driver';
        $_ENV['DB_HOST'] = 'localhost';
        $_ENV['DB_PORT'] = '3306';
        $_ENV['DB_NAME'] = 'sparkinsight_test';
        $_ENV['DB_USER'] = 'root';
        $_ENV['DB_PASSWORD'] = '';
        $_ENV['DB_CHARSET'] = 'utf8mb4';
    }

    protected function tearDown(): void
    {
        unset($_ENV['DB_DRIVER'], $_ENV['DB_HOST'], $_ENV['DB_PORT'], $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $_ENV['DB_CHARSET']);
    }

    public function testExecuteReturnsFailureWhenConnectionCannotBeCreated(): void
    {
        $command = new InitializeDbCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Cannot connect to database', $tester->getDisplay());
    }

    public function testCommandMetadata(): void
    {
        $command = new InitializeDbCommand();
        $this->assertSame('db:init', $command->getName());
        $this->assertStringContainsString('Initialize the database by running all migrations', $command->getDescription());
    }
}
