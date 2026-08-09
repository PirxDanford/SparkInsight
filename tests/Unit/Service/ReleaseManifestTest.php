<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\ReleaseManifest;

final class ReleaseManifestTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function baseFullManifest(): array
    {
        return [
            'format' => ReleaseManifest::FORMAT,
            'application' => ReleaseManifest::APPLICATION,
            'package_id' => 'package-001',
            'package_type' => 'full',
            'release_id' => 'release-001',
            'created_at' => '2026-08-08T16:00:00+00:00',
            'minimum_php' => '8.1.0',
            'required_extensions' => ['json', 'openssl', 'pdo'],
            'composer_lock_sha256' => str_repeat('a', 64),
            'expanded_size' => 12,
            'file_count' => 2,
            'files' => [
                ['path' => 'src/Controller/HomeController.php', 'size' => 5, 'sha256' => str_repeat('b', 64)],
                ['path' => 'templates/home.php', 'size' => 7, 'sha256' => str_repeat('c', 64)],
            ],
            'payload_files' => [
                'src/Controller/HomeController.php',
                'templates/home.php',
            ],
            'delete' => [],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function assertManifestThrows(array $manifest, string $messagePart): void
    {
        try {
            ReleaseManifest::fromArray($manifest);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($messagePart, $exception->getMessage());
        }
    }

    public function testParsesValidFullManifest(): void
    {
        $manifest = ReleaseManifest::fromArray($this->baseFullManifest());

        $this->assertSame('package-001', $manifest->packageId());
        $this->assertSame('full', $manifest->packageType());
        $this->assertSame('release-001', $manifest->releaseId());
        $this->assertSame('2026-08-08T16:00:00+00:00', $manifest->createdAt());
        $this->assertSame('8.1.0', $manifest->minimumPhp());
        $this->assertSame(['json', 'openssl', 'pdo'], $manifest->requiredExtensions());
        $this->assertSame(str_repeat('a', 64), $manifest->composerLockSha256());
        $this->assertSame(12, $manifest->expandedSize());
        $this->assertSame(2, $manifest->fileCount());
        $this->assertCount(2, $manifest->files());
        $this->assertSame(['src/Controller/HomeController.php', 'templates/home.php'], $manifest->payloadFiles());
        $this->assertSame([], $manifest->deletePaths());
        $this->assertNull($manifest->baseReleaseId());
        $this->assertNull($manifest->baseManifestSha256());
    }

    public function testFromJsonRejectsNonObjectPayload(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must decode to an object');

        ReleaseManifest::fromJson('1');
    }

    public function testFromJsonRejectsInvalidJson(): void
    {
        $this->expectException(\JsonException::class);

        ReleaseManifest::fromJson('{bad-json');
    }

    public function testRejectsInvalidFormatAndApplication(): void
    {
        $manifest = $this->baseFullManifest();
        $manifest['format'] = 'wrong-format';
        $this->assertManifestThrows($manifest, 'format must be');

        $manifest = $this->baseFullManifest();
        $manifest['application'] = 'wrong/app';
        $this->assertManifestThrows($manifest, 'application must be');
    }

    public function testRejectsInvalidCoreFields(): void
    {
        $manifest = $this->baseFullManifest();
        $manifest['package_type'] = 'delta';
        $this->assertManifestThrows($manifest, 'package_type must be full or patch');

        $manifest = $this->baseFullManifest();
        $manifest['created_at'] = 'not-a-date';
        $this->assertManifestThrows($manifest, 'created_at must be a valid timestamp');

        $manifest = $this->baseFullManifest();
        $manifest['package_id'] = '@bad';
        $this->assertManifestThrows($manifest, 'package_id contains invalid characters');

        $manifest = $this->baseFullManifest();
        $manifest['release_id'] = '@bad';
        $this->assertManifestThrows($manifest, 'release_id contains invalid characters');

        $manifest = $this->baseFullManifest();
        $manifest['minimum_php'] = '   ';
        $this->assertManifestThrows($manifest, 'minimum_php is required');
    }

    public function testRejectsInvalidStringListsAndHashes(): void
    {
        $manifest = $this->baseFullManifest();
        $manifest['required_extensions'] = 'not-an-array';
        $this->assertManifestThrows($manifest, 'required_extensions must be an array of strings');

        $manifest = $this->baseFullManifest();
        $manifest['required_extensions'] = ['json', ''];
        $this->assertManifestThrows($manifest, 'required_extensions contains an invalid entry');

        $manifest = $this->baseFullManifest();
        $manifest['required_extensions'] = ['json', 'json'];
        $this->assertManifestThrows($manifest, 'required_extensions contains duplicate entries');

        $manifest = $this->baseFullManifest();
        $manifest['composer_lock_sha256'] = 'abc';
        $this->assertManifestThrows($manifest, 'composer_lock_sha256 must be a SHA-256 hex string');
    }

    public function testRejectsInvalidNumericFields(): void
    {
        $manifest = $this->baseFullManifest();
        $manifest['expanded_size'] = '12';
        $this->assertManifestThrows($manifest, 'expanded_size must be an integer');

        $manifest = $this->baseFullManifest();
        $manifest['expanded_size'] = -1;
        $this->assertManifestThrows($manifest, 'expanded_size must not be negative');

        $manifest = $this->baseFullManifest();
        $manifest['file_count'] = 3;
        $this->assertManifestThrows($manifest, 'file_count does not match files list length');

        $manifest = $this->baseFullManifest();
        $manifest['expanded_size'] = 999;
        $this->assertManifestThrows($manifest, 'expanded_size does not match sum of file sizes');
    }

    public function testRejectsInvalidFilesAndPayloadCollections(): void
    {
        $manifest = $this->baseFullManifest();
        $manifest['files'] = 'nope';
        $this->assertManifestThrows($manifest, 'files must be an array of file records');

        $manifest = $this->baseFullManifest();
        $manifest['files'] = ['bad-entry'];
        $this->assertManifestThrows($manifest, 'files contains a non-object entry');

        $manifest = $this->baseFullManifest();
        $manifest['files'] = [
            ['path' => 'src/Foo.php', 'size' => 1, 'sha256' => str_repeat('d', 64)],
            ['path' => 'src/foo.php', 'size' => 1, 'sha256' => str_repeat('e', 64)],
        ];
        $manifest['payload_files'] = ['src/Foo.php', 'src/foo.php'];
        $manifest['file_count'] = 2;
        $manifest['expanded_size'] = 2;
        $this->assertManifestThrows($manifest, 'duplicate or case-colliding path');

        $manifest = $this->baseFullManifest();
        $manifest['payload_files'] = 'not-an-array';
        $this->assertManifestThrows($manifest, 'payload_files must be an array of paths');

        $manifest = $this->baseFullManifest();
        $manifest['payload_files'] = [''];
        $this->assertManifestThrows($manifest, 'payload_files contains an invalid path entry');

        $manifest = $this->baseFullManifest();
        $manifest['payload_files'] = ['src/Foo.php', 'src/foo.php'];
        $manifest['files'] = [
            ['path' => 'src/Foo.php', 'size' => 1, 'sha256' => str_repeat('d', 64)],
            ['path' => 'src/foo.php', 'size' => 1, 'sha256' => str_repeat('e', 64)],
        ];
        $manifest['file_count'] = 2;
        $manifest['expanded_size'] = 2;
        $this->assertManifestThrows($manifest, 'duplicate or case-colliding path');
    }

    public function testPatchManifestRequiresBaseMetadata(): void
    {
        $manifest = $this->baseFullManifest();
        $manifest['package_type'] = 'patch';

        $this->assertManifestThrows($manifest, 'Patch packages require base_release_id and base_manifest_sha256');
    }

    public function testRejectsPayloadFilesOutsideFinalTarget(): void
    {
        $manifest = $this->baseFullManifest();
        $manifest['files'] = [
            ['path' => 'src/Controller/HomeController.php', 'size' => 5, 'sha256' => str_repeat('1', 64)],
        ];
        $manifest['expanded_size'] = 5;
        $manifest['file_count'] = 1;
        $manifest['payload_files'] = ['templates/home.php'];

        $this->assertManifestThrows($manifest, 'payload_files must be a subset of files');
    }

    public function testRejectsFullPackageDeleteAndPayloadMismatch(): void
    {
        $manifest = $this->baseFullManifest();
        $manifest['delete'] = ['old/file.php'];
        $this->assertManifestThrows($manifest, 'Full packages must not declare delete entries');

        $manifest = $this->baseFullManifest();
        $manifest['payload_files'] = ['src/Controller/HomeController.php'];
        $this->assertManifestThrows($manifest, 'Full packages must include every target file in payload_files');
    }

    public function testPatchRulesAndOptionalFields(): void
    {
        $manifest = $this->baseFullManifest();
        $manifest['package_type'] = 'patch';
        $manifest['base_release_id'] = 'base-001';
        $manifest['base_manifest_sha256'] = str_repeat('d', 64);
        $manifest['payload_files'] = [];
        $this->assertManifestThrows($manifest, 'Patch packages must contain at least one payload file');

        $manifest = $this->baseFullManifest();
        $manifest['package_type'] = 'patch';
        $manifest['base_release_id'] = 123;
        $manifest['base_manifest_sha256'] = str_repeat('d', 64);
        $this->assertManifestThrows($manifest, 'base_release_id must be a string when present');

        $manifest = $this->baseFullManifest();
        $manifest['package_type'] = 'patch';
        $manifest['base_release_id'] = '   ';
        $manifest['base_manifest_sha256'] = str_repeat('d', 64);
        $this->assertManifestThrows($manifest, 'Patch packages require base_release_id and base_manifest_sha256');

        $manifest = $this->baseFullManifest();
        $manifest['package_type'] = 'patch';
        $manifest['base_release_id'] = '@bad';
        $manifest['base_manifest_sha256'] = str_repeat('d', 64);
        $this->assertManifestThrows($manifest, 'base_release_id contains invalid characters');

        $manifest = $this->baseFullManifest();
        $manifest['package_type'] = 'patch';
        $manifest['base_release_id'] = 'base-001';
        $manifest['base_manifest_sha256'] = ['bad'];
        $this->assertManifestThrows($manifest, 'base_manifest_sha256 must be a SHA-256 hex string when present');
    }

    public function testRejectsDeletePayloadOverlap(): void
    {
        $manifest = $this->baseFullManifest();
        $manifest['package_type'] = 'patch';
        $manifest['base_release_id'] = 'base-001';
        $manifest['base_manifest_sha256'] = str_repeat('d', 64);
        $manifest['payload_files'] = ['src/Controller/HomeController.php'];
        $manifest['files'] = [
            ['path' => 'src/Controller/HomeController.php', 'size' => 5, 'sha256' => str_repeat('1', 64)],
        ];
        $manifest['file_count'] = 1;
        $manifest['expanded_size'] = 5;
        $manifest['delete'] = ['src/Controller/HomeController.php'];

        $this->assertManifestThrows($manifest, 'delete entries cannot overlap with payload_files');
    }

    public function testParsesValidPatchManifestAndNormalizesHashes(): void
    {
        $manifest = $this->baseFullManifest();
        $manifest['package_type'] = 'patch';
        $manifest['base_release_id'] = 'base-001';
        $manifest['base_manifest_sha256'] = strtoupper(str_repeat('d', 64));
        $manifest['composer_lock_sha256'] = strtoupper(str_repeat('e', 64));
        $manifest['files'] = [
            ['path' => 'src/Controller/HomeController.php', 'size' => 5, 'sha256' => strtoupper(str_repeat('1', 64))],
        ];
        $manifest['payload_files'] = ['src/Controller/HomeController.php'];
        $manifest['delete'] = ['templates/home.php'];
        $manifest['expanded_size'] = 5;
        $manifest['file_count'] = 1;

        $parsed = ReleaseManifest::fromArray($manifest);

        $this->assertSame(str_repeat('e', 64), $parsed->composerLockSha256());
        $this->assertSame(str_repeat('d', 64), $parsed->baseManifestSha256());
        $this->assertSame(str_repeat('1', 64), $parsed->files()[0]['sha256']);
    }

    public function testReadPathListReturnsEmptyWhenDeleteMissing(): void
    {
        $manifest = $this->baseFullManifest();
        unset($manifest['delete']);

        $parsed = ReleaseManifest::fromArray($manifest);

        $this->assertSame([], $parsed->deletePaths());
    }

    public function testRejectsDuplicateDeletePathsCaseInsensitive(): void
    {
        $manifest = $this->baseFullManifest();
        $manifest['package_type'] = 'patch';
        $manifest['base_release_id'] = 'base-001';
        $manifest['base_manifest_sha256'] = str_repeat('a', 64);
        $manifest['files'] = [
            ['path' => 'src/Foo.php', 'size' => 1, 'sha256' => str_repeat('b', 64)],
        ];
        $manifest['payload_files'] = ['src/Foo.php'];
        $manifest['delete'] = ['tmp/one.txt', 'TMP/one.txt'];
        $manifest['file_count'] = 1;
        $manifest['expanded_size'] = 1;

        $this->assertManifestThrows($manifest, 'delete contains a duplicate or case-colliding path');
    }
}