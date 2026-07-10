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

    public function validateFdxContent(string $fdxXml, ?string $source = null, ?string $versionLabel = null): array
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

        $rootName = $xml->getName();
        if ($rootName !== 'FDX' && $rootName !== 'FinalDraft') {
            $errors[] = 'Import content must be a Scrivener/Final Draft XML file with a root <FDX> or <FinalDraft> element.';
        }

        $title = $this->extractTitle($xml) ?? $this->deriveTitleFromSourceOrLabel($source, $versionLabel);
        if ($title === null) {
            $errors[] = 'Import content must include a document title.';
        }

        return $errors;
    }

    public function importFdxContent(string $fdxXml, string $versionLabel, int $authorId, ?string $source = null, ?string $bookTitle = null, ?string $importBatchId = null): int
    {
        $errors = $this->validateFdxContent($fdxXml, $source, $versionLabel);
        if ($errors !== []) {
            throw new ImportValidationException($errors);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string(trim($fdxXml));
        if ($xml === false) {
            throw new ImportValidationException(['Could not parse FDX content after validation.']);
        }

        $title = $this->extractTitle($xml) ?? $this->deriveTitleFromSourceOrLabel($source, $versionLabel);
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
        $contentText = $this->extractPlainTextFromXml($xml);
        $sectionCount = $this->countTextSections($xml);
        $status = $sectionCount > 0 ? 'ready' : 'placeholder';
        $effectiveImportBatchId = $importBatchId ?? $this->generateImportBatchId();

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(
                'INSERT INTO content_versions (title, book_title, version_label, source, content_rtf, content_text, author_id, status, metadata, import_batch_id, imported_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$title, $bookTitle, $versionLabel, $source, null, $contentText !== '' ? $contentText : null, $authorId, $status, $metadata, $effectiveImportBatchId, $now, $now, $now]
            );

            $contentVersionId = (int) $this->connection->lastInsertId();
            $this->assignImportedContentToReviewers($contentVersionId, $now);
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

    public function importFdxDirectory(string $directory, int $authorId, ?string $labelPrefix = null, bool $dryRun = false, ?string $bookTitle = null): array
    {
        $projects = $this->discoverScrivenerProjects($directory);
        if ($projects !== []) {
            return $this->importScrivenerDirectory($directory, $authorId, $labelPrefix, $dryRun, $bookTitle);
        }

        return $this->importLegacyFdxDirectory($directory, $authorId, $labelPrefix, $dryRun, $bookTitle);
    }

    public function importScrivenerDirectory(string $directory, int $authorId, ?string $labelPrefix = null, bool $dryRun = false, ?string $bookTitle = null): array
    {
        $projects = $this->discoverScrivenerProjects($directory);
        if ($projects === []) {
            throw new \RuntimeException('No Scrivener project backups were found at: ' . $directory);
        }

        $result = [
            'scanned_projects' => 0,
            'scanned' => 0,
            'imported' => [],
            'failed' => [],
        ];
        $importBatchId = $dryRun ? null : $this->generateImportBatchId();

        foreach ($projects as $projectPath) {
            $result['scanned_projects']++;
            $scrivxPath = $this->resolveScrivxPath($projectPath);
            if ($scrivxPath === null) {
                $result['failed'][$projectPath] = ['Could not find a .scrivx file in project directory.'];
                continue;
            }

            try {
                $items = $this->parseScrivenerProjectItems($projectPath, $scrivxPath, $labelPrefix, $bookTitle);
            } catch (ImportValidationException $e) {
                $result['failed'][$projectPath] = $e->getErrors();
                continue;
            } catch (\Throwable $e) {
                $result['failed'][$projectPath] = [$e->getMessage()];
                continue;
            }

            foreach ($items as $item) {
                $result['scanned']++;
                $entryKey = $item['list_path'];

                try {
                    if ($dryRun) {
                        $result['imported'][$entryKey] = [
                            'version_label' => $item['version_label'],
                            'order_path' => $item['order_path'],
                            'kind' => $item['kind'],
                        ];
                        continue;
                    }

                    $id = $this->insertScrivenerItem($item, $authorId, $bookTitle, $importBatchId);
                    $result['imported'][$entryKey] = [
                        'id' => $id,
                        'import_batch_id' => $importBatchId,
                        'version_label' => $item['version_label'],
                        'order_path' => $item['order_path'],
                        'kind' => $item['kind'],
                    ];
                } catch (ImportValidationException $exception) {
                    $result['failed'][$entryKey] = $exception->getErrors();
                } catch (\Throwable $exception) {
                    $result['failed'][$entryKey] = [$exception->getMessage()];
                }
            }
        }

        return $result;
    }

    private function importLegacyFdxDirectory(string $directory, int $authorId, ?string $labelPrefix = null, bool $dryRun = false, ?string $bookTitle = null): array
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
        $importBatchId = $dryRun ? null : $this->generateImportBatchId();

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
                    $errors = $this->validateFdxContent($fdxXml, $source, $versionLabel);
                    if ($errors !== []) {
                        $result['failed'][$relativePath] = $errors;
                        continue;
                    }

                    $result['imported'][$relativePath] = [
                        'version_label' => $versionLabel,
                    ];
                    continue;
                }

                $contentVersionId = $this->importFdxContent($fdxXml, $versionLabel, $authorId, $source, $bookTitle, $importBatchId);
                $result['imported'][$relativePath] = [
                    'id' => $contentVersionId,
                    'import_batch_id' => $importBatchId,
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

    private function insertScrivenerItem(array $item, int $authorId, ?string $bookTitle, ?string $importBatchId): int
    {
        $title = $item['title'];
        $versionLabel = $item['version_label'];
        $duplicateCount = $this->connection
            ->executeQuery('SELECT COUNT(*) FROM content_versions WHERE title = ? AND version_label = ?', [$title, $versionLabel])
            ->fetchOne();

        if ($duplicateCount !== false && (int) $duplicateCount > 0) {
            throw new ImportValidationException(['A content version with this title and version label already exists.']);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $metadata = is_array($item['metadata']) ? $item['metadata'] : [];
        $effectiveImportBatchId = $importBatchId ?? $this->generateImportBatchId();

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(
                'INSERT INTO content_versions (title, book_title, version_label, source, content_rtf, content_text, author_id, status, metadata, import_batch_id, imported_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $title,
                    $bookTitle ?? $item['book_title'],
                    $versionLabel,
                    $item['source'],
                    $item['content_rtf'] !== '' ? $item['content_rtf'] : null,
                    $item['content_text'] !== '' ? $item['content_text'] : null,
                    $authorId,
                    $item['status'],
                    json_encode($metadata, JSON_THROW_ON_ERROR),
                    $effectiveImportBatchId,
                    $now,
                    $now,
                    $now,
                ]
            );

            $contentVersionId = (int) $this->connection->lastInsertId();
            $this->assignImportedContentToReviewers($contentVersionId, $now);
            $remapSummary = $this->remapReviewerAnchorsForUpdatedScrivenerVersion(
                $contentVersionId,
                $title,
                (string) ($bookTitle ?? $item['book_title'] ?? ''),
                $authorId,
                (string) ($item['content_text'] ?? ''),
                $now
            );
            if ($remapSummary !== null) {
                $metadata['anchor_remap'] = $remapSummary;
                $this->connection->executeStatement(
                    'UPDATE content_versions SET metadata = ?, updated_at = ? WHERE id = ?',
                    [json_encode($metadata, JSON_THROW_ON_ERROR), $now, $contentVersionId]
                );
            }
            $this->connection->commit();

            return $contentVersionId;
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }
    }

    private function generateImportBatchId(): string
    {
        return 'imp_' . bin2hex(random_bytes(8));
    }

    private function discoverScrivenerProjects(string $path): array
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        if ($normalized === '') {
            return [];
        }

        if (is_file($normalized) && str_ends_with(strtolower($normalized), '.scrivx')) {
            return [dirname($normalized)];
        }

        if (is_dir($normalized) && str_ends_with(strtolower($normalized), '.scriv')) {
            return [$normalized];
        }

        if (!is_dir($normalized)) {
            return [];
        }

        $projects = [];
        $directScrivx = $this->resolveScrivxPath($normalized);
        if ($directScrivx !== null) {
            $projects[] = $normalized;
        }

        $iterator = new \FilesystemIterator($normalized, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            if (!$entry->isDir()) {
                continue;
            }

            $candidate = str_replace('\\', '/', $entry->getPathname());
            if (!str_ends_with(strtolower($candidate), '.scriv')) {
                continue;
            }

            $projects[] = $candidate;
        }

        sort($projects);
        return array_values(array_unique($projects));
    }

    private function resolveScrivxPath(string $projectPath): ?string
    {
        $projectPath = rtrim(str_replace('\\', '/', $projectPath), '/');
        if (!is_dir($projectPath)) {
            return null;
        }

        $projectBaseName = basename($projectPath);
        if (str_ends_with(strtolower($projectBaseName), '.scriv')) {
            $projectBaseName = substr($projectBaseName, 0, -6);
        }

        $preferredPath = $projectPath . '/' . $projectBaseName . '.scrivx';
        if (is_file($preferredPath)) {
            return $preferredPath;
        }

        $iterator = new \FilesystemIterator($projectPath, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            if ($entry->isFile() && str_ends_with(strtolower($entry->getFilename()), '.scrivx')) {
                return str_replace('\\', '/', $entry->getPathname());
            }
        }

        return null;
    }

    private function parseScrivenerProjectItems(string $projectPath, string $scrivxPath, ?string $labelPrefix, ?string $bookTitleOverride = null): array
    {
        $rawXml = file_get_contents($scrivxPath);
        if ($rawXml === false) {
            throw new ImportValidationException(['Unable to read Scrivener project file: ' . $scrivxPath]);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string(trim($rawXml));
        if ($xml === false) {
            $libxmlErrors = [];
            foreach (libxml_get_errors() as $error) {
                $libxmlErrors[] = trim($error->message);
            }
            libxml_clear_errors();
            throw new ImportValidationException(['Invalid Scrivener project XML: ' . implode('; ', $libxmlErrors)]);
        }

        if ($xml->getName() !== 'ScrivenerProject') {
            throw new ImportValidationException(['Expected ScrivenerProject root in .scrivx file.']);
        }

        $rootNode = $this->resolveImportRootNode($xml);
        if ($rootNode === null) {
            throw new ImportValidationException(['Could not find a binder root with importable children.']);
        }

        $rootTitle = $this->resolveImportedBookTitle(
            $this->readBinderTitle($rootNode),
            $bookTitleOverride
        );
        $rootUuid = (string) ($rootNode['UUID'] ?? '');
        $items = [];

        $children = $rootNode->Children->BinderItem ?? [];
        $index = 1;
        foreach ($children as $childNode) {
            $this->collectScrivenerItems(
                $childNode,
                [$index],
                [$rootTitle],
                $rootUuid,
                $rootTitle,
                $projectPath,
                $scrivxPath,
                $labelPrefix,
                $items
            );
            $index++;
        }

        return $items;
    }

    private function resolveImportRootNode(\SimpleXMLElement $xml): ?\SimpleXMLElement
    {
        $bookNodes = $xml->xpath('/ScrivenerProject/Binder/BinderItem[Title="The Book"]');
        if (is_array($bookNodes) && $bookNodes !== []) {
            return $bookNodes[0];
        }

        $draftNodes = $xml->xpath('/ScrivenerProject/Binder/BinderItem[@Type="DraftFolder"]');
        if (is_array($draftNodes) && $draftNodes !== []) {
            return $draftNodes[0];
        }

        $binderNodes = $xml->xpath('/ScrivenerProject/Binder/BinderItem');
        if (is_array($binderNodes) && $binderNodes !== []) {
            return $binderNodes[0];
        }

        return null;
    }

    private function collectScrivenerItems(
        \SimpleXMLElement $node,
        array $orderTrail,
        array $parentPathParts,
        string $parentUuid,
        string $bookTitle,
        string $projectPath,
        string $scrivxPath,
        ?string $labelPrefix,
        array &$items
    ): void {
        $uuid = (string) ($node['UUID'] ?? '');
        $type = (string) ($node['Type'] ?? 'Text');
        $title = $this->readBinderTitle($node) ?? 'Untitled Item';
        $pathParts = array_merge($parentPathParts, [$title]);
        $listPath = implode('/', $pathParts);
        $orderPath = implode('.', array_map(static fn (int $value): string => str_pad((string) $value, 4, '0', STR_PAD_LEFT), $orderTrail));
        $isDirectory = $this->isDirectoryNodeType($type) || isset($node->Children->BinderItem);

        $source = null;
        $rawContent = '';
        $plainText = '';
        $hasDataFile = false;
        if ($uuid !== '') {
            $dataFilePath = rtrim($projectPath, '/') . '/Files/Data/' . $uuid . '/content.rtf';
            if (is_file($dataFilePath)) {
                $hasDataFile = true;
                $source = $this->toProjectRelativePath($dataFilePath);
                $raw = file_get_contents($dataFilePath);
                if ($raw !== false) {
                    $rawContent = $raw;
                    $plainText = $this->extractPlainTextFromRtf($rawContent);
                }
            }
        }

        $sectionCount = $this->countTextSectionsFromText($plainText);
        $status = $sectionCount > 0 ? 'ready' : 'placeholder';

        $metadata = [
            'format' => 'scrivener',
            'source' => $source,
            'book_title' => $bookTitle,
            'scrivener' => [
                'project_file' => $this->toProjectRelativePath($scrivxPath),
                'project_name' => basename(rtrim($projectPath, '/')),
                'uuid' => $uuid,
                'parent_uuid' => $parentUuid,
                'type' => $type,
                'kind' => $isDirectory ? 'directory' : 'text',
                'order_path' => $orderPath,
                'list_path' => $listPath,
                'depth' => count($orderTrail),
                'has_data_file' => $hasDataFile,
            ],
            'section_count' => $sectionCount,
            'content_hash' => $rawContent !== '' ? sha1($rawContent) : null,
        ];

        $versionLabel = $this->buildScrivenerVersionLabel($listPath, $orderPath, $labelPrefix);
        $items[] = [
            'title' => $title,
            'book_title' => $bookTitle,
            'version_label' => $versionLabel,
            'source' => $source,
            'content_rtf' => $rawContent,
            'content_text' => $plainText,
            'status' => $status,
            'metadata' => $metadata,
            'kind' => $isDirectory ? 'directory' : 'text',
            'order_path' => $orderPath,
            'list_path' => $listPath,
        ];

        $children = $node->Children->BinderItem ?? [];
        $index = 1;
        foreach ($children as $childNode) {
            $this->collectScrivenerItems(
                $childNode,
                array_merge($orderTrail, [$index]),
                $pathParts,
                $uuid,
                $bookTitle,
                $projectPath,
                $scrivxPath,
                $labelPrefix,
                $items
            );
            $index++;
        }
    }

    private function readBinderTitle(\SimpleXMLElement $node): ?string
    {
        if (isset($node->Title) && trim((string) $node->Title) !== '') {
            return trim((string) $node->Title);
        }

        return null;
    }

    private function resolveImportedBookTitle(?string $binderTitle, ?string $bookTitleOverride = null): string
    {
        $override = trim((string) $bookTitleOverride);
        if ($override !== '') {
            return $override;
        }

        $title = trim((string) $binderTitle);
        if ($title === '' || strcasecmp($title, 'The Book') === 0) {
            return 'Enterprise Community Management';
        }

        return $title;
    }

    private function isDirectoryNodeType(string $type): bool
    {
        return str_ends_with($type, 'Folder') || $type === 'Folder';
    }

    private function toProjectRelativePath(string $absolutePath): string
    {
        $absolutePath = str_replace('\\', '/', $absolutePath);
        $projectRoot = str_replace('\\', '/', dirname(__DIR__, 2));

        if (str_starts_with($absolutePath, $projectRoot . '/')) {
            return substr($absolutePath, strlen($projectRoot) + 1);
        }

        return $absolutePath;
    }

    private function extractPlainTextFromRtf(string $rtf): string
    {
        $rtf = str_ireplace(['\\pard', '\\par', '\\tab'], ["\n", "\n", "\t"], $rtf);
        $rtf = preg_replace('/\\\\[a-z]+-?\d*\s?/i', '', $rtf) ?? $rtf;
        $rtf = str_replace(['{', '}'], '', $rtf);
        $rtf = preg_replace('/[ \t]+/u', ' ', $rtf) ?? $rtf;
        $rtf = preg_replace('/\n{3,}/', "\n\n", $rtf) ?? $rtf;

        return trim($rtf);
    }

    private function countTextSectionsFromText(string $text): int
    {
        $normalized = trim($text);
        if ($normalized === '') {
            return 0;
        }

        $lines = preg_split('/(?:\r\n|\r|\n)+/', $normalized) ?: [];
        $count = 0;
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $count++;
            }
        }

        return max(1, $count);
    }

    private function buildScrivenerVersionLabel(string $listPath, string $orderPath, ?string $labelPrefix): string
    {
        $label = trim(($labelPrefix !== null ? trim($labelPrefix, '/') . '/' : '') . $orderPath . ' ' . $listPath);
        if (strlen($label) <= 100) {
            return $label;
        }

        $hash = substr(sha1($label), 0, 12);
        $truncated = substr($label, 0, 86);

        return rtrim($truncated) . '#' . $hash;
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
        return count($this->extractTextLinesFromXml($xml));
    }

    private function extractPlainTextFromXml(\SimpleXMLElement $xml): string
    {
        $lines = $this->extractTextLinesFromXml($xml);
        if ($lines === []) {
            return '';
        }

        return implode("\n\n", $lines);
    }

    private function extractTextLinesFromXml(\SimpleXMLElement $xml): array
    {
        $lines = [];
        $paragraphNodes = $xml->xpath('//Paragraph | //paragraph');
        if ($paragraphNodes !== false && $paragraphNodes !== []) {
            foreach ($paragraphNodes as $paragraphNode) {
                $textParts = [];
                $embeddedTextNodes = $paragraphNode->xpath('.//TEXT | .//Text | .//text');
                if ($embeddedTextNodes !== false) {
                    foreach ($embeddedTextNodes as $textNode) {
                        $chunk = trim((string) preg_replace('/\s+/u', ' ', (string) $textNode));
                        if ($chunk !== '') {
                            $textParts[] = $chunk;
                        }
                    }
                }

                $text = trim((string) preg_replace('/\s+/u', ' ', implode(' ', $textParts)));
                if ($text === '') {
                    $text = trim((string) preg_replace('/\s+/u', ' ', (string) $paragraphNode));
                }

                if ($text !== '') {
                    $lines[] = $text;
                }
            }

            if ($lines !== []) {
                return $lines;
            }
        }

        $textNodes = $xml->xpath('//TEXT | //Text | //text');
        if ($textNodes === false) {
            return [];
        }

        foreach ($textNodes as $textNode) {
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $textNode));
            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return $lines;
    }

    private function assignImportedContentToReviewers(int $contentVersionId, string $timestamp): void
    {
        try {
            $userRows = $this->connection
                ->executeQuery('SELECT id, roles FROM users WHERE status = ?', ['active'])
                ->fetchAllAssociative();
        } catch (\Throwable) {
            return;
        }

        foreach ($userRows as $row) {
            $reviewerId = (int) ($row['id'] ?? 0);
            if ($reviewerId <= 0 || !$this->hasReviewerRole($row['roles'] ?? null)) {
                continue;
            }

            try {
                $this->connection->executeStatement(
                    'INSERT INTO review_assignments (content_version_id, reviewer_id, priority, due_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$contentVersionId, $reviewerId, 'normal', null, $timestamp, $timestamp]
                );
            } catch (\Throwable) {
                // Keep import successful even when assignment table/state differs.
            }
        }
    }

    private function hasReviewerRole(mixed $rawRoles): bool
    {
        if (is_array($rawRoles)) {
            return in_array('reviewer', $rawRoles, true);
        }

        if (is_string($rawRoles) && trim($rawRoles) !== '') {
            try {
                $decoded = json_decode($rawRoles, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return in_array('reviewer', $decoded, true);
                }
            } catch (\Throwable) {
                return str_contains(strtolower($rawRoles), 'reviewer');
            }
        }

        return false;
    }

    private function remapReviewerAnchorsForUpdatedScrivenerVersion(
        int $newContentVersionId,
        string $title,
        string $bookTitle,
        int $authorId,
        string $newContentText,
        string $timestamp
    ): ?array {
        try {
            $previous = $this->connection->executeQuery(
                "SELECT id, content_text FROM content_versions WHERE author_id = ? AND title = ? AND COALESCE(book_title, '') = ? AND id <> ? ORDER BY imported_at DESC, id DESC LIMIT 1",
                [$authorId, $title, $bookTitle, $newContentVersionId]
            )->fetchAssociative();
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($previous) || $previous === []) {
            return null;
        }

        $previousVersionId = (int) ($previous['id'] ?? 0);
        if ($previousVersionId <= 0) {
            return null;
        }

        try {
            $reviews = $this->connection->executeQuery(
                "SELECT
                    id,
                    reviewer_id,
                    title,
                    status,
                    details,
                    selected_excerpt,
                    anchor_start_offset,
                    anchor_end_offset,
                    anchor_container_path
                FROM reviews
                WHERE content_version_id = ?
                  AND status IN ('open', 'needs_author_review')",
                [$previousVersionId]
            )->fetchAllAssociative();
        } catch (\Throwable) {
            return [
                'previous_content_version_id' => $previousVersionId,
                'copied_reviews' => 0,
                'mapped_high' => 0,
                'mapped_medium' => 0,
                'mapped_low' => 0,
                'not_applicable' => 0,
                'failed' => 0,
            ];
        }

        $previousContentText = (string) ($previous['content_text'] ?? '');
        $summary = [
            'previous_content_version_id' => $previousVersionId,
            'copied_reviews' => 0,
            'mapped_high' => 0,
            'mapped_medium' => 0,
            'mapped_low' => 0,
            'not_applicable' => 0,
            'failed' => 0,
        ];

        foreach ($reviews as $review) {
            $remap = $this->remapReviewAnchor($review, $previousContentText, $newContentText);

            try {
                $this->connection->executeStatement(
                    'INSERT INTO reviews (content_version_id, reviewer_id, title, status, details, selected_excerpt, anchor_start_offset, anchor_end_offset, anchor_container_path, anchor_remap_state, anchor_remap_confidence, anchor_remap_reason, anchor_remapped_from_review_id, anchor_remapped_from_content_version_id, created_at, updated_at, resolved_at, resolution_decision, resolution_actor_id, resolution_actor_role, resolution_recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $newContentVersionId,
                        (int) ($review['reviewer_id'] ?? 0) > 0 ? (int) $review['reviewer_id'] : null,
                        (string) ($review['title'] ?? 'Review note'),
                        'needs_author_review',
                        $review['details'] !== null ? (string) $review['details'] : null,
                        $review['selected_excerpt'] !== null ? trim((string) $review['selected_excerpt']) : null,
                        $remap['start_offset'],
                        $remap['end_offset'],
                        $remap['container_path'],
                        $remap['state'],
                        $remap['confidence'],
                        $remap['reason'],
                        (int) ($review['id'] ?? 0) > 0 ? (int) $review['id'] : null,
                        $previousVersionId,
                        $timestamp,
                        $timestamp,
                        null,
                        null,
                        null,
                        null,
                        null,
                    ]
                );
            } catch (\Throwable) {
                continue;
            }

            $summary['copied_reviews']++;
            if ($remap['state'] === 'not_applicable') {
                $summary['not_applicable']++;
                continue;
            }

            if ($remap['state'] === 'failed') {
                $summary['failed']++;
                continue;
            }

            if ($remap['confidence'] === 'high') {
                $summary['mapped_high']++;
                continue;
            }

            if ($remap['confidence'] === 'medium') {
                $summary['mapped_medium']++;
                continue;
            }

            $summary['mapped_low']++;
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $review
     *
     * @return array{state: string, confidence: string, reason: string, start_offset: ?int, end_offset: ?int, container_path: ?string}
     */
    private function remapReviewAnchor(array $review, string $oldContentText, string $newContentText): array
    {
        $excerpt = trim((string) ($review['selected_excerpt'] ?? ''));
        $start = array_key_exists('anchor_start_offset', $review) && $review['anchor_start_offset'] !== null
            ? max(0, (int) $review['anchor_start_offset'])
            : null;
        $end = array_key_exists('anchor_end_offset', $review) && $review['anchor_end_offset'] !== null
            ? max(0, (int) $review['anchor_end_offset'])
            : null;

        if ($excerpt === '' && $start === null && $end === null) {
            return [
                'state' => 'not_applicable',
                'confidence' => 'none',
                'reason' => 'whole_item_note',
                'start_offset' => null,
                'end_offset' => null,
                'container_path' => null,
            ];
        }

        $newLength = strlen($newContentText);
        if ($newLength === 0) {
            return [
                'state' => 'failed',
                'confidence' => 'failed',
                'reason' => 'new_content_is_empty',
                'start_offset' => null,
                'end_offset' => null,
                'container_path' => null,
            ];
        }

        if ($excerpt !== '') {
            $positions = $this->findAllOccurrences($newContentText, $excerpt);
            if (count($positions) === 1) {
                $matchStart = $positions[0];
                return [
                    'state' => 'mapped',
                    'confidence' => 'high',
                    'reason' => 'excerpt_unique_match',
                    'start_offset' => $matchStart,
                    'end_offset' => $matchStart + strlen($excerpt),
                    'container_path' => null,
                ];
            }

            if (count($positions) > 1) {
                $bestStart = $this->pickClosestPositionByExpectedOffset($positions, $start, strlen($oldContentText), $newLength);
                return [
                    'state' => 'mapped',
                    'confidence' => 'medium',
                    'reason' => 'excerpt_ambiguous_match',
                    'start_offset' => $bestStart,
                    'end_offset' => $bestStart + strlen($excerpt),
                    'container_path' => null,
                ];
            }
        }

        if ($start !== null && $end !== null && $end > $start && $oldContentText !== '') {
            $spanLength = min(200, $end - $start);
            $span = trim(substr($oldContentText, $start, $spanLength));
            if ($span !== '') {
                $positions = $this->findAllOccurrences($newContentText, $span);
                if (count($positions) === 1) {
                    $matchStart = $positions[0];
                    return [
                        'state' => 'mapped',
                        'confidence' => 'medium',
                        'reason' => 'old_span_unique_match',
                        'start_offset' => $matchStart,
                        'end_offset' => $matchStart + strlen($span),
                        'container_path' => null,
                    ];
                }

                if (count($positions) > 1) {
                    $bestStart = $this->pickClosestPositionByExpectedOffset($positions, $start, strlen($oldContentText), $newLength);
                    return [
                        'state' => 'mapped',
                        'confidence' => 'low',
                        'reason' => 'old_span_ambiguous_match',
                        'start_offset' => $bestStart,
                        'end_offset' => $bestStart + strlen($span),
                        'container_path' => null,
                    ];
                }
            }
        }

        return [
            'state' => 'failed',
            'confidence' => 'failed',
            'reason' => 'no_match_in_new_content',
            'start_offset' => null,
            'end_offset' => null,
            'container_path' => null,
        ];
    }

    /**
     * @return list<int>
     */
    private function findAllOccurrences(string $haystack, string $needle): array
    {
        if ($needle === '') {
            return [];
        }

        $positions = [];
        $offset = 0;
        while (true) {
            $position = strpos($haystack, $needle, $offset);
            if ($position === false) {
                break;
            }

            $positions[] = $position;
            $offset = $position + 1;
        }

        return $positions;
    }

    /**
     * @param list<int> $positions
     */
    private function pickClosestPositionByExpectedOffset(array $positions, ?int $oldStart, int $oldLength, int $newLength): int
    {
        if ($positions === []) {
            return 0;
        }

        if ($oldStart === null || $oldLength <= 0) {
            return $positions[0];
        }

        $ratio = min(1.0, max(0.0, $oldStart / max(1, $oldLength)));
        $expected = (int) round($ratio * max(0, $newLength - 1));

        $best = $positions[0];
        $bestDistance = abs($best - $expected);
        foreach ($positions as $position) {
            $distance = abs($position - $expected);
            if ($distance < $bestDistance) {
                $best = $position;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    private function deriveTitleFromSourceOrLabel(?string $source, ?string $versionLabel): ?string
    {
        $candidate = null;

        if ($source !== null && trim($source) !== '') {
            $candidate = basename(str_replace('\\', '/', $source));
            $candidate = preg_replace('/\.fdx$/i', '', $candidate);
        } elseif ($versionLabel !== null && trim($versionLabel) !== '') {
            $candidate = basename(str_replace('\\', '/', $versionLabel));
        }

        if ($candidate === null) {
            return null;
        }

        $candidate = preg_replace('/^\d+\s+/u', '', (string) $candidate);
        $candidate = preg_replace('/\s*\[\d+\]$/u', '', (string) $candidate);
        $candidate = trim((string) $candidate);
        return $candidate === '' ? null : $candidate;
    }
}
