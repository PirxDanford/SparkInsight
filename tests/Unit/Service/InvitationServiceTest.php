<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use SparkInsight\Service\InvitationService;

class InvitationServiceTest extends TestCase
{
    private Connection $connection;
    private InvitationService $invitationService;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->invitationService = new InvitationService($this->connection);
    }

    public function testGenerateInvitation(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'INSERT INTO invitations (code, email, roles, created_at, expires_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)',
                $this->callback(function ($params) {
                    return strlen($params[0]) === 64 && // 32 bytes hex
                           $params[1] === 'test@example.com' &&
                           $params[2] === '["reviewer"]' &&
                           is_string($params[3]);
                })
            );

        $code = $this->invitationService->generateInvitation(['reviewer'], 'test@example.com', 48);

        $this->assertIsString($code);
        $this->assertEquals(64, strlen($code));
    }

    public function testValidateInvitationValid(): void
    {
        $invitationData = [
            'id' => 1,
            'code' => 'abc123',
            'email' => 'test@example.com',
            'roles' => '["reviewer"]',
        ];

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAssociative')->willReturn($invitationData);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT * FROM invitations WHERE code = ? AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP', ['abc123'])
            ->willReturn($resultMock);

        $invitation = $this->invitationService->validateInvitation('abc123');

        $this->assertEquals(1, $invitation['id']);
        $this->assertEquals(['reviewer'], $invitation['roles']);
    }

    public function testValidateInvitationInvalid(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAssociative')->willReturn(false);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($resultMock);

        $invitation = $this->invitationService->validateInvitation('invalid');

        $this->assertNull($invitation);
    }

    public function testMarkInvitationAsUsed(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with('UPDATE invitations SET used_by = ?, used_at = CURRENT_TIMESTAMP WHERE code = ? AND used_at IS NULL', [123, 'abc123']);

        $this->invitationService->markInvitationAsUsed('abc123', 123);
    }

    public function testDeleteInvitation(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with('DELETE FROM invitations WHERE code = ?', ['abc123'])
            ->willReturn(1);

        $result = $this->invitationService->deleteInvitation('abc123');

        $this->assertTrue($result);
    }

    public function testGetInvitationsReturnsList(): void
    {
        $expectedRows = [
            [
                'id' => 1,
                'code' => 'abc123',
                'email' => 'test@example.com',
                'roles' => json_encode(['reviewer']),
                'used_by' => null,
                'used_at' => null,
                'created_at' => '2026-01-01 00:00:00',
                'expires_at' => '2026-01-02 00:00:00',
            ],
        ];

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAllAssociative')->willReturn($expectedRows);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                'SELECT invitations.*, u.name AS used_by_name, u.email AS used_by_email FROM invitations LEFT JOIN users u ON invitations.used_by = u.id ORDER BY created_at DESC LIMIT ? OFFSET ?',
                [50, 0],
                [ParameterType::INTEGER, ParameterType::INTEGER]
            )
            ->willReturn($resultMock);

        $invitations = $this->invitationService->getInvitations([], 50, 0);

        $this->assertCount(1, $invitations);
        $this->assertSame('abc123', $invitations[0]['code']);
        $this->assertSame(['reviewer'], $invitations[0]['roles']);
        $this->assertNull($invitations[0]['used_by_display']);
    }

    public function testGetInvitationsIncludesUsedByDisplay(): void
    {
        $expectedRows = [
            [
                'id' => 2,
                'code' => 'def456',
                'email' => 'another@example.com',
                'roles' => json_encode(['author']),
                'used_by' => 5,
                'used_at' => '2026-01-03 12:00:00',
                'created_at' => '2026-01-03 10:00:00',
                'expires_at' => '2026-01-04 10:00:00',
                'used_by_name' => 'Reviewer One',
                'used_by_email' => 'reviewer@example.com',
            ],
        ];

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAllAssociative')->willReturn($expectedRows);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                'SELECT invitations.*, u.name AS used_by_name, u.email AS used_by_email FROM invitations LEFT JOIN users u ON invitations.used_by = u.id ORDER BY created_at DESC LIMIT ? OFFSET ?',
                [50, 0],
                [ParameterType::INTEGER, ParameterType::INTEGER]
            )
            ->willReturn($resultMock);

        $invitations = $this->invitationService->getInvitations([], 50, 0);

        $this->assertCount(1, $invitations);
        $this->assertSame('def456', $invitations[0]['code']);
        $this->assertSame('Reviewer One <reviewer@example.com>', $invitations[0]['used_by_display']);
    }

    public function testGetInvitationCountReturnsInteger(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAssociative')->willReturn(['count' => '7']);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT COUNT(*) as count FROM invitations')
            ->willReturn($resultMock);

        $count = $this->invitationService->getInvitationCount([]);

        $this->assertSame(7, $count);
    }

    public function testGetInvitationsBindsPaginationAsIntegersWithFilters(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAllAssociative')->willReturn([]);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('WHERE email = ? AND roles LIKE ? ORDER BY created_at DESC LIMIT ? OFFSET ?'),
                ['test@example.com', '%"reviewer"%', 50, 0],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER]
            )
            ->willReturn($resultMock);

        $this->invitationService->getInvitations([
            'email' => 'test@example.com',
            'role' => 'reviewer',
        ], 50, 0);
    }
}