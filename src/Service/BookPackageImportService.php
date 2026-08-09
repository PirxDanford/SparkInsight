<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use Doctrine\DBAL\Connection;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

class BookPackageImportService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{imported: int, book_title: string, source_format: string, manifest: array<string, mixed>}
     */
    public function importFromPackage(string $packagePath, int $authorId): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required to import book packages.');
        }

        if (!is_file($packagePath)) {
            throw new RuntimeException('Book package file not found: ' . $packagePath);
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($packagePath);
        if ($openResult !== true) {
            throw new RuntimeException('Could not open book package ZIP; code ' . (string) $openResult);
        }

        try {
            $manifestJson = $zip->getFromName('manifest.json');
            if ($manifestJson === false) {
                throw new RuntimeException('Book package is missing manifest.json.');
            }

            $manifest = json_decode((string) $manifestJson, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($manifest)) {
                throw new RuntimeException('Book package manifest is invalid.');
            }

            $bookTitle = (string) ($manifest['book_title'] ?? '');
            $sourceFormat = (string) ($manifest['source_format'] ?? 'scrivener');
            $payloadFiles = (array) ($manifest['payload_files'] ?? []);
            if ($payloadFiles === []) {
                throw new RuntimeException('Book package manifest does not include any payload files.');
            }

            $tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparkinsight-book-import-' . bin2hex(random_bytes(8));
            if (!mkdir($tempDirectory, 0o700, true) && !is_dir($tempDirectory)) {
                throw new RuntimeException('Could not create temporary directory for book package import.');
            }

            try {
                $sourceRoot = $tempDirectory . DIRECTORY_SEPARATOR . 'book-source';
                if (!mkdir($sourceRoot, 0o700, true) && !is_dir($sourceRoot)) {
                    throw new RuntimeException('Could not create temporary book source directory.');
                }

                foreach ($payloadFiles as $entryName) {
                    $entryContent = $zip->getFromName($entryName);
                    if ($entryContent === false) {
                        throw new RuntimeException('Book package is missing payload entry: ' . $entryName);
                    }

                    $relativePath = mb_ltrim(mb_substr($entryName, mb_strlen('payload/book-source/')), '/');
                    $targetPath = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                    $targetDirectory = dirname($targetPath);
                    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0o700, true) && !is_dir($targetDirectory)) {
                        throw new RuntimeException('Could not create destination directory for package payload entry: ' . $entryName);
                    }

                    if (file_put_contents($targetPath, $entryContent) === false) {
                        throw new RuntimeException('Could not extract book payload entry: ' . $entryName);
                    }
                }

                $importResult = $this->connection->executeQuery(
                    'SELECT COUNT(*) FROM content_versions WHERE author_id = ? AND COALESCE(book_title, \'\') = ? AND status = ? LIMIT 1',
                    [$authorId, $bookTitle, 'ready'],
                )->fetchOne();

                $importedCount = is_numeric($importResult) ? (int) $importResult : 0;
                $importBatchId = 'pkg_' . bin2hex(random_bytes(6));
                $importedItems = $this->importScrivenerProject($sourceRoot, $bookTitle, $authorId, $importBatchId, $sourceFormat, $manifest);

                return [
                    'imported' => $importedCount + count($importedItems),
                    'book_title' => $bookTitle,
                    'source_format' => $sourceFormat,
                    'manifest' => $manifest,
                ];
            } finally {
                $this->removeDirectory($tempDirectory);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<int, array<string, mixed>>
     */
    private function importScrivenerProject(string $sourceRoot, string $bookTitle, int $authorId, string $importBatchId, string $sourceFormat, array $manifest): array
    {
        $scrivxPath = $this->findScrivx($sourceRoot);
        if ($scrivxPath === null) {
            throw new RuntimeException('Book package does not contain a Scrivener project file.');
        }

        $contentImportService = new ContentImportService($this->connection);
        $result = $contentImportService->importScrivenerDirectory($sourceRoot, $authorId, null, false, $bookTitle);

        return is_array($result['imported'] ?? null) ? $result['imported'] : [];
    }

    private function findScrivx(string $directory): ?string
    {
        if (is_file($directory . DIRECTORY_SEPARATOR . 'book.scrivx')) {
            return $directory . DIRECTORY_SEPARATOR . 'book.scrivx';
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            if ($fileInfo->isFile() && mb_strtolower($fileInfo->getExtension()) === 'scrivx') {
                return $fileInfo->getPathname();
            }
        }

        return null;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            if ($fileInfo->isDir()) {
                @rmdir($fileInfo->getPathname());
            } else {
                @unlink($fileInfo->getPathname());
            }
        }

        @rmdir($directory);
    }
}
