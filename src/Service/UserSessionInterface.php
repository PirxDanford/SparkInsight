<?php

declare(strict_types=1);

namespace SparkInsight\Service;

interface UserSessionInterface
{
    public function getUser(): ?array;

    public function setUser(array $user): void;

    public function getState(): ?string;

    public function setState(string $state): void;

    public function getData(string $key): mixed;

    public function setData(string $key, mixed $value): void;

    public function setFlash(string $type, string $message): void;

    public function getFlash(): ?array;

    public function getCsrfToken(): string;

    public function validateCsrfToken(string $token): bool;

    public function regenerate(): void;

    public function clear(): void;
}
