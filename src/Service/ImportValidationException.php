<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use RuntimeException;

final class ImportValidationException extends RuntimeException
{
    private array $errors;

    public function __construct(array $errors)
    {
        parent::__construct('Import validation failed.');
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
