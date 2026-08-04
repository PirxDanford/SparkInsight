<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use GuzzleHttp\Client as GuzzleClient;
use InvalidArgumentException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use SparkInsight\Config\Config;

final class OAuthProviderFactory implements OAuthProviderFactoryInterface
{
    private Config $config;

    /**
     * @var null|callable(string, string, AccessToken): mixed
     */
    private $authenticatedResponseFetcher;

    /**
     * @param null|callable(string, string, AccessToken): mixed $authenticatedResponseFetcher
     */
    public function __construct(Config $config, ?callable $authenticatedResponseFetcher = null)
    {
        $this->config = $config;
        $this->authenticatedResponseFetcher = $authenticatedResponseFetcher;
    }

    public function createProvider(string $provider): GenericProvider
    {
        $providerConfig = $this->config->getProviderConfig($provider);
        if (empty($providerConfig['client_id']) || empty($providerConfig['client_secret'])) {
            throw new InvalidArgumentException(sprintf('OAuth provider "%s" is not configured.', $provider));
        }

        $options = [
            'clientId' => $providerConfig['client_id'],
            'clientSecret' => $providerConfig['client_secret'],
            'redirectUri' => $providerConfig['redirect_uri'],
            'urlAuthorize' => $this->getAuthorizeUrl($provider),
            'urlAccessToken' => $this->getAccessTokenUrl($provider),
            'urlResourceOwnerDetails' => $this->getResourceOwnerUrl($provider),
            'scopes' => explode(' ', $providerConfig['scope']),
        ];

        // Create HTTP client with SSL verification disabled in development
        $httpClientOptions = [];
        if ($this->config->get('app_env') === 'development') {
            $httpClientOptions['verify'] = false;
        }
        $httpClient = new GuzzleClient($httpClientOptions);

        return new GenericProvider($options, ['httpClient' => $httpClient]);
    }

    public function getUserProfile(string $provider, AccessToken $token): array
    {
        $providerName = mb_strtolower($provider);

        if ($providerName === 'google') {
            $resourceOwner = $this->createProvider($providerName)->getResourceOwner($token);
            $data = $resourceOwner->toArray();

            return [
                'provider' => 'google',
                'provider_id' => $data['sub'] ?? $data['id'] ?? '',
                'name' => mb_trim($data['name'] ?? ($data['given_name'] . ' ' . ($data['family_name'] ?? ''))),
                'email' => $data['email'] ?? '',
                'avatar' => $data['picture'] ?? null,
            ];
        }

        if ($providerName === 'github') {
            $resourceOwner = $this->createProvider($providerName)->getResourceOwner($token);
            $profile = $resourceOwner->toArray();
            $email = $profile['email'] ?? '';

            if (empty($email)) {
                $email = $this->fetchGitHubEmail($token);
            }

            return [
                'provider' => 'github',
                'provider_id' => (string) ($profile['id'] ?? ''),
                'name' => mb_trim((string) ($profile['name'] ?? $profile['login'] ?? 'GitHub user')),
                'email' => $email,
                'avatar' => $profile['avatar_url'] ?? null,
            ];
        }

        if ($providerName === 'linkedin') {
            $resourceOwner = $this->createProvider($providerName)->getResourceOwner($token);
            $profile = $resourceOwner->toArray();
            $email = (string) ($profile['email'] ?? '');
            if ($email === '') {
                $email = $this->fetchLinkedInEmail($token);
            }

            $name = mb_trim((string) ($profile['name'] ?? ''));
            if ($name === '') {
                $name = mb_trim(sprintf('%s %s', $profile['localizedFirstName'] ?? '', $profile['localizedLastName'] ?? ''));
            }

            $avatar = $profile['picture'] ?? null;
            if (!is_string($avatar) || mb_trim($avatar) === '') {
                $avatar = null;
            }

            return [
                'provider' => 'linkedin',
                'provider_id' => (string) ($profile['sub'] ?? $profile['id'] ?? ''),
                'name' => $name !== '' ? $name : 'LinkedIn user',
                'email' => $email,
                'avatar' => $avatar,
            ];
        }

        throw new InvalidArgumentException(sprintf('Provider "%s" is not supported for profile retrieval.', $provider));
    }

    public function getSupportedProviders(): array
    {
        $providers = $this->config->getActiveProviders();

        return array_intersect_key($providers, array_flip(['github', 'google', 'linkedin']));
    }

    public function getProviderScope(string $provider): string
    {
        return $this->config->getProviderConfig($provider)['scope'] ?? '';
    }

    private function getAuthorizeUrl(string $provider): string
    {
        return match (mb_strtolower($provider)) {
            'github' => 'https://github.com/login/oauth/authorize',
            'google' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'linkedin' => 'https://www.linkedin.com/oauth/v2/authorization',
            default => throw new InvalidArgumentException('Unsupported provider.'),
        };
    }

    private function getAccessTokenUrl(string $provider): string
    {
        return match (mb_strtolower($provider)) {
            'github' => 'https://github.com/login/oauth/access_token',
            'google' => 'https://oauth2.googleapis.com/token',
            'linkedin' => 'https://www.linkedin.com/oauth/v2/accessToken',
            default => throw new InvalidArgumentException('Unsupported provider.'),
        };
    }

    private function getResourceOwnerUrl(string $provider): string
    {
        return match (mb_strtolower($provider)) {
            'github' => 'https://api.github.com/user',
            'google' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'linkedin' => 'https://api.linkedin.com/v2/userinfo',
            default => throw new InvalidArgumentException('Unsupported provider.'),
        };
    }

    private function fetchGitHubEmail(AccessToken $token): string
    {
        $response = $this->fetchAuthenticatedProviderResponse('github', 'https://api.github.com/user/emails', $token);
        if (!is_array($response)) {
            return '';
        }

        foreach ($response as $item) {
            if (!empty($item['primary']) && !empty($item['verified'])) {
                return $item['email'] ?? '';
            }
        }

        return $response[0]['email'] ?? '';
    }

    private function fetchLinkedInEmail(AccessToken $token): string
    {
        $response = $this->fetchAuthenticatedProviderResponse(
            'linkedin',
            'https://api.linkedin.com/v2/emailAddress?q=members&projection=(elements*(handle~))',
            $token,
        );
        if (!is_array($response)) {
            return '';
        }

        $element = $response['elements'][0] ?? null;
        if (!is_array($element)) {
            return '';
        }

        return $element['handle~']['emailAddress'] ?? '';
    }

    private function fetchAuthenticatedProviderResponse(string $provider, string $url, AccessToken $token): mixed
    {
        if ($this->authenticatedResponseFetcher !== null) {
            return ($this->authenticatedResponseFetcher)($provider, $url, $token);
        }

        $providerClient = $this->createProvider($provider);
        $request = $providerClient->getAuthenticatedRequest('GET', $url, $token);

        return $providerClient->getParsedResponse($request);
    }
}
