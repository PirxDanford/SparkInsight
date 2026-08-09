<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\ReleaseRootPublisher;

final class ReleaseRootPublisherTest extends TestCase
{
    public function testPublishCopiesManagedPathsIntoProjectRoot(): void
    {
        $releaseRoot = sys_get_temp_dir() . '/sparkinsight-release-root-' . bin2hex(random_bytes(8));
        $projectRoot = sys_get_temp_dir() . '/sparkinsight-project-root-' . bin2hex(random_bytes(8));
        mkdir($releaseRoot . '/public', 0777, true);
        mkdir($releaseRoot . '/src', 0777, true);
        mkdir($releaseRoot . '/database/migrations', 0777, true);
        mkdir($releaseRoot . '/vendor', 0777, true);
        file_put_contents($releaseRoot . '/public/index.php', "<?php\nreturn 1;\n");
        file_put_contents($releaseRoot . '/src/App.php', "<?php\nreturn 2;\n");
        file_put_contents($releaseRoot . '/database/migrations/001.php', "<?php\n");
        file_put_contents($releaseRoot . '/composer.json', "{}\n");
        file_put_contents($releaseRoot . '/composer.lock', "{}\n");
        file_put_contents($releaseRoot . '/si.php', "<?php\n");
        file_put_contents($releaseRoot . '/LICENSE', "MIT\n");
        mkdir($projectRoot . '/public', 0777, true);
        file_put_contents($projectRoot . '/public/index.php', "<?php\nreturn 0;\n");

        try {
            $publisher = new ReleaseRootPublisher();
            $publisher->publish($releaseRoot, $projectRoot);

            $this->assertFileExists($projectRoot . '/public/index.php');
            $this->assertStringContainsString('return 1;', (string) file_get_contents($projectRoot . '/public/index.php'));
            $this->assertFileExists($projectRoot . '/src/App.php');
            $this->assertFileExists($projectRoot . '/database/migrations/001.php');
            $this->assertFileExists($projectRoot . '/composer.json');
        } finally {
            $this->deleteDirectory($releaseRoot);
            $this->deleteDirectory($projectRoot);
        }
    }

    public function testPublishThrowsWhenReleaseRootMissing(): void
    {
        $projectRoot = sys_get_temp_dir() . '/sparkinsight-project-root-' . bin2hex(random_bytes(8));
        mkdir($projectRoot, 0777, true);

        try {
            $publisher = new ReleaseRootPublisher();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Release root does not exist for publish:');
            $publisher->publish($projectRoot . '/missing-release', $projectRoot);
        } finally {
            $this->deleteDirectory($projectRoot);
        }
    }

    public function testPublishThrowsWhenPublishedEntrypointMissing(): void
    {
        $releaseRoot = sys_get_temp_dir() . '/sparkinsight-release-root-' . bin2hex(random_bytes(8));
        $projectRoot = sys_get_temp_dir() . '/sparkinsight-project-root-' . bin2hex(random_bytes(8));
        mkdir($releaseRoot . '/src', 0777, true);
        mkdir($projectRoot, 0777, true);
        file_put_contents($releaseRoot . '/src/App.php', "<?php\n");

        try {
            $publisher = new ReleaseRootPublisher();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Published project root is missing public entrypoint.');
            $publisher->publish($releaseRoot, $projectRoot);
        } finally {
            $this->deleteDirectory($releaseRoot);
            $this->deleteDirectory($projectRoot);
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
