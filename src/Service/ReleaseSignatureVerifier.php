<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use RuntimeException;

final class ReleaseSignatureVerifier
{
    public function verifyDetached(string $message, string $signature, string $publicKey): bool
    {
        $verifyFunction = 'sodium_crypto_sign_verify_detached';
        if (!function_exists($verifyFunction)) {
            throw new RuntimeException('Sodium Ed25519 verification is unavailable.');
        }

        $signature = $this->decodeKeyMaterial($signature, 64, 'signature');
        $publicKey = $this->decodeKeyMaterial($publicKey, 32, 'public key');

        if ($signature === '' || $publicKey === '') {
            throw new RuntimeException('Signature and public key must not be empty.');
        }

        return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
    }

    public function verifyFiles(string $manifestPath, string $signaturePath, string $publicKeyPath): bool
    {
        $manifest = $this->readFile($manifestPath, 'manifest');
        $signature = $this->readFile($signaturePath, 'signature');
        $publicKey = $this->readFile($publicKeyPath, 'public key');

        return $this->verifyDetached($manifest, $signature, $publicKey);
    }

    private function readFile(string $path, string $label): string
    {
        if (!is_file($path)) {
            throw new RuntimeException(ucfirst($label) . ' file not found: ' . $path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Could not read ' . $label . ' file: ' . $path);
        }

        return $contents;
    }

    private function decodeKeyMaterial(string $value, int $expectedLength, string $label): string
    {
        if ($value === '') {
            throw new RuntimeException('Empty ' . $label . ' is not allowed.');
        }

        // Raw binary inputs are valid and must not be altered.
        if (mb_strlen($value, '8bit') === $expectedLength) {
            return $value;
        }

        $trimmed = mb_trim($value, " \t\n\r\0\x0B", '8bit');
        if ($trimmed === '') {
            throw new RuntimeException('Empty ' . $label . ' is not allowed.');
        }

        $decoded = null;
        if (preg_match('/^[A-Fa-f0-9]+$/', $trimmed) === 1 && mb_strlen($trimmed, '8bit') === $expectedLength * 2) {
            $hexDecoded = hex2bin($trimmed);
            if ($hexDecoded !== false) {
                $decoded = $hexDecoded;
            }
        } elseif (preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $trimmed) === 1) {
            $candidate = base64_decode($trimmed, true);
            if ($candidate !== false) {
                $decoded = $candidate;
            }
        }

        if ($decoded === null || mb_strlen($decoded, '8bit') !== $expectedLength) {
            throw new RuntimeException('Invalid ' . $label . ' length; expected ' . $expectedLength . ' bytes.');
        }

        return $decoded;
    }
}
