<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use RuntimeException;

final class ReleaseManifest
{
    public const FORMAT = 'sparkinsight-release-v1';

    public const APPLICATION = 'sparkinsight/sparkinsight';

    /**
     * @param array<int, string> $requiredExtensions
     * @param array<int, array{path: string, size: int, sha256: string}> $files
     * @param array<int, string> $payloadFiles
     * @param array<int, string> $deletePaths
     */
    private function __construct(
        private readonly string $packageId,
        private readonly string $packageType,
        private readonly string $releaseId,
        private readonly string $createdAt,
        private readonly string $minimumPhp,
        private readonly array $requiredExtensions,
        private readonly string $composerLockSha256,
        private readonly int $expandedSize,
        private readonly int $fileCount,
        private readonly array $files,
        private readonly array $payloadFiles,
        private readonly array $deletePaths,
        private readonly ?string $baseReleaseId,
        private readonly ?string $baseManifestSha256,
    ) {
    }

    public static function fromJson(string $json, ?ReleasePathPolicy $pathPolicy = null): self
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Release manifest JSON must decode to an object.');
        }

        return self::fromArray($decoded, $pathPolicy);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public static function fromArray(array $manifest, ?ReleasePathPolicy $pathPolicy = null): self
    {
        $pathPolicy ??= new ReleasePathPolicy();

        self::assertStringField($manifest, 'format', self::FORMAT);
        self::assertStringField($manifest, 'application', self::APPLICATION);

        $packageId = self::readIdField($manifest, 'package_id');
        $packageType = self::readStringField($manifest, 'package_type');
        if (!in_array($packageType, ['full', 'patch'], true)) {
            throw new RuntimeException('package_type must be full or patch.');
        }

        $releaseId = self::readIdField($manifest, 'release_id');
        $createdAt = self::readStringField($manifest, 'created_at');
        if (strtotime($createdAt) === false) {
            throw new RuntimeException('created_at must be a valid timestamp.');
        }

        $minimumPhp = self::readStringField($manifest, 'minimum_php');
        $requiredExtensions = self::readStringListField($manifest, 'required_extensions');
        $composerLockSha256 = self::readHexHashField($manifest, 'composer_lock_sha256');
        $expandedSize = self::readIntField($manifest, 'expanded_size');
        $fileCount = self::readIntField($manifest, 'file_count');

        $files = self::readFileRecords($manifest, $pathPolicy);
        $payloadFiles = self::readPathList($manifest, 'payload_files', $pathPolicy);
        $deletePaths = self::readPathList($manifest, 'delete', $pathPolicy);

        $baseReleaseId = self::optionalIdField($manifest, 'base_release_id');
        $baseManifestSha256 = self::optionalHexHashField($manifest, 'base_manifest_sha256');

        if ($fileCount !== count($files)) {
            throw new RuntimeException('file_count does not match files list length.');
        }

        $calculatedExpandedSize = 0;
        foreach ($files as $file) {
            $calculatedExpandedSize += $file['size'];
        }

        if ($expandedSize !== $calculatedExpandedSize) {
            throw new RuntimeException('expanded_size does not match sum of file sizes.');
        }

        $filePaths = array_map(static fn (array $file): string => $file['path'], $files);
        $filePathSet = array_fill_keys($filePaths, true);

        foreach ($payloadFiles as $payloadPath) {
            if (!isset($filePathSet[$payloadPath])) {
                throw new RuntimeException('payload_files must be a subset of files. Missing: ' . $payloadPath);
            }
        }

        if ($packageType === 'full') {
            if ($deletePaths !== []) {
                throw new RuntimeException('Full packages must not declare delete entries.');
            }

            sort($filePaths);
            $payloadComparison = $payloadFiles;
            sort($payloadComparison);
            if ($filePaths !== $payloadComparison) {
                throw new RuntimeException('Full packages must include every target file in payload_files.');
            }
        } else {
            if ($baseReleaseId === null || $baseManifestSha256 === null) {
                throw new RuntimeException('Patch packages require base_release_id and base_manifest_sha256.');
            }

            if ($payloadFiles === []) {
                throw new RuntimeException('Patch packages must contain at least one payload file.');
            }
        }

        foreach ($deletePaths as $deletePath) {
            if (in_array($deletePath, $payloadFiles, true)) {
                throw new RuntimeException('delete entries cannot overlap with payload_files: ' . $deletePath);
            }
        }

        return new self(
            $packageId,
            $packageType,
            $releaseId,
            $createdAt,
            $minimumPhp,
            $requiredExtensions,
            $composerLockSha256,
            $expandedSize,
            $fileCount,
            $files,
            $payloadFiles,
            $deletePaths,
            $baseReleaseId,
            $baseManifestSha256,
        );
    }

    public function packageId(): string
    {
        return $this->packageId;
    }

    public function packageType(): string
    {
        return $this->packageType;
    }

    public function releaseId(): string
    {
        return $this->releaseId;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public function minimumPhp(): string
    {
        return $this->minimumPhp;
    }

    /**
     * @return array<int, string>
     */
    public function requiredExtensions(): array
    {
        return $this->requiredExtensions;
    }

    public function composerLockSha256(): string
    {
        return $this->composerLockSha256;
    }

    public function expandedSize(): int
    {
        return $this->expandedSize;
    }

    public function fileCount(): int
    {
        return $this->fileCount;
    }

    /**
     * @return array<int, array{path: string, size: int, sha256: string}>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * @return array<int, string>
     */
    public function payloadFiles(): array
    {
        return $this->payloadFiles;
    }

    /**
     * @return array<int, string>
     */
    public function deletePaths(): array
    {
        return $this->deletePaths;
    }

    public function baseReleaseId(): ?string
    {
        return $this->baseReleaseId;
    }

    public function baseManifestSha256(): ?string
    {
        return $this->baseManifestSha256;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function assertStringField(array $manifest, string $field, string $expected): void
    {
        if (!isset($manifest[$field]) || !is_string($manifest[$field]) || mb_trim($manifest[$field]) !== $expected) {
            throw new RuntimeException($field . ' must be ' . $expected . '.');
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function readStringField(array $manifest, string $field): string
    {
        if (!isset($manifest[$field]) || !is_string($manifest[$field]) || mb_trim($manifest[$field]) === '') {
            throw new RuntimeException($field . ' is required and must be a non-empty string.');
        }

        return mb_trim($manifest[$field]);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function readIdField(array $manifest, string $field): string
    {
        $value = self::readStringField($manifest, $field);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,63}$/', $value) !== 1) {
            throw new RuntimeException($field . ' contains invalid characters.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function optionalIdField(array $manifest, string $field): ?string
    {
        if (!array_key_exists($field, $manifest) || $manifest[$field] === null || $manifest[$field] === '') {
            return null;
        }

        if (!is_string($manifest[$field])) {
            throw new RuntimeException($field . ' must be a string when present.');
        }

        $value = mb_trim($manifest[$field]);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,63}$/', $value) !== 1) {
            throw new RuntimeException($field . ' contains invalid characters.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<int, string>
     */
    private static function readStringListField(array $manifest, string $field): array
    {
        if (!isset($manifest[$field]) || !is_array($manifest[$field])) {
            throw new RuntimeException($field . ' must be an array of strings.');
        }

        $values = [];
        foreach ($manifest[$field] as $value) {
            if (!is_string($value) || mb_trim($value) === '') {
                throw new RuntimeException($field . ' contains an invalid entry.');
            }

            $values[] = mb_trim($value);
        }

        if (count($values) !== count(array_unique($values))) {
            throw new RuntimeException($field . ' contains duplicate entries.');
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function readHexHashField(array $manifest, string $field): string
    {
        $value = self::readStringField($manifest, $field);
        if (preg_match('/^[a-f0-9]{64}$/i', $value) !== 1) {
            throw new RuntimeException($field . ' must be a SHA-256 hex string.');
        }

        return mb_strtolower($value);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function optionalHexHashField(array $manifest, string $field): ?string
    {
        if (!array_key_exists($field, $manifest) || $manifest[$field] === null || $manifest[$field] === '') {
            return null;
        }

        if (!is_string($manifest[$field]) || preg_match('/^[a-f0-9]{64}$/i', $manifest[$field]) !== 1) {
            throw new RuntimeException($field . ' must be a SHA-256 hex string when present.');
        }

        return mb_strtolower(mb_trim($manifest[$field]));
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function readIntField(array $manifest, string $field): int
    {
        if (!isset($manifest[$field]) || !is_int($manifest[$field])) {
            throw new RuntimeException($field . ' must be an integer.');
        }

        if ($manifest[$field] < 0) {
            throw new RuntimeException($field . ' must not be negative.');
        }

        return $manifest[$field];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<int, array{path: string, size: int, sha256: string}>
     */
    private static function readFileRecords(array $manifest, ReleasePathPolicy $pathPolicy): array
    {
        if (!isset($manifest['files']) || !is_array($manifest['files'])) {
            throw new RuntimeException('files must be an array of file records.');
        }

        $files = [];
        $seenPaths = [];
        foreach ($manifest['files'] as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('files contains a non-object entry.');
            }

            $path = self::readStringField($entry, 'path');
            $normalizedPath = $pathPolicy->normalize($path);
            $lowerPath = mb_strtolower($normalizedPath);
            if (isset($seenPaths[$lowerPath])) {
                throw new RuntimeException('files contains a duplicate or case-colliding path: ' . $normalizedPath);
            }
            $seenPaths[$lowerPath] = true;

            $size = self::readIntField($entry, 'size');
            $sha256 = self::readHexHashField($entry, 'sha256');

            $files[] = [
                'path' => $normalizedPath,
                'size' => $size,
                'sha256' => $sha256,
            ];
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<int, string>
     */
    private static function readPathList(array $manifest, string $field, ReleasePathPolicy $pathPolicy): array
    {
        if (!isset($manifest[$field])) {
            return [];
        }

        if (!is_array($manifest[$field])) {
            throw new RuntimeException($field . ' must be an array of paths.');
        }

        $paths = [];
        $seenPaths = [];
        foreach ($manifest[$field] as $entry) {
            if (!is_string($entry) || mb_trim($entry) === '') {
                throw new RuntimeException($field . ' contains an invalid path entry.');
            }

            $normalizedPath = $pathPolicy->ensureAllowedPayloadPath($entry);
            $lowerPath = mb_strtolower($normalizedPath);
            if (isset($seenPaths[$lowerPath])) {
                throw new RuntimeException($field . ' contains a duplicate or case-colliding path: ' . $normalizedPath);
            }
            $seenPaths[$lowerPath] = true;
            $paths[] = $normalizedPath;
        }

        return $paths;
    }
}
