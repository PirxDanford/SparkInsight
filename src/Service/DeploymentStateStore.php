<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use RuntimeException;

final class DeploymentStateStore
{
    public function __construct(
        private readonly string $deployRoot,
        private readonly ReleasePathPolicy $pathPolicy = new ReleasePathPolicy(),
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $operationId): ?array
    {
        $path = $this->statePath($operationId);
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Stored deployment state is invalid: ' . $path);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function write(string $operationId, array $state): void
    {
        $path = $this->statePath($operationId);
        $directory = dirname($path);
        $this->ensureDirectory($directory);

        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Could not encode deployment state.');
        }

        $temporaryPath = $path . '.tmp';
        if (file_put_contents($temporaryPath, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Could not write temporary deployment state: ' . $temporaryPath);
        }

        if (is_file($path) && !unlink($path)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Could not replace existing deployment state: ' . $path);
        }

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Could not move deployment state into place: ' . $path);
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    public function appendAuditEvent(string $operationId, array $event): void
    {
        $path = $this->auditPath($operationId);
        $this->ensureDirectory(dirname($path));

        $encoded = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Could not encode audit event.');
        }

        if (file_put_contents($path, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Could not append deployment audit event: ' . $path);
        }
    }

    private function statePath(string $operationId): string
    {
        return $this->operationPath($operationId, 'state');
    }

    private function auditPath(string $operationId): string
    {
        $operationId = $this->validateOperationId($operationId);

        return rtrim($this->deployRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.deploy' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . $operationId . '.jsonl';
    }

    private function operationPath(string $operationId, string $subDirectory): string
    {
        $operationId = $this->validateOperationId($operationId);

        return rtrim($this->deployRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.deploy' . DIRECTORY_SEPARATOR . $subDirectory . DIRECTORY_SEPARATOR . $operationId . '.json';
    }

    private function validateOperationId(string $operationId): string
    {
        $operationId = trim($operationId);
        if ($operationId === '') {
            throw new RuntimeException('Operation ID must not be empty.');
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,63}$/', $operationId) !== 1) {
            throw new RuntimeException('Operation ID contains invalid characters.');
        }

        return $operationId;
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