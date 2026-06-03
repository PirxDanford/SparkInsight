<?php

declare(strict_types=1);

namespace SparkInsightTest\Integration\Service;

use SparkInsight\Service\InvitationService;
use SparkInsightTest\Integration\DatabaseTestCase;

class InvitationServiceIntegrationTest extends DatabaseTestCase
{
    private InvitationService $invitationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invitationService = new InvitationService($this->connection);
    }

    public function testGenerateInvitationCreatesValidInvitation(): void
    {
        $code = $this->invitationService->generateInvitation(['reviewer'], 'test@example.com', 24);

        $this->assertIsString($code);
        $this->assertNotEmpty($code);

        $invitation = $this->invitationService->validateInvitation($code);

        $this->assertNotNull($invitation);
        $this->assertSame($code, $invitation['code']);
        $this->assertSame('test@example.com', $invitation['email']);
        $this->assertSame(['reviewer'], $invitation['roles']);
    }

    public function testValidateInvitationRejectsUsedInvitation(): void
    {
        $code = $this->invitationService->generateInvitation(['author'], null, 24);
        $invitation = $this->invitationService->validateInvitation($code);
        $this->assertNotNull($invitation);

        $this->invitationService->markInvitationAsUsed($code, 1);
        $this->assertNull($this->invitationService->validateInvitation($code));
    }

    public function testValidateInvitationRejectsExpiredInvitation(): void
    {
        $code = 'expiredcode123';
        $this->connection->executeStatement(
            'INSERT INTO invitations (code, email, roles, created_at, expires_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)',
            [$code, null, json_encode(['reviewer']), date('Y-m-d H:i:s', strtotime('-1 hour'))]
        );

        $this->assertNull($this->invitationService->validateInvitation($code));
    }
}
