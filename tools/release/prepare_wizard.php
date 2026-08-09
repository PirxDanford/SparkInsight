<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$wizardRoot = $root . '/.deploy/wizard';

$requiredFiles = [
    'index.php',
    '.htaccess',
    'recovery.php',
    '.deploy/trusted-release-key.pub',
];

$requiredDirectories = [
    'init',
];

foreach ($requiredFiles as $relativePath) {
    $source = $root . '/' . $relativePath;
    if (!is_file($source)) {
        fwrite(STDERR, '[error] Required file not found: ' . $relativePath . PHP_EOL);
        if ($relativePath === '.deploy/trusted-release-key.pub') {
            fwrite(STDERR, '[hint] Run "composer release:keygen" first.' . PHP_EOL);
        }
        exit(1);
    }
}

foreach ($requiredDirectories as $relativePath) {
    $source = $root . '/' . $relativePath;
    if (!is_dir($source)) {
        fwrite(STDERR, '[error] Required directory not found: ' . $relativePath . PHP_EOL);
        exit(1);
    }
}

if (is_dir($wizardRoot)) {
    deleteDirectory($wizardRoot);
}

foreach ($requiredFiles as $relativePath) {
    $source = $root . '/' . $relativePath;
    $destination = $wizardRoot . '/' . $relativePath;
    $destinationDir = dirname($destination);

    if (!is_dir($destinationDir) && !mkdir($destinationDir, 0o777, true) && !is_dir($destinationDir)) {
        fwrite(STDERR, '[error] Failed to create directory: ' . $destinationDir . PHP_EOL);
        exit(1);
    }

    if (!copy($source, $destination)) {
        fwrite(STDERR, '[error] Failed to copy file: ' . $relativePath . PHP_EOL);
        exit(1);
    }
}

foreach ($requiredDirectories as $relativePath) {
    $source = $root . '/' . $relativePath;
    $destination = $wizardRoot . '/' . $relativePath;
    copyDirectory($source, $destination);
}

$token = bin2hex(random_bytes(16));
$tokenPath = $wizardRoot . '/.deploy/init-token.txt';
if (file_put_contents($tokenPath, $token, LOCK_EX) === false) {
    fwrite(STDERR, '[error] Failed to write init token file.' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, '[ok] Wizard FTP bundle prepared at .deploy/wizard' . PHP_EOL);
fwrite(STDOUT, '[ok] Upload the contents of .deploy/wizard to the web root (preserve paths).' . PHP_EOL);
fwrite(STDOUT, '[ok] Open /init?token=' . $token . ' on the target host to run first activation.' . PHP_EOL);

function deleteDirectory(string $directory): void
{
    $items = scandir($directory);
    if ($items === false) {
        throw new RuntimeException('Failed to scan directory: ' . $directory);
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . '/' . $item;
        if (is_dir($path)) {
            deleteDirectory($path);
            continue;
        }

        if (!unlink($path)) {
            throw new RuntimeException('Failed to remove file: ' . $path);
        }
    }

    if (!rmdir($directory)) {
        throw new RuntimeException('Failed to remove directory: ' . $directory);
    }
}

function copyDirectory(string $sourceDir, string $targetDir): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        $relativePath = mb_substr($item->getPathname(), mb_strlen(mb_rtrim($sourceDir, DIRECTORY_SEPARATOR)) + 1);
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $relativePath;

        if ($item->isDir()) {
            if (!is_dir($targetPath) && !mkdir($targetPath, 0o777, true) && !is_dir($targetPath)) {
                throw new RuntimeException('Failed to create directory: ' . $targetPath);
            }
            continue;
        }

        $targetPathDir = dirname($targetPath);
        if (!is_dir($targetPathDir) && !mkdir($targetPathDir, 0o777, true) && !is_dir($targetPathDir)) {
            throw new RuntimeException('Failed to create directory: ' . $targetPathDir);
        }

        if (!copy($item->getPathname(), $targetPath)) {
            throw new RuntimeException('Failed to copy file: ' . $relativePath);
        }
    }
}
