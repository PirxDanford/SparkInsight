<?php

declare(strict_types=1);

namespace SparkInsightTest\Integration\Controller;

use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\PhpRenderer;
use SparkInsight\Controller\AuthController;
use SparkInsight\Service\InvitationService;
use SparkInsight\Service\OAuthProviderFactoryInterface;
use SparkInsight\Service\UserService;
use SparkInsight\Service\UserSession;
use SparkInsightTest\Integration\DatabaseTestCase;

final class AuthControllerOAuthProviderQaIntegrationTest extends DatabaseTestCase
{
    private UserSession $session;

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $_SESSION = [];
    }

    /**
     * @dataProvider providerSignupFlowProvider
     */
    public function testProviderSignupFlowCreatesUserAndConsumesInvitation(
        string $provider,
        string $scope,
        array $profile,
        array $expectedAuthorizationExtras
    ): void {
        $this->session = new UserSession();
        $connection = $this->connection;

        $invitationCode = 'invite-' . $provider;

        $connection->executeStatement(
            'INSERT INTO invitations (code, email, roles, created_at, expires_at) VALUES (?, ?, ?, ?, ?)',
            [
                $invitationCode,
                $profile['email'],
                json_encode(['reviewer']),
                date('Y-m-d H:i:s', strtotime('-1 day')),
                date('Y-m-d H:i:s', strtotime('+30 days')),
            ]
        );

        $providerInstance = $this->createMock(GenericProvider::class);
        $authorizationUrl = 'https://oauth.example.test/' . $provider;
        $capturedAuthorizationParams = null;

        $providerInstance->expects($this->once())
            ->method('getAuthorizationUrl')
            ->with($this->callback(function (array $params) use (&$capturedAuthorizationParams, $scope, $expectedAuthorizationExtras): bool {
                $capturedAuthorizationParams = $params;

                $this->assertArrayHasKey('state', $params);
                $this->assertNotSame('', $params['state']);
                $this->assertSame($scope, $params['scope']);

                foreach ($expectedAuthorizationExtras as $key => $value) {
                    $this->assertArrayHasKey($key, $params);
                    $this->assertSame($value, $params[$key]);
                }

                return true;
            }))
            ->willReturn($authorizationUrl);

        $providerInstance->expects($this->once())
            ->method('getAccessToken')
            ->with('authorization_code', ['code' => 'authcode'])
            ->willReturn(new AccessToken(['access_token' => 'token', 'expires' => 3600]));

        $providerFactory = $this->createMock(OAuthProviderFactoryInterface::class);
        $providerFactory->expects($this->exactly(2))
            ->method('getSupportedProviders')
            ->willReturn([$provider => ['label' => ucfirst($provider)]]);
        $providerFactory->expects($this->exactly(2))
            ->method('createProvider')
            ->with($provider)
            ->willReturn($providerInstance);
        $providerFactory->expects($this->once())
            ->method('getProviderScope')
            ->with($provider)
            ->willReturn($scope);
        $providerFactory->expects($this->once())
            ->method('getUserProfile')
            ->with($provider, $this->isInstanceOf(AccessToken::class))
            ->willReturn($profile);

        $renderer = $this->createMock(PhpRenderer::class);
        $invitationService = new InvitationService($connection);
        $userService = new UserService($connection);
        $controller = new AuthController($renderer, $providerFactory, $this->session, $invitationService, $userService);

        $loginRequest = $this->createMock(ServerRequestInterface::class);
        $loginRequest->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['code' => $invitationCode]);

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('withHeader')
            ->willReturn($response);
        $response->expects($this->exactly(2))
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $loginResult = $controller->login($loginRequest, $response, ['provider' => $provider]);

        $this->assertSame($response, $loginResult);
        $this->assertSame('signup', $this->session->getData('auth_mode'));
        $this->assertSame($invitationCode, $this->session->getData('invitation_code'));
        $this->assertSame($capturedAuthorizationParams['state'], $this->session->getState());

        $callbackRequest = $this->createMock(ServerRequestInterface::class);
        $callbackRequest->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([
                'code' => 'authcode',
                'state' => $this->session->getState(),
            ]);

        $callbackResult = $controller->callback($callbackRequest, $response, ['provider' => $provider]);

        $this->assertSame($response, $callbackResult);

        $userRow = $connection->executeQuery('SELECT * FROM users WHERE provider = ? AND provider_id = ?', [$provider, $profile['provider_id']])->fetchAssociative();
        $this->assertIsArray($userRow);
        $this->assertSame($provider, $userRow['provider']);
        $this->assertSame($profile['provider_id'], $userRow['provider_id']);
        $this->assertSame($profile['email'], $userRow['email']);
        $this->assertSame($profile['name'], $userRow['name']);
        $this->assertSame('active', $userRow['status']);
        $this->assertSame($invitationCode, $userRow['invitation_used']);

        $invitationRow = $connection->executeQuery('SELECT * FROM invitations WHERE code = ?', [$invitationCode])->fetchAssociative();
        $this->assertIsArray($invitationRow);
        $this->assertNotNull($invitationRow['used_at']);
        $this->assertSame((string) $userRow['id'], (string) $invitationRow['used_by']);
        $this->assertSame($provider, $this->session->getUser()['provider']);
        $this->assertSame($profile['email'], $this->session->getUser()['email']);
    }

    public static function providerSignupFlowProvider(): array
    {
        return [
            'google' => [
                'google',
                'openid profile email',
                [
                    'provider' => 'google',
                    'provider_id' => 'google-123',
                    'email' => 'user@google.test',
                    'name' => 'Google User',
                    'avatar' => 'https://avatars.example.test/google.png',
                ],
                [
                    'access_type' => 'offline',
                ],
            ],
            'linkedin' => [
                'linkedin',
                'openid profile email',
                [
                    'provider' => 'linkedin',
                    'provider_id' => 'linkedin-123',
                    'email' => 'user@linkedin.test',
                    'name' => 'LinkedIn User',
                    'avatar' => null,
                ],
                [],
            ],
        ];
    }
}