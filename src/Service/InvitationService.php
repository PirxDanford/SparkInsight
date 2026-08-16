<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class InvitationService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function generateInvitation(array $roles, ?string $email = null, int $expirationHours = 24): string
    {
        $code = bin2hex(random_bytes(32));
        $expiresAt = new DateTime("+{$expirationHours} hours");

        $this->connection->executeStatement(
            'INSERT INTO invitations (code, email, roles, created_at, expires_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)',
            [
                $code,
                $email,
                json_encode($roles),
                $expiresAt->format('Y-m-d H:i:s'),
            ],
        );

        return $code;
    }

    public function validateInvitation(string $code): ?array
    {
        $result = $this->connection->executeQuery(
            'SELECT * FROM invitations WHERE code = ? AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP',
            [$code],
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return [
            'id' => $result['id'],
            'code' => $result['code'],
            'email' => $result['email'],
            'roles' => json_decode($result['roles'] ?? '[]', true),
        ];
    }

    public function markInvitationAsUsed(string $code, int $userId): void
    {
        $this->connection->executeStatement(
            'UPDATE invitations SET used_by = ?, used_at = CURRENT_TIMESTAMP WHERE code = ? AND used_at IS NULL',
            [$userId, $code],
        );
    }

    public function deleteInvitation(string $code): bool
    {
        $deleted = $this->connection->executeStatement(
            'DELETE FROM invitations WHERE code = ?',
            [$code],
        );

        return $deleted > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getInvitationByCode(string $code): ?array
    {
        $result = $this->connection->executeQuery(
            'SELECT invitations.*, u.name AS used_by_name, u.email AS used_by_email FROM invitations LEFT JOIN users u ON invitations.used_by = u.id WHERE invitations.code = ?',
            [$code],
        )->fetchAssociative();

        if (!is_array($result)) {
            return null;
        }

        $expiresAt = $result['expires_at'] ? new DateTime((string) $result['expires_at']) : null;
        $status = 'pending';

        if ($result['used_at'] !== null) {
            $status = 'used';
        } elseif ($expiresAt !== null && $expiresAt <= new DateTime()) {
            $status = 'expired';
        }

        $usedByLabel = null;
        if (!empty($result['used_by_name']) || !empty($result['used_by_email'])) {
            $nameRaw = $result['used_by_name'] ?? '';
            $emailRaw = $result['used_by_email'] ?? '';
            $name = is_string($nameRaw) ? mb_trim($nameRaw) : '';
            $email = is_string($emailRaw) ? mb_trim($emailRaw) : '';

            if ($name !== '' && $email !== '') {
                $usedByLabel = sprintf('%s <%s>', $name, $email);
            } elseif ($name !== '') {
                $usedByLabel = $name;
            } elseif ($email !== '') {
                $usedByLabel = $email;
            }
        }

        return [
            'id' => $result['id'],
            'code' => $result['code'],
            'email' => $result['email'],
            'roles' => json_decode((string) ($result['roles'] ?? '[]'), true),
            'used_by' => $result['used_by'],
            'used_by_name' => $result['used_by_name'] ?? null,
            'used_by_email' => $result['used_by_email'] ?? null,
            'used_by_display' => $usedByLabel,
            'used_at' => $result['used_at'],
            'created_at' => $result['created_at'],
            'expires_at' => $result['expires_at'],
            'status' => $status,
        ];
    }

    public function getInvitations(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['email'])) {
            $where[] = 'invitations.email = ?';
            $params[] = $filters['email'];
        }

        if (!empty($filters['role'])) {
            $where[] = 'invitations.roles LIKE ?';
            $params[] = '%"' . $filters['role'] . '"%';
        }

        if (!empty($filters['status'])) {
            switch ($filters['status']) {
                case 'used':
                    $where[] = 'invitations.used_at IS NOT NULL';
                    break;
                case 'expired':
                    $where[] = 'invitations.used_at IS NULL AND invitations.expires_at <= CURRENT_TIMESTAMP';
                    break;
                case 'pending':
                default:
                    $where[] = 'invitations.used_at IS NULL AND invitations.expires_at > CURRENT_TIMESTAMP';
                    break;
            }
        }

        $sql = 'SELECT invitations.*, u.name AS used_by_name, u.email AS used_by_email FROM invitations LEFT JOIN users u ON invitations.used_by = u.id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY invitations.created_at DESC LIMIT ? OFFSET ?';

        $params[] = $limit;
        $params[] = $offset;
        $types = array_fill(0, count($params) - 2, ParameterType::STRING);
        $types[] = ParameterType::INTEGER;
        $types[] = ParameterType::INTEGER;

        $results = $this->connection->executeQuery($sql, $params, $types)->fetchAllAssociative();

        return array_map(static function (array $row) {
            $expiresAtRaw = $row['expires_at'] ?? null;
            $expiresAt = is_string($expiresAtRaw) && $expiresAtRaw !== '' ? new DateTime($expiresAtRaw) : null;
            $status = 'pending';

            if ($row['used_at'] !== null) {
                $status = 'used';
            } elseif ($expiresAt !== null && $expiresAt <= new DateTime()) {
                $status = 'expired';
            }

            $usedByLabel = null;
            if (!empty($row['used_by_name']) || !empty($row['used_by_email'])) {
                $nameRaw = $row['used_by_name'] ?? '';
                $emailRaw = $row['used_by_email'] ?? '';
                $name = is_string($nameRaw) ? mb_trim($nameRaw) : '';
                $email = is_string($emailRaw) ? mb_trim($emailRaw) : '';

                if ($name !== '' && $email !== '') {
                    $usedByLabel = sprintf('%s <%s>', $name, $email);
                } elseif ($name !== '') {
                    $usedByLabel = $name;
                } elseif ($email !== '') {
                    $usedByLabel = $email;
                }
            }

            return [
                'id' => $row['id'],
                'code' => $row['code'],
                'email' => $row['email'],
                'roles' => json_decode(is_string($row['roles'] ?? null) ? $row['roles'] : '[]', true),
                'used_by' => $row['used_by'],
                'used_by_name' => $row['used_by_name'] ?? null,
                'used_by_email' => $row['used_by_email'] ?? null,
                'used_by_display' => $usedByLabel,
                'used_at' => $row['used_at'],
                'created_at' => $row['created_at'],
                'expires_at' => $row['expires_at'],
                'status' => $status,
            ];
        }, $results);
    }

    public function getInvitationCount(array $filters = []): int
    {
        $where = [];
        $params = [];

        if (!empty($filters['email'])) {
            $where[] = 'email = ?';
            $params[] = $filters['email'];
        }

        if (!empty($filters['role'])) {
            $where[] = 'roles LIKE ?';
            $params[] = '%"' . $filters['role'] . '"%';
        }

        if (!empty($filters['status'])) {
            switch ($filters['status']) {
                case 'used':
                    $where[] = 'used_at IS NOT NULL';
                    break;
                case 'expired':
                    $where[] = 'used_at IS NULL AND expires_at <= CURRENT_TIMESTAMP';
                    break;
                case 'pending':
                default:
                    $where[] = 'used_at IS NULL AND expires_at > CURRENT_TIMESTAMP';
                    break;
            }
        }

        $sql = 'SELECT COUNT(*) as count FROM invitations';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $result = $this->connection->executeQuery($sql, $params)->fetchAssociative();

        return (int) ($result['count'] ?? 0);
    }

    public function purgeExpiredInvitations(): int
    {
        return $this->connection->executeStatement(
            'DELETE FROM invitations WHERE used_at IS NULL AND expires_at <= CURRENT_TIMESTAMP',
        );
    }
}
