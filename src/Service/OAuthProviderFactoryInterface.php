<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;

interface OAuthProviderFactoryInterface
{
    /**
     * Get list of supported OAuth providers.
     *
     * @return array<string> List of provider names
     */
    public function getSupportedProviders(): array;

    /**
     * Create an OAuth provider instance.
     *
     * @param string $provider Provider name (e.g., 'github', 'google')
     */
    public function createProvider(string $provider): GenericProvider;

    /**
     * Get user profile from OAuth provider.
     *
     * @param string $provider Provider name
     * @param AccessToken $token OAuth access token
     * @return array<string, mixed> User profile data
     */
    public function getUserProfile(string $provider, AccessToken $token): array;

    /**
     * Get OAuth scope for a provider.
     *
     * @param string $provider Provider name
     * @return string OAuth scope string
     */
    public function getProviderScope(string $provider): string;
}
