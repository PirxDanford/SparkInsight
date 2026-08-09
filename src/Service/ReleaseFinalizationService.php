<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use RuntimeException;

final class ReleaseFinalizationService
{
    private readonly string $deployRoot;

    private readonly CurrentPointerStore $pointerStore;

    public function __construct(
        string $deployRoot,
        ?CurrentPointerStore $pointerStore = null,
    ) {
        $this->deployRoot = $deployRoot;
        $this->pointerStore = $pointerStore ?? new CurrentPointerStore($deployRoot);
    }

    public function finalize(?string $operationId = null): array
    {
        $pointer = $this->pointerStore->read();
        $current = $pointer['current'];
        $previous = $pointer['previous'];

        if ($current === null) {
            throw new RuntimeException('No active release exists to finalize.');
        }

        if ($previous !== null) {
            $previousRoot = rtrim($this->deployRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.deploy' . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . $previous;
            $this->removeDirectory($previousRoot);
        }

        $this->pointerStore->write($current, null, $operationId);

        return [
            'current' => $current,
            'previous' => null,
            'operation_id' => $operationId,
        ];
    }

    private function removeDirectory(string $directory): void
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