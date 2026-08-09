<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\ReleaseManifest;
use SparkInsight\Service\ReleaseMaterializer;

final class ReleaseMaterializerTest extends TestCase
{
    public function testPatchMaterializationFromProjectRootDoesNotCopyDeployTreeRecursively(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-materializer-' . bin2hex(random_bytes(8));
        $stagingRoot = $root . '/.deploy/staging-releases';
        $releaseId = 'SparkInsight';
        $packagePath = $root . '/patch.zip';

        mkdir($root . '/src', 0777, true);
        mkdir($root . '/public', 0777, true);
        mkdir($root . '/.deploy/staging-releases/existing', 0777, true);
        file_put_contents($root . '/composer.lock', "{}\n");
        file_put_contents($root . '/public/index.php', "<?php\nreturn 1;\n");
        file_put_contents($root . '/src/App.php', "<?php\nreturn 1;\n");
        file_put_contents($root . '/.deploy/staging-releases/existing/sentinel.txt', 'do-not-copy');

        $manifest = [
            'format' => ReleaseManifest::FORMAT,
            'application' => ReleaseManifest::APPLICATION,
            'package_id' => 'package-' . bin2hex(random_bytes(4)),
            'package_type' => 'patch',
            'release_id' => $releaseId,
            'created_at' => date(DATE_ATOM),
            'minimum_php' => '8.1.0',
            'required_extensions' => ['json', 'zip', 'openssl', 'pdo', 'session', 'tokenizer'],
            'composer_lock_sha256' => hash_file('sha256', $root . '/composer.lock'),
            'expanded_size' => strlen("{}\n") + strlen("<?php\nreturn 1;\n") + strlen("<?php\nreturn 2;\n"),
            'file_count' => 3,
            'files' => [
                [
                    'path' => 'composer.lock',
                    'size' => filesize($root . '/composer.lock'),
                    'sha256' => hash_file('sha256', $root . '/composer.lock'),
                ],
                [
                    'path' => 'public/index.php',
                    'size' => filesize($root . '/public/index.php'),
                    'sha256' => hash_file('sha256', $root . '/public/index.php'),
                ],
                [
                    'path' => 'src/App.php',
                    'size' => strlen("<?php\nreturn 2;\n"),
                    'sha256' => hash('sha256', "<?php\nreturn 2;\n"),
                ],
            ],
            'payload_files' => ['src/App.php'],
            'delete' => [],
            'base_release_id' => 'base-release',
            'base_manifest_sha256' => str_repeat('a', 64),
        ];

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached($manifestJson, $secretKey);

        $zip = new \ZipArchive();
        $zip->open($packagePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $manifestJson);
        $zip->addFromString('manifest.sig', $signature);
        $zip->addFromString('payload/src/App.php', "<?php\nreturn 2;\n");
        $zip->close();

        try {
            $materializer = new ReleaseMaterializer();
            $result = $materializer->materialize($packagePath, $publicKey, $stagingRoot, $root);

            $releaseRoot = (string) $result['release_root'];
            $this->assertFileExists($releaseRoot . '/src/App.php');
            $this->assertFileExists($releaseRoot . '/public/index.php');
            $this->assertFileExists($releaseRoot . '/composer.lock');
            $this->assertDirectoryDoesNotExist($releaseRoot . '/.deploy');
            $this->assertStringContainsString('return 2;', (string) file_get_contents($releaseRoot . '/src/App.php'));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testMaterializePatchThrowsWhenBaseRootMissing(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-materializer-' . bin2hex(random_bytes(8));
        $packagePath = $root . '/patch.zip';
        mkdir($root, 0777, true);

        $manifest = [
            'format' => ReleaseManifest::FORMAT,
            'application' => ReleaseManifest::APPLICATION,
            'package_id' => 'package-' . bin2hex(random_bytes(4)),
            'package_type' => 'patch',
            'release_id' => 'release-' . bin2hex(random_bytes(3)),
            'created_at' => date(DATE_ATOM),
            'minimum_php' => '8.1.0',
            'required_extensions' => ['json'],
            'composer_lock_sha256' => str_repeat('a', 64),
            'expanded_size' => 1,
            'file_count' => 1,
            'files' => [
                ['path' => 'src/App.php', 'size' => 1, 'sha256' => hash('sha256', 'x')],
            ],
            'payload_files' => ['src/App.php'],
            'delete' => [],
            'base_release_id' => 'base-release',
            'base_manifest_sha256' => str_repeat('a', 64),
        ];

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached($manifestJson, $secretKey);

        $zip = new \ZipArchive();
        $zip->open($packagePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $manifestJson);
        $zip->addFromString('manifest.sig', $signature);
        $zip->addFromString('payload/src/App.php', 'x');
        $zip->close();

        try {
            $materializer = new ReleaseMaterializer();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Patch packages require an existing base release directory.');
            $materializer->materialize($packagePath, $publicKey, $root . '/staging', null);
        } finally {
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
