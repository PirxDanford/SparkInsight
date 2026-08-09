<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ReleaseRootPublisher
{
    /** @var array<int, string> */
    private const MANAGED_ROOT_PATHS = [
        'public',
        'src',
        'templates',
        'resources',
        'database',
        'vendor',
        'si.php',
        'composer.json',
        'composer.lock',
        'LICENSE',
    ];

    public function publish(string $releaseRoot, string $projectRoot): void
    {
        $normalizedReleaseRoot = mb_rtrim($releaseRoot, DIRECTORY_SEPARATOR);
        $normalizedProjectRoot = mb_rtrim($projectRoot, DIRECTORY_SEPARATOR);

        if (!is_dir($normalizedReleaseRoot)) {
            throw new RuntimeException('Release root does not exist for publish: ' . $normalizedReleaseRoot);
        }

        foreach (self::MANAGED_ROOT_PATHS as $relativePath) {
            $targetPath = $normalizedProjectRoot . DIRECTORY_SEPARATOR . $relativePath;
            $this->removePath($targetPath);

            $sourcePath = $normalizedReleaseRoot . DIRECTORY_SEPARATOR . $relativePath;
            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $targetPath);
                continue;
            }

            if (is_file($sourcePath)) {
                $this->ensureDirectory(dirname($targetPath));
                if (!copy($sourcePath, $targetPath)) {
                    throw new RuntimeException('Failed to publish file to project root: ' . $relativePath);
                }
            }
        }

        $publicEntrypoint = $normalizedProjectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($publicEntrypoint)) {
            throw new RuntimeException('Published project root is missing public entrypoint.');
        }
    }

    private function removePath(string $path): void
    {
        if (is_file($path)) {
            if (!unlink($path)) {
                throw new RuntimeException('Could not remove file before publish: ' . $path);
            }

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                if (!rmdir($item->getPathname())) {
                    throw new RuntimeException('Could not remove directory before publish: ' . $item->getPathname());
                }

                continue;
            }

            if (!unlink($item->getPathname())) {
                throw new RuntimeException('Could not remove file before publish: ' . $item->getPathname());
            }
        }

        if (!rmdir($path)) {
            throw new RuntimeException('Could not remove directory before publish: ' . $path);
        }
    }

    private function copyDirectory(string $sourceDir, string $targetDir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $relativePath = mb_substr($item->getPathname(), mb_strlen(mb_rtrim($sourceDir, DIRECTORY_SEPARATOR)) + 1);
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                $this->ensureDirectory($targetPath);
                continue;
            }

            $this->ensureDirectory(dirname($targetPath));
            if (!copy($item->getPathname(), $targetPath)) {
                throw new RuntimeException('Could not copy file during publish: ' . $relativePath);
            }
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
