<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use Doctrine\DBAL\Connection;

final class ContentImportService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function validateFdxContent(string $fdxXml): array
    {
        $errors = [];
        $fdxXml = trim($fdxXml);

        if ($fdxXml === '') {
            return ['Import content is empty.'];
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($fdxXml);

        if ($xml === false) {
            $libxmlErrors = [];
            foreach (libxml_get_errors() as $error) {
                $libxmlErrors[] = trim($error->message);
            }
            libxml_clear_errors();
            $errors[] = 'Invalid XML import content: ' . implode('; ', $libxmlErrors);
            return $errors;
        }

        if ($xml->getName() !== 'FDX') {
            $errors[] = 'Import content must be a Scrivener FDX file with a root <FDX> element.';
        }

        if ($this->extractTitle($xml) === null) {
            $errors[] = 'Import content must include a document title.';
        }

        $textNodes = $xml->xpath('//TEXT');
        if ($textNodes === false || count($textNodes) === 0) {
            $errors[] = 'Import content must include at least one text section.';
        }

        return $errors;
    }

    public function importFdxContent(string $fdxXml, string $versionLabel, int $authorId, ?string $source = null): int
    {
        $errors = $this->validateFdxContent($fdxXml);
        if ($errors !== []) {
            throw new ImportValidationException($errors);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string(trim($fdxXml));
        if ($xml === false) {
            throw new ImportValidationException(['Could not parse FDX content after validation.']);
        }

        $title = $this->extractTitle($xml);
        if ($title === null) {
            throw new ImportValidationException(['Import content title extraction failed.']);
        }

        $duplicateCount = $this->connection
            ->executeQuery('SELECT COUNT(*) FROM content_versions WHERE title = ? AND version_label = ?', [$title, $versionLabel])
            ->fetchOne();

        if ($duplicateCount !== false && (int) $duplicateCount > 0) {
            throw new ImportValidationException(['A content version with this title and version label already exists.']);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $metadata = json_encode([
            'format' => 'fdx',
            'content_hash' => sha1($fdxXml),
            'section_count' => $this->countTextSections($xml),
            'source' => $source,
        ], JSON_THROW_ON_ERROR);

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(
                'INSERT INTO content_versions (title, version_label, source, author_id, status, metadata, imported_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$title, $versionLabel, $source, $authorId, 'ready', $metadata, $now, $now, $now]
            );

            $contentVersionId = (int) $this->connection->lastInsertId();
            $this->connection->commit();

            return $contentVersionId;
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }
    }

    public function rollbackImport(int $contentVersionId): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->beginTransaction();

        try {
            $updated = $this->connection->executeStatement(
                'UPDATE content_versions SET status = ?, updated_at = ? WHERE id = ? AND status != ?',
                ['archived', $now, $contentVersionId, 'archived']
            );

            $this->connection->commit();

            return $updated > 0;
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }
    }

    public function importFdxDirectory(string $directory, int $authorId, ?string $labelPrefix = null, bool $dryRun = false): array
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException('Import directory does not exist: ' . $directory);
        }

        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        $result = [
            'scanned' => 0,
            'imported' => [],
            'failed' => [],
        ];

        foreach ($iterator as $file) {
            if (!$file->isFile() || strcasecmp($file->getExtension(), 'fdx') !== 0) {
                continue;
            }

            $result['scanned']++;
            $filePath = str_replace('\\', '/', $file->getPathname());
            $relativePath = ltrim(substr($filePath, strlen($directory) + 1), '/');
            $versionLabel = $this->buildVersionLabel($relativePath, $labelPrefix);
            $source = $relativePath;

            try {
                $fdxXml = file_get_contents($file->getPathname());
                if ($fdxXml === false) {
                    throw new \RuntimeException('Unable to read FDX file: ' . $file->getPathname());
                }

                if ($dryRun) {
                    $errors = $this->validateFdxContent($fdxXml);
                    if ($errors !== []) {
                        $result['failed'][$relativePath] = $errors;
                        continue;
                    }

                    $result['imported'][$relativePath] = [
                        'version_label' => $versionLabel,
                    ];
                    continue;
                }

                $contentVersionId = $this->importFdxContent($fdxXml, $versionLabel, $authorId, $source);
                $result['imported'][$relativePath] = [
                    'id' => $contentVersionId,
                    'version_label' => $versionLabel,
                ];
            } catch (ImportValidationException $exception) {
                $result['failed'][$relativePath] = $exception->getErrors();
            } catch (\Throwable $exception) {
                $result['failed'][$relativePath] = [$exception->getMessage()];
            }
        }

        return $result;
    }

    private function buildVersionLabel(string $relativePath, ?string $labelPrefix): string
    {
        $relativePath = preg_replace('/\.fdx$/i', '', $relativePath);
        $relativePath = trim($relativePath, '/');

        return $labelPrefix !== null ? trim($labelPrefix . '/' . $relativePath, '/') : $relativePath;
    }

    private function extractTitle(\SimpleXMLElement $xml): ?string
    {
        if (isset($xml->PROJECT->NAME) && trim((string) $xml->PROJECT->NAME) !== '') {
            return trim((string) $xml->PROJECT->NAME);
        }

        if (isset($xml->TITLE) && trim((string) $xml->TITLE) !== '') {
            return trim((string) $xml->TITLE);
        }

        $titles = $xml->xpath('//TITLE');
        if ($titles !== false) {
            foreach ($titles as $titleNode) {
                $title = trim((string) $titleNode);
                if ($title !== '') {
                    return $title;
                }
            }
        }

        return null;
    }

    private function countTextSections(\SimpleXMLElement $xml): int
    {
        $nodes = $xml->xpath('//TEXT');
        if ($nodes === false) {
            return 0;
        }

        return count($nodes);
    }
}
