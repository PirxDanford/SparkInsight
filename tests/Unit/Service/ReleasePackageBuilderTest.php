<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\ReleasePackageBuilder;
use SparkInsight\Service\ReleaseManifest;

final class ReleasePackageBuilderTest extends TestCase
{
    private function invokePrivate(ReleasePackageBuilder $builder, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($builder, $method);

        return $reflection->invoke($builder, ...$args);
    }

    public function testBuildsSignedFullPackage(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root . '/src', 0777, true);
        mkdir($root . '/templates', 0777, true);
        file_put_contents($root . '/composer.lock', '{}');
        file_put_contents($root . '/src/App.php', '<?php return 1;');
        file_put_contents($root . '/templates/home.php', '<p>home</p>');

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $privateKeyPath = $root . '/private.key';
        file_put_contents($privateKeyPath, $secretKey);

        try {
            $builder = new ReleasePackageBuilder($root, ['src', 'templates', 'composer.lock']);
            $result = $builder->buildFullPackage($root . '/release.zip', $privateKeyPath, ['json', 'openssl']);

            $this->assertFileExists($result['package_path']);
            $this->assertFileExists($result['manifest_path']);
            $manifest = json_decode($result['manifest_json'], true);
            $this->assertSame(ReleaseManifest::FORMAT, $manifest['format']);
            $this->assertSame(['composer.lock', 'src/App.php', 'templates/home.php'], $manifest['payload_files']);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testBuildsPatchPackageWithDeletes(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        $baseRoot = $root . '/base';
        $targetRoot = $root . '/target';
        mkdir($baseRoot, 0777, true);
        mkdir($targetRoot, 0777, true);
        mkdir($baseRoot . '/src', 0777, true);
        mkdir($targetRoot . '/src', 0777, true);
        mkdir($baseRoot . '/public', 0777, true);
        mkdir($targetRoot . '/public', 0777, true);
        file_put_contents($baseRoot . '/composer.lock', '{}');
        file_put_contents($targetRoot . '/composer.lock', '{}');
        file_put_contents($baseRoot . '/public/index.php', '<?php echo 1;');
        file_put_contents($targetRoot . '/public/index.php', '<?php echo 1;');
        file_put_contents($baseRoot . '/src/App.php', '<?php return 1;');
        file_put_contents($targetRoot . '/src/App.php', '<?php return 2;');
        file_put_contents($targetRoot . '/src/NewThing.php', '<?php return 3;');

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $privateKeyPath = $root . '/private.key';
        file_put_contents($privateKeyPath, $secretKey);

        try {
            $builder = new ReleasePackageBuilder($targetRoot, ['src', 'public', 'composer.lock']);
            $result = $builder->buildPatchPackage($baseRoot, $root . '/patch.zip', $privateKeyPath, ['json', 'openssl']);

            $manifest = json_decode($result['manifest_json'], true);
            $this->assertSame('patch', $manifest['package_type']);
            $this->assertContains('src/App.php', $manifest['payload_files']);
            $this->assertContains('src/NewThing.php', $manifest['payload_files']);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testBuildsPatchPackageFromBasePackageArtifact(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        $baseRoot = $root . '/base';
        $targetRoot = $root . '/target';
        mkdir($baseRoot, 0777, true);
        mkdir($targetRoot, 0777, true);
        mkdir($baseRoot . '/src', 0777, true);
        mkdir($targetRoot . '/src', 0777, true);
        mkdir($baseRoot . '/public', 0777, true);
        mkdir($targetRoot . '/public', 0777, true);
        file_put_contents($baseRoot . '/composer.lock', '{}');
        file_put_contents($targetRoot . '/composer.lock', '{}');
        file_put_contents($baseRoot . '/public/index.php', '<?php echo 1;');
        file_put_contents($targetRoot . '/public/index.php', '<?php echo 1;');
        file_put_contents($baseRoot . '/src/App.php', '<?php return 1;');
        file_put_contents($targetRoot . '/src/App.php', '<?php return 2;');

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $privateKeyPath = $root . '/private.key';
        file_put_contents($privateKeyPath, $secretKey);

        try {
            $baseBuilder = new ReleasePackageBuilder($baseRoot, ['src', 'public', 'composer.lock']);
            $basePackageResult = $baseBuilder->buildFullPackage($root . '/base-full.zip', $privateKeyPath, ['json', 'openssl']);

            $targetBuilder = new ReleasePackageBuilder($targetRoot, ['src', 'public', 'composer.lock']);
            $patchResult = $targetBuilder->buildPatchPackage(null, $root . '/patch-from-artifact.zip', $privateKeyPath, ['json', 'openssl'], $basePackageResult['package_path']);

            $manifest = json_decode($patchResult['manifest_json'], true);
            $this->assertSame('patch', $manifest['package_type']);
            $this->assertContains('src/App.php', $manifest['payload_files']);
            $this->assertNotContains('public/index.php', $manifest['payload_files']);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testBuildPatchPackageFailsWhenBaseRootMissing(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        file_put_contents($root . '/composer.lock', '{}');

        try {
            $builder = new ReleasePackageBuilder($root, ['composer.lock']);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Patch base root does not exist');
            $builder->buildPatchPackage($root . '/missing', $root . '/patch.zip', $root . '/private.key');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testBuildPatchPackageFailsWhenBaseRootMissingComposerLock(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        $baseRoot = $root . '/base';
        mkdir($baseRoot . '/public', 0777, true);
        mkdir($baseRoot . '/src', 0777, true);
        file_put_contents($root . '/composer.lock', '{}');

        try {
            $builder = new ReleasePackageBuilder($root, ['composer.lock']);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('missing composer.lock');
            $builder->buildPatchPackage($baseRoot, $root . '/patch.zip', $root . '/private.key');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testBuildPatchPackageFailsWhenBaseRootMissingRequiredDirectories(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        $baseRoot = $root . '/base';
        mkdir($baseRoot, 0777, true);
        file_put_contents($baseRoot . '/composer.lock', '{}');
        file_put_contents($root . '/composer.lock', '{}');

        try {
            $builder = new ReleasePackageBuilder($root, ['composer.lock']);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('missing expected release directories');
            $builder->buildPatchPackage($baseRoot, $root . '/patch.zip', $root . '/private.key');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testBuildPatchPackageFailsWhenBasePackageFileIsMissing(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        file_put_contents($root . '/composer.lock', '{}');

        try {
            $builder = new ReleasePackageBuilder($root, ['composer.lock']);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Patch base package/manifest file not found');
            $builder->buildPatchPackage(null, $root . '/patch.zip', $root . '/private.key', ['json'], $root . '/missing-base.json');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testBuildPatchPackageFailsWhenBaseManifestFileIsEmpty(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/composer.lock', '{}');
        file_put_contents($root . '/src/App.php', '<?php return 1;');
        $baseManifestPath = $root . '/base.manifest.json';
        file_put_contents($baseManifestPath, '');

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $privateKeyPath = $root . '/private.key';
        file_put_contents($privateKeyPath, $secretKey);

        try {
            $builder = new ReleasePackageBuilder($root, ['src', 'composer.lock']);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Base manifest file is empty');
            $builder->buildPatchPackage(null, $root . '/patch.zip', $privateKeyPath, ['json'], $baseManifestPath);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testBuildPatchPackageFailsWhenBaseZipCannotBeOpened(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/composer.lock', '{}');
        file_put_contents($root . '/src/App.php', '<?php return 1;');

        $baseZipPath = $root . '/base.zip';
        file_put_contents($baseZipPath, 'not-a-zip');

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $privateKeyPath = $root . '/private.key';
        file_put_contents($privateKeyPath, $secretKey);

        try {
            $builder = new ReleasePackageBuilder($root, ['src', 'composer.lock']);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not open base package ZIP');
            $builder->buildPatchPackage(null, $root . '/patch.zip', $privateKeyPath, ['json'], $baseZipPath);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testBuildPatchPackageFailsWhenBaseZipMissingManifestEntry(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/composer.lock', '{}');
        file_put_contents($root . '/src/App.php', '<?php return 1;');

        $baseZipPath = $root . '/base.zip';
        $zip = new \ZipArchive();
        $zip->open($baseZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.sig', 'sig-only');
        $zip->close();

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $privateKeyPath = $root . '/private.key';
        file_put_contents($privateKeyPath, $secretKey);

        try {
            $builder = new ReleasePackageBuilder($root, ['src', 'composer.lock']);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('does not contain manifest.json');
            $builder->buildPatchPackage(null, $root . '/patch.zip', $privateKeyPath, ['json'], $baseZipPath);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testDetermineMinimumPhpVersionCoversFallbackAndParsingBranches(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $builder = new ReleasePackageBuilder($root, []);

            $this->assertSame('8.1.0', $this->invokePrivate($builder, 'determineMinimumPhpVersion'));

            file_put_contents($root . '/composer.json', '{invalid-json');
            $this->assertSame('8.1.0', $this->invokePrivate($builder, 'determineMinimumPhpVersion'));

            file_put_contents($root . '/composer.json', json_encode(['require' => ['php' => '']], JSON_THROW_ON_ERROR));
            $this->assertSame('8.1.0', $this->invokePrivate($builder, 'determineMinimumPhpVersion'));

            file_put_contents($root . '/composer.json', json_encode(['require' => ['php' => 'not-a-version']], JSON_THROW_ON_ERROR));
            $this->assertSame('8.1.0', $this->invokePrivate($builder, 'determineMinimumPhpVersion'));

            file_put_contents($root . '/composer.json', json_encode(['require' => ['php' => '^8.3 || >=8.2.4']], JSON_THROW_ON_ERROR));
            $this->assertSame('8.2.4', $this->invokePrivate($builder, 'determineMinimumPhpVersion'));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testResolveReleaseIdFromRootFallbackBranches(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $builder = new ReleasePackageBuilder($root, []);

            $separatorFallback = $this->invokePrivate($builder, 'resolveReleaseIdFromRoot', DIRECTORY_SEPARATOR);
            $this->assertStringStartsWith('release-', $separatorFallback);

            $invalidFallback = $this->invokePrivate($builder, 'resolveReleaseIdFromRoot', $root . '/bad release id');
            $this->assertStringStartsWith('release-', $invalidFallback);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testResolveReleaseIdFromRootKeepsValidBasename(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $builder = new ReleasePackageBuilder($root, []);
            $this->assertSame(basename($root), $this->invokePrivate($builder, 'resolveReleaseIdFromRoot', $root));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testReadBinaryFileThrowsWhenReadFailsForUnreadableStream(): void
    {
        if (!in_array('failingread', stream_get_wrappers(), true)) {
            stream_wrapper_register('failingread', FailingReadStreamWrapper::class);
        }

        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $builder = new ReleasePackageBuilder($root, []);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not read private key: failingread://private.key');
            $this->invokePrivate($builder, 'readBinaryFile', 'failingread://private.key', 'private key');
        } finally {
            restore_error_handler();
            $this->deleteDirectory($root);
        }
    }

    public function testHashFileReturnsNullWhenHashOperationFails(): void
    {
        if (!in_array('failingread', stream_get_wrappers(), true)) {
            stream_wrapper_register('failingread', FailingReadStreamWrapper::class);
        }

        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $builder = new ReleasePackageBuilder($root, []);
            set_error_handler(static fn (): bool => true);

            $this->assertNull($this->invokePrivate($builder, 'hashFile', 'failingread://composer.lock'));
        } finally {
            restore_error_handler();
            $this->deleteDirectory($root);
        }
    }

    public function testHashFileReturnsNullWhenFileIsMissing(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $builder = new ReleasePackageBuilder($root, []);
            $this->assertNull($this->invokePrivate($builder, 'hashFile', $root . '/missing.lock'));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testBuildPatchPackageFromManifestFileUsesNonZipBaseBranch(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        $targetRoot = $root . '/target';
        mkdir($targetRoot . '/src', 0777, true);
        file_put_contents($targetRoot . '/composer.lock', '{}');
        file_put_contents($targetRoot . '/src/App.php', '<?php return 2;');

        $baseManifestPath = $root . '/base.manifest.json';
        $baseManifest = [
            'format' => ReleaseManifest::FORMAT,
            'application' => ReleaseManifest::APPLICATION,
            'package_id' => 'package-base-001',
            'package_type' => 'full',
            'release_id' => 'release-base-001',
            'created_at' => '2026-08-08T16:00:00+00:00',
            'minimum_php' => '8.1.0',
            'required_extensions' => ['json'],
            'composer_lock_sha256' => str_repeat('a', 64),
            'expanded_size' => 14,
            'file_count' => 2,
            'files' => [
                ['path' => 'composer.lock', 'size' => 2, 'sha256' => hash('sha256', '{}')],
                ['path' => 'src/App.php', 'size' => 12, 'sha256' => hash('sha256', '<?php return 1;')],
            ],
            'payload_files' => ['composer.lock', 'src/App.php'],
            'delete' => [],
        ];
        file_put_contents($baseManifestPath, json_encode($baseManifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $privateKeyPath = $root . '/private.key';
        file_put_contents($privateKeyPath, $secretKey);

        try {
            $builder = new ReleasePackageBuilder($targetRoot, ['src', 'composer.lock']);
            $result = $builder->buildPatchPackage(null, $root . '/patch-from-manifest.zip', $privateKeyPath, ['json'], $baseManifestPath);

            $manifest = json_decode($result['manifest_json'], true);
            $this->assertSame('patch', $manifest['package_type']);
            $this->assertContains('src/App.php', $manifest['payload_files']);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testWritePackageFailsWhenOutputDirectoryCannotBeCreated(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        file_put_contents($root . '/blocked', 'file');

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $privateKeyPath = $root . '/private.key';
        file_put_contents($privateKeyPath, $secretKey);

        $manifest = [
            'format' => ReleaseManifest::FORMAT,
            'application' => ReleaseManifest::APPLICATION,
            'package_id' => 'package-dirfail-001',
            'package_type' => 'full',
            'release_id' => 'release-dirfail-001',
            'created_at' => '2026-08-09T00:00:00+00:00',
            'minimum_php' => '8.1.0',
            'required_extensions' => ['json'],
            'composer_lock_sha256' => str_repeat('a', 64),
            'expanded_size' => 0,
            'file_count' => 0,
            'files' => [],
            'payload_files' => [],
            'delete' => [],
        ];

        try {
            $builder = new ReleasePackageBuilder($root, []);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not create package output directory');
            $this->invokePrivate($builder, 'writePackage', $root . '/blocked/out.zip', $privateKeyPath, $manifest, [], []);
        } finally {
            restore_error_handler();
            $this->deleteDirectory($root);
        }
    }

    public function testWritePackageRemovesPartialZipOnPayloadAddFailure(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-builder-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $privateKeyPath = $root . '/private.key';
        file_put_contents($privateKeyPath, $secretKey);

        $manifest = [
            'format' => ReleaseManifest::FORMAT,
            'application' => ReleaseManifest::APPLICATION,
            'package_id' => 'package-failure-001',
            'package_type' => 'full',
            'release_id' => 'release-failure-001',
            'created_at' => '2026-08-09T00:00:00+00:00',
            'minimum_php' => '8.1.0',
            'required_extensions' => ['json'],
            'composer_lock_sha256' => str_repeat('a', 64),
            'expanded_size' => 0,
            'file_count' => 0,
            'files' => [],
            'payload_files' => [],
            'delete' => [],
        ];

        $packagePath = $root . '/broken.zip';
        $files = [['path' => 'missing/file.php', 'size' => 0, 'sha256' => str_repeat('0', 64)]];

        try {
            $builder = new ReleasePackageBuilder($root, []);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not add payload file to package ZIP: missing/file.php');
            $this->invokePrivate($builder, 'writePackage', $packagePath, $privateKeyPath, $manifest, $files, ['missing/file.php']);
        } finally {
            restore_error_handler();
            $this->assertFileDoesNotExist($packagePath);
            $this->deleteDirectory($root);
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

final class FailingReadStreamWrapper
{
    public $context;

    public function url_stat(string $path, int $flags): array
    {
        return [
            'dev' => 0,
            'ino' => 0,
            'mode' => 0100000,
            'nlink' => 1,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => 1,
            'atime' => time(),
            'mtime' => time(),
            'ctime' => time(),
            'blksize' => -1,
            'blocks' => -1,
        ];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }
}