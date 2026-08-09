<?php

declare(strict_types=1);

use SparkInsight\Service\CurrentPointerStore;
use SparkInsight\Service\RecoveryKeyManager;
use SparkInsight\Service\ReleaseRootPublisher;

require __DIR__ . '/vendor/autoload.php';

$projectRoot = __DIR__;
$pointerStore = new CurrentPointerStore($projectRoot);
$recoveryKeyManager = new RecoveryKeyManager($projectRoot);
$rootPublisher = new ReleaseRootPublisher();

$newKey = $recoveryKeyManager->ensureInitialized();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "SparkInsight recovery endpoint\n";
    echo "Submit POST with key=<recovery-key> to revert to previous release.\n";
    if ($newKey !== null) {
        echo "Generated one-time recovery key (store securely): {$newKey}\n";
    }

    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Method not allowed. Use POST.';
    exit(1);
}

$providedKey = isset($_POST['key']) ? trim((string) $_POST['key']) : '';
if ($providedKey === '' || !$recoveryKeyManager->verify($providedKey)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Recovery key verification failed.';
    exit(1);
}

$pointer = $pointerStore->read();
$previous = $pointer['previous'];
$current = $pointer['current'];

if (!is_string($previous) || $previous === '') {
    http_response_code(409);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'No previous release is available for recovery.';
    exit(1);
}

$pointerStore->write($previous, $current, 'manual-recovery-' . date('YmdHis'));

$previousReleaseRoot = $projectRoot . DIRECTORY_SEPARATOR . '.deploy' . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . $previous;
$rootPublisher->publish($previousReleaseRoot, $projectRoot);

header('Content-Type: text/plain; charset=UTF-8');
echo 'Recovery completed. Current release is now: ' . $previous;
