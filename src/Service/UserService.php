<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Throwable;

final class UserService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findOrCreateUser(
        string $provider,
        string $providerId,
        string $email,
        string $name,
        ?string $avatar = null,
        array $roles = ['reviewer'],
        ?string $invitationCode = null,
    ): array {
        // Check if user exists
        $existing = $this->connection->executeQuery(
            'SELECT * FROM users WHERE provider = ? AND provider_id = ?',
            [$provider, $providerId],
        )->fetchAssociative();

        if ($existing) {
            // Update last login time for existing user
            $now = date('Y-m-d H:i:s');
            $this->connection->executeStatement(
                'UPDATE users SET last_login = ?, updated_at = ? WHERE id = ?',
                [$now, $now, $existing['id']],
            );

            return [
                'id' => $existing['id'],
                'provider' => $existing['provider'],
                'provider_id' => $existing['provider_id'],
                'email' => $existing['email'],
                'name' => $existing['name'],
                'display_name' => $existing['display_name'] ?? null,
                'avatar' => $existing['avatar'],
                'roles' => json_decode($existing['roles'] ?? '[]', true),
                'status' => $existing['status'],
                'invitation_used' => $existing['invitation_used'],
                'last_login' => $now,
            ];
        }

        // Create new user
        $now = date('Y-m-d H:i:s');
        $this->connection->executeStatement(
            'INSERT INTO users (provider, provider_id, email, name, avatar, roles, status, invitation_used, last_login, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $provider,
                $providerId,
                $email,
                $name,
                $avatar,
                json_encode($roles),
                'active',
                $invitationCode,
                $now,
                $now,
                $now,
            ],
        );

        $userId = (int) $this->connection->lastInsertId();

        return [
            'id' => $userId,
            'provider' => $provider,
            'provider_id' => $providerId,
            'email' => $email,
            'name' => $name,
            'display_name' => null,
            'avatar' => $avatar,
            'roles' => $roles,
            'status' => 'active',
            'invitation_used' => $invitationCode,
            'last_login' => $now,
        ];
    }

    public function getUserByProviderAndId(string $provider, string $providerId): ?array
    {
        try {
            $identityResult = $this->connection->executeQuery(
                'SELECT u.* FROM oauth_identities oi INNER JOIN users u ON u.id = oi.user_id WHERE oi.provider = ? AND oi.provider_user_id = ? LIMIT 1',
                [$provider, $providerId],
            )->fetchAssociative();

            if ($identityResult) {
                return [
                    'id' => $identityResult['id'],
                    'provider' => $identityResult['provider'],
                    'provider_id' => $identityResult['provider_id'],
                    'email' => $identityResult['email'],
                    'name' => $identityResult['name'],
                    'display_name' => $identityResult['display_name'] ?? null,
                    'avatar' => $identityResult['avatar'],
                    'roles' => json_decode($identityResult['roles'] ?? '[]', true),
                    'status' => $identityResult['status'],
                    'invitation_used' => $identityResult['invitation_used'],
                    'last_login' => $identityResult['last_login'],
                    'created_at' => $identityResult['created_at'],
                    'updated_at' => $identityResult['updated_at'],
                ];
            }
        } catch (Throwable $e) {
            // Identity table may not exist in older environments; fall back to legacy lookup.
        }

        $result = $this->connection->executeQuery(
            'SELECT * FROM users WHERE provider = ? AND provider_id = ?',
            [$provider, $providerId],
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return [
            'id' => $result['id'],
            'provider' => $result['provider'],
            'provider_id' => $result['provider_id'],
            'email' => $result['email'],
            'name' => $result['name'],
            'display_name' => $result['display_name'] ?? null,
            'avatar' => $result['avatar'],
            'roles' => json_decode($result['roles'] ?? '[]', true),
            'status' => $result['status'],
            'invitation_used' => $result['invitation_used'],
            'last_login' => $result['last_login'],
            'created_at' => $result['created_at'],
            'updated_at' => $result['updated_at'],
        ];
    }

    public function getUserByEmail(string $email): ?array
    {
        $result = $this->connection->executeQuery(
            'SELECT * FROM users WHERE email = ? LIMIT 1',
            [$email],
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return [
            'id' => $result['id'],
            'provider' => $result['provider'],
            'provider_id' => $result['provider_id'],
            'email' => $result['email'],
            'name' => $result['name'],
            'display_name' => $result['display_name'] ?? null,
            'avatar' => $result['avatar'],
            'roles' => json_decode($result['roles'] ?? '[]', true),
            'status' => $result['status'],
            'invitation_used' => $result['invitation_used'],
            'last_login' => $result['last_login'],
            'created_at' => $result['created_at'],
            'updated_at' => $result['updated_at'],
        ];
    }

    public function getLinkedOAuthProviders(int $userId): array
    {
        try {
            return $this->connection->executeQuery(
                'SELECT provider, provider_user_id, provider_email, linked_at, last_used_at FROM oauth_identities WHERE user_id = ? ORDER BY linked_at ASC',
                [$userId],
            )->fetchAllAssociative();
        } catch (Throwable $e) {
            return [];
        }
    }

    public function linkOAuthProvider(int $userId, string $provider, string $providerUserId, ?string $providerEmail = null): bool
    {
        $now = date('Y-m-d H:i:s');

        $this->connection->executeStatement(
            'INSERT INTO oauth_identities (user_id, provider, provider_user_id, provider_email, linked_at, last_used_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $provider, $providerUserId, $providerEmail, $now, $now],
        );

        return true;
    }

    public function setLastLogin(int $userId): bool
    {
        $now = date('Y-m-d H:i:s');
        $result = $this->connection->executeStatement(
            'UPDATE users SET last_login = ?, updated_at = ? WHERE id = ?',
            [$now, $now, $userId],
        );

        return $result > 0;
    }

    public function getUserById(int $id): ?array
    {
        $result = $this->connection->executeQuery(
            'SELECT * FROM users WHERE id = ?',
            [$id],
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return [
            'id' => $result['id'],
            'provider' => $result['provider'],
            'provider_id' => $result['provider_id'],
            'email' => $result['email'],
            'name' => $result['name'],
            'display_name' => $result['display_name'] ?? null,
            'avatar' => $result['avatar'],
            'roles' => json_decode($result['roles'] ?? '[]', true),
            'status' => $result['status'],
            'invitation_used' => $result['invitation_used'],
            'last_login' => $result['last_login'],
            'created_at' => $result['created_at'],
            'updated_at' => $result['updated_at'],
        ];
    }

    public function getAllUsers(array $filters = [], array $sort = ['created_at' => 'DESC'], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['role'])) {
            $where[] = 'JSON_CONTAINS(roles, ?)';
            $params[] = json_encode($filters['role']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $where[] = '(name LIKE ? OR display_name LIKE ? OR email LIKE ?)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $orderBy = [];
        foreach ($sort as $field => $direction) {
            $allowedFields = ['name', 'email', 'created_at', 'last_login', 'status'];
            if (in_array($field, $allowedFields)) {
                $orderBy[] = "$field " . mb_strtoupper($direction);
            }
        }
        $orderClause = !empty($orderBy) ? 'ORDER BY ' . implode(', ', $orderBy) : '';

        $sql = "SELECT * FROM users $whereClause $orderClause LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types = array_fill(0, count($params) - 2, ParameterType::STRING);
        $types[] = ParameterType::INTEGER;
        $types[] = ParameterType::INTEGER;

        $results = $this->connection->executeQuery($sql, $params, $types)->fetchAllAssociative();

        return array_map(static fn ($row) => [
            'id' => $row['id'],
            'provider' => $row['provider'],
            'provider_id' => $row['provider_id'],
            'email' => $row['email'],
            'name' => $row['name'],
            'display_name' => $row['display_name'] ?? null,
            'avatar' => $row['avatar'],
            'roles' => json_decode($row['roles'] ?? '[]', true),
            'status' => $row['status'],
            'invitation_used' => $row['invitation_used'],
            'last_login' => $row['last_login'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ], $results);
    }

    public function updateUserStatus(int $userId, string $status): bool
    {
        if (!in_array($status, ['active', 'disabled'])) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $result = $this->connection->executeStatement(
            'UPDATE users SET status = ?, updated_at = ? WHERE id = ?',
            [$status, $now, $userId],
        );

        return $result > 0;
    }

    public function updateUserRoles(int $userId, array $roles): bool
    {
        $now = date('Y-m-d H:i:s');
        $result = $this->connection->executeStatement(
            'UPDATE users SET roles = ?, updated_at = ? WHERE id = ?',
            [json_encode($roles), $now, $userId],
        );

        return $result > 0;
    }

    public function updateUserDisplayName(int $userId, ?string $displayName): bool
    {
        $normalizedDisplayName = $displayName !== null ? mb_trim($displayName) : null;
        if ($normalizedDisplayName === '') {
            $normalizedDisplayName = null;
        }

        if ($normalizedDisplayName !== null && mb_strlen($normalizedDisplayName) > 255) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $result = $this->connection->executeStatement(
            'UPDATE users SET display_name = ?, updated_at = ? WHERE id = ?',
            [$normalizedDisplayName, $now, $userId],
        );

        return $result > 0;
    }

    public function getUserCount(array $filters = []): int
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['role'])) {
            $where[] = 'JSON_CONTAINS(roles, ?)';
            $params[] = json_encode($filters['role']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $where[] = '(name LIKE ? OR display_name LIKE ? OR email LIKE ?)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT COUNT(*) as count FROM users $whereClause";

        $result = $this->connection->executeQuery($sql, $params)->fetchAssociative();

        return (int) ($result['count'] ?? 0);
    }
}
