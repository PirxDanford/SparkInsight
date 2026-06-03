<?php

declare(strict_types=1);

namespace SparkInsight\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;
use SparkInsight\Service\OAuthProviderFactoryInterface;
use SparkInsight\Service\UserSessionInterface;

final class AuthController
{
    public function __construct(
        private readonly PhpRenderer $renderer,
        private readonly OAuthProviderFactoryInterface $providerFactory,
        private readonly UserSessionInterface $session,
        private readonly object $invitationService,
        private readonly object $userService,
    ) {
    }

    public function showLogin(Request $request, Response $response): Response
    {
        if ($this->session->getUser() !== null) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        return $this->renderPage($response, 'login.php', [
            'title' => 'Login',
            'providers' => $this->buildProviderOptions($this->providerFactory->getSupportedProviders()),
            'authMode' => 'login',
        ]);
    }

    public function showSignUp(Request $request, Response $response): Response
    {
        if ($this->session->getUser() !== null) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $queryParams = $request->getQueryParams();
        $code = trim((string) ($queryParams['code'] ?? ''));
        $providers = [];
        $flashMessage = null;

        if ($code !== '') {
            $invitation = $this->invitationService->validateInvitation($code);
            if ($invitation === null) {
                $flashMessage = [
                    'type' => 'error',
                    'message' => 'Invitation code is invalid or expired. Please request a new invitation.',
                ];
                $code = '';
            } else {
                $providers = $this->buildProviderOptions($this->providerFactory->getSupportedProviders());
            }
        }

        return $this->renderPage($response, 'signup.php', [
            'title' => 'Sign Up',
            'providers' => $providers,
            'invitationCode' => $code,
            'flash_message' => $flashMessage,
        ]);
    }

    private function buildProviderOptions(array $providers): array
    {
        $options = [];

        foreach ($providers as $key => $provider) {
            if (is_array($provider)) {
                if (!isset($provider['key'])) {
                    $provider['key'] = is_string($key) ? $key : ($provider['key'] ?? '');
                }

                if (!isset($provider['name'])) {
                    $provider['name'] = $provider['label'] ?? ucfirst((string) $provider['key']);
                }

                $options[] = $provider;
                continue;
            }

            if (!is_string($provider)) {
                throw new \InvalidArgumentException('Unsupported provider format');
            }

            $label = match (strtolower($provider)) {
                'github' => 'GitHub',
                'google' => 'Google',
                default => ucfirst($provider),
            };

            $options[] = [
                'key' => $provider,
                'name' => $label,
                'label' => $label,
            ];
        }

        return $options;
    }

    private function isProviderSupported(string $provider): bool
    {
        $supportedProviders = $this->providerFactory->getSupportedProviders();
        return array_key_exists($provider, $supportedProviders);
    }

    private function clearAuthSession(): void
    {
        $this->session->setData('auth_mode', null);
        $this->session->setData('invitation_code', null);
        $this->session->setData('invitation_roles', null);
        $this->session->setData('invitation_email', null);
        $this->session->setState('');
    }

    public function login(Request $request, Response $response, array $args): Response
    {
        $provider = $args['provider'] ?? '';
        if (!$this->isProviderSupported($provider)) {
            return $this->redirectWithError($response, '/');
        }

        $queryParams = $request->getQueryParams();
        $invitationCode = trim((string) ($queryParams['code'] ?? ''));
        $isSignup = $invitationCode !== '';

        if ($isSignup) {
            $invitation = $this->invitationService->validateInvitation($invitationCode);
            if (!$invitation) {
                return $this->renderError($response, 'Invalid Invitation', 'The invitation code is invalid or has expired.');
            }

            $this->session->setData('invitation_code', $invitationCode);
            $this->session->setData('invitation_roles', $invitation['roles']);
            $this->session->setData('invitation_email', $invitation['email']);
            $this->session->setData('auth_mode', 'signup');
        } else {
            $this->session->setData('auth_mode', 'login');
        }

        $oauthProvider = $this->providerFactory->createProvider($provider);
        $state = bin2hex(random_bytes(16));
        $this->session->setState($state);

        $authorizationUrl = $oauthProvider->getAuthorizationUrl([
            'state' => $state,
            'scope' => $this->providerFactory->getProviderScope($provider),
            'access_type' => $provider === 'google' ? 'offline' : null,
            'prompt' => $provider === 'google' ? 'consent' : null,
        ]);

        return $response->withHeader('Location', $authorizationUrl)->withStatus(302);
    }

    public function callback(Request $request, Response $response, array $args): Response
    {
        $provider = $args['provider'] ?? '';
        if (!$this->isProviderSupported($provider)) {
            return $this->redirectWithError($response, '/');
        }

        $queryParams = $request->getQueryParams();
        $code = $queryParams['code'] ?? null;
        $state = $queryParams['state'] ?? null;
        $error = $queryParams['error'] ?? null;

        if ($error !== null || $code === null || $state === null) {
            $this->clearAuthSession();
            return $this->renderError($response, 'Authentication failed.', 'Please try again from the homepage.');
        }

        $expectedState = $this->session->getState();
        if ($expectedState === null || !hash_equals($expectedState, (string) $state)) {
            $this->clearAuthSession();
            return $this->renderError($response, 'Invalid OAuth state.', 'The response did not match the expected login session.');
        }

        $authMode = $this->session->getData('auth_mode') ?? 'login';

        $oauthProvider = $this->providerFactory->createProvider($provider);
        $accessToken = $oauthProvider->getAccessToken('authorization_code', ['code' => $code]);
        $profile = $this->providerFactory->getUserProfile($provider, $accessToken);

        if ($authMode === 'signup') {
            $invitationCode = $this->session->getData('invitation_code');

            if (!$invitationCode) {
                $this->clearAuthSession();
                return $this->renderError($response, 'Invitation Error', 'Invitation information was lost. Please try signing up again.');
            }

            $invitation = $this->invitationService->validateInvitation($invitationCode);
            if ($invitation === null) {
                $this->clearAuthSession();
                return $this->renderError($response, 'Invalid Invitation', 'The invitation code is invalid or has expired.');
            }

            $invitationRoles = $invitation['roles'] ?? ['reviewer'];
            $invitationEmail = $invitation['email'];

            if ($invitationEmail && strcasecmp($invitationEmail, $profile['email'] ?? '') !== 0) {
                $this->clearAuthSession();
                return $this->renderError($response, 'Invitation Email Mismatch', 'The authenticated email address does not match the invitation email.');
            }

            $existingUser = $this->userService->findOrCreateUser(
                $profile['provider'],
                $profile['provider_id'],
                $profile['email'],
                $profile['name'],
                $profile['avatar'],
                $invitationRoles,
                $invitationCode
            );

            if ($existingUser['invitation_used'] !== null && $existingUser['invitation_used'] !== $invitationCode) {
                $this->clearAuthSession();
                return $this->renderError($response, 'Account Exists', 'An account with this provider already exists. Please use the login option instead.');
            }

            $this->invitationService->markInvitationAsUsed($invitationCode, $existingUser['id']);
            $this->clearAuthSession();
            $this->session->setUser($existingUser);
            $this->session->regenerate();

            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $existingUser = $this->userService->getUserByProviderAndId($profile['provider'], $profile['provider_id']);

        if (!$existingUser) {
            $this->clearAuthSession();
            return $this->renderError($response, 'Account Not Found', 'No account found for this provider. Please use the signup option with a valid invitation.');
        }

        if ($existingUser['status'] !== 'active') {
            $this->clearAuthSession();
            return $this->renderError($response, 'Account Disabled', 'Your account has been disabled. Please contact an administrator.');
        }

        $this->userService->setLastLogin($existingUser['id']);
        $existingUser['last_login'] = date('Y-m-d H:i:s');

        $this->clearAuthSession();
        $this->session->setUser($existingUser);
        $this->session->regenerate();

        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->session->clear();
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    public function demo(Request $request, Response $response): Response
    {
        $this->session->setUser([
            'provider' => 'Development demo',
            'name' => 'Demo Reviewer',
            'email' => 'demo@example.com',
            'roles' => ['reviewer'],
            'status' => 'active',
        ]);

        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }

    private function renderPage(Response $response, string $template, array $data = []): Response
    {
        return $this->renderer->render($response, $template, array_merge($data, [
            'user' => $this->session->getUser(),
        ]));
    }

    private function redirectWithError(Response $response, string $target): Response
    {
        return $response->withHeader('Location', $target)->withStatus(302);
    }

    private function renderError(Response $response, string $title, string $message): Response
    {
        $response->getBody()->write(<<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{$title} · SparkInsight</title>
    <link rel="stylesheet" href="/style.css" />
</head>
<body>
    <div class="page-shell">
        <div class="notification-box error" id="auth-error">
            <div class="notification-content">
                <strong>{$title}</strong>
                <p>{$message}</p>
                <button class="notification-close" onclick="document.getElementById('auth-error').style.display='none'">&times;</button>
            </div>
            <div class="notification-actions">
                <a class="button" href="/">Back to home</a>
            </div>
        </div>
    </div>
</body>
</html>
HTML);

        return $response->withStatus(400)->withHeader('Content-Type', 'text/html');
    }
}
