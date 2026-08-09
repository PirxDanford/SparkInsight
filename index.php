<?php

declare(strict_types=1);

$pointerPath = __DIR__ . '/.deploy/current.json';

$legacyEntrypoint = __DIR__ . '/public/index.php';
if (is_file($legacyEntrypoint)) {
    require $legacyEntrypoint;

    return;
}

if (is_file($pointerPath)) {
    $decoded = json_decode((string) file_get_contents($pointerPath), true);
    if (is_array($decoded) && isset($decoded['current']) && is_string($decoded['current']) && $decoded['current'] !== '') {
        $releaseEntrypoint = __DIR__ . '/.deploy/releases/' . $decoded['current'] . '/public/index.php';
        if (is_file($releaseEntrypoint)) {
            require $releaseEntrypoint;

            return;
        }
    }
}

$initEntrypoint = __DIR__ . '/init/index.php';
if (is_file($initEntrypoint)) {
    header('Location: /init/');
    exit(0);
}

http_response_code(503);
header('Content-Type: text/plain; charset=UTF-8');
echo 'SparkInsight is not initialized yet. Upload the wizard bundle and open /init.';
