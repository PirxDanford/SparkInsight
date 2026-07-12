<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Controller;

use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use SparkInsight\Service\OAuthProviderFactoryInterface;

/**
 * Test implementation for OAuthProviderFactory
 * This is needed because OAuthProviderFactory is final and cannot be mocked or extended
 */
class TestOAuthProviderFactory implements OAuthProviderFactoryInterface
{
    private array $supportedProviders;

    public function __construct(array $supportedProviders = ['github', 'google'])
    {
        $this->supportedProviders = $supportedProviders;
    }

    public function getSupportedProviders(): array
    {
        return $this->supportedProviders;
    }

    public function createProvider(string $provider): GenericProvider
    {
        // For template rendering tests, we don't need actual provider instances
        throw new \Exception('Not implemented for template tests');
    }

    public function getUserProfile(string $provider, AccessToken $token): array
    {
        // For template rendering tests, we don't need actual user profiles
        throw new \Exception('Not implemented for template tests');
    }

    public function getProviderScope(string $provider): string
    {
        // Return a default scope for testing
        return 'openid profile email';
    }
}

