<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\ReleaseManifest;
use SparkInsight\Service\ReleasePackageIntakeService;

final class ReleasePackageIntakeServiceTest extends TestCase
{
    public function testIntakeStoresPackageAndSeedsState(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-intake-' . bin2hex(random_bytes(8));
        mkdir($root);

        $uploadedPackage = $root . '/source.zip';
        $publicKeyPath = $root . '/trusted-release-key.pub';
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        file_put_contents($publicKeyPath, $publicKey);

        $manifest = [
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
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached($manifestJson, $secretKey);

        $zip = new \ZipArchive();
        $zip->open($uploadedPackage, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $manifestJson);
        $zip->addFromString('manifest.sig', $signature);
        $zip->addFromString('payload/src/Controller/HomeController.php', 'hello');
        $zip->addFromString('payload/templates/home.php', 'goodbye');
        $zip->close();

        try {
            $service = new ReleasePackageIntakeService($root);
            $result = $service->intake($uploadedPackage, $publicKeyPath, 'package-001');

            $this->assertSame('package-001', $result['operation_id']);
            $this->assertFileExists($result['package_path']);
            $this->assertSame('package-001', $result['manifest']->packageId());
            $this->assertFileExists($root . '/.deploy/state/package-001.json');
            $this->assertFileExists($root . '/.deploy/logs/package-001.jsonl');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testIntakeThrowsWhenUploadedPackageMissing(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-intake-' . bin2hex(random_bytes(8));
        mkdir($root);
        $publicKeyPath = $root . '/trusted-release-key.pub';
        file_put_contents($publicKeyPath, 'key');

        try {
            $service = new ReleasePackageIntakeService($root);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Uploaded package file not found:');
            $service->intake($root . '/missing.zip', $publicKeyPath, 'missing-001');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testIntakeThrowsWhenTrustedPublicKeyIsEmpty(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-intake-' . bin2hex(random_bytes(8));
        mkdir($root);

        $uploadedPackage = $root . '/source.zip';
        $publicKeyPath = $root . '/trusted-release-key.pub';
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);

        $manifest = [
            'format' => ReleaseManifest::FORMAT,
            'application' => ReleaseManifest::APPLICATION,
            'package_id' => 'package-empty-key',
            'package_type' => 'full',
            'release_id' => 'release-empty-key',
            'created_at' => date(DATE_ATOM),
            'minimum_php' => '8.1.0',
            'required_extensions' => ['json'],
            'composer_lock_sha256' => str_repeat('a', 64),
            'expanded_size' => 5,
            'file_count' => 1,
            'files' => [
                ['path' => 'src/App.php', 'size' => 5, 'sha256' => hash('sha256', 'hello')],
            ],
            'payload_files' => ['src/App.php'],
            'delete' => [],
        ];
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached($manifestJson, $secretKey);

        $zip = new \ZipArchive();
        $zip->open($uploadedPackage, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $manifestJson);
        $zip->addFromString('manifest.sig', $signature);
        $zip->addFromString('payload/src/App.php', 'hello');
        $zip->close();

        file_put_contents($publicKeyPath, '');

        try {
            $service = new ReleasePackageIntakeService($root);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Trusted release public key file is empty:');
            $service->intake($uploadedPackage, $publicKeyPath, 'empty-key-001');
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