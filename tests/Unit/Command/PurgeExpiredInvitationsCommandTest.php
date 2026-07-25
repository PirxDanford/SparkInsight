<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Command;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use SparkInsight\Command\PurgeExpiredInvitationsCommand;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeExpiredInvitationsCommandTest extends TestCase
{
    public function testExecuteFailsWithoutForceOption(): void
    {
        $command = new PurgeExpiredInvitationsCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Refusing to delete invitations without --force.', $tester->getDisplay());
    }

    public function testExecutePurgesExpiredInvitationsWhenForced(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with('DELETE FROM invitations WHERE used_at IS NULL AND expires_at <= CURRENT_TIMESTAMP')
            ->willReturn(3);

        $command = new PurgeExpiredInvitationsCommand($connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--force' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Purged 3 expired invitation(s).', $tester->getDisplay());
    }

    public function testExecuteFailsWhenPurgeThrows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->willThrowException(new \RuntimeException('boom'));

        $command = new PurgeExpiredInvitationsCommand($connection);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Failed to purge expired invitations: boom', $tester->getDisplay());
    }

    public function testCommandMetadata(): void
    {
        $command = new PurgeExpiredInvitationsCommand();
        $this->assertSame('invite:purge-expired', $command->getName());
        $this->assertStringContainsString('Delete expired and unused invitations', $command->getDescription());
        $this->assertTrue($command->getDefinition()->hasOption('force'));
    }
}
