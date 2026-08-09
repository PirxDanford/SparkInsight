<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\CurrentPointerStore;
use SparkInsight\Service\ReleaseActivationService;
use SparkInsight\Service\ReleaseFinalizationService;

final class ReleaseActivationServiceTest extends TestCase
{
    public function testActivateAndFinalizeUpdatesPointerAndRemovesPreviousRelease(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-activation-' . bin2hex(random_bytes(8));
        mkdir($root);

        try {
            $this->createReleaseEntrypoint($root, 'release-old');
            $this->createReleaseEntrypoint($root, 'release-new');

            $pointerStore = new CurrentPointerStore($root);
            $pointerStore->write('release-old', null, 'seed-op');

            $activation = new ReleaseActivationService($root, $pointerStore);
            $result = $activation->activate('release-new', 'deploy-op');

            $this->assertSame('release-new', $result['current']);
            $this->assertSame('release-old', $result['previous']);

            $finalization = new ReleaseFinalizationService($root, $pointerStore);
            $finalResult = $finalization->finalize('deploy-op');

            $this->assertSame('release-new', $finalResult['current']);
            $this->assertNull($finalResult['previous']);
            $this->assertDirectoryDoesNotExist($root . '/.deploy/releases/release-old');
            $this->assertDirectoryExists($root . '/.deploy/releases/release-new');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testActivateFailsWhenEntrypointMissing(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-activation-' . bin2hex(random_bytes(8));
        mkdir($root);

        try {
            $activation = new ReleaseActivationService($root);

            $this->expectException(\RuntimeException::class);
            $activation->activate('missing-release', 'deploy-op');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    private function createReleaseEntrypoint(string $root, string $releaseId): void
    {
        $entrypoint = $root . '/.deploy/releases/' . $releaseId . '/public/index.php';
        $directory = dirname($entrypoint);
        if (!is_dir($directory)) {
            mkdir($directory, 0o700, true);
        }

        file_put_contents($entrypoint, "<?php echo 'ok';\n");
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