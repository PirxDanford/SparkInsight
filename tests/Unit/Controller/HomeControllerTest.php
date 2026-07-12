<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\PhpRenderer;
use SparkInsight\Config\Config;
use SparkInsight\Controller\HomeController;
use SparkInsight\Service\OAuthProviderFactory;
use SparkInsight\Service\UserSession;

class HomeControllerTest extends TestCase
{
    private PhpRenderer $renderer;
    private OAuthProviderFactory $providerFactory;
    private Config $config;
    private UserSession $session;
    private HomeController $controller;

    protected function setUp(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $_ENV['OAUTH_GITHUB_CLIENT_ID'] = '123';
        $_ENV['OAUTH_GITHUB_CLIENT_SECRET'] = 'secret';
        $_ENV['OAUTH_GOOGLE_CLIENT_ID'] = '';
        $_ENV['OAUTH_GOOGLE_CLIENT_SECRET'] = '';
        $_ENV['OAUTH_LINKEDIN_CLIENT_ID'] = '';
        $_ENV['OAUTH_LINKEDIN_CLIENT_SECRET'] = '';

        $_SERVER['APP_ENV'] = 'development';
        $_SERVER['APP_URL'] = 'http://localhost:8000';
        $_SERVER['OAUTH_GITHUB_CLIENT_ID'] = '123';
        $_SERVER['OAUTH_GITHUB_CLIENT_SECRET'] = 'secret';
        $_SERVER['OAUTH_GOOGLE_CLIENT_ID'] = '';
        $_SERVER['OAUTH_GOOGLE_CLIENT_SECRET'] = '';
        $_SERVER['OAUTH_LINKEDIN_CLIENT_ID'] = '';
        $_SERVER['OAUTH_LINKEDIN_CLIENT_SECRET'] = '';

        $this->renderer = $this->createMock(PhpRenderer::class);
        $this->config = Config::fromEnvironment();
        $this->providerFactory = new OAuthProviderFactory($this->config);
        $this->session = new UserSession();
        $this->controller = new HomeController($this->renderer, $this->providerFactory, $this->config, $this->session);
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_ENV']);
        unset($_ENV['APP_URL']);
        unset($_ENV['OAUTH_GITHUB_CLIENT_ID']);
        unset($_ENV['OAUTH_GITHUB_CLIENT_SECRET']);
        unset($_ENV['OAUTH_GOOGLE_CLIENT_ID'], $_ENV['OAUTH_GOOGLE_CLIENT_SECRET']);
        unset($_ENV['OAUTH_LINKEDIN_CLIENT_ID'], $_ENV['OAUTH_LINKEDIN_CLIENT_SECRET']);
        unset($_SERVER['APP_ENV'], $_SERVER['APP_URL']);
        unset($_SERVER['OAUTH_GITHUB_CLIENT_ID'], $_SERVER['OAUTH_GITHUB_CLIENT_SECRET']);
        unset($_SERVER['OAUTH_GOOGLE_CLIENT_ID'], $_SERVER['OAUTH_GOOGLE_CLIENT_SECRET']);
        unset($_SERVER['OAUTH_LINKEDIN_CLIENT_ID'], $_SERVER['OAUTH_LINKEDIN_CLIENT_SECRET']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    public function testInvokeRendersHomePage(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with(
                $response,
                'home.php',
                [
                    'providers' => [
                        [
                            'name' => 'GitHub',
                            'key' => 'github',
                            'login_url' => '/auth/github',
                        ],
                    ],
                    'appUrl' => 'http://localhost:8000',
                    'showDemo' => true,
                    'user' => null,
                    'flash_message' => null,
                ]
            )
            ->willReturn($response);

        $result = $this->controller->__invoke($request, $response);

        $this->assertSame($response, $result);
    }
}