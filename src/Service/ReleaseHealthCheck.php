<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Throwable;

final class ReleaseHealthCheck
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function run(string $releaseRoot): array
    {
        $problems = [];

        if (!is_file($releaseRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php')) {
            $problems[] = 'Release entrypoint is missing.';
        }

        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (Throwable $throwable) {
            $problems[] = 'Database health check failed: ' . $throwable->getMessage();
        }

        if ($problems !== []) {
            throw new RuntimeException(implode(PHP_EOL, $problems));
        }

        return $problems;
    }
}
