<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use Doctrine\DBAL\Connection;
use Throwable;

final class AppSettingsService
{
    /** @var array<string, array{value: mixed, type: string}> */
    private const DEFAULT_SETTINGS = [
        'invitation_default_hours' => ['value' => 168, 'type' => 'int'],
        'invitation_default_roles' => ['value' => ['reviewer'], 'type' => 'json'],
        'import_cron_schedule' => ['value' => '0 2 * * *', 'type' => 'string'],
        'invitation_cleanup_cron_schedule' => ['value' => '30 2 * * *', 'type' => 'string'],
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function getInvitationDefaultHours(): int
    {
        $value = $this->get('invitation_default_hours');

        return $this->normalizeHours($value);
    }

    /**
     * @return array<int, string>
     */
    public function getInvitationDefaultRoles(): array
    {
        $value = $this->get('invitation_default_roles');

        return $this->normalizeRoles($value);
    }

    public function getImportCronSchedule(): string
    {
        $value = mb_trim((string) $this->get('import_cron_schedule'));

        return $value !== '' ? $value : (string) self::DEFAULT_SETTINGS['import_cron_schedule']['value'];
    }

    public function getInvitationCleanupCronSchedule(): string
    {
        $value = mb_trim((string) $this->get('invitation_cleanup_cron_schedule'));

        return $value !== '' ? $value : (string) self::DEFAULT_SETTINGS['invitation_cleanup_cron_schedule']['value'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAll(): array
    {
        $settings = [];
        foreach (self::DEFAULT_SETTINGS as $key => $meta) {
            $settings[$key] = $meta['value'];
        }

        try {
            $rows = $this->connection->executeQuery(
                'SELECT setting_key, setting_value, value_type FROM app_settings',
            )->fetchAllAssociative();
        } catch (Throwable $e) {
            return $settings;
        }

        foreach ($rows as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            if (!array_key_exists($key, self::DEFAULT_SETTINGS)) {
                continue;
            }

            $settings[$key] = $this->decodeValue(
                (string) ($row['setting_value'] ?? ''),
                (string) ($row['value_type'] ?? self::DEFAULT_SETTINGS[$key]['type']),
                self::DEFAULT_SETTINGS[$key]['value'],
            );
        }

        $settings['invitation_default_hours'] = $this->normalizeHours($settings['invitation_default_hours'] ?? null);
        $settings['invitation_default_roles'] = $this->normalizeRoles($settings['invitation_default_roles'] ?? null);
        $settings['import_cron_schedule'] = $this->sanitizeCron((string) ($settings['import_cron_schedule'] ?? ''));
        $settings['invitation_cleanup_cron_schedule'] = $this->sanitizeCron((string) ($settings['invitation_cleanup_cron_schedule'] ?? ''));

        return $settings;
    }

    public function get(string $settingKey): mixed
    {
        if (!array_key_exists($settingKey, self::DEFAULT_SETTINGS)) {
            return null;
        }

        try {
            $row = $this->connection->executeQuery(
                'SELECT setting_value, value_type FROM app_settings WHERE setting_key = ?',
                [$settingKey],
            )->fetchAssociative();
        } catch (Throwable $e) {
            return self::DEFAULT_SETTINGS[$settingKey]['value'];
        }

        if (!$row) {
            return self::DEFAULT_SETTINGS[$settingKey]['value'];
        }

        return $this->decodeValue(
            (string) ($row['setting_value'] ?? ''),
            (string) ($row['value_type'] ?? self::DEFAULT_SETTINGS[$settingKey]['type']),
            self::DEFAULT_SETTINGS[$settingKey]['value'],
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveFromAdminInput(array $input): void
    {
        $hours = $this->normalizeHours($input['invitation_default_hours'] ?? null);
        $roles = $this->normalizeRoles($input['invitation_default_roles'] ?? []);
        $importCron = $this->sanitizeCron((string) ($input['import_cron_schedule'] ?? ''));
        $cleanupCron = $this->sanitizeCron((string) ($input['invitation_cleanup_cron_schedule'] ?? ''));

        $this->upsert('invitation_default_hours', (string) $hours, 'int');
        $this->upsert('invitation_default_roles', json_encode($roles), 'json');
        $this->upsert('import_cron_schedule', $importCron, 'string');
        $this->upsert('invitation_cleanup_cron_schedule', $cleanupCron, 'string');
    }

    private function upsert(string $key, string $value, string $type): void
    {
        $this->connection->executeStatement(
            'INSERT INTO app_settings (setting_key, setting_value, value_type, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_at = CURRENT_TIMESTAMP',
            [$key, $value, $type],
        );
    }

    private function decodeValue(string $rawValue, string $type, mixed $fallback): mixed
    {
        if ($type === 'int') {
            return is_numeric($rawValue) ? (int) $rawValue : $fallback;
        }

        if ($type === 'json') {
            $decoded = json_decode($rawValue, true);

            return is_array($decoded) ? $decoded : $fallback;
        }

        return $rawValue;
    }

    private function normalizeHours(mixed $value): int
    {
        if (!is_numeric($value)) {
            return (int) self::DEFAULT_SETTINGS['invitation_default_hours']['value'];
        }

        $hours = (int) $value;
        if ($hours < 1) {
            return (int) self::DEFAULT_SETTINGS['invitation_default_hours']['value'];
        }

        return min($hours, 24 * 30);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeRoles(mixed $value): array
    {
        $roles = [];
        foreach ((array) $value as $role) {
            $roleName = mb_trim((string) $role);
            if (in_array($roleName, ['reviewer', 'author', 'admin'], true)) {
                $roles[$roleName] = $roleName;
            }
        }

        if ($roles === []) {
            return (array) self::DEFAULT_SETTINGS['invitation_default_roles']['value'];
        }

        return array_values($roles);
    }

    private function sanitizeCron(string $value): string
    {
        $trimmed = mb_trim($value);

        return $trimmed !== '' ? $trimmed : '* * * * *';
    }
}
