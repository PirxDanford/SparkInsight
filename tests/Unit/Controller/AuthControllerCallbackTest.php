<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\PhpRenderer;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use SparkInsight\Config\Config;
use SparkInsight\Controller\AuthController;
use SparkInsight\Service\InvitationService;
use SparkInsight\Service\OAuthProviderFactory;
use SparkInsight\Service\OAuthProviderFactoryInterface;
use SparkInsight\Service\UserService;
use SparkInsight\Service\UserSession;

class AuthControllerCallbackTest extends TestCase
{
    private PhpRenderer $renderer;
    private OAuthProviderFactory $providerFactory;
    private UserSession $session;
    private InvitationService $invitationService;
    private UserService $userService;
    private AuthController $controller;
    private Connection $connection;

    protected function setUp(): void
    {
        $_ENV['OAUTH_GITHUB_CLIENT_ID'] = '123';
        $_ENV['OAUTH_GITHUB_CLIENT_SECRET'] = 'secret';

        $this->renderer = $this->createMock(PhpRenderer::class);
        $this->providerFactory = new OAuthProviderFactory(Config::fromEnvironment());
        $this->session = new UserSession();
        $this->connection = $this->createMock(Connection::class);
        $this->invitationService = new InvitationService($this->connection);
        $this->userService = new UserService($this->connection);
        $this->controller = new AuthController($this->renderer, $this->providerFactory, $this->session, $this->invitationService, $this->userService);
    }

    protected function tearDown(): void
    {
        unset($_ENV['OAUTH_GITHUB_CLIENT_ID']);
        unset($_ENV['OAUTH_GITHUB_CLIENT_SECRET']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    public function testCallbackWithValidStateButNoCode(): void
    {
        $this->session->setState('expected-state');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['state' => 'expected-state']);

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->expects($this->once())
            ->method('write');

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getBody')
            ->willReturn($stream);
        $response->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($response);
        $response->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'text/html')
            ->willReturn($response);

        $result = $this->controller->callback($request, $response, ['provider' => 'github']);

        $this->assertSame($response, $result);
    }

    public function testCallbackWithValidCodeButNoState(): void
    {
        $this->session->setState('expected-state');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['code' => 'code123']);

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->expects($this->once())
            ->method('write');

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getBody')
            ->willReturn($stream);
        $response->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($response);
        $response->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'text/html')
            ->willReturn($response);

        $result = $this->controller->callback($request, $response, ['provider' => 'github']);

        $this->assertSame($response, $result);
    }

    public function testCallbackWithOAuthError(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['error' => 'access_denied']);

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->expects($this->once())
            ->method('write');

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getBody')
            ->willReturn($stream);
        $response->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($response);
        $response->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'text/html')
            ->willReturn($response);

        $result = $this->controller->callback($request, $response, ['provider' => 'github']);

        $this->assertSame($response, $result);
    }

    public function testCallbackWithInvalidProvider(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->callback($request, $response, ['provider' => 'invalid']);

        $this->assertSame($response, $result);
    }

    public function testLoginWithInvalidProvider(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->login($request, $response, ['provider' => 'invalid']);

        $this->assertSame($response, $result);
    }

    public function testLoginGeneratesOAuthUrl(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([]);

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->atLeastOnce())
            ->method('withHeader')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->login($request, $response, ['provider' => 'github']);

        $this->assertNotNull($result);
        $this->assertNotNull($this->session->getState());
    }

    public function testLoginWithInvitationCodeSetsSignupModeAndRedirects(): void
    {
        $provider = $this->createMock(GenericProvider::class);
        $provider->expects($this->once())
            ->method('getAuthorizationUrl')
            ->willReturn('https://auth.example.com');

        $providerFactory = $this->createMock(OAuthProviderFactoryInterface::class);
        $providerFactory->expects($this->once())
            ->method('getSupportedProviders')
            ->willReturn(['github' => []]);
        $providerFactory->expects($this->once())
            ->method('createProvider')
            ->with('github')
            ->willReturn($provider);
        $providerFactory->expects($this->once())
            ->method('getProviderScope')
            ->with('github')
            ->willReturn('read:user user:email');

        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 1,
                'code' => 'abc123',
                'email' => 'test@example.com',
                'roles' => json_encode(['reviewer']),
            ]);

        $connection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($result);

        $invitationService = new InvitationService($connection);
        $controller = new AuthController($this->renderer, $providerFactory, $this->session, $invitationService, $this->userService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['code' => 'abc123']);

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', 'https://auth.example.com')
            ->willReturn($response);
        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $controller->login($request, $response, ['provider' => 'github']);

        $this->assertSame($response, $result);
        $this->assertSame('signup', $this->session->getData('auth_mode'));
        $this->assertSame('abc123', $this->session->getData('invitation_code'));
        $this->assertSame(['reviewer'], $this->session->getData('invitation_roles'));
        $this->assertSame('test@example.com', $this->session->getData('invitation_email'));
    }

    public function testCallbackWithSignupCreatesUserAndRedirects(): void
    {
        $provider = $this->createMock(GenericProvider::class);
        $provider->expects($this->once())
            ->method('getAccessToken')
            ->with('authorization_code', ['code' => 'authcode'])
            ->willReturn(new AccessToken(['access_token' => 'token', 'expires' => 3600]));

        $providerFactory = $this->createMock(OAuthProviderFactoryInterface::class);
        $providerFactory->expects($this->once())
            ->method('getSupportedProviders')
            ->willReturn(['github' => []]);
        $providerFactory->expects($this->once())
            ->method('createProvider')
            ->with('github')
            ->willReturn($provider);
        $providerFactory->expects($this->never())
            ->method('getProviderScope');
        $providerFactory->expects($this->once())
            ->method('getUserProfile')
            ->with('github', $this->isInstanceOf(AccessToken::class))
            ->willReturn([
                'provider' => 'github',
                'provider_id' => '123',
                'email' => 'test@example.com',
                'name' => 'Test User',
                'avatar' => 'https://example.com/avatar.png',
            ]);

        $invitationConnection = $this->createMock(Connection::class);
        $validateResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $validateResult->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 1,
                'code' => 'abc123',
                'email' => 'test@example.com',
                'roles' => json_encode(['reviewer']),
            ]);

        $invitationConnection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT * FROM invitations WHERE code = ? AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP', ['abc123'])
            ->willReturn($validateResult);

        $invitationConnection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE invitations SET used_by = ?, used_at = CURRENT_TIMESTAMP WHERE code = ? AND used_at IS NULL',
                [123, 'abc123']
            )
            ->willReturn(1);

        $invitationService = new InvitationService($invitationConnection);

        $userConnection = $this->createMock(Connection::class);
        $selectResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $selectResult->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(false);

        $userConnection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($selectResult);

        $userConnection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'INSERT INTO users (provider, provider_id, email, name, avatar, roles, status, invitation_used, last_login, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $this->callback(function (array $params) {
                    return $params[0] === 'github'
                        && $params[1] === '123'
                        && $params[2] === 'test@example.com'
                        && $params[3] === 'Test User'
                        && $params[4] === 'https://example.com/avatar.png'
                        && $params[5] === json_encode(['reviewer'])
                        && $params[6] === 'active'
                        && $params[7] === 'abc123'
                        && count($params) === 11;
                })
            )
            ->willReturn(1);

        $userConnection->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('123');

        $userService = new UserService($userConnection);

        $controller = new AuthController($this->renderer, $providerFactory, $this->session, $invitationService, $userService);
        $this->session->setState('state123');
        $this->session->setData('auth_mode', 'signup');
        $this->session->setData('invitation_code', 'abc123');
        $this->session->setData('invitation_roles', ['reviewer']);
        $this->session->setData('invitation_email', 'test@example.com');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['code' => 'authcode', 'state' => 'state123']);

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/dashboard')
            ->willReturn($response);
        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $controller->callback($request, $response, ['provider' => 'github']);

        $this->assertSame($response, $result);
        $this->assertSame(123, $this->session->getUser()['id']);
    }

    public function testCallbackWithSignupFailsWhenInvitationIsInvalidOnCallback(): void
    {
        $provider = $this->createMock(GenericProvider::class);
        $provider->expects($this->once())
            ->method('getAccessToken')
            ->with('authorization_code', ['code' => 'authcode'])
            ->willReturn(new AccessToken(['access_token' => 'token', 'expires' => 3600]));

        $providerFactory = $this->createMock(OAuthProviderFactoryInterface::class);
        $providerFactory->expects($this->once())
            ->method('getSupportedProviders')
            ->willReturn(['github' => []]);
        $providerFactory->expects($this->once())
            ->method('createProvider')
            ->with('github')
            ->willReturn($provider);
        $providerFactory->expects($this->never())
            ->method('getProviderScope');
        $providerFactory->expects($this->once())
            ->method('getUserProfile')
            ->with('github', $this->isInstanceOf(AccessToken::class))
            ->willReturn([
                'provider' => 'github',
                'provider_id' => '123',
                'email' => 'test@example.com',
                'name' => 'Test User',
                'avatar' => 'https://example.com/avatar.png',
            ]);

        $invitationService = new class {
            public function validateInvitation(string $code): ?array
            {
                return null;
            }
        };

        $controller = new AuthController($this->renderer, $providerFactory, $this->session, $invitationService, $this->userService);
        $this->session->setState('state123');
        $this->session->setData('auth_mode', 'signup');
        $this->session->setData('invitation_code', 'abc123');
        $this->session->setData('invitation_roles', ['reviewer']);
        $this->session->setData('invitation_email', 'test@example.com');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['code' => 'authcode', 'state' => 'state123']);

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->expects($this->once())
            ->method('write');

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getBody')
            ->willReturn($stream);
        $response->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($response);
        $response->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'text/html')
            ->willReturn($response);

        $result = $controller->callback($request, $response, ['provider' => 'github']);

        $this->assertSame($response, $result);
        $this->assertNull($this->session->getUser());
    }

    public function testCallbackWithLoginRedirectsWhenExistingUser(): void
    {
        $provider = $this->createMock(GenericProvider::class);
        $provider->expects($this->once())
            ->method('getAccessToken')
            ->with('authorization_code', ['code' => 'authcode'])
            ->willReturn(new AccessToken(['access_token' => 'token', 'expires' => 3600]));

        $providerFactory = $this->createMock(OAuthProviderFactoryInterface::class);
        $providerFactory->expects($this->once())
            ->method('getSupportedProviders')
            ->willReturn(['github' => []]);
        $providerFactory->expects($this->once())
            ->method('createProvider')
            ->with('github')
            ->willReturn($provider);
        $providerFactory->expects($this->never())
            ->method('getProviderScope');
        $providerFactory->expects($this->once())
            ->method('getUserProfile')
            ->with('github', $this->isInstanceOf(AccessToken::class))
            ->willReturn([
                'provider' => 'github',
                'provider_id' => '123',
                'email' => 'test@example.com',
                'name' => 'Test User',
                'avatar' => 'https://example.com/avatar.png',
            ]);

        $userConnection = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 456,
                'provider' => 'github',
                'provider_id' => '123',
                'email' => 'test@example.com',
                'name' => 'Test User',
                'avatar' => 'https://example.com/avatar.png',
                'roles' => json_encode(['reviewer']),
                'status' => 'active',
                'invitation_used' => 'abc123',
                'last_login' => '2026-05-20 00:00:00',
                'created_at' => '2026-05-20 00:00:00',
                'updated_at' => '2026-05-20 00:00:00',
            ]);

        $userConnection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($result);

        $userConnection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE users SET last_login = ?, updated_at = ? WHERE id = ?',
                $this->callback(fn($params) => count($params) === 3 && $params[2] === 456)
            )
            ->willReturn(1);

        $userService = new UserService($userConnection);
        $controller = new AuthController($this->renderer, $providerFactory, $this->session, $this->invitationService, $userService);
        $this->session->setState('state123');
        $this->session->setData('auth_mode', 'login');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['code' => 'authcode', 'state' => 'state123']);

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/dashboard')
            ->willReturn($response);
        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $controller->callback($request, $response, ['provider' => 'github']);

        $this->assertSame($response, $result);
        $this->assertSame(456, $this->session->getUser()['id']);
    }

    public function testLogoutClearsSessionAndRedirects(): void
    {
        $this->session->setUser(['id' => 1, 'name' => 'Test User']);

        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->logout($request, $response);

        $this->assertNull($this->session->getUser());
        $this->assertSame($response, $result);
    }

    public function testDemoSetsSessionAndRedirects(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/dashboard')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->demo($request, $response);

        $this->assertNotNull($this->session->getUser());
        $this->assertSame('Demo Reviewer', $this->session->getUser()['name']);
        $this->assertSame($response, $result);
    }

    public function testRenderErrorReturnsHTMLResponse(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);

        $response->expects($this->once())
            ->method('getBody')
            ->willReturn($body);

        $body->expects($this->once())
            ->method('write');

        $response->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'text/html')
            ->willReturn($response);

        // Use reflection to call private method
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('renderError');

        $result = $method->invoke($this->controller, $response, 'Test Error', 'Error message');

        $this->assertSame($response, $result);
    }

    public function testBuildProviderOptionsHandlesMultipleProviders(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('buildProviderOptions');

        $result = $method->invoke($this->controller, ['github', 'google']);

        $this->assertCount(2, $result);
        $this->assertSame('github', $result[0]['key']);
        $this->assertSame('google', $result[1]['key']);
    }

    public function testBuildProviderOptionsHandlesGithubLabel(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('buildProviderOptions');

        $result = $method->invoke($this->controller, ['github']);

        $this->assertSame('GitHub', $result[0]['label']);
    }

    public function testBuildProviderOptionsHandlesGoogleLabel(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('buildProviderOptions');

        $result = $method->invoke($this->controller, ['google']);

        $this->assertSame('Google', $result[0]['label']);
    }

    public function testShowLoginRendersLoginTemplate(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with(
                $response,
                'login.php',
                $this->callback(function ($data) {
                    return isset($data['title']) && $data['title'] === 'Login'
                        && isset($data['authMode']) && $data['authMode'] === 'login';
                })
            )
            ->willReturn($response);

        $result = $this->controller->showLogin($request, $response);

        $this->assertSame($response, $result);
    }

    public function testShowSignUpRendersSignupTemplate(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([]);

        $response = $this->createMock(ResponseInterface::class);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with(
                $response,
                'signup.php',
                $this->callback(function ($data) {
                    return isset($data['title']) && $data['title'] === 'Sign Up';
                })
            )
            ->willReturn($response);

        $result = $this->controller->showSignUp($request, $response);

        $this->assertSame($response, $result);
    }
}
