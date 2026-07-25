<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use SparkInsight\Service\AppSettingsService;

class AppSettingsServiceTest extends TestCase
{
    private Connection $connection;
    private AppSettingsService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->service = new AppSettingsService($this->connection);
    }

    public function testGetReturnsNullForUnknownSetting(): void
    {
        $this->assertNull($this->service->get('unknown_setting'));
    }

    public function testGetReturnsDefaultWhenQueryFails(): void
    {
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willThrowException(new \RuntimeException('db down'));

        $this->assertSame(168, $this->service->getInvitationDefaultHours());
    }

    public function testGetInvitationDefaultRolesNormalizesDecodedValues(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'setting_value' => '[" reviewer ","author","invalid","author"]',
            'value_type' => 'json',
        ]);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                'SELECT setting_value, value_type FROM app_settings WHERE setting_key = ?',
                ['invitation_default_roles'],
            )
            ->willReturn($result);

        $this->assertSame(['reviewer', 'author'], $this->service->getInvitationDefaultRoles());
    }

    public function testGetAllReturnsDefaultsWhenQueryFails(): void
    {
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willThrowException(new \RuntimeException('db down'));

        $all = $this->service->getAll();

        $this->assertSame(168, $all['invitation_default_hours']);
        $this->assertSame(['reviewer'], $all['invitation_default_roles']);
        $this->assertSame('0 2 * * *', $all['import_cron_schedule']);
        $this->assertSame('30 2 * * *', $all['invitation_cleanup_cron_schedule']);
    }

    public function testGetAllDecodesAndNormalizesValues(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            [
                'setting_key' => 'invitation_default_hours',
                'setting_value' => '9999',
                'value_type' => 'int',
            ],
            [
                'setting_key' => 'invitation_default_roles',
                'setting_value' => '["reviewer","author","invalid","reviewer"]',
                'value_type' => 'json',
            ],
            [
                'setting_key' => 'import_cron_schedule',
                'setting_value' => '',
                'value_type' => 'string',
            ],
            [
                'setting_key' => 'invitation_cleanup_cron_schedule',
                'setting_value' => ' 15 1 * * * ',
                'value_type' => 'string',
            ],
            [
                'setting_key' => 'unknown_setting',
                'setting_value' => 'ignored',
                'value_type' => 'string',
            ],
        ]);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT setting_key, setting_value, value_type FROM app_settings')
            ->willReturn($result);

        $all = $this->service->getAll();

        $this->assertSame(720, $all['invitation_default_hours']);
        $this->assertSame(['reviewer', 'author'], $all['invitation_default_roles']);
        $this->assertSame('* * * * *', $all['import_cron_schedule']);
        $this->assertSame('15 1 * * *', $all['invitation_cleanup_cron_schedule']);
        $this->assertArrayNotHasKey('unknown_setting', $all);
    }

    public function testSaveFromAdminInputUpsertsNormalizedValues(): void
    {
        $calls = [];

        $this->connection->expects($this->exactly(4))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$calls): int {
                $calls[] = [$sql, $params];
                return 1;
            });

        $this->service->saveFromAdminInput([
            'invitation_default_hours' => '0',
            'invitation_default_roles' => [' reviewer ', 'admin', 'invalid', 'admin'],
            'import_cron_schedule' => '  ',
            'invitation_cleanup_cron_schedule' => ' 0 3 * * * ',
        ]);

        $this->assertCount(4, $calls);
        $this->assertSame('invitation_default_hours', $calls[0][1][0]);
        $this->assertSame('168', $calls[0][1][1]);
        $this->assertSame('int', $calls[0][1][2]);

        $this->assertSame('invitation_default_roles', $calls[1][1][0]);
        $this->assertSame('["reviewer","admin"]', $calls[1][1][1]);
        $this->assertSame('json', $calls[1][1][2]);

        $this->assertSame('import_cron_schedule', $calls[2][1][0]);
        $this->assertSame('* * * * *', $calls[2][1][1]);
        $this->assertSame('string', $calls[2][1][2]);

        $this->assertSame('invitation_cleanup_cron_schedule', $calls[3][1][0]);
        $this->assertSame('0 3 * * *', $calls[3][1][1]);
        $this->assertSame('string', $calls[3][1][2]);
    }
}
