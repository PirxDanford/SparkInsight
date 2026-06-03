<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Config;

use PHPUnit\Framework\TestCase;
use SparkInsight\Config\Config;

class ConfigTest extends TestCase
{
    private array $originalEnv = [];
    private array $originalServer = [];

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV;
        $this->originalServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment([
            'APP_ENV',
            'APP_URL',
            'OAUTH_GITHUB_CLIENT_ID',
            'OAUTH_GITHUB_CLIENT_SECRET',
            'OAUTH_GOOGLE_CLIENT_ID',
            'OAUTH_GOOGLE_CLIENT_SECRET',
            'OAUTH_LINKEDIN_CLIENT_ID',
            'OAUTH_LINKEDIN_CLIENT_SECRET',
            'OAUTH_FACEBOOK_CLIENT_ID',
            'OAUTH_FACEBOOK_CLIENT_SECRET',
            'DB_DRIVER',
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASSWORD',
            'DB_CHARSET',
        ]);
    }

    private function restoreEnvironment(array $keys): void
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->originalEnv)) {
                $_ENV[$key] = $this->originalEnv[$key];
            } else {
                unset($_ENV[$key]);
            }

            if (array_key_exists($key, $this->originalServer)) {
                $_SERVER[$key] = $this->originalServer[$key];
            } else {
                unset($_SERVER[$key]);
            }
        }
    }

    public function testFromEnvironmentLoadsDefaults(): void
    {
        $tempDir = sys_get_temp_dir() . '/sparkinsight_config_test_' . bin2hex(random_bytes(8));
        mkdir($tempDir);

        $keysToClear = [
            'APP_ENV',
            'APP_URL',
            'OAUTH_GITHUB_CLIENT_ID',
            'OAUTH_GITHUB_CLIENT_SECRET',
            'OAUTH_GOOGLE_CLIENT_ID',
            'OAUTH_GOOGLE_CLIENT_SECRET',
            'OAUTH_LINKEDIN_CLIENT_ID',
            'OAUTH_LINKEDIN_CLIENT_SECRET',
            'OAUTH_FACEBOOK_CLIENT_ID',
            'OAUTH_FACEBOOK_CLIENT_SECRET',
            'DB_DRIVER',
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASSWORD',
            'DB_CHARSET',
        ];

        foreach ($keysToClear as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }

        try {
            $config = Config::fromEnvironment($tempDir);

            $this->assertEquals('development', $config->get('app_env'));
            $this->assertEquals('http://localhost:8000', $config->get('app_url'));
            $this->assertEquals('pdo_mysql', $config->getDatabaseConfig()['driver']);
            $this->assertEquals('localhost', $config->getDatabaseConfig()['host']);
            $this->assertEquals(3306, $config->getDatabaseConfig()['port']);
        } finally {
            rmdir($tempDir);
        }
    }

    public function testFromEnvironmentLoadsFromEnv(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_URL'] = 'https://example.com';
        $_ENV['OAUTH_GITHUB_CLIENT_ID'] = '123';
        $_ENV['OAUTH_GITHUB_CLIENT_SECRET'] = 'secret';
        $_ENV['OAUTH_GOOGLE_CLIENT_ID'] = '456';
        $_ENV['OAUTH_GOOGLE_CLIENT_SECRET'] = 'secret';
        $_ENV['OAUTH_LINKEDIN_CLIENT_ID'] = '789';
        $_ENV['OAUTH_LINKEDIN_CLIENT_SECRET'] = 'secret';
        $_ENV['OAUTH_FACEBOOK_CLIENT_ID'] = 'abc';
        $_ENV['OAUTH_FACEBOOK_CLIENT_SECRET'] = 'secret';
        $_ENV['DB_HOST'] = 'db.example.com';

        $config = Config::fromEnvironment();

        $this->assertEquals('production', $config->get('app_env'));
        $this->assertEquals('https://example.com', $config->get('app_url'));
        $this->assertEquals(['github', 'google', 'linkedin', 'facebook'], array_keys($config->getActiveProviders()));
        $this->assertEquals('db.example.com', $config->getDatabaseConfig()['host']);
    }

    public function testGetProviderConfig(): void
    {
        $_ENV['OAUTH_GITHUB_CLIENT_ID'] = '123';
        $_ENV['OAUTH_GITHUB_CLIENT_SECRET'] = 'secret';

        $config = Config::fromEnvironment();

        $provider = $config->getProviderConfig('github');
        $this->assertEquals('GitHub', $provider['label']);
        $this->assertEquals('123', $provider['client_id']);
        $this->assertEquals('secret', $provider['client_secret']);
        $this->assertStringContainsString('callback/github', $provider['redirect_uri']);
    }

    public function testGetActiveProviders(): void
    {
        $_ENV['OAUTH_GITHUB_CLIENT_ID'] = '123';
        $_ENV['OAUTH_GITHUB_CLIENT_SECRET'] = 'secret';
        $_ENV['OAUTH_GOOGLE_CLIENT_ID'] = '456';

        $config = Config::fromEnvironment();

        $active = $config->getActiveProviders();
        $this->assertCount(1, $active); // Only github has both id and secret
        $this->assertArrayHasKey('github', $active);
    }

    public function testGetDatabaseConfig(): void
    {
        $_ENV['DB_HOST'] = 'testdb';
        $_ENV['DB_PORT'] = '3307';

        $config = Config::fromEnvironment();

        $db = $config->getDatabaseConfig();
        $this->assertEquals('testdb', $db['host']);
        $this->assertEquals(3307, $db['port']);
        $this->assertEquals('pdo_mysql', $db['driver']);
    }
}