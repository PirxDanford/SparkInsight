<?php

declare(strict_types=1);

namespace SparkInsight\Config;

use Dotenv\Dotenv;

final class Config
{
    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function fromEnvironment(?string $basePath = null): self
    {
        $basePath ??= __DIR__ . '/../../';

        if (file_exists($basePath . '/.env')) {
            $dotenv = Dotenv::createImmutable($basePath);
            $dotenv->safeLoad();
        }

        $providers = [
            'github' => [
                'label' => 'GitHub',
                'client_id' => $_ENV['OAUTH_GITHUB_CLIENT_ID'] ?? $_SERVER['OAUTH_GITHUB_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['OAUTH_GITHUB_CLIENT_SECRET'] ?? $_SERVER['OAUTH_GITHUB_CLIENT_SECRET'] ?? '',
                'redirect_uri' => rtrim($_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? 'http://localhost:8000', '/') . '/callback/github',
                'scope' => 'read:user user:email',
            ],
            'google' => [
                'label' => 'Google',
                'client_id' => $_ENV['OAUTH_GOOGLE_CLIENT_ID'] ?? $_SERVER['OAUTH_GOOGLE_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['OAUTH_GOOGLE_CLIENT_SECRET'] ?? $_SERVER['OAUTH_GOOGLE_CLIENT_SECRET'] ?? '',
                'redirect_uri' => rtrim($_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? 'http://localhost:8000', '/') . '/callback/google',
                'scope' => 'openid profile email',
            ],
            'linkedin' => [
                'label' => 'LinkedIn',
                'client_id' => $_ENV['OAUTH_LINKEDIN_CLIENT_ID'] ?? $_SERVER['OAUTH_LINKEDIN_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['OAUTH_LINKEDIN_CLIENT_SECRET'] ?? $_SERVER['OAUTH_LINKEDIN_CLIENT_SECRET'] ?? '',
                'redirect_uri' => rtrim($_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? 'http://localhost:8000', '/') . '/callback/linkedin',
                'scope' => 'openid profile email',
            ],
        ];

        return new self([
            'app_env' => $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'development',
            'app_url' => rtrim($_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? 'http://localhost:8000', '/'),
            'providers' => $providers,
            'database' => [
                'driver' => $_ENV['DB_DRIVER'] ?? $_SERVER['DB_DRIVER'] ?? 'pdo_mysql',
                'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'localhost',
                'port' => $_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? 3306,
                'dbname' => $_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? 'sparkinsight',
                'user' => $_ENV['DB_USER'] ?? $_SERVER['DB_USER'] ?? 'root',
                'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? '',
                'charset' => $_ENV['DB_CHARSET'] ?? $_SERVER['DB_CHARSET'] ?? 'utf8mb4',
            ],
        ]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function getProviderConfig(string $provider): array
    {
        return $this->values['providers'][$provider] ?? [];
    }

    public function getActiveProviders(): array
    {
        return array_filter($this->values['providers'], static function (array $provider): bool {
            return (bool) $provider['client_id'] && (bool) $provider['client_secret'];
        });
    }

    public function getDatabaseConfig(): array
    {
        $database = $this->values['database'];

        if (($database['driver'] ?? '') === 'pdo_sqlite') {
            return [
                'driver' => 'pdo_sqlite',
                'path' => $database['dbname'] ?? ':memory:',
            ];
        }

        return $database;
    }
}
