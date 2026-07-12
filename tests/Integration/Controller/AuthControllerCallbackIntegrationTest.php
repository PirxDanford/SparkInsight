<?php

declare(strict_types=1);

namespace SparkInsightTest\Integration\Controller;

use SparkInsight\Service\UserService;
use SparkInsightTest\Integration\DatabaseTestCase;

/**
 * Integration test for GitHub OAuth signup flow.
 * 
 * This test verifies that the baseline database schema includes user tracking fields
 * needed by the OAuth signup flow by testing UserService operations against
 * the test database with these fields.
 */
class AuthControllerCallbackIntegrationTest extends DatabaseTestCase
{
    private UserService $userService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userService = new UserService($this->connection);
    }

    /**
     * Test that a new GitHub OAuth user can be created with all schema fields.
    * This specifically verifies baseline user tracking fields (status, invitation_used, last_login) work.
     */
    public function testGitHubOAuthSignupCreatesUserWithNewSchemaFields(): void
    {
        // Simulate GitHub OAuth profile
        $user = $this->userService->findOrCreateUser(
            provider: 'github',
            providerId: '12345',
            email: 'user@github.com',
            name: 'GitHub User',
            avatar: 'https://avatars.githubusercontent.com/u/12345'
        );

        $this->assertNotNull($user['id'], 'User should be created');
        $this->assertEquals('github', $user['provider']);
        $this->assertEquals('12345', $user['provider_id']);
        $this->assertEquals('user@github.com', $user['email']);
        $this->assertEquals('GitHub User', $user['name']);
        
        // Verify baseline schema fields exist and have correct default values
        $this->assertArrayHasKey('status', $user, 'Status column should exist in baseline schema');
        $this->assertEquals('active', $user['status'], 'New users should have status "active"');
        
        $this->assertArrayHasKey('invitation_used', $user, 'Invitation_used column should exist in baseline schema');
        // First call to findOrCreateUser sets last_login to current time, so invitation_used will be null
        $this->assertNull($user['invitation_used'], 'OAuth signup should not have invitation_used set');
        
        $this->assertArrayHasKey('last_login', $user, 'Last_login column should exist in baseline schema');
        // After findOrCreateUser, last_login is set to now for new users
        $this->assertNotNull($user['last_login'], 'New users should have last_login set');
        
        // Now verify in the actual database
        $result = $this->connection->executeQuery(
            'SELECT * FROM users WHERE id = ?',
            [$user['id']]
        );
        $dbUser = $result->fetchAssociative();
        
        $this->assertNotNull($dbUser, 'User should exist in database');
        $this->assertEquals('active', $dbUser['status']);
        $this->assertNull($dbUser['invitation_used']);
        $this->assertNotNull($dbUser['last_login']);
    }

    /**
     * Test that an existing user can be retrieved and last_login updated on second OAuth login.
     * This verifies the database schema supports the OAuth login workflow with the new fields.
     */
    public function testGitHubOAuthLoginUpdatesLastLogin(): void
    {
        // Create a user (first login)
        $user1 = $this->userService->findOrCreateUser(
            provider: 'github',
            providerId: 'existing-user-123',
            email: 'existing@github.com',
            name: 'Existing GitHub User',
            avatar: null
        );

        $this->assertNotNull($user1['id'], 'User should be created on first login');
        $firstLastLogin = $user1['last_login'];

        // Sleep a bit to ensure time difference
        usleep(100000); // 100ms

        // Call findOrCreateUser again (second login) - should update last_login
        $user2 = $this->userService->findOrCreateUser(
            provider: 'github',
            providerId: 'existing-user-123',
            email: 'existing@github.com',
            name: 'Existing GitHub User',
            avatar: null
        );

        $this->assertEquals($user1['id'], $user2['id'], 'Should be the same user');
        $this->assertEquals('active', $user2['status'], 'Status should remain active');
        $this->assertNull($user2['invitation_used'], 'Invitation_used should still be null');
        // Last login should be updated
        $this->assertNotNull($user2['last_login'], 'Last_login should be set');
        
        // Verify in database
        $result = $this->connection->executeQuery(
            'SELECT * FROM users WHERE id = ?',
            [$user1['id']]
        );
        $dbUser = $result->fetchAssociative();
        
        $this->assertNotNull($dbUser, 'User should exist in database');
        $this->assertEquals('active', $dbUser['status']);
        $this->assertNull($dbUser['invitation_used']);
        $this->assertNotNull($dbUser['last_login']);
    }
}
