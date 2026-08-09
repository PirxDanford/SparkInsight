<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\ReleaseSignatureVerifier;

final class ReleaseSignatureVerifierTest extends TestCase
{
    public function testVerifiesDetachedSignature(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium is not available in this environment.');
        }

        $message = '{"format":"sparkinsight-release-v1"}';
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $signature = sodium_crypto_sign_detached($message, $secretKey);

        $verifier = new ReleaseSignatureVerifier();

        $this->assertTrue($verifier->verifyDetached($message, $signature, $publicKey));
        $this->assertFalse($verifier->verifyDetached($message . 'tampered', $signature, $publicKey));
    }

    public function testVerifiesDetachedSignatureWithHexAndBase64KeyMaterial(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium is not available in this environment.');
        }

        $message = '{"format":"sparkinsight-release-v1"}';
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $signature = sodium_crypto_sign_detached($message, $secretKey);

        $verifier = new ReleaseSignatureVerifier();

        $this->assertTrue($verifier->verifyDetached($message, bin2hex($signature), base64_encode($publicKey)));
    }

    public function testRejectsInvalidSignatureMaterialLength(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium is not available in this environment.');
        }

        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $verifier = new ReleaseSignatureVerifier();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid signature length');
        $verifier->verifyDetached('msg', 'bad-signature', $publicKey);
    }

    public function testVerifiesFilesOnDisk(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium is not available in this environment.');
        }

        $tempDir = sys_get_temp_dir() . '/sparkinsight-signature-' . bin2hex(random_bytes(8));
        mkdir($tempDir);

        $manifestPath = $tempDir . '/manifest.json';
        $signaturePath = $tempDir . '/manifest.sig';
        $publicKeyPath = $tempDir . '/trusted-release-key.pub';

        try {
            $message = '{"format":"sparkinsight-release-v1"}';
            $keyPair = sodium_crypto_sign_keypair();
            $secretKey = sodium_crypto_sign_secretkey($keyPair);
            $publicKey = sodium_crypto_sign_publickey($keyPair);
            $signature = sodium_crypto_sign_detached($message, $secretKey);

            file_put_contents($manifestPath, $message);
            file_put_contents($signaturePath, $signature);
            file_put_contents($publicKeyPath, $publicKey);

            $verifier = new ReleaseSignatureVerifier();
            $this->assertTrue($verifier->verifyFiles($manifestPath, $signaturePath, $publicKeyPath));
        } finally {
            @unlink($manifestPath);
            @unlink($signaturePath);
            @unlink($publicKeyPath);
            @rmdir($tempDir);
        }
    }

    public function testVerifyFilesThrowsWhenManifestFileIsMissing(): void
    {
        $tempDir = sys_get_temp_dir() . '/sparkinsight-signature-' . bin2hex(random_bytes(8));
        mkdir($tempDir);

        $signaturePath = $tempDir . '/manifest.sig';
        $publicKeyPath = $tempDir . '/trusted-release-key.pub';

        try {
            file_put_contents($signaturePath, 'sig');
            file_put_contents($publicKeyPath, 'pub');

            $verifier = new ReleaseSignatureVerifier();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Manifest file not found');
            $verifier->verifyFiles($tempDir . '/manifest.json', $signaturePath, $publicKeyPath);
        } finally {
            @unlink($signaturePath);
            @unlink($publicKeyPath);
            @rmdir($tempDir);
        }
    }

    public function testVerifyDetachedRejectsEmptySignatureMaterial(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium is not available in this environment.');
        }

        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $verifier = new ReleaseSignatureVerifier();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Empty signature is not allowed');
        $verifier->verifyDetached('message', '', $publicKey);
    }

    public function testVerifyDetachedRejectsInvalidPublicKeyLength(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium is not available in this environment.');
        }

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $signature = sodium_crypto_sign_detached('message', $secretKey);

        $verifier = new ReleaseSignatureVerifier();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid public key length');
        $verifier->verifyDetached('message', $signature, 'abc');
    }

    public function testVerifyFilesThrowsWhenSignatureFileIsMissing(): void
    {
        $tempDir = sys_get_temp_dir() . '/sparkinsight-signature-' . bin2hex(random_bytes(8));
        mkdir($tempDir);
        $manifestPath = $tempDir . '/manifest.json';
        $publicKeyPath = $tempDir . '/trusted-release-key.pub';

        try {
            file_put_contents($manifestPath, '{}');
            file_put_contents($publicKeyPath, 'pub');

            $verifier = new ReleaseSignatureVerifier();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Signature file not found');
            $verifier->verifyFiles($manifestPath, $tempDir . '/manifest.sig', $publicKeyPath);
        } finally {
            @unlink($manifestPath);
            @unlink($publicKeyPath);
            @rmdir($tempDir);
        }
    }
}