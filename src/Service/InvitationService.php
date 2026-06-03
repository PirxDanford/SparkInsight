<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use Doctrine\DBAL\Connection;

final class InvitationService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function generateInvitation(array $roles, ?string $email = null, int $expirationHours = 24): string
    {
        $code = bin2hex(random_bytes(32));
        $expiresAt = new \DateTime("+{$expirationHours} hours");

        $this->connection->executeStatement(
            'INSERT INTO invitations (code, email, roles, created_at, expires_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)',
            [
                $code,
                $email,
                json_encode($roles),
                $expiresAt->format('Y-m-d H:i:s'),
            ]
        );

        return $code;
    }

    public function validateInvitation(string $code): ?array
    {
        $result = $this->connection->executeQuery(
            'SELECT * FROM invitations WHERE code = ? AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP',
            [$code]
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
            [$userId, $code]
        );
    }

    public function getInvitations(array $filters = [], int $limit = 50, int $offset = 0): array
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

        $sql = 'SELECT invitations.*, u.name AS used_by_name, u.email AS used_by_email FROM invitations LEFT JOIN users u ON invitations.used_by = u.id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ? OFFSET ?';

        $params[] = $limit;
        $params[] = $offset;

        $results = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(static function (array $row) {
            $expiresAt = $row['expires_at'] ? new \DateTime($row['expires_at']) : null;
            $status = 'pending';

            if ($row['used_at'] !== null) {
                $status = 'used';
            } elseif ($expiresAt !== null && $expiresAt <= new \DateTime()) {
                $status = 'expired';
            }

            $usedByLabel = null;
            if (!empty($row['used_by_name']) || !empty($row['used_by_email'])) {
                $name = trim((string) ($row['used_by_name'] ?? ''));
                $email = trim((string) ($row['used_by_email'] ?? ''));

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
                'roles' => json_decode($row['roles'] ?? '[]', true),
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
}