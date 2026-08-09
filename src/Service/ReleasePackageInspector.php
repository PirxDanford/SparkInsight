<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use RuntimeException;
use ZipArchive;

final class ReleasePackageInspector
{
    public function __construct(
        private readonly ReleaseSignatureVerifier $signatureVerifier = new ReleaseSignatureVerifier(),
        private readonly ReleasePathPolicy $pathPolicy = new ReleasePathPolicy(),
    ) {
    }

    public function inspect(string $packagePath, string $publicKey): ReleaseManifest
    {
        if (!is_file($packagePath)) {
            throw new RuntimeException('Package ZIP not found: ' . $packagePath);
        }

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required to inspect packages.');
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($packagePath);
        if ($openResult !== true) {
            throw new RuntimeException('Unable to open package ZIP; code ' . (string) $openResult);
        }

        try {
            $manifestJson = $this->readZipEntry($zip, 'manifest.json');
            $signature = $this->readZipEntry($zip, 'manifest.sig');
            $manifest = ReleaseManifest::fromJson($manifestJson, $this->pathPolicy);

            if (!$this->signatureVerifier->verifyDetached($manifestJson, $signature, $publicKey)) {
                throw new RuntimeException('Manifest signature verification failed.');
            }

            $this->validateArchiveContents($zip, $manifest);

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    private function readZipEntry(ZipArchive $zip, string $entryName): string
    {
        $contents = $zip->getFromName($entryName);
        if ($contents === false) {
            throw new RuntimeException('Missing required ZIP entry: ' . $entryName);
        }

        return $contents;
    }

    private function validateArchiveContents(ZipArchive $zip, ReleaseManifest $manifest): void
    {
        $expectedFiles = array_fill_keys(array_map(static fn (string $path): string => 'payload/' . $path, $manifest->payloadFiles()), true);
        $expectedFiles['manifest.json'] = true;
        $expectedFiles['manifest.sig'] = true;

        $seenEntries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false || !isset($stat['name'])) {
                throw new RuntimeException('Unable to inspect ZIP entry at index ' . $index . '.');
            }

            $entryName = (string) $stat['name'];
            if ($entryName === '' || isset($seenEntries[mb_strtolower($entryName)])) {
                throw new RuntimeException('Duplicate ZIP entry detected: ' . $entryName);
            }
            $seenEntries[mb_strtolower($entryName)] = true;

            if ($entryName === 'manifest.json' || $entryName === 'manifest.sig') {
                continue;
            }

            if (str_ends_with($entryName, '/')) {
                $directoryName = mb_rtrim(mb_substr($entryName, mb_strlen('payload/')), '/');
                if (!str_starts_with($entryName, 'payload/')) {
                    throw new RuntimeException('Unexpected directory entry in package: ' . $entryName);
                }

                if ($directoryName !== '') {
                    $this->pathPolicy->ensureAllowedPayloadPath($directoryName);
                }

                continue;
            }

            if (!str_starts_with($entryName, 'payload/')) {
                throw new RuntimeException('Unexpected ZIP entry outside payload: ' . $entryName);
            }

            $payloadPath = mb_substr($entryName, mb_strlen('payload/'));
            $normalizedPayloadPath = $this->pathPolicy->ensureAllowedPayloadPath($payloadPath);
            $expectedArchiveEntry = 'payload/' . $normalizedPayloadPath;

            if (!isset($expectedFiles[$expectedArchiveEntry])) {
                throw new RuntimeException('Unexpected payload entry not declared in manifest: ' . $entryName);
            }
        }

        foreach ($expectedFiles as $expectedEntry => $_required) {
            if ($expectedEntry === 'manifest.json' || $expectedEntry === 'manifest.sig') {
                continue;
            }

            if ($zip->locateName($expectedEntry) === false) {
                throw new RuntimeException('Package is missing declared payload entry: ' . $expectedEntry);
            }
        }
    }
}
