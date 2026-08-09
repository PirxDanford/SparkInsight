<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use RuntimeException;

final class ReleasePathPolicy
{
    private const MAX_PATH_LENGTH = 240;

    /**
     * @var array<int, string>
     */
    private const RESERVED_EXACT = [
        '.env',
        '.deploy',
        '.htaccess',
        'index.php',
        'recovery.php',
        'manifest.json',
        'manifest.sig',
    ];

    /**
     * @var array<int, string>
     */
    private const RESERVED_PREFIXES = [
        '.deploy/',
    ];

    public function normalize(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '') {
            throw new RuntimeException('Path must not be empty.');
        }

        if (strlen($path) > self::MAX_PATH_LENGTH) {
            throw new RuntimeException('Path is too long.');
        }

        if ($this->isAbsolutePath($path)) {
            throw new RuntimeException('Absolute paths are not allowed: ' . $path);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new RuntimeException('Control characters are not allowed in paths.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Parent traversal and empty path segments are not allowed: ' . $path);
            }

            if (preg_match('/^[A-Za-z0-9._-]+$/', $segment) !== 1) {
                throw new RuntimeException('Invalid path segment: ' . $segment);
            }
        }

        if ($this->isReservedPath($path)) {
            throw new RuntimeException('Reserved deployment path is not allowed: ' . $path);
        }

        return $path;
    }

    public function ensureAllowedPayloadPath(string $path): string
    {
        $normalized = $this->normalize($path);

        if (str_starts_with($normalized, 'payload/')) {
            $normalized = substr($normalized, 8);
        }

        if ($this->isReservedPath($normalized)) {
            throw new RuntimeException('Package entry is reserved: ' . $normalized);
        }

        return $normalized;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:\\//', $path) === 1;
    }

    private function isReservedPath(string $path): bool
    {
        $lower = strtolower($path);

        if (in_array($lower, self::RESERVED_EXACT, true)) {
            return true;
        }

        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        if (preg_match('#(^|/)\.deploy(/|$)#', $lower) === 1) {
            return true;
        }

        return preg_match('#(^|/)\.env(/|$)#', $lower) === 1;
    }
}