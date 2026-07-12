<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Controller;

use SparkInsight\Service\UserSessionInterface;

class TestUserSession implements UserSessionInterface
{
    private ?array $user = null;
    private ?string $state = null;
    private array $data = [];
    
    public function getUser(): ?array
    {
        return $this->user;
    }
    
    public function setUser(array $user): void
    {
        $this->user = $user;
    }
    
    public function getState(): ?string
    {
        return $this->state;
    }
    
    public function setState(string $state): void
    {
        $this->state = $state;
    }
    
    public function getData(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function setData(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function setFlash(string $type, string $message): void
    {
        $this->data['_flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    public function getFlash(): ?array
    {
        $flash = $this->data['_flash'] ?? null;
        unset($this->data['_flash']);

        return is_array($flash) ? $flash : null;
    }

    public function getCsrfToken(): string
    {
        if (empty($this->data['_csrf_token'])) {
            $this->data['_csrf_token'] = bin2hex(random_bytes(16));
        }

        return $this->data['_csrf_token'];
    }

    public function validateCsrfToken(string $token): bool
    {
        if (empty($this->data['_csrf_token']) || !is_string($token)) {
            return false;
        }

        return hash_equals($this->data['_csrf_token'], $token);
    }

    public function regenerate(): void
    {
        // No-op for test session stub; real session regeneration is performed in production session storage.
    }

    public function clear(): void
    {
        $this->user = null;
        $this->state = null;
        $this->data = [];
    }
}