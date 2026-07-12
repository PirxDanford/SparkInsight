<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\PhpRenderer;
use SparkInsight\Config\Config;
use SparkInsight\Controller\AuthController;
use SparkInsight\Service\InvitationService;
use SparkInsight\Service\OAuthProviderFactory;
use SparkInsight\Service\UserService;
use SparkInsight\Service\UserSession;

class AuthControllerTest extends TestCase
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

    public function testShowLogin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'login.php', $this->anything())
            ->willReturnCallback(function ($renderResponse, $template, $data) use ($response) {
                $this->assertSame($response, $renderResponse);
                $this->assertSame('login.php', $template);
                $this->assertIsArray($data);
                $this->assertSame('Login', $data['title']);
                $this->assertSame('login', $data['authMode']);
                $this->assertNull($data['user']);
                $this->assertIsArray($data['providers']);
                $this->assertSame('github', $data['providers'][0]['key']);
                return $response;
            });

        $result = $this->controller->showLogin($request, $response);

        $this->assertSame($response, $result);
    }

    public function testShowLoginRedirectsWhenAlreadyAuthenticated(): void
    {
        $this->session->setUser(['id' => 1, 'name' => 'Existing User']);

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

        $result = $this->controller->showLogin($request, $response);

        $this->assertSame($response, $result);
    }

    public function testShowSignUp(): void
    {
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 1,
                'code' => 'abc123',
                'email' => null,
                'roles' => json_encode(['reviewer']),
            ]);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($result);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['code' => 'abc123']);

        $response = $this->createMock(ResponseInterface::class);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'signup.php', $this->anything())
            ->willReturnCallback(function ($renderResponse, $template, $data) use ($response) {
                $this->assertSame($response, $renderResponse);
                $this->assertSame('signup.php', $template);
                $this->assertIsArray($data);
                $this->assertSame('Sign Up', $data['title']);
                $this->assertSame('abc123', $data['invitationCode']);
                $this->assertNull($data['user']);
                $this->assertIsArray($data['providers']);
                $this->assertSame('github', $data['providers'][0]['key']);
                return $response;
            });

        $result = $this->controller->showSignUp($request, $response);

        $this->assertSame($response, $result);
    }

    public function testShowSignUpWithInvalidInvitationCodeShowsErrorMessage(): void
    {
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(false);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($result);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['code' => 'invalid-code']);

        $response = $this->createMock(ResponseInterface::class);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'signup.php', $this->callback(function ($data) {
                return isset($data['title'], $data['invitationCode'], $data['flash_message'], $data['providers'])
                    && $data['title'] === 'Sign Up'
                    && $data['invitationCode'] === ''
                    && is_array($data['providers'])
                    && count($data['providers']) === 0
                    && $data['flash_message']['type'] === 'error'
                    && str_contains($data['flash_message']['message'], 'invalid or expired');
            }))
            ->willReturn($response);

        $result = $this->controller->showSignUp($request, $response);

        $this->assertSame($response, $result);
    }

    public function testShowSignUpRedirectsWhenAlreadyAuthenticated(): void
    {
        $this->session->setUser(['id' => 2, 'name' => 'Existing User']);

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

        $result = $this->controller->showSignUp($request, $response);

        $this->assertSame($response, $result);
    }

    public function testLogout(): void
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

        $result = $this->controller->logout($request, $response);

        $this->assertSame($response, $result);
    }

    public function testLoginWithInvalidProviderRedirects(): void
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

    public function testCallbackInvalidStateReturnsError(): void
    {
        $this->session->setState('expected-state');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['code' => 'code', 'state' => 'wrong-state']);

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->expects($this->once())
            ->method('write')
            ->with($this->stringContains('Invalid OAuth state.'));

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

    public function testDemo(): void
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

        $this->assertSame($response, $result);
    }

    public function testShowSignUpWithoutCode(): void
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
                    return isset($data['title']) && $data['title'] === 'Sign Up'
                        && isset($data['invitationCode']) && $data['invitationCode'] === '';
                })
            )
            ->willReturn($response);

        $result = $this->controller->showSignUp($request, $response);

        $this->assertSame($response, $result);
    }

    public function testLoginWithGithubProvider(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([]);

        $response = $this->createMock(ResponseInterface::class);

        // Mock response chaining
        $response->expects($this->once())
            ->method('withHeader')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->login($request, $response, ['provider' => 'github']);

        $this->assertSame($response, $result);
        $this->assertNotNull($this->session->getState());
    }

    public function testLoginWithEmptyInvitationCodeUsesLoginMode(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['code' => '']);

        $response = $this->createMock(ResponseInterface::class);

        $response->expects($this->once())
            ->method('withHeader')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->login($request, $response, ['provider' => 'github']);

        $this->assertSame($response, $result);
        $this->assertSame('login', $this->session->getData('auth_mode'));
    }

    public function testLoginWithInvitationCode(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['code' => 'valid-invitation-code']);

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->atLeastOnce())
            ->method('withHeader')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->willReturn($response);

        $result = $this->controller->login($request, $response, ['provider' => 'github']);

        $this->assertNotNull($result);
    }

    public function testCallbackWithMissingCode(): void
    {
        $this->session->setState('state-value');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['state' => 'state-value']);

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->expects($this->once())
            ->method('write')
            ->with($this->stringContains('Authentication failed'));

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
        $this->session->setState('state-value');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['error' => 'access_denied']);

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->expects($this->once())
            ->method('write')
            ->with($this->stringContains('Authentication failed'));

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

    public function testBuildProviderOptionsWithInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported provider format');

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('buildProviderOptions');

        $method->invoke($this->controller, [123]); // Invalid provider format
    }

    public function testBuildProviderOptionsWithArrayProvider(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('buildProviderOptions');

        $result = $method->invoke($this->controller, [['key' => 'test', 'name' => 'Test Provider']]);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('key', $result[0]);
        $this->assertSame('test', $result[0]['key']);
    }

    public function testBuildProviderOptionsWithStringProvider(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('buildProviderOptions');

        $result = $method->invoke($this->controller, ['github']);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('key', $result[0]);
        $this->assertSame('github', $result[0]['key']);
        $this->assertSame('GitHub', $result[0]['label']);
    }

    public function testBuildProviderOptionsWithMultipleProviders(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('buildProviderOptions');

        $_ENV['OAUTH_GOOGLE_CLIENT_ID'] = '456';
        $_ENV['OAUTH_GOOGLE_CLIENT_SECRET'] = 'secret';

        $providerFactory = new OAuthProviderFactory(Config::fromEnvironment());
        $controller = new AuthController($this->renderer, $providerFactory, $this->session, $this->invitationService, $this->userService);

        $result = $method->invoke($controller, ['github', 'google']);

        $this->assertCount(2, $result);
        $this->assertSame('GitHub', $result[0]['label']);
        $this->assertSame('Google', $result[1]['label']);

        unset($_ENV['OAUTH_GOOGLE_CLIENT_ID']);
        unset($_ENV['OAUTH_GOOGLE_CLIENT_SECRET']);
    }

    public function testBuildProviderOptionsAddsKeyWhenProviderArrayIsConfigured(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('buildProviderOptions');

        $providerConfig = [
            'label' => 'GitHub',
            'client_id' => '123',
            'client_secret' => 'secret',
            'redirect_uri' => 'http://localhost:8000/callback/github',
            'scope' => 'read:user user:email',
        ];

        $result = $method->invoke($this->controller, ['github' => $providerConfig]);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('key', $result[0]);
        $this->assertSame('github', $result[0]['key']);
        $this->assertSame('GitHub', $result[0]['label']);
        $this->assertSame('GitHub', $result[0]['name']);
    }
}