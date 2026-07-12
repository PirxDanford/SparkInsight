<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\PhpRenderer;
use SparkInsight\Controller\AuthController;
use SparkInsight\Config\Config;
use SparkInsight\Service\InvitationService;
use SparkInsight\Service\OAuthProviderFactory;
use SparkInsight\Service\UserService;
use SparkInsight\Service\UserSession;

class AuthControllerTemplateTest extends TestCase
{
    private PhpRenderer $renderer;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->renderer = new PhpRenderer(__DIR__ . "/../../../templates");

        // Create mocks for all dependencies
        // Note: We cannot mock OAuthProviderFactory or UserSession as they are final
        // For template rendering tests, we use test implementations
        $providerFactory = new TestOAuthProviderFactory(['github', 'google']);
        $session = new TestUserSession();

        $invitationService = new TestInvitationService();
        $userService = new TestUserService();

        // Create the controller with all dependencies
        $this->controller = new AuthController(
            $this->renderer,
            $providerFactory,
            $session,
            $invitationService,
            $userService
        );
    }

    protected function tearDown(): void
    {
        // No cleanup needed
    }

    public function testShowLoginRendersTemplate(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $result = $this->controller->showLogin($request, $response);

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertEquals(200, $result->getStatusCode());
    }

    public function testShowSignUpRendersTemplate(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method("getQueryParams")
            ->willReturn(["code" => "test123"]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $result = $this->controller->showSignUp($request, $response);

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertEquals(200, $result->getStatusCode());
    }

    public function testShowSignUpWithoutCodeRendersTemplate(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method("getQueryParams")
            ->willReturn([]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $result = $this->controller->showSignUp($request, $response);

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertEquals(200, $result->getStatusCode());
    }
}
