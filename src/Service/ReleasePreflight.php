<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use RuntimeException;

final class ReleasePreflight
{
    /**
     * @return array<int, string>
     */
    public function run(string $releaseRoot, ReleaseManifest $manifest): array
    {
        $problems = [];

        if (version_compare(PHP_VERSION, $manifest->minimumPhp(), '<')) {
            $problems[] = 'PHP version ' . PHP_VERSION . ' is below minimum ' . $manifest->minimumPhp();
        }

        foreach ($manifest->requiredExtensions() as $extension) {
            if (!extension_loaded($extension)) {
                $problems[] = 'Missing required extension: ' . $extension;
            }
        }

        $entrypoint = rtrim($releaseRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($entrypoint)) {
            $problems[] = 'Missing application entrypoint: ' . $entrypoint;
        }

        $autoload = rtrim($releaseRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($autoload)) {
            $problems[] = 'Missing Composer autoload file: ' . $autoload;
        }

        $composerLock = rtrim($releaseRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.lock';
        if (!is_file($composerLock)) {
            $problems[] = 'Missing composer.lock: ' . $composerLock;
        }

        if (!is_dir(rtrim($releaseRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations')) {
            $problems[] = 'Missing migration directory.';
        }

        $freeDisk = @disk_free_space($releaseRoot);
        if (is_float($freeDisk) ? $freeDisk <= 0 : $freeDisk === false || $freeDisk <= 0) {
            $problems[] = 'Unable to determine free disk space.';
        }

        if ($problems !== []) {
            throw new RuntimeException(implode(PHP_EOL, $problems));
        }

        return $problems;
    }
}