<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Config\Config;
use SparkInsight\Service\OAuthProviderFactory;

class OAuthProviderFactoryTest extends TestCase
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
        $_ENV['OAUTH_FACEBOOK_CLIENT_ID'] = 'abc';
        $_ENV['OAUTH_FACEBOOK_CLIENT_SECRET'] = 'secret';

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
        unset($_ENV['OAUTH_FACEBOOK_CLIENT_ID']);
        unset($_ENV['OAUTH_FACEBOOK_CLIENT_SECRET']);
    }

    public function testGetSupportedProviders(): void
    {
        $providers = $this->factory->getSupportedProviders();

        $this->assertCount(4, $providers);
        $this->assertArrayHasKey('github', $providers);
        $this->assertArrayHasKey('google', $providers);
        $this->assertArrayHasKey('linkedin', $providers);
        $this->assertArrayHasKey('facebook', $providers);
    }

    public function testGetProviderScope(): void
    {
        $scope = $this->factory->getProviderScope('github');

        $this->assertEquals('read:user user:email', $scope);
    }

    public function testCreateProviderThrowsForUnconfigured(): void
    {
        $_ENV['OAUTH_GITHUB_CLIENT_ID'] = '';
        $_ENV['OAUTH_GITHUB_CLIENT_SECRET'] = '';

        $config = Config::fromEnvironment();
        $factory = new OAuthProviderFactory($config);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OAuth provider "github" is not configured.');

        $factory->createProvider('github');
    }

    public function testGetAuthorizeUrl(): void
    {
        $githubProvider = $this->factory->createProvider('github');
        $googleProvider = $this->factory->createProvider('google');

        $this->assertEquals('https://github.com/login/oauth/authorize', $githubProvider->getBaseAuthorizationUrl());
        $this->assertEquals('https://accounts.google.com/o/oauth2/v2/auth', $googleProvider->getBaseAuthorizationUrl());
    }

    public function testGetAuthorizeUrlThrowsForUnsupported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OAuth provider "unsupported" is not configured.');

        $this->factory->createProvider('unsupported');
    }

    public function testCreateProviderWithoutSecret(): void
    {
        $_ENV['OAUTH_GITHUB_CLIENT_ID'] = '123';
        $_ENV['OAUTH_GITHUB_CLIENT_SECRET'] = '';

        $config = Config::fromEnvironment();
        $factory = new OAuthProviderFactory($config);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OAuth provider "github" is not configured.');

        $factory->createProvider('github');
    }

    public function testGetProviderScopeGoogle(): void
    {
        $scope = $this->factory->getProviderScope('google');

        $this->assertStringContainsString('openid', $scope);
    }

    public function testGetProviderScopeGithub(): void
    {
        $scope = $this->factory->getProviderScope('github');

        $this->assertSame('read:user user:email', $scope);
    }

    public function testCreateProviderForGoogle(): void
    {
        $_ENV['OAUTH_GOOGLE_CLIENT_ID'] = '456';
        $_ENV['OAUTH_GOOGLE_CLIENT_SECRET'] = 'secret';

        $config = Config::fromEnvironment();
        $factory = new OAuthProviderFactory($config);
        $provider = $factory->createProvider('google');

        $this->assertNotNull($provider);
        $this->assertEquals('https://accounts.google.com/o/oauth2/v2/auth', $provider->getBaseAuthorizationUrl());
    }

    public function testCreateProviderForLinkedIn(): void
    {
        $provider = $this->factory->createProvider('linkedin');

        $this->assertNotNull($provider);
        $this->assertEquals('https://www.linkedin.com/oauth/v2/authorization', $provider->getBaseAuthorizationUrl());
    }

    public function testCreateProviderForFacebook(): void
    {
        $provider = $this->factory->createProvider('facebook');

        $this->assertNotNull($provider);
        $this->assertEquals('https://www.facebook.com/v16.0/dialog/oauth', $provider->getBaseAuthorizationUrl());
    }

    public function testCreateProviderForGithub(): void
    {
        $provider = $this->factory->createProvider('github');

        $this->assertNotNull($provider);
        $this->assertEquals('https://github.com/login/oauth/authorize', $provider->getBaseAuthorizationUrl());
    }

    public function testGetAccessTokenUrlForGithub(): void
    {
        $provider = $this->factory->createProvider('github');
        $this->assertEquals('https://github.com/login/oauth/access_token', $provider->getBaseAccessTokenUrl([]));
    }

    public function testGetAccessTokenUrlForGoogle(): void
    {
        $_ENV['OAUTH_GOOGLE_CLIENT_ID'] = '456';
        $_ENV['OAUTH_GOOGLE_CLIENT_SECRET'] = 'secret';

        $config = Config::fromEnvironment();
        $factory = new OAuthProviderFactory($config);
        $provider = $factory->createProvider('google');

        $this->assertEquals('https://oauth2.googleapis.com/token', $provider->getBaseAccessTokenUrl([]));
    }

    public function testCreateProviderInProductionDisablesSSLVerification(): void
    {
        $_ENV['APP_ENV'] = 'development';

        $config = Config::fromEnvironment();
        $factory = new OAuthProviderFactory($config);
        $provider = $factory->createProvider('github');

        $this->assertNotNull($provider);
    }

    public function testGetSupportedProvidersOnlyIncludesGithubAndGoogle(): void
    {
        $_ENV['OAUTH_TWITTER_CLIENT_ID'] = 'twitter123';
        $_ENV['OAUTH_TWITTER_CLIENT_SECRET'] = 'twitter_secret';

        $config = Config::fromEnvironment();
        $factory = new OAuthProviderFactory($config);
        $providers = $factory->getSupportedProviders();

        $this->assertArrayNotHasKey('twitter', $providers);

        unset($_ENV['OAUTH_TWITTER_CLIENT_ID']);
        unset($_ENV['OAUTH_TWITTER_CLIENT_SECRET']);
    }

    public function testCreateProviderWithMissingClientId(): void
    {
        $_ENV['OAUTH_GITHUB_CLIENT_ID'] = '';

        $config = Config::fromEnvironment();
        $factory = new OAuthProviderFactory($config);

        $this->expectException(\InvalidArgumentException::class);
        $factory->createProvider('github');
    }

    public function testCreateProviderWithMissingClientSecret(): void
    {
        $_ENV['OAUTH_GITHUB_CLIENT_SECRET'] = '';

        $config = Config::fromEnvironment();
        $factory = new OAuthProviderFactory($config);

        $this->expectException(\InvalidArgumentException::class);
        $factory->createProvider('github');
    }
}