<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use SparkInsight\Service\UserService;
use RuntimeException;

class UserServiceTest extends TestCase
{
    private Connection $connection;
    private UserService $userService;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->userService = new UserService($this->connection);
    }

    public function testFindOrCreateUserCreatesNewUser(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAssociative')->willReturn(false);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT * FROM users WHERE provider = ? AND provider_id = ?', ['github', '123'])
            ->willReturn($resultMock);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->callback(function ($sql) {
                    return strpos($sql, 'INSERT INTO users') !== false && 
                           strpos($sql, 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)') !== false;
                }),
                $this->callback(function ($params) {
                    return is_array($params) && count($params) === 11;
                })
            );

        $this->connection->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $user = $this->userService->findOrCreateUser('github', '123', 'test@example.com', 'Test User', 'avatar.jpg');

        $this->assertEquals(1, $user['id']);
        $this->assertEquals('github', $user['provider']);
        $this->assertEquals('active', $user['status']);
        $this->assertEquals(['reviewer'], $user['roles']);
    }

    public function testFindOrCreateUserUpdatesExistingUser(): void
    {
        $existingUser = [
            'id' => 1,
            'provider' => 'github',
            'provider_id' => '123',
            'email' => 'test@example.com',
            'name' => 'Test User',
            'display_name' => null,
            'avatar' => 'avatar.jpg',
            'roles' => '["reviewer"]',
            'status' => 'active',
            'invitation_used' => null,
            'last_login' => '2023-01-01 00:00:00',
        ];

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAssociative')->willReturn($existingUser);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT * FROM users WHERE provider = ? AND provider_id = ?', ['github', '123'])
            ->willReturn($resultMock);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->callback(function ($sql) {
                    return strpos($sql, 'UPDATE users SET last_login = ?') !== false && 
                           strpos($sql, 'updated_at = ?') !== false && 
                           strpos($sql, 'WHERE id = ?') !== false;
                }),
                $this->callback(function ($params) {
                    return is_array($params) && count($params) === 3;
                })
            );

        $user = $this->userService->findOrCreateUser('github', '123', 'test@example.com', 'Test User', 'avatar.jpg');

        $this->assertEquals(1, $user['id']);
        $this->assertEquals(['reviewer'], $user['roles']);
    }

    public function testGetUserByProviderAndId(): void
    {
        $userData = [
            'id' => 1,
            'provider' => 'github',
            'provider_id' => '123',
            'email' => 'test@example.com',
            'name' => 'Test User',
            'display_name' => null,
            'avatar' => 'avatar.jpg',
            'roles' => '["reviewer"]',
            'status' => 'active',
            'invitation_used' => null,
            'last_login' => '2023-01-01 00:00:00',
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00',
        ];

        $identityResult = $this->createMock(Result::class);
        $identityResult->method('fetchAssociative')->willReturn(false);

        $legacyResult = $this->createMock(Result::class);
        $legacyResult->method('fetchAssociative')->willReturn($userData);

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $params) use ($identityResult, $legacyResult) {
                static $call = 0;
                $call++;

                if ($call === 1) {
                    $this->assertSame('SELECT u.* FROM oauth_identities oi INNER JOIN users u ON u.id = oi.user_id WHERE oi.provider = ? AND oi.provider_user_id = ? LIMIT 1', $sql);
                    $this->assertSame(['github', '123'], $params);
                    return $identityResult;
                }

                $this->assertSame('SELECT * FROM users WHERE provider = ? AND provider_id = ?', $sql);
                $this->assertSame(['github', '123'], $params);
                return $legacyResult;
            });

        $user = $this->userService->getUserByProviderAndId('github', '123');

        $this->assertEquals(1, $user['id']);
        $this->assertEquals(['reviewer'], $user['roles']);
    }

    public function testGetUserByProviderAndIdNotFound(): void
    {
        $identityResult = $this->createMock(Result::class);
        $identityResult->method('fetchAssociative')->willReturn(false);

        $legacyResult = $this->createMock(Result::class);
        $legacyResult->method('fetchAssociative')->willReturn(false);

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnOnConsecutiveCalls($identityResult, $legacyResult);

        $user = $this->userService->getUserByProviderAndId('github', '123');

        $this->assertNull($user);
    }

    public function testSetLastLogin(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with('UPDATE users SET last_login = ?, updated_at = ? WHERE id = ?', $this->callback(function ($params) {
                return is_array($params) && count($params) === 3 && $params[2] === 1;
            }))
            ->willReturn(1);

        $result = $this->userService->setLastLogin(1);

        $this->assertTrue($result);
    }

    public function testUpdateUserStatus(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with('UPDATE users SET status = ?, updated_at = ? WHERE id = ?', $this->callback(function ($params) {
                return is_array($params) && count($params) === 3 && $params[0] === 'disabled' && $params[2] === 1;
            }))
            ->willReturn(1);

        $result = $this->userService->updateUserStatus(1, 'disabled');

        $this->assertTrue($result);
    }

    public function testUpdateUserStatusInvalidStatus(): void
    {
        $result = $this->userService->updateUserStatus(1, 'invalid');

        $this->assertFalse($result);
    }

    public function testGetUserByIdReturnsUser(): void
    {
        $userData = [
            'id' => 2,
            'provider' => 'google',
            'provider_id' => 'abc',
            'email' => 'user@example.com',
            'name' => 'Example User',
            'display_name' => 'Public Example',
            'avatar' => 'avatar.png',
            'roles' => '["reviewer"]',
            'status' => 'active',
            'invitation_used' => null,
            'last_login' => '2026-01-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAssociative')->willReturn($userData);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT * FROM users WHERE id = ?', [2])
            ->willReturn($resultMock);

        $user = $this->userService->getUserById(2);

        $this->assertSame(2, $user['id']);
        $this->assertSame('Public Example', $user['display_name']);
        $this->assertSame(['reviewer'], $user['roles']);
    }

    public function testGetUserByIdNotFound(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAssociative')->willReturn(false);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT * FROM users WHERE id = ?', [3])
            ->willReturn($resultMock);

        $user = $this->userService->getUserById(3);

        $this->assertNull($user);
    }

    public function testGetAllUsersAppliesFiltersAndSort(): void
    {
        $filters = ['status' => 'active', 'role' => 'reviewer', 'search' => 'test'];
        $sort = ['email' => 'ASC'];

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAllAssociative')->willReturn([
            [
                'id' => 5,
                'provider' => 'github',
                'provider_id' => '123',
                'email' => 'test@example.com',
                'name' => 'Filtered User',
                'display_name' => 'Readable Filtered User',
                'avatar' => null,
                'roles' => '["reviewer"]',
                'status' => 'active',
                'invitation_used' => null,
                'last_login' => '2026-01-01 00:00:00',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ],
        ]);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('SELECT * FROM users'),
                $this->callback(function ($params) {
                    return is_array($params)
                        && $params[0] === 'active'
                        && $params[1] === json_encode('reviewer')
                        && $params[2] === '%test%'
                        && $params[3] === '%test%'
                        && $params[4] === '%test%';
                })
            )
            ->willReturn($resultMock);

        $users = $this->userService->getAllUsers($filters, $sort, 50, 0);

        $this->assertCount(1, $users);
        $this->assertSame(['reviewer'], $users[0]['roles']);
    }

    public function testGetUserCountWithFilters(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAssociative')->willReturn(['count' => 7]);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('SELECT COUNT(*) as count FROM users'),
                $this->callback(function ($params) {
                    return is_array($params)
                        && $params[0] === 'disabled'
                        && $params[1] === json_encode('admin')
                        && $params[2] === '%find%'
                        && $params[3] === '%find%'
                        && $params[4] === '%find%';
                })
            )
            ->willReturn($resultMock);

        $count = $this->userService->getUserCount([
            'status' => 'disabled',
            'role' => 'admin',
            'search' => 'find',
        ]);

        $this->assertSame(7, $count);
    }

    public function testUpdateUserDisplayName(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with('UPDATE users SET display_name = ?, updated_at = ? WHERE id = ?', $this->callback(function ($params) {
                return is_array($params)
                    && $params[0] === 'Friendly Name'
                    && $params[2] === 4;
            }))
            ->willReturn(1);

        $result = $this->userService->updateUserDisplayName(4, 'Friendly Name');

        $this->assertTrue($result);
    }

    public function testUpdateUserDisplayNameRejectsTooLongValue(): void
    {
        $result = $this->userService->updateUserDisplayName(4, str_repeat('a', 256));

        $this->assertFalse($result);
    }

    public function testGetUserByEmailReturnsUser(): void
    {
        $userData = [
            'id' => 9,
            'provider' => 'github',
            'provider_id' => 'oid-9',
            'email' => 'person@example.com',
            'name' => 'Person Example',
            'display_name' => 'Display Person',
            'avatar' => null,
            'roles' => '["admin","reviewer"]',
            'status' => 'active',
            'invitation_used' => null,
            'last_login' => '2026-01-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAssociative')->willReturn($userData);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT * FROM users WHERE email = ? LIMIT 1', ['person@example.com'])
            ->willReturn($resultMock);

        $user = $this->userService->getUserByEmail('person@example.com');

        $this->assertSame(9, $user['id']);
        $this->assertSame('Display Person', $user['display_name']);
        $this->assertSame(['admin', 'reviewer'], $user['roles']);
    }

    public function testGetUserByEmailReturnsNullWhenNotFound(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAssociative')->willReturn(false);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT * FROM users WHERE email = ? LIMIT 1', ['missing@example.com'])
            ->willReturn($resultMock);

        $user = $this->userService->getUserByEmail('missing@example.com');

        $this->assertNull($user);
    }

    public function testGetLinkedOAuthProvidersReturnsRows(): void
    {
        $rows = [
            [
                'provider' => 'github',
                'provider_user_id' => 'gh_1',
                'provider_email' => 'gh@example.com',
                'linked_at' => '2026-01-01 10:00:00',
                'last_used_at' => '2026-01-02 10:00:00',
            ],
            [
                'provider' => 'google',
                'provider_user_id' => 'gg_2',
                'provider_email' => 'gg@example.com',
                'linked_at' => '2026-01-03 10:00:00',
                'last_used_at' => '2026-01-04 10:00:00',
            ],
        ];

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAllAssociative')->willReturn($rows);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                'SELECT provider, provider_user_id, provider_email, linked_at, last_used_at FROM oauth_identities WHERE user_id = ? ORDER BY linked_at ASC',
                [7]
            )
            ->willReturn($resultMock);

        $providers = $this->userService->getLinkedOAuthProviders(7);

        $this->assertSame($rows, $providers);
    }

    public function testGetLinkedOAuthProvidersReturnsEmptyArrayWhenQueryFails(): void
    {
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willThrowException(new RuntimeException('table missing'));

        $providers = $this->userService->getLinkedOAuthProviders(7);

        $this->assertSame([], $providers);
    }

    public function testLinkOAuthProviderInsertsIdentityAndReturnsTrue(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'INSERT INTO oauth_identities (user_id, provider, provider_user_id, provider_email, linked_at, last_used_at) VALUES (?, ?, ?, ?, ?, ?)',
                $this->callback(function ($params) {
                    return is_array($params)
                        && count($params) === 6
                        && $params[0] === 11
                        && $params[1] === 'github'
                        && $params[2] === 'gh-11'
                        && $params[3] === 'dev@example.com'
                        && is_string($params[4])
                        && is_string($params[5]);
                })
            )
            ->willReturn(1);

        $result = $this->userService->linkOAuthProvider(11, 'github', 'gh-11', 'dev@example.com');

        $this->assertTrue($result);
    }

    public function testUpdateUserRoles(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with('UPDATE users SET roles = ?, updated_at = ? WHERE id = ?', $this->callback(function ($params) {
                return is_array($params)
                    && json_decode($params[0], true) === ['author']
                    && $params[2] === 4;
            }))
            ->willReturn(1);

        $result = $this->userService->updateUserRoles(4, ['author']);

        $this->assertTrue($result);
    }

    public function testGetAllUsersBindsLimitAndOffsetAsIntegers(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAllAssociative')->willReturn([]);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('LIMIT ? OFFSET ?'),
                ['active', 20, 0],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER]
            )
            ->willReturn($resultMock);

        $this->userService->getAllUsers(['status' => 'active'], ['created_at' => 'DESC'], 20, 0);
    }
}