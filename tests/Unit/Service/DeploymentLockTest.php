<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\DeploymentLock;

final class DeploymentLockTest extends TestCase
{
    public function testWithLockRunsCallbackAndWritesMetadata(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-lock-' . bin2hex(random_bytes(8));
        mkdir($root);

        try {
            $lock = new DeploymentLock($root);
            $result = $lock->withLock('operation-001', static fn (): string => 'locked');

            $this->assertSame('locked', $result);
            $this->assertFileExists($root . '/.deploy/update.lock');
            $this->assertFileExists($root . '/.deploy/update.lock.json');

            $metadata = json_decode((string) file_get_contents($root . '/.deploy/update.lock.json'), true);
            $this->assertIsArray($metadata);
            $this->assertSame('operation-001', $metadata['operation_id']);
            $this->assertSame('released', $metadata['status']);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testWithLockReleasesMetadataWhenCallbackThrows(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-lock-' . bin2hex(random_bytes(8));
        mkdir($root);

        try {
            $lock = new DeploymentLock($root);

            try {
                $lock->withLock('operation-002', static function (): void {
                    throw new \RuntimeException('boom');
                });
                $this->fail('Expected callback exception to bubble.');
            } catch (\RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }

            $metadata = json_decode((string) file_get_contents($root . '/.deploy/update.lock.json'), true);
            $this->assertIsArray($metadata);
            $this->assertSame('operation-002', $metadata['operation_id']);
            $this->assertSame('released', $metadata['status']);
            $this->assertArrayHasKey('released_at', $metadata);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testWithLockThrowsWhenDeployDirectoryCannotBeCreated(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-lock-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        file_put_contents($root . '/.deploy', 'blocked');

        try {
            $lock = new DeploymentLock($root);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not create directory:');
            $lock->withLock('operation-003', static fn (): string => 'never');
        } finally {
            restore_error_handler();
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