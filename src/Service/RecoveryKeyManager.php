<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use RuntimeException;

final class RecoveryKeyManager
{
    public function __construct(
        private readonly string $deployRoot,
    ) {
    }

    public function ensureInitialized(): ?string
    {
        $path = $this->recoveryPath();
        if (is_file($path)) {
            return null;
        }

        $key = bin2hex(random_bytes(24));
        $this->writeState(password_hash($key, PASSWORD_DEFAULT), 0);

        return $key;
    }

    public function verify(string $providedKey): bool
    {
        $state = $this->readState();
        if ($state === null || !isset($state['key_hash']) || !is_string($state['key_hash'])) {
            return false;
        }

        $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
        $this->writeRawState($state);

        return password_verify($providedKey, $state['key_hash']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readState(): ?array
    {
        $path = $this->recoveryPath();
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function writeState(string $keyHash, int $attempts): void
    {
        $this->writeRawState([
            'format' => 1,
            'key_hash' => $keyHash,
            'attempts' => $attempts,
            'locked_until' => null,
            'updated_at' => date(DATE_ATOM),
        ]);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeRawState(array $state): void
    {
        $path = $this->recoveryPath();
        $this->ensureDirectory(dirname($path));
        $temporaryPath = $path . '.tmp';
        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Could not encode recovery state.');
        }

        if (file_put_contents($temporaryPath, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Could not write recovery state.');
        }

        if (is_file($path)) {
            @unlink($path);
        }

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Could not store recovery state.');
        }
    }

    private function recoveryPath(): string
    {
        return rtrim($this->deployRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.deploy' . DIRECTORY_SEPARATOR . 'recovery.json';
    }

    private function ensureDirectory(string $directory): void
    {
        if ($directory === '' || is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create directory: ' . $directory);
        }
    }
}