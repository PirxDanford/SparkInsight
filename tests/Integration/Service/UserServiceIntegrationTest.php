<?php

declare(strict_types=1);

namespace SparkInsightTest\Integration\Service;

use SparkInsight\Service\UserService;
use SparkInsightTest\Integration\DatabaseTestCase;

class UserServiceIntegrationTest extends DatabaseTestCase
{
    private UserService $userService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userService = new UserService($this->connection);
    }

    public function testFindOrCreateUserCreatesNewUser(): void
    {
        $user = $this->userService->findOrCreateUser('github', '123', 'test@example.com', 'Test User', 'avatar.jpg');

        $this->assertEquals(1, $user['id']);
        $this->assertEquals('github', $user['provider']);
        $this->assertEquals('active', $user['status']);
        $this->assertEquals(['reviewer'], $user['roles']);
    }

    public function testFindOrCreateUserUpdatesExistingUser(): void
    {
        // Create user
        $this->userService->findOrCreateUser('github', '123', 'test@example.com', 'Test User', 'avatar.jpg');

        // Find again
        $user = $this->userService->findOrCreateUser('github', '123', 'test@example.com', 'Updated User', 'newavatar.jpg');

        $this->assertEquals(1, $user['id']);
        $this->assertEquals('Test User', $user['name']); // Should not update name
        $this->assertEquals('avatar.jpg', $user['avatar']); // Should not update
    }

    public function testGetUserByProviderAndId(): void
    {
        $this->userService->findOrCreateUser('github', '123', 'test@example.com', 'Test User');

        $user = $this->userService->getUserByProviderAndId('github', '123');

        $this->assertEquals(1, $user['id']);
        $this->assertEquals('test@example.com', $user['email']);
    }

    public function testGetUserById(): void
    {
        $this->userService->findOrCreateUser('github', '123', 'test@example.com', 'Test User');

        $user = $this->userService->getUserById(1);

        $this->assertEquals(1, $user['id']);
        $this->assertEquals('test@example.com', $user['email']);
    }

    public function testUpdateUserStatus(): void
    {
        $this->userService->findOrCreateUser('github', '123', 'test@example.com', 'Test User');

        $result = $this->userService->updateUserStatus(1, 'disabled');

        $this->assertTrue($result);

        $user = $this->userService->getUserById(1);
        $this->assertEquals('disabled', $user['status']);
    }

    public function testUpdateUserDisplayName(): void
    {
        $this->userService->findOrCreateUser('github', '123', 'test@example.com', 'Test User');

        $result = $this->userService->updateUserDisplayName(1, 'Readable Name');

        $this->assertTrue($result);

        $user = $this->userService->getUserById(1);
        $this->assertSame('Readable Name', $user['display_name']);
    }

    public function testGetAllUsers(): void
    {
        $this->userService->findOrCreateUser('github', '123', 'test@example.com', 'Test User');
        $this->userService->findOrCreateUser('google', '456', 'test2@example.com', 'Test User 2');

        $users = $this->userService->getAllUsers();

        $this->assertCount(2, $users);
    }
}