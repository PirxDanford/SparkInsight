<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;
use SparkInsight\Config\Config;
use SparkInsight\Service\OAuthProviderFactory;

class OAuthProviderFactoryProfileTest extends TestCase
{
    private Config $config;
    private OAuthProviderFactory $factory;

    protected function setUp(): void
    {
        $_ENV['OAUTH_GITHUB_CLIENT_ID'] = '123';
        $_ENV['OAUTH_GITHUB_CLIENT_SECRET'] = 'secret';
        $_ENV['OAUTH_GOOGLE_CLIENT_ID'] = '456';
        $_ENV['OAUTH_GOOGLE_CLIENT_SECRET'] = 'secret';
        $_ENV['OAUTH_LINKEDIN_CLIENT_ID'] = '789';
        $_ENV['OAUTH_LINKEDIN_CLIENT_SECRET'] = 'secret';
        $_ENV['OAUTH_GITHUB_REDIRECT_URI'] = 'http://localhost:8000/callback/github';
        $_ENV['OAUTH_GOOGLE_REDIRECT_URI'] = 'http://localhost:8000/callback/google';
        $_ENV['OAUTH_LINKEDIN_REDIRECT_URI'] = 'http://localhost:8000/callback/linkedin';

        $this->config = Config::fromEnvironment();
        $this->factory = new OAuthProviderFactory($this->config);
    }

    protected function tearDown(): void
    {
        unset($_ENV['OAUTH_GITHUB_CLIENT_ID']);
        unset($_ENV['OAUTH_GITHUB_CLIENT_SECRET']);
        unset($_ENV['OAUTH_GOOGLE_CLIENT_ID']);
        unset($_ENV['OAUTH_GOOGLE_CLIENT_SECRET']);
        unset($_ENV['OAUTH_LINKEDIN_CLIENT_ID']);
        unset($_ENV['OAUTH_LINKEDIN_CLIENT_SECRET']);
        unset($_ENV['OAUTH_GITHUB_REDIRECT_URI']);
        unset($_ENV['OAUTH_GOOGLE_REDIRECT_URI']);
        unset($_ENV['OAUTH_LINKEDIN_REDIRECT_URI']);
    }

    public function testGetUserProfileThrowsForUnsupportedProvider(): void
    {
        $token = new AccessToken([
            'access_token' => 'test_token',
            'expires_in' => 3600,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Provider "unsupported" is not supported');

        $this->factory->getUserProfile('unsupported', $token);
    }

    public function testFactoryReturnsValidProviderInstance(): void
    {
        $githubProvider = $this->factory->createProvider('github');
        $googleProvider = $this->factory->createProvider('google');

        $this->assertNotNull($githubProvider);
        $this->assertNotNull($googleProvider);
        $this->assertInstanceOf(\League\OAuth2\Client\Provider\GenericProvider::class, $githubProvider);
        $this->assertInstanceOf(\League\OAuth2\Client\Provider\GenericProvider::class, $googleProvider);
    }

    public function testGetSupportedProvidersReturnsArray(): void
    {
        $providers = $this->factory->getSupportedProviders();

        $this->assertIsArray($providers);
        $this->assertNotEmpty($providers);
    }

    public function testGetProviderScopeReturnsString(): void
    {
        $githubScope = $this->factory->getProviderScope('github');
        $googleScope = $this->factory->getProviderScope('google');

        $this->assertIsString($githubScope);
        $this->assertIsString($googleScope);
        $this->assertNotEmpty($githubScope);
        $this->assertNotEmpty($googleScope);
    }

    public function testCreateProviderGithubURLsAreCorrect(): void
    {
        $provider = $this->factory->createProvider('github');

        $this->assertStringContainsString('github.com', $provider->getBaseAuthorizationUrl());
        $this->assertStringContainsString('github.com', $provider->getBaseAccessTokenUrl([]));
    }

    public function testCreateProviderGoogleURLsAreCorrect(): void
    {
        $provider = $this->factory->createProvider('google');

        $this->assertStringContainsString('google', $provider->getBaseAuthorizationUrl());
        $this->assertStringContainsString('google', $provider->getBaseAccessTokenUrl([]));
    }

    public function testCreateProviderLinkedInURLsAreCorrect(): void
    {
        $provider = $this->factory->createProvider('linkedin');

        $this->assertStringContainsString('linkedin.com', $provider->getBaseAuthorizationUrl());
        $this->assertStringContainsString('linkedin.com', $provider->getBaseAccessTokenUrl([]));
    }

    public function testCreateProviderWithDevelopmentEnvironmentConfigured(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $config = Config::fromEnvironment();
        $factory = new OAuthProviderFactory($config);

        $provider = $factory->createProvider('github');
        
        $this->assertNotNull($provider);
    }

    public function testCreateProviderWithProductionEnvironmentConfigured(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $config = Config::fromEnvironment();
        $factory = new OAuthProviderFactory($config);

        $provider = $factory->createProvider('github');
        
        $this->assertNotNull($provider);
    }

    public function testGetSupportedProvidersDoesNotIncludeUnconfiguredProviders(): void
    {
        $providers = $this->factory->getSupportedProviders();

        $this->assertArrayNotHasKey('twitter', $providers);
    }

    public function testCreateProviderThrowsWhenClientIdEmpty(): void
    {
        $_ENV['OAUTH_GITHUB_CLIENT_ID'] = '';
        $_ENV['OAUTH_GITHUB_CLIENT_SECRET'] = 'secret';
        $config = Config::fromEnvironment();
        $factory = new OAuthProviderFactory($config);

        $this->expectException(\InvalidArgumentException::class);
        $factory->createProvider('github');
    }

    public function testCreateProviderThrowsWhenClientSecretEmpty(): void
    {
        $_ENV['OAUTH_GITHUB_CLIENT_ID'] = '123';
        $_ENV['OAUTH_GITHUB_CLIENT_SECRET'] = '';
        $config = Config::fromEnvironment();
        $factory = new OAuthProviderFactory($config);

        $this->expectException(\InvalidArgumentException::class);
        $factory->createProvider('github');
    }

    public function testGetProviderScopeGithubContainsUserScope(): void
    {
        $scope = $this->factory->getProviderScope('github');

        $this->assertStringContainsString('user', $scope);
        $this->assertStringContainsString('email', $scope);
    }

    public function testGetProviderScopeGoogleContainsOpenIdScope(): void
    {
        $scope = $this->factory->getProviderScope('google');

        $this->assertStringContainsString('openid', $scope);
    }
}
