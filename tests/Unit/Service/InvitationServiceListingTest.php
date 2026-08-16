<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use SparkInsight\Service\InvitationService;

/**
 * Covers getInvitations() and getInvitationCount() against an in-memory SQLite database.
 */
final class InvitationServiceListingTest extends TestCase
{
    private Connection $connection;

    private InvitationService $service;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE invitations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT NOT NULL,
                email TEXT,
                roles TEXT,
                expires_at TEXT NOT NULL,
                used_at TEXT,
                used_by INTEGER,
                created_at TEXT NOT NULL
            )
            SQL);
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                email TEXT
            )
            SQL);

        $this->connection->executeStatement(
            "INSERT INTO users (id, name, email) VALUES (1, 'Nina', 'nina@example.com'), (2, '', 'eli@example.com'), (3, 'Max', '')",
        );

        $future = date('Y-m-d H:i:s', strtotime('+1 day'));
        $past = date('Y-m-d H:i:s', strtotime('-1 day'));
        $now = date('Y-m-d H:i:s');

        $rows = [
            // code, email, roles, expires_at, used_at, used_by
            ['code-pending', 'a@example.com', '["reviewer"]', $future, null, null],
            ['code-used-full', 'b@example.com', '["admin","author"]', $future, $now, 1],
            ['code-expired', null, '["reviewer"]', $past, null, null],
            ['code-used-email-only', null, '["author"]', $future, $now, 2],
            ['code-used-name-only', null, null, $future, $now, 3],
            ['code-used-no-user', null, '["reviewer"]', $future, $now, null],
        ];

        foreach ($rows as [$code, $email, $roles, $expiresAt, $usedAt, $usedBy]) {
            $this->connection->executeStatement(
                'INSERT INTO invitations (code, email, roles, expires_at, used_at, used_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$code, $email, $roles, $expiresAt, $usedAt, $usedBy, $now],
            );
        }

        $this->service = new InvitationService($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testGetInvitationsReturnsAllWithComputedStatusAndUsedByDisplay(): void
    {
        $invitations = $this->service->getInvitations();
        $this->assertCount(6, $invitations);

        $byCode = [];
        foreach ($invitations as $invitation) {
            $byCode[$invitation['code']] = $invitation;
        }

        $this->assertSame('pending', $byCode['code-pending']['status']);
        $this->assertSame(['reviewer'], $byCode['code-pending']['roles']);
        $this->assertNull($byCode['code-pending']['used_by_display']);

        $this->assertSame('used', $byCode['code-used-full']['status']);
        $this->assertSame('Nina <nina@example.com>', $byCode['code-used-full']['used_by_display']);

        $this->assertSame('expired', $byCode['code-expired']['status']);

        $this->assertSame('eli@example.com', $byCode['code-used-email-only']['used_by_display']);
        $this->assertSame('Max', $byCode['code-used-name-only']['used_by_display']);
        $this->assertSame([], $byCode['code-used-name-only']['roles']);
        $this->assertNull($byCode['code-used-no-user']['used_by_display']);
    }

    public function testGetInvitationsAppliesEmailRoleAndStatusFilters(): void
    {
        $byEmail = $this->service->getInvitations(['email' => 'a@example.com']);
        $this->assertSame(['code-pending'], array_column($byEmail, 'code'));

        $byRole = $this->service->getInvitations(['role' => 'reviewer']);
        $codes = array_column($byRole, 'code');
        sort($codes);
        $this->assertSame(['code-expired', 'code-pending', 'code-used-no-user'], $codes);

        $used = $this->service->getInvitations(['status' => 'used']);
        $this->assertCount(4, $used);

        $expired = $this->service->getInvitations(['status' => 'expired']);
        $this->assertSame(['code-expired'], array_column($expired, 'code'));

        $pending = $this->service->getInvitations(['status' => 'pending']);
        $this->assertSame(['code-pending'], array_column($pending, 'code'));

        $defaultStatus = $this->service->getInvitations(['status' => 'something-else']);
        $this->assertSame(['code-pending'], array_column($defaultStatus, 'code'));
    }

    public function testGetInvitationsHonorsLimitAndOffset(): void
    {
        $page = $this->service->getInvitations([], 2, 1);
        $this->assertCount(2, $page);
    }

    public function testGetInvitationCountAppliesFilters(): void
    {
        $this->assertSame(6, $this->service->getInvitationCount());
        $this->assertSame(1, $this->service->getInvitationCount(['email' => 'a@example.com']));
        $this->assertSame(3, $this->service->getInvitationCount(['role' => 'reviewer']));
        $this->assertSame(4, $this->service->getInvitationCount(['status' => 'used']));
        $this->assertSame(1, $this->service->getInvitationCount(['status' => 'expired']));
        $this->assertSame(1, $this->service->getInvitationCount(['status' => 'pending']));
        $this->assertSame(1, $this->service->getInvitationCount(['status' => 'unknown-status']));
    }
}
