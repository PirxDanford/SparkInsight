<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\ReleasePackageInspector;
use SparkInsight\Service\ReleaseManifest;

final class ReleasePackageInspectorTest extends TestCase
{
    public function testInspectsValidPackageArchive(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $tempDir = sys_get_temp_dir() . '/sparkinsight-package-' . bin2hex(random_bytes(8));
        mkdir($tempDir);

        $packagePath = $tempDir . '/release.zip';
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);

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
        $zip->open($packagePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $manifestJson);
        $zip->addFromString('manifest.sig', $signature);
        $zip->addFromString('payload/src/Controller/HomeController.php', 'hello');
        $zip->addFromString('payload/templates/home.php', 'goodbye');
        $zip->close();

        try {
            $inspector = new ReleasePackageInspector();
            $result = $inspector->inspect($packagePath, $publicKey);

            $this->assertSame('package-001', $result->packageId());
            $this->assertSame(['src/Controller/HomeController.php', 'templates/home.php'], $result->payloadFiles());
        } finally {
            @unlink($packagePath);
            @rmdir($tempDir);
        }
    }

    public function testRejectsUnexpectedPackageEntry(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $tempDir = sys_get_temp_dir() . '/sparkinsight-package-' . bin2hex(random_bytes(8));
        mkdir($tempDir);

        $packagePath = $tempDir . '/release.zip';
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);

        $manifest = [
            'format' => ReleaseManifest::FORMAT,
            'application' => ReleaseManifest::APPLICATION,
            'package_id' => 'package-002',
            'package_type' => 'full',
            'release_id' => 'release-002',
            'created_at' => '2026-08-08T16:00:00+00:00',
            'minimum_php' => '8.1.0',
            'required_extensions' => ['json', 'openssl', 'pdo'],
            'composer_lock_sha256' => str_repeat('d', 64),
            'expanded_size' => 5,
            'file_count' => 1,
            'files' => [
                ['path' => 'src/Controller/HomeController.php', 'size' => 5, 'sha256' => str_repeat('e', 64)],
            ],
            'payload_files' => [
                'src/Controller/HomeController.php',
            ],
            'delete' => [],
        ];
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached($manifestJson, $secretKey);

        $zip = new \ZipArchive();
        $zip->open($packagePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $manifestJson);
        $zip->addFromString('manifest.sig', $signature);
        $zip->addFromString('payload/src/Controller/HomeController.php', 'hello');
        $zip->addFromString('payload/src/Controller/Unexpected.php', 'oops');
        $zip->close();

        try {
            $inspector = new ReleasePackageInspector();

            $this->expectException(\RuntimeException::class);
            $inspector->inspect($packagePath, $publicKey);
        } finally {
            @unlink($packagePath);
            @rmdir($tempDir);
        }
    }

    public function testInspectThrowsWhenManifestSignatureEntryMissing(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $tempDir = sys_get_temp_dir() . '/sparkinsight-package-' . bin2hex(random_bytes(8));
        mkdir($tempDir);

        $packagePath = $tempDir . '/release.zip';
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);

        $manifest = [
            'format' => ReleaseManifest::FORMAT,
            'application' => ReleaseManifest::APPLICATION,
            'package_id' => 'package-003',
            'package_type' => 'full',
            'release_id' => 'release-003',
            'created_at' => '2026-08-08T16:00:00+00:00',
            'minimum_php' => '8.1.0',
            'required_extensions' => ['json', 'openssl', 'pdo'],
            'composer_lock_sha256' => str_repeat('f', 64),
            'expanded_size' => 5,
            'file_count' => 1,
            'files' => [
                ['path' => 'src/Controller/HomeController.php', 'size' => 5, 'sha256' => str_repeat('e', 64)],
            ],
            'payload_files' => [
                'src/Controller/HomeController.php',
            ],
            'delete' => [],
        ];
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $zip = new \ZipArchive();
        $zip->open($packagePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $manifestJson);
        $zip->addFromString('payload/src/Controller/HomeController.php', 'hello');
        $zip->close();

        try {
            $inspector = new ReleasePackageInspector();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Missing required ZIP entry: manifest.sig');
            $inspector->inspect($packagePath, $publicKey);
        } finally {
            @unlink($packagePath);
            @rmdir($tempDir);
        }
    }
}