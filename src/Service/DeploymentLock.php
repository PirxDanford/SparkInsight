<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use RuntimeException;

final class DeploymentLock
{
    public function __construct(
        private readonly string $deployRoot,
    ) {
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function withLock(string $operationId, callable $callback): mixed
    {
        $lockPath = mb_rtrim($this->deployRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.deploy' . DIRECTORY_SEPARATOR . 'update.lock';
        $metadataPath = $lockPath . '.json';
        $this->ensureDirectory(dirname($lockPath));

        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Could not open deployment lock: ' . $lockPath);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Could not acquire deployment lock: ' . $lockPath);
            }

            $this->writeMetadata($metadataPath, [
                'operation_id' => $operationId,
                'acquired_at' => date(DATE_ATOM),
                'pid' => function_exists('getmypid') ? getmypid() : null,
                'status' => 'locked',
            ]);

            try {
                return $callback();
            } finally {
                $this->writeMetadata($metadataPath, [
                    'operation_id' => $operationId,
                    'acquired_at' => date(DATE_ATOM),
                    'released_at' => date(DATE_ATOM),
                    'pid' => function_exists('getmypid') ? getmypid() : null,
                    'status' => 'released',
                ]);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function writeMetadata(string $path, array $metadata): void
    {
        $encoded = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Could not encode deployment lock metadata.');
        }

        if (file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Could not write deployment lock metadata: ' . $path);
        }
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
