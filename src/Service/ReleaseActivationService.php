<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use RuntimeException;

final class ReleaseActivationService
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

    /**
     * @return array{current: string, previous: ?string, operation_id: string|null}
     */
    public function activate(string $releaseId, ?string $operationId = null): array
    {
        $releaseRoot = rtrim($this->deployRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.deploy' . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . $releaseId;
        return $this->activateFromRoot($releaseId, $releaseRoot, $operationId);
    }

    /**
     * @return array{current: string, previous: ?string, operation_id: string|null}
     */
    public function activateFromRoot(string $releaseId, string $releaseRoot, ?string $operationId = null): array
    {
        $entrypoint = $releaseRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($entrypoint)) {
            throw new RuntimeException('Release entrypoint does not exist: ' . $entrypoint);
        }

        $pointer = $this->pointerStore->read();
        $previous = $pointer['current'];
        $this->pointerStore->write($releaseId, $previous, $operationId);

        return [
            'current' => $releaseId,
            'previous' => $previous,
            'operation_id' => $operationId,
        ];
    }
}