<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\ReleaseManifest;
use SparkInsight\Service\ReleasePreflight;

final class ReleasePreflightTest extends TestCase
{
    public function testRunReturnsEmptyArrayForValidReleaseRoot(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-preflight-' . bin2hex(random_bytes(8));
        mkdir($root . '/public', 0777, true);
        mkdir($root . '/vendor', 0777, true);
        mkdir($root . '/database/migrations', 0777, true);
        file_put_contents($root . '/public/index.php', "<?php\n");
        file_put_contents($root . '/vendor/autoload.php', "<?php\n");
        file_put_contents($root . '/composer.lock', "{}\n");

        try {
            $manifest = $this->createManifest();
            $preflight = new ReleasePreflight();

            $result = $preflight->run($root, $manifest);

            $this->assertSame([], $result);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testRunThrowsWhenRequiredFilesAreMissing(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-preflight-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $manifest = $this->createManifest();
            $preflight = new ReleasePreflight();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Missing application entrypoint:');
            $preflight->run($root, $manifest);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testRunCollectsVersionExtensionAndDiskProblems(): void
    {
        $manifest = $this->createManifest('99.0.0', ['ext_that_should_not_exist_for_tests']);
        $preflight = new ReleasePreflight();

        try {
            $preflight->run(sys_get_temp_dir() . '/nonexistent-preflight-root-' . bin2hex(random_bytes(8)), $manifest);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();
            $this->assertStringContainsString('is below minimum 99.0.0', $message);
            $this->assertStringContainsString('Missing required extension: ext_that_should_not_exist_for_tests', $message);
            $this->assertStringContainsString('Unable to determine free disk space.', $message);
        }
    }

    private function createManifest(string $minimumPhp = '8.1.0', array $requiredExtensions = ['json']): ReleaseManifest
    {
        $data = [
            'format' => ReleaseManifest::FORMAT,
            'application' => ReleaseManifest::APPLICATION,
            'package_id' => 'package-' . bin2hex(random_bytes(4)),
            'package_type' => 'full',
            'release_id' => 'release-' . bin2hex(random_bytes(3)),
            'created_at' => date(DATE_ATOM),
            'minimum_php' => $minimumPhp,
            'required_extensions' => $requiredExtensions,
            'composer_lock_sha256' => str_repeat('a', 64),
            'expanded_size' => 1,
            'file_count' => 1,
            'files' => [
                ['path' => 'public/index.php', 'size' => 1, 'sha256' => str_repeat('b', 64)],
            ],
            'payload_files' => ['public/index.php'],
            'delete' => [],
        ];

        return ReleaseManifest::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
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
