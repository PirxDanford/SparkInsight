<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\PhpRenderer;
use SparkInsight\Controller\DashboardController;
use SparkInsight\Service\UserSession;

class DashboardControllerTest extends TestCase
{
    private PhpRenderer $renderer;
    private UserSession $session;
    private DashboardController $controller;

    protected function setUp(): void
    {
        $this->renderer = $this->createMock(PhpRenderer::class);
        $this->session = new UserSession();
        $this->controller = new DashboardController($this->renderer, $this->session);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    public function testInvokeWhenNotLoggedIn(): void
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

        $result = $this->controller->__invoke($request, $response);

        $this->assertSame($response, $result);
    }

    public function testInvokeWhenLoggedIn(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $user = ['id' => 1, 'name' => 'Test'];
        $this->session->setUser($user);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'dashboard.php', ['user' => $user])
            ->willReturn($response);

        $result = $this->controller->__invoke($request, $response);

        $this->assertSame($response, $result);
    }
}