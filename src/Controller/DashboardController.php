<?php

declare(strict_types=1);

namespace SparkInsight\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;
use SparkInsight\Service\UserSession;

final class DashboardController
{
    public function __construct(
        private readonly PhpRenderer $renderer,
        private readonly UserSession $session,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        if (! $this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        return $this->renderer->render($response, 'dashboard.php', [
            'user' => $this->session->getUser(),
        ]);
    }
}
