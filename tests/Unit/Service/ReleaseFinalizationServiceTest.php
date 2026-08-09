<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\CurrentPointerStore;
use SparkInsight\Service\ReleaseFinalizationService;

final class ReleaseFinalizationServiceTest extends TestCase
{
    public function testFinalizeThrowsWhenNoCurrentReleaseExists(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-finalize-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $service = new ReleaseFinalizationService($root);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('No active release exists to finalize.');
            $service->finalize('op-1');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testFinalizeRemovesPreviousReleaseAndClearsPointerPrevious(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-finalize-' . bin2hex(random_bytes(8));
        mkdir($root . '/.deploy/releases/release-prev/sub', 0777, true);
        mkdir($root . '/.deploy/releases/release-new/public', 0777, true);
        file_put_contents($root . '/.deploy/releases/release-prev/sub/file.txt', 'x');
        file_put_contents($root . '/.deploy/releases/release-new/public/index.php', "<?php\n");

        try {
            $pointerStore = new CurrentPointerStore($root);
            $pointerStore->write('release-new', 'release-prev', 'seed-op');

            $service = new ReleaseFinalizationService($root, $pointerStore);
            $result = $service->finalize('op-2');

            $this->assertSame('release-new', $result['current']);
            $this->assertNull($result['previous']);
            $this->assertDirectoryDoesNotExist($root . '/.deploy/releases/release-prev');

            $pointer = $pointerStore->read();
            $this->assertSame('release-new', $pointer['current']);
            $this->assertNull($pointer['previous']);
            $this->assertSame('op-2', $pointer['operation_id']);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testFinalizeHandlesMissingPreviousReleaseDirectory(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-finalize-' . bin2hex(random_bytes(8));
        mkdir($root . '/.deploy/releases/release-new/public', 0777, true);
        file_put_contents($root . '/.deploy/releases/release-new/public/index.php', "<?php\n");

        try {
            $pointerStore = new CurrentPointerStore($root);
            $pointerStore->write('release-new', 'release-missing', 'seed-op');

            $service = new ReleaseFinalizationService($root, $pointerStore);
            $result = $service->finalize('op-3');

            $this->assertSame('release-new', $result['current']);
            $this->assertNull($result['previous']);

            $pointer = $pointerStore->read();
            $this->assertSame('release-new', $pointer['current']);
            $this->assertNull($pointer['previous']);
            $this->assertSame('op-3', $pointer['operation_id']);
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
