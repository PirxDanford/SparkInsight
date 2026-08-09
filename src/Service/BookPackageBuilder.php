<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SimpleXMLElement;
use SplFileInfo;
use Throwable;
use ZipArchive;

final class BookPackageBuilder
{
    /**
     * @return array{manifest_path: string, signature_path: string, package_path: string, manifest_json: string}
     */
    public function buildBookPackage(string $packagePath, string $sourceRoot, string $privateKeyPath, string $bookTitle): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required to build book packages.');
        }

        $sourceDirectory = $this->normalizeDirectory($sourceRoot);
        if (!is_dir($sourceDirectory)) {
            throw new RuntimeException('Book source root does not exist: ' . $sourceRoot);
        }

        $preview = $this->buildImportPreview($sourceRoot, $bookTitle);
        $manifest = [
            'format' => ReleaseManifest::FORMAT,
            'application' => ReleaseManifest::APPLICATION,
            'package_type' => 'book',
            'book_title' => $bookTitle,
            'source_format' => 'scrivener',
            'source_root' => $sourceRoot,
            'created_at' => date(DATE_ATOM),
            'import_preview' => $preview,
            'payload_files' => $this->collectPayloadFiles($sourceDirectory),
        ];

        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $privateKey = $this->readBinaryFile($privateKeyPath, 'private key');
        if ($privateKey === '') {
            throw new RuntimeException('Private key file is empty.');
        }
        $signature = sodium_crypto_sign_detached($manifestJson, $privateKey);

        $packageDirectory = dirname($packagePath);
        if ($packageDirectory !== '' && !is_dir($packageDirectory) && !mkdir($packageDirectory, 0o700, true) && !is_dir($packageDirectory)) {
            throw new RuntimeException('Could not create package output directory: ' . $packageDirectory);
        }

        $zip = new ZipArchive();
        $opened = $zip->open($packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException('Could not create book package ZIP; code ' . (string) $opened);
        }

        try {
            if (!$zip->addFromString('manifest.json', $manifestJson)) {
                throw new RuntimeException('Could not add manifest.json to book package ZIP.');
            }

            if (!$zip->addFromString('manifest.sig', $signature)) {
                throw new RuntimeException('Could not add manifest.sig to book package ZIP.');
            }

            $filesToAdd = $this->collectFiles($sourceDirectory);
            if ($filesToAdd === []) {
                throw new RuntimeException('Book source root contains no files to package.');
            }

            foreach ($filesToAdd as $relativePath) {
                $absolutePath = $sourceDirectory . DIRECTORY_SEPARATOR . $relativePath;
                $entryName = 'payload/book-source/' . mb_ltrim($relativePath, '/');
                if (!$zip->addFile($absolutePath, $entryName)) {
                    throw new RuntimeException('Could not add book payload file to package ZIP: ' . $relativePath);
                }
            }

            if (!$zip->close()) {
                throw new RuntimeException('Could not finalize book package ZIP.');
            }
        } catch (Throwable $exception) {
            $zip->close();
            @unlink($packagePath);

            throw $exception;
        }

        $manifestPath = $packagePath . '.manifest.json';
        $signaturePath = $packagePath . '.manifest.sig';
        file_put_contents($manifestPath, $manifestJson);
        file_put_contents($signaturePath, $signature);

        return [
            'manifest_path' => $manifestPath,
            'signature_path' => $signaturePath,
            'package_path' => $packagePath,
            'manifest_json' => $manifestJson,
        ];
    }

    /**
     * @return array{book_title: string, source_file: string, imported: array<int, array{path: string}>}
     */
    private function buildImportPreview(string $sourceRoot, string $bookTitle): array
    {
        $directory = $this->normalizeDirectory($sourceRoot);
        $scrivx = $this->findScrivx($directory);
        if ($scrivx === null) {
            throw new RuntimeException('No Scrivener project file found in book source root: ' . $sourceRoot);
        }

        $rawXml = (string) file_get_contents($scrivx);
        if ($rawXml === '') {
            throw new RuntimeException('Could not read Scrivener project file: ' . $scrivx);
        }

        $xml = simplexml_load_string($rawXml);
        if ($xml === false) {
            throw new RuntimeException('Invalid Scrivener project XML: ' . $scrivx);
        }

        $rootTitle = $this->extractRootTitle($xml) ?? $bookTitle;
        $imported = [];
        $this->collectBinderItems($xml, $imported, $rootTitle);

        return [
            'book_title' => $bookTitle,
            'source_file' => basename($scrivx),
            'imported' => $imported,
        ];
    }

    /**
     * @param array<int, array{path: string}> $imported
     */
    private function collectBinderItems(?SimpleXMLElement $xml, array &$imported, string $rootTitle): void
    {
        if ($xml === null) {
            return;
        }

        foreach ($xml->xpath('/ScrivenerProject/Binder/BinderItem') as $item) {
            $this->collectBinderItem($item, $imported, $rootTitle);
        }
    }

    /**
     * @param array<int, array{path: string}> $imported
     */
    private function collectBinderItem(SimpleXMLElement $item, array &$imported, string $rootTitle): void
    {
        $itemType = mb_trim((string) ($item['Type'] ?? ''));
        if ($itemType === 'Folder') {
            foreach ($item->Children->BinderItem ?? [] as $child) {
                $this->collectBinderItem($child, $imported, $rootTitle);
            }

            return;
        }

        $title = mb_trim((string) ($item->Title ?? ''));
        if ($title === '') {
            return;
        }

        $imported[] = [
            'path' => $rootTitle . '/' . $title,
        ];
    }

    private function extractRootTitle(?SimpleXMLElement $xml): ?string
    {
        if ($xml === null) {
            return null;
        }

        $root = $xml->xpath('/ScrivenerProject/Binder/BinderItem[Title="The Book"]');
        if (is_array($root) && $root !== []) {
            $title = mb_trim((string) ($root[0]->Title ?? ''));
            if ($title !== '') {
                return $title;
            }
        }

        return null;
    }

    private function normalizeDirectory(string $path): string
    {
        return mb_rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    private function findScrivx(string $directory): ?string
    {
        if (is_file($directory . '/book.scrivx')) {
            return $directory . '/book.scrivx';
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

    /**
     * @return array<int, string>
     */
    private function collectPayloadFiles(string $directory): array
    {
        $files = $this->collectFiles($directory);

        return array_map(static fn (string $relativePath): string => 'payload/book-source/' . mb_ltrim($relativePath, '/'), $files);
    }

    /**
     * @return array<int, string>
     */
    private function collectFiles(string $directory): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        $files = [];
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            if (!$fileInfo->isFile()) {
                continue;
            }

            $absolutePath = $fileInfo->getPathname();
            $relativePath = mb_ltrim(mb_substr($absolutePath, mb_strlen($directory) + 1), '/');
            $files[] = str_replace('\\', '/', $relativePath);
        }

        sort($files);

        return $files;
    }

    private function readBinaryFile(string $path, string $label): string
    {
        if (!is_file($path)) {
            throw new RuntimeException(ucfirst($label) . ' not found: ' . $path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Could not read ' . $label . ': ' . $path);
        }

        return $contents;
    }
}
