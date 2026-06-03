<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Controller;

class TestInvitationService
{
    public function validateInvitation(string $code): ?array
    {
        // For template rendering tests, we just return a valid invitation
        return [
            'id' => 1,
            'code' => $code,
            'email' => null,
            'roles' => ['user'],
        ];
    }
}
