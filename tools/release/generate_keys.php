<?php

declare(strict_types=1);

if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, 'Sodium extension is required.' . PHP_EOL);
    exit(1);
}

$deployDir = __DIR__ . '/../../.deploy';
$privateKeyPath = $deployDir . '/release-private.key';
$publicKeyPath = $deployDir . '/trusted-release-key.pub';

if (!is_dir($deployDir) && !mkdir($deployDir, 0o700, true) && !is_dir($deployDir)) {
    fwrite(STDERR, 'Could not create .deploy directory.' . PHP_EOL);
    exit(1);
}

$keyPair = sodium_crypto_sign_keypair();
$privateKey = sodium_crypto_sign_secretkey($keyPair);
$publicKey = sodium_crypto_sign_publickey($keyPair);

if (file_put_contents($privateKeyPath, $privateKey) === false) {
    fwrite(STDERR, 'Could not write private key file.' . PHP_EOL);
    exit(1);
}

if (file_put_contents($publicKeyPath, $publicKey) === false) {
    fwrite(STDERR, 'Could not write public key file.' . PHP_EOL);
    exit(1);
}

echo 'Wrote .deploy/release-private.key and .deploy/trusted-release-key.pub' . PHP_EOL;
