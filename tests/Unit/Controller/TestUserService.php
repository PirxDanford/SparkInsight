<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Controller;

class TestUserService
{
    public function findOrCreateUser(string $provider, string $providerId, string $email, string $name): array
    {
        // For template rendering tests, we just return a mock user
        return [
            'id' => 1,
            'provider' => $provider,
            'provider_id' => $providerId,
            'email' => $email,
            'name' => $name,
            'roles' => ['user'],
        ];
    }
}
