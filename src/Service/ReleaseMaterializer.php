<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use RuntimeException;
use ZipArchive;

final class ReleaseMaterializer
{
    /**
     * @var array<int, string>
     */
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

    public function __construct(
        private readonly ReleasePackageInspector $inspector = new ReleasePackageInspector(),
        private readonly ReleasePathPolicy $pathPolicy = new ReleasePathPolicy(),
    ) {
    }

    public function materialize(string $packagePath, string $publicKey, string $stagingRoot, ?string $baseReleaseRoot = null): array
    {
        $manifest = $this->inspector->inspect($packagePath, $publicKey);
        $stagingReleaseRoot = rtrim($stagingRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $manifest->releaseId();
        $this->removeDirectory($stagingReleaseRoot);
        $this->ensureDirectory($stagingReleaseRoot);

        if ($manifest->packageType() === 'patch') {
            if ($baseReleaseRoot === null || !is_dir($baseReleaseRoot)) {
                throw new RuntimeException('Patch packages require an existing base release directory.');
            }

            $this->copyManagedPaths($baseReleaseRoot, $stagingReleaseRoot);
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($packagePath);
        if ($openResult !== true) {
            throw new RuntimeException('Unable to open package ZIP for materialization; code ' . (string) $openResult);
        }

        try {
            foreach ($manifest->payloadFiles() as $payloadPath) {
                $entryName = 'payload/' . $payloadPath;
                $contents = $zip->getFromName($entryName);
                if ($contents === false) {
                    throw new RuntimeException('Missing payload entry during materialization: ' . $entryName);
                }

                $targetPath = $stagingReleaseRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $payloadPath);
                $this->ensureDirectory(dirname($targetPath));
                if (file_put_contents($targetPath, $contents, LOCK_EX) === false) {
                    throw new RuntimeException('Could not write staged file: ' . $targetPath);
                }

                $expected = null;
                foreach ($manifest->files() as $file) {
                    if ($file['path'] === $payloadPath) {
                        $expected = $file;
                        break;
                    }
                }

                if ($expected === null) {
                    throw new RuntimeException('Payload file missing from manifest index: ' . $payloadPath);
                }

                $actualHash = hash_file('sha256', $targetPath);
                if ($actualHash === false || !hash_equals($expected['sha256'], $actualHash)) {
                    throw new RuntimeException('Materialized file hash mismatch: ' . $payloadPath);
                }
            }
        } finally {
            $zip->close();
        }

        foreach ($manifest->deletePaths() as $deletePath) {
            $targetPath = $stagingReleaseRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $deletePath);
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
        }

        foreach ($manifest->files() as $file) {
            $targetPath = $stagingReleaseRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file['path']);
            if (!is_file($targetPath)) {
                throw new RuntimeException('Missing staged file after materialization: ' . $file['path']);
            }
        }

        return [
            'release_root' => $stagingReleaseRoot,
            'manifest' => $manifest,
        ];
    }

    private function copyDirectory(string $sourceDir, string $targetDir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            $relativePath = substr($fileInfo->getPathname(), strlen(rtrim($sourceDir, DIRECTORY_SEPARATOR)) + 1);
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $relativePath;

            if ($fileInfo->isDir()) {
                $this->ensureDirectory($targetPath);
                continue;
            }

            $this->ensureDirectory(dirname($targetPath));
            if (!copy($fileInfo->getPathname(), $targetPath)) {
                throw new RuntimeException('Could not copy base file during patch materialization: ' . $relativePath);
            }
        }
    }

    private function copyManagedPaths(string $sourceRoot, string $targetRoot): void
    {
        $normalizedSourceRoot = rtrim($sourceRoot, DIRECTORY_SEPARATOR);
        $normalizedTargetRoot = rtrim($targetRoot, DIRECTORY_SEPARATOR);

        foreach (self::MANAGED_ROOT_PATHS as $relativePath) {
            $sourcePath = $normalizedSourceRoot . DIRECTORY_SEPARATOR . $relativePath;
            $targetPath = $normalizedTargetRoot . DIRECTORY_SEPARATOR . $relativePath;

            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $targetPath);
                continue;
            }

            if (is_file($sourcePath)) {
                $this->ensureDirectory(dirname($targetPath));
                if (!copy($sourcePath, $targetPath)) {
                    throw new RuntimeException('Could not copy base file during patch materialization: ' . $relativePath);
                }
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

    private function removeDirectory(string $directory): void
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