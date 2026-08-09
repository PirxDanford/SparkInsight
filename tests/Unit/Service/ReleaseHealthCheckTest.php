<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use SparkInsight\Service\ReleaseHealthCheck;

final class ReleaseHealthCheckTest extends TestCase
{
    public function testRunPassesWhenEntrypointExistsAndDatabaseIsHealthy(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-health-' . bin2hex(random_bytes(8));
        mkdir($root . '/public', 0777, true);
        file_put_contents($root . '/public/index.php', "<?php\n");

        try {
            $connection = $this->createMock(Connection::class);
            $connection->expects($this->once())
                ->method('executeQuery')
                ->with('SELECT 1');

            $check = new ReleaseHealthCheck($connection);
            $this->assertSame([], $check->run($root));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testRunThrowsWhenEntrypointMissingAndDatabaseFails(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-health-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $connection = $this->createMock(Connection::class);
            $connection->expects($this->once())
                ->method('executeQuery')
                ->with('SELECT 1')
                ->willThrowException(new \RuntimeException('db down'));

            $check = new ReleaseHealthCheck($connection);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Release entrypoint is missing.');
            $this->expectExceptionMessage('Database health check failed: db down');
            $check->run($root);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
