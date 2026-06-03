<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use SparkInsight\Service\UserService;

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
            'avatar' => 'avatar.jpg',
            'roles' => '["reviewer"]',
            'status' => 'active',
            'invitation_used' => null,
            'last_login' => '2023-01-01 00:00:00',
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00',
        ];

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAssociative')->willReturn($userData);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT * FROM users WHERE provider = ? AND provider_id = ?', ['github', '123'])
            ->willReturn($resultMock);

        $user = $this->userService->getUserByProviderAndId('github', '123');

        $this->assertEquals(1, $user['id']);
        $this->assertEquals(['reviewer'], $user['roles']);
    }

    public function testGetUserByProviderAndIdNotFound(): void
    {
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAssociative')->willReturn(false);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($resultMock);

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
                        && $params[3] === '%test%';
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
                        && $params[3] === '%find%';
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
}