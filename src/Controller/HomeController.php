<?php

declare(strict_types=1);

namespace SparkInsight\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;
use SparkInsight\Config\Config;
use SparkInsight\Service\OAuthProviderFactory;
use SparkInsight\Service\UserSession;

final class HomeController
{
    public function __construct(
        private readonly PhpRenderer $renderer,
        private readonly OAuthProviderFactory $providerFactory,
        private readonly Config $config,
        private readonly UserSession $session,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $providers = $this->providerFactory->getSupportedProviders();
        $activeLinks = array_map(static function (array $provider, string $name): array {
            return [
                'name' => $provider['label'] ?? ucfirst($name),
                'key' => $name,
                'login_url' => '/auth/' . $name,
            ];
        }, $providers, array_keys($providers));

        return $this->renderer->render($response, 'home.php', [
            'providers' => $activeLinks,
            'appUrl' => $this->config->get('app_url'),
            'showDemo' => true,
            'user' => $this->session->getUser(),
            'flash_message' => $this->session->getFlash(),
        ]);
    }
}
