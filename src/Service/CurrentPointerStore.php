<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use RuntimeException;

final class CurrentPointerStore
{
    public function __construct(
        private readonly string $deployRoot,
    ) {
    }

    /**
     * @return array{format: int, current: ?string, previous: ?string, activated_at: ?string, operation_id: ?string}
     */
    public function read(): array
    {
        $path = $this->pointerPath();
        if (!is_file($path)) {
            return [
                'format' => 1,
                'current' => null,
                'previous' => null,
                'activated_at' => null,
                'operation_id' => null,
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid current pointer document: ' . $path);
        }

        return [
            'format' => (int) ($decoded['format'] ?? 1),
            'current' => isset($decoded['current']) ? (string) $decoded['current'] : null,
            'previous' => isset($decoded['previous']) ? (string) $decoded['previous'] : null,
            'activated_at' => isset($decoded['activated_at']) ? (string) $decoded['activated_at'] : null,
            'operation_id' => isset($decoded['operation_id']) ? (string) $decoded['operation_id'] : null,
        ];
    }

    public function write(?string $current, ?string $previous, ?string $operationId): void
    {
        $path = $this->pointerPath();
        $this->ensureDirectory(dirname($path));

        $payload = [
            'format' => 1,
            'current' => $current,
            'previous' => $previous,
            'activated_at' => date(DATE_ATOM),
            'operation_id' => $operationId,
        ];

        $temporaryPath = $path . '.tmp';
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Could not encode current pointer document.');
        }

        if (file_put_contents($temporaryPath, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Could not write temporary current pointer document: ' . $temporaryPath);
        }

        if (is_file($path) && !unlink($path)) {
            @unlink($temporaryPath);

            throw new RuntimeException('Could not replace current pointer document: ' . $path);
        }

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new RuntimeException('Could not move current pointer document into place: ' . $path);
        }
    }

    private function pointerPath(): string
    {
        return mb_rtrim($this->deployRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.deploy' . DIRECTORY_SEPARATOR . 'current.json';
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
