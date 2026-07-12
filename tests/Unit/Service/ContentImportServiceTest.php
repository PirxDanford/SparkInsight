<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use SparkInsight\Service\ContentImportService;
use SparkInsight\Service\ImportValidationException;

final class ContentImportServiceTest extends TestCase
{
    private Connection $connection;
    private ContentImportService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->service = new ContentImportService($this->connection);
    }

    public function testValidateFdxContentReturnsErrorsForEmptyContent(): void
    {
        $errors = $this->service->validateFdxContent('');

        $this->assertSame(['Import content is empty.'], $errors);
    }

    public function testValidateFdxContentReturnsErrorsForInvalidXml(): void
    {
        $errors = $this->service->validateFdxContent('<FDX><PROJECT></FDX>');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Invalid XML import content:', $errors[0]);
    }

    public function testValidateFdxContentReturnsErrorsForMissingTitle(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FDX>
    <TEXT>Some content</TEXT>
</FDX>
XML;

        $errors = $this->service->validateFdxContent($xml);

        $this->assertSame([
            'Import content must include a document title.',
        ], $errors);
    }

    public function testValidateFdxContentAcceptsFinalDraftRootAndParagraphNodes(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FinalDraft>
    <Content>
        <Paragraph Type="General"/>
    </Content>
</FinalDraft>
XML;

        $errors = $this->service->validateFdxContent($xml, 'Notes/Example.fdx');

        $this->assertSame([], $errors);
    }

    public function testImportFdxContentInsertsContentVersionAndReturnsId(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FDX>
    <PROJECT>
        <NAME>My Imported Document</NAME>
    </PROJECT>
    <TEXT>First paragraph</TEXT>
</FDX>
XML;

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn('0');

        $usersResultMock = $this->createMock(Result::class);
        $usersResultMock->method('fetchAllAssociative')->willReturn([]);

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $params) use ($resultMock, $usersResultMock) {
                if ($sql === 'SELECT COUNT(*) FROM content_versions WHERE title = ? AND version_label = ?') {
                    TestCase::assertSame(['My Imported Document', 'v1'], $params);

                    return $resultMock;
                }

                if ($sql === 'SELECT id, roles FROM users WHERE status = ?') {
                    TestCase::assertSame(['active'], $params);

                    return $usersResultMock;
                }

                TestCase::fail('Unexpected query: ' . $sql);
            });

        $this->connection->expects($this->once())
            ->method('beginTransaction');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->callback(static function (string $sql) {
                    return str_contains($sql, 'INSERT INTO content_versions') && str_contains($sql, 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                }),
                $this->callback(static function (array $params) {
                    return $params[0] === 'My Imported Document'
                        && $params[1] === null
                        && $params[2] === 'v1'
                        && $params[4] === null
                        && $params[5] === 'First paragraph'
                        && $params[6] === 123
                        && $params[7] === 'ready';
                })
            )
            ->willReturn(1);

        $this->connection->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('42');

        $this->connection->expects($this->once())
            ->method('commit');

        $contentVersionId = $this->service->importFdxContent($xml, 'v1', 123, 'fdx-import');

        $this->assertSame(42, $contentVersionId);
    }

    public function testImportFdxContentThrowsExceptionForDuplicateVersion(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FDX>
    <PROJECT>
        <NAME>My Imported Document</NAME>
    </PROJECT>
    <TEXT>First paragraph</TEXT>
</FDX>
XML;

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn('1');

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($resultMock);

        $this->expectException(ImportValidationException::class);
        $this->expectExceptionMessage('Import validation failed.');

        try {
            $this->service->importFdxContent($xml, 'v1', 123, 'fdx-import');
        } catch (ImportValidationException $exception) {
            $this->assertSame(['A content version with this title and version label already exists.'], $exception->getErrors());
            throw $exception;
        }
    }

    public function testImportFdxContentUsesSourceAsTitleFallbackWhenMissingTitle(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FinalDraft>
    <Content>
        <Paragraph Type="General"/>
    </Content>
</FinalDraft>
XML;

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn('0');

        $usersResultMock = $this->createMock(Result::class);
        $usersResultMock->method('fetchAllAssociative')->willReturn([]);

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $params) use ($resultMock, $usersResultMock) {
                if ($sql === 'SELECT COUNT(*) FROM content_versions WHERE title = ? AND version_label = ?') {
                    TestCase::assertSame(['Sample Chapter', 'v1'], $params);

                    return $resultMock;
                }

                if ($sql === 'SELECT id, roles FROM users WHERE status = ?') {
                    TestCase::assertSame(['active'], $params);

                    return $usersResultMock;
                }

                TestCase::fail('Unexpected query: ' . $sql);
            });

        $this->connection->expects($this->once())
            ->method('beginTransaction');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->callback(static function (string $sql) {
                    return str_contains($sql, 'INSERT INTO content_versions');
                }),
                $this->callback(static function (array $params) {
                    return $params[0] === 'Sample Chapter'
                        && $params[1] === null
                        && $params[2] === 'v1'
                        && $params[6] === 123;
                })
            )
            ->willReturn(1);

        $this->connection->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('9');

        $this->connection->expects($this->once())
            ->method('commit');

        $id = $this->service->importFdxContent($xml, 'v1', 123, 'Notes/Sample Chapter.fdx');

        $this->assertSame(9, $id);
    }

    public function testImportFdxContentMarksEmptyContentAsPlaceholder(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FDX>
    <PROJECT>
        <NAME>Placeholder Document</NAME>
    </PROJECT>
    <TEXT></TEXT>
    <Paragraph Type="General"></Paragraph>
</FDX>
XML;

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn('0');

        $usersResultMock = $this->createMock(Result::class);
        $usersResultMock->method('fetchAllAssociative')->willReturn([]);

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $params) use ($resultMock, $usersResultMock) {
                if ($sql === 'SELECT COUNT(*) FROM content_versions WHERE title = ? AND version_label = ?') {
                    TestCase::assertSame(['Placeholder Document', 'v1'], $params);

                    return $resultMock;
                }

                if ($sql === 'SELECT id, roles FROM users WHERE status = ?') {
                    TestCase::assertSame(['active'], $params);

                    return $usersResultMock;
                }

                TestCase::fail('Unexpected query: ' . $sql);
            });

        $this->connection->expects($this->once())
            ->method('beginTransaction');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->callback(static function (string $sql) {
                    return str_contains($sql, 'INSERT INTO content_versions');
                }),
                $this->callback(static function (array $params) {
                    return $params[0] === 'Placeholder Document'
                        && $params[2] === 'v1'
                        && $params[7] === 'placeholder';
                })
            )
            ->willReturn(1);

        $this->connection->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('11');

        $this->connection->expects($this->once())
            ->method('commit');

        $id = $this->service->importFdxContent($xml, 'v1', 123, 'Notes/Placeholder Document.fdx');

        $this->assertSame(11, $id);
    }

    public function testRollbackImportArchivesExistingVersion(): void
    {
        $this->connection->expects($this->once())
            ->method('beginTransaction');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE content_versions SET status = ?, updated_at = ? WHERE id = ? AND status != ?',
                $this->callback(static function (array $params) {
                    return $params[0] === 'archived'
                        && is_string($params[1])
                        && $params[2] === 42
                        && $params[3] === 'archived';
                })
            )
            ->willReturn(1);

        $this->connection->expects($this->once())
            ->method('commit');

        $result = $this->service->rollbackImport(42);

        $this->assertTrue($result);
    }

    public function testRollbackImportReturnsFalseWhenContentVersionDoesNotExist(): void
    {
        $this->connection->expects($this->once())
            ->method('beginTransaction');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE content_versions SET status = ?, updated_at = ? WHERE id = ? AND status != ?',
                $this->callback(static function (array $params) {
                    return $params[0] === 'archived'
                        && is_string($params[1])
                        && $params[2] === 99
                        && $params[3] === 'archived';
                })
            )
            ->willReturn(0);

        $this->connection->expects($this->once())
            ->method('commit');

        $result = $this->service->rollbackImport(99);

        $this->assertFalse($result);
    }

    public function testImportFdxDirectoryImportsAllFdxFilesRecursively(): void
    {
        $directory = sys_get_temp_dir() . '/sparkinsight_scrivener_' . bin2hex(random_bytes(8));
        mkdir($directory . '/Notes', 0777, true);

        file_put_contents($directory . '/RootDoc.fdx', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FDX>
    <PROJECT>
        <NAME>Root Document</NAME>
    </PROJECT>
    <TEXT>Root paragraph</TEXT>
</FDX>
XML
        );

        file_put_contents($directory . '/Notes/ChildDoc.fdx', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FDX>
    <PROJECT>
        <NAME>Child Document</NAME>
    </PROJECT>
    <TEXT>Child paragraph</TEXT>
</FDX>
XML
        );

        $dbPath = sys_get_temp_dir() . '/sparkinsight_import_' . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE content_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    book_title TEXT,
    version_label TEXT NOT NULL,
    source TEXT,
    content_rtf TEXT,
    content_text TEXT,
    author_id INTEGER,
    status TEXT NOT NULL,
    metadata TEXT,
    import_batch_id TEXT,
    imported_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL
        );

        $service = new ContentImportService($connection);
        $result = $service->importFdxDirectory($directory, 123);

        $this->assertSame(2, $result['scanned']);
        $this->assertCount(2, $result['imported']);
        $this->assertEmpty($result['failed']);
        $this->assertSame('RootDoc', $result['imported']['RootDoc.fdx']['version_label']);
        $this->assertSame('Notes/ChildDoc', $result['imported']['Notes/ChildDoc.fdx']['version_label']);
        $this->assertContains($result['imported']['RootDoc.fdx']['id'], [1, 2]);
        $this->assertContains($result['imported']['Notes/ChildDoc.fdx']['id'], [1, 2]);
        $this->assertNotSame($result['imported']['RootDoc.fdx']['id'], $result['imported']['Notes/ChildDoc.fdx']['id']);

        $row = $connection->fetchOne('SELECT COUNT(*) FROM content_versions');
        $this->assertSame(2, (int) $row);

        $connection->close();
        unlink($dbPath);
        unlink($directory . '/RootDoc.fdx');
        unlink($directory . '/Notes/ChildDoc.fdx');
        rmdir($directory . '/Notes');
        rmdir($directory);
    }

    public function testImportFdxDirectoryReportsFailedFiles(): void
    {
        $directory = sys_get_temp_dir() . '/sparkinsight_scrivener_' . bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);

        file_put_contents($directory . '/InvalidDoc.fdx', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<NotFdx>
    <Body>Unsupported structure</Body>
</NotFdx>
XML
        );

        $dbPath = sys_get_temp_dir() . '/sparkinsight_import_' . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE content_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    book_title TEXT,
    version_label TEXT NOT NULL,
    source TEXT,
    content_rtf TEXT,
    content_text TEXT,
    author_id INTEGER,
    status TEXT NOT NULL,
    metadata TEXT,
    import_batch_id TEXT,
    imported_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL
        );

        $service = new ContentImportService($connection);
        $result = $service->importFdxDirectory($directory, 123);

        $this->assertSame(1, $result['scanned']);
        $this->assertEmpty($result['imported']);
        $this->assertArrayHasKey('InvalidDoc.fdx', $result['failed']);
        $this->assertContains('Import content must be a Scrivener/Final Draft XML file with a root <FDX> or <FinalDraft> element.', $result['failed']['InvalidDoc.fdx']);

        $connection->close();
        unlink($dbPath);
        unlink($directory . '/InvalidDoc.fdx');
        rmdir($directory);
    }

    public function testImportScrivenerDirectoryImportsDirectoriesAndPreservesOrder(): void
    {
        $directory = sys_get_temp_dir() . '/sparkinsight_scrivener_' . bin2hex(random_bytes(8));
        $project = $directory . '/book.scriv';
        mkdir($project . '/Files/Data/A1111111-1111-1111-1111-111111111111', 0777, true);
        mkdir($project . '/Files/Data/B2222222-2222-2222-2222-222222222222', 0777, true);

        file_put_contents($project . '/book.scrivx', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ScrivenerProject>
    <Binder>
        <BinderItem UUID="ROOT-BOOK" Type="Folder">
            <Title>The Book</Title>
            <Children>
                <BinderItem UUID="F1111111-1111-1111-1111-111111111111" Type="Folder">
                    <Title>Front Matter</Title>
                    <Children>
                        <BinderItem UUID="A1111111-1111-1111-1111-111111111111" Type="Text">
                            <Title>Title page</Title>
                        </BinderItem>
                    </Children>
                </BinderItem>
                <BinderItem UUID="C3333333-3333-3333-3333-333333333333" Type="Folder">
                    <Title>Chapters</Title>
                    <Children>
                        <BinderItem UUID="B2222222-2222-2222-2222-222222222222" Type="Text">
                            <Title>1 Foundations</Title>
                        </BinderItem>
                    </Children>
                </BinderItem>
            </Children>
        </BinderItem>
    </Binder>
</ScrivenerProject>
XML
        );

        file_put_contents($project . '/Files/Data/A1111111-1111-1111-1111-111111111111/content.rtf', '{\\rtf1\\ansi\\deff0 Title page text\\par Intro line}');
        file_put_contents($project . '/Files/Data/B2222222-2222-2222-2222-222222222222/content.rtf', '{\\rtf1\\ansi\\deff0 Foundations content\\par Section line}');

        $dbPath = sys_get_temp_dir() . '/sparkinsight_import_' . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE content_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    book_title TEXT,
    version_label TEXT NOT NULL,
    source TEXT,
    content_rtf TEXT,
    content_text TEXT,
    author_id INTEGER,
    status TEXT NOT NULL,
    metadata TEXT,
    import_batch_id TEXT,
    imported_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL
        );

        $service = new ContentImportService($connection);
        $result = $service->importScrivenerDirectory($directory, 123, null, false, 'Book Import');

        $this->assertSame(1, $result['scanned_projects']);
        $this->assertSame(4, $result['scanned']);
        $this->assertCount(4, $result['imported']);
        $this->assertEmpty($result['failed']);

        $this->assertArrayHasKey('Book Import/Front Matter', $result['imported']);
        $this->assertArrayHasKey('Book Import/Front Matter/Title page', $result['imported']);
        $this->assertArrayHasKey('Book Import/Chapters', $result['imported']);
        $this->assertArrayHasKey('Book Import/Chapters/1 Foundations', $result['imported']);

        $frontMatterOrder = $result['imported']['Book Import/Front Matter']['order_path'];
        $chaptersOrder = $result['imported']['Book Import/Chapters']['order_path'];
        $this->assertSame('0001', $frontMatterOrder);
        $this->assertSame('0002', $chaptersOrder);

        $rows = $connection->fetchAllAssociative('SELECT title, status, metadata FROM content_versions ORDER BY version_label ASC');
        $this->assertCount(4, $rows);
        $this->assertSame('Front Matter', $rows[0]['title']);
        $this->assertSame('placeholder', $rows[0]['status']);

        $titlePageRow = $connection->fetchAssociative("SELECT metadata, status, source, content_rtf, content_text FROM content_versions WHERE title = 'Title page' LIMIT 1");
        $this->assertIsArray($titlePageRow);
        $this->assertSame('ready', $titlePageRow['status']);
        $this->assertStringContainsString('Files/Data/A1111111-1111-1111-1111-111111111111/content.rtf', (string) $titlePageRow['source']);
        $this->assertStringContainsString('{\\rtf1', (string) $titlePageRow['content_rtf']);
        $this->assertStringContainsString('Title page text', (string) $titlePageRow['content_text']);
        $metadata = json_decode((string) $titlePageRow['metadata'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('0001.0001', $metadata['scrivener']['order_path']);
        $this->assertSame('text', $metadata['scrivener']['kind']);

        $connection->close();
        unlink($dbPath);
        $this->deleteDirectoryRecursively($directory);
    }

    public function testImportScrivenerDirectoryStoresContentForFolderNodesWithChildren(): void
    {
        $directory = sys_get_temp_dir() . '/sparkinsight_scrivener_' . bin2hex(random_bytes(8));
        $project = $directory . '/book.scriv';
        mkdir($project . '/Files/Data/F1111111-1111-1111-1111-111111111111', 0777, true);
        mkdir($project . '/Files/Data/A1111111-1111-1111-1111-111111111111', 0777, true);

        file_put_contents($project . '/book.scrivx', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ScrivenerProject>
    <Binder>
        <BinderItem UUID="ROOT-BOOK" Type="Folder">
            <Title>The Book</Title>
            <Children>
                <BinderItem UUID="F1111111-1111-1111-1111-111111111111" Type="Folder">
                    <Title>1.1 What Is A Community?</Title>
                    <Children>
                        <BinderItem UUID="A1111111-1111-1111-1111-111111111111" Type="Text">
                            <Title>Child Scene</Title>
                        </BinderItem>
                    </Children>
                </BinderItem>
            </Children>
        </BinderItem>
    </Binder>
</ScrivenerProject>
XML
        );

        file_put_contents($project . '/Files/Data/F1111111-1111-1111-1111-111111111111/content.rtf', '{\\rtf1\\ansi\\deff0 Folder intro text\\par More folder text}');
        file_put_contents($project . '/Files/Data/A1111111-1111-1111-1111-111111111111/content.rtf', '{\\rtf1\\ansi\\deff0 Child text}');

        $dbPath = sys_get_temp_dir() . '/sparkinsight_import_' . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE content_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    book_title TEXT,
    version_label TEXT NOT NULL,
    source TEXT,
    content_rtf TEXT,
    content_text TEXT,
    author_id INTEGER,
    status TEXT NOT NULL,
    metadata TEXT,
    import_batch_id TEXT,
    imported_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL
        );

        $service = new ContentImportService($connection);
        $result = $service->importScrivenerDirectory($directory, 123, null, false, 'Book Import');

        $this->assertCount(2, $result['imported']);

        $folderRow = $connection->fetchAssociative("SELECT status, source, content_rtf, content_text, metadata FROM content_versions WHERE title = '1.1 What Is A Community?' LIMIT 1");
        $this->assertIsArray($folderRow);
        $this->assertSame('ready', $folderRow['status']);
        $this->assertStringContainsString('Files/Data/F1111111-1111-1111-1111-111111111111/content.rtf', (string) $folderRow['source']);
        $this->assertStringContainsString('{\\rtf1', (string) $folderRow['content_rtf']);
        $this->assertStringContainsString('Folder intro text', (string) $folderRow['content_text']);

        $metadata = json_decode((string) $folderRow['metadata'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('directory', $metadata['scrivener']['kind']);
        $this->assertTrue((bool) $metadata['scrivener']['has_data_file']);

        $connection->close();
        unlink($dbPath);
        $this->deleteDirectoryRecursively($directory);
    }

    public function testImportScrivenerDirectoryRemapsReviewerAnchorsToUpdatedVersionWithHighConfidence(): void
    {
        $directory = sys_get_temp_dir() . '/sparkinsight_scrivener_' . bin2hex(random_bytes(8));
        $project = $directory . '/book.scriv';
        $uuid = 'A1111111-1111-1111-1111-111111111111';
        mkdir($project . '/Files/Data/' . $uuid, 0777, true);

        file_put_contents($project . '/book.scrivx', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ScrivenerProject>
    <Binder>
        <BinderItem UUID="ROOT-BOOK" Type="Folder">
            <Title>The Book</Title>
            <Children>
                <BinderItem UUID="A1111111-1111-1111-1111-111111111111" Type="Text">
                    <Title>Scene One</Title>
                </BinderItem>
            </Children>
        </BinderItem>
    </Binder>
</ScrivenerProject>
XML
        );

        $dbPath = sys_get_temp_dir() . '/sparkinsight_import_' . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE content_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    book_title TEXT,
    version_label TEXT NOT NULL,
    source TEXT,
    content_rtf TEXT,
    content_text TEXT,
    author_id INTEGER,
    status TEXT NOT NULL,
    metadata TEXT,
    import_batch_id TEXT,
    imported_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL
        );
        $connection->executeStatement(<<<'SQL'
CREATE TABLE reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_version_id INTEGER NOT NULL,
    reviewer_id INTEGER,
    title TEXT NOT NULL,
    status TEXT NOT NULL,
    details TEXT,
    selected_excerpt TEXT,
    anchor_start_offset INTEGER,
    anchor_end_offset INTEGER,
    anchor_container_path TEXT,
    anchor_remap_state TEXT,
    anchor_remap_confidence TEXT,
    anchor_remap_reason TEXT,
    anchor_remapped_from_review_id INTEGER,
    anchor_remapped_from_content_version_id INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    resolved_at TEXT,
    resolution_decision TEXT,
    resolution_actor_id INTEGER,
    resolution_actor_role TEXT,
    resolution_recorded_at TEXT
);
SQL
        );

        file_put_contents($project . '/Files/Data/' . $uuid . '/content.rtf', '{\\rtf1\\ansi\\deff0 Alpha beta gamma delta}');

        $service = new ContentImportService($connection);
        $firstImport = $service->importScrivenerDirectory($directory, 123, 'v1', false, 'Book Import');
        $firstVersionId = (int) ($firstImport['imported']['Book Import/Scene One']['id'] ?? 0);
        $this->assertGreaterThan(0, $firstVersionId);

        $connection->executeStatement(
            "INSERT INTO reviews (content_version_id, reviewer_id, title, status, details, selected_excerpt, anchor_start_offset, anchor_end_offset, anchor_container_path, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))",
            [$firstVersionId, 9, 'Reviewer note', 'open', 'Please revisit this phrase.', 'beta gamma', 6, 16, 'article[1]/p[1]']
        );

        file_put_contents($project . '/Files/Data/' . $uuid . '/content.rtf', '{\\rtf1\\ansi\\deff0 Intro text. Alpha beta gamma delta}');

        $secondImport = $service->importScrivenerDirectory($directory, 123, 'v2', false, 'Book Import');
        $secondVersionId = (int) ($secondImport['imported']['Book Import/Scene One']['id'] ?? 0);
        $this->assertGreaterThan(0, $secondVersionId);
        $this->assertNotSame($firstVersionId, $secondVersionId);

        $remapped = $connection->fetchAssociative(
            'SELECT content_version_id, reviewer_id, status, selected_excerpt, anchor_start_offset, anchor_end_offset, anchor_remap_state, anchor_remap_confidence, anchor_remap_reason, anchor_remapped_from_content_version_id, anchor_remapped_from_review_id FROM reviews WHERE content_version_id = ? ORDER BY id DESC LIMIT 1',
            [$secondVersionId]
        );

        $this->assertIsArray($remapped);
        $this->assertSame($secondVersionId, (int) $remapped['content_version_id']);
        $this->assertSame(9, (int) $remapped['reviewer_id']);
        $this->assertSame('needs_author_review', (string) $remapped['status']);
        $this->assertSame('beta gamma', (string) $remapped['selected_excerpt']);
        $this->assertSame('mapped', (string) $remapped['anchor_remap_state']);
        $this->assertSame('high', (string) $remapped['anchor_remap_confidence']);
        $this->assertSame('excerpt_unique_match', (string) $remapped['anchor_remap_reason']);
        $this->assertSame($firstVersionId, (int) $remapped['anchor_remapped_from_content_version_id']);
        $this->assertGreaterThan(0, (int) $remapped['anchor_remapped_from_review_id']);
        $this->assertGreaterThan(6, (int) $remapped['anchor_start_offset']);
        $this->assertGreaterThan((int) $remapped['anchor_start_offset'], (int) $remapped['anchor_end_offset']);

        $updatedMetadata = $connection->fetchOne('SELECT metadata FROM content_versions WHERE id = ?', [$secondVersionId]);
        $this->assertIsString($updatedMetadata);
        $metadata = json_decode($updatedMetadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($firstVersionId, (int) ($metadata['anchor_remap']['previous_content_version_id'] ?? 0));
        $this->assertSame(1, (int) ($metadata['anchor_remap']['copied_reviews'] ?? 0));
        $this->assertSame(1, (int) ($metadata['anchor_remap']['mapped_high'] ?? 0));
        $this->assertSame(0, (int) ($metadata['anchor_remap']['failed'] ?? 0));

        $connection->close();
        unlink($dbPath);
        $this->deleteDirectoryRecursively($directory);
    }

    public function testImportScrivenerDirectoryMarksFailedRemapWhenAnchorCannotBeFound(): void
    {
        $directory = sys_get_temp_dir() . '/sparkinsight_scrivener_' . bin2hex(random_bytes(8));
        $project = $directory . '/book.scriv';
        $uuid = 'A1111111-1111-1111-1111-111111111111';
        mkdir($project . '/Files/Data/' . $uuid, 0777, true);

        file_put_contents($project . '/book.scrivx', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ScrivenerProject>
    <Binder>
        <BinderItem UUID="ROOT-BOOK" Type="Folder">
            <Title>The Book</Title>
            <Children>
                <BinderItem UUID="A1111111-1111-1111-1111-111111111111" Type="Text">
                    <Title>Scene One</Title>
                </BinderItem>
            </Children>
        </BinderItem>
    </Binder>
</ScrivenerProject>
XML
        );

        $dbPath = sys_get_temp_dir() . '/sparkinsight_import_' . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE content_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    book_title TEXT,
    version_label TEXT NOT NULL,
    source TEXT,
    content_rtf TEXT,
    content_text TEXT,
    author_id INTEGER,
    status TEXT NOT NULL,
    metadata TEXT,
    import_batch_id TEXT,
    imported_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL
        );
        $connection->executeStatement(<<<'SQL'
CREATE TABLE reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_version_id INTEGER NOT NULL,
    reviewer_id INTEGER,
    title TEXT NOT NULL,
    status TEXT NOT NULL,
    details TEXT,
    selected_excerpt TEXT,
    anchor_start_offset INTEGER,
    anchor_end_offset INTEGER,
    anchor_container_path TEXT,
    anchor_remap_state TEXT,
    anchor_remap_confidence TEXT,
    anchor_remap_reason TEXT,
    anchor_remapped_from_review_id INTEGER,
    anchor_remapped_from_content_version_id INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    resolved_at TEXT,
    resolution_decision TEXT,
    resolution_actor_id INTEGER,
    resolution_actor_role TEXT,
    resolution_recorded_at TEXT
);
SQL
        );

        file_put_contents($project . '/Files/Data/' . $uuid . '/content.rtf', '{\\rtf1\\ansi\\deff0 Alpha beta gamma delta}');

        $service = new ContentImportService($connection);
        $firstImport = $service->importScrivenerDirectory($directory, 123, 'v1', false, 'Book Import');
        $firstVersionId = (int) ($firstImport['imported']['Book Import/Scene One']['id'] ?? 0);
        $this->assertGreaterThan(0, $firstVersionId);

        $connection->executeStatement(
            "INSERT INTO reviews (content_version_id, reviewer_id, title, status, details, selected_excerpt, anchor_start_offset, anchor_end_offset, anchor_container_path, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))",
            [$firstVersionId, 11, 'Reviewer note', 'open', 'No longer matches exactly.', 'beta gamma', 6, 16, 'article[1]/p[1]']
        );

        file_put_contents($project . '/Files/Data/' . $uuid . '/content.rtf', '{\\rtf1\\ansi\\deff0 Entirely rewritten content with no overlap}');

        $secondImport = $service->importScrivenerDirectory($directory, 123, 'v2', false, 'Book Import');
        $secondVersionId = (int) ($secondImport['imported']['Book Import/Scene One']['id'] ?? 0);
        $this->assertGreaterThan(0, $secondVersionId);

        $remapped = $connection->fetchAssociative(
            'SELECT status, anchor_start_offset, anchor_end_offset, anchor_container_path, anchor_remap_state, anchor_remap_confidence, anchor_remap_reason FROM reviews WHERE content_version_id = ? ORDER BY id DESC LIMIT 1',
            [$secondVersionId]
        );

        $this->assertIsArray($remapped);
        $this->assertSame('needs_author_review', (string) $remapped['status']);
        $this->assertNull($remapped['anchor_start_offset']);
        $this->assertNull($remapped['anchor_end_offset']);
        $this->assertNull($remapped['anchor_container_path']);
        $this->assertSame('failed', (string) $remapped['anchor_remap_state']);
        $this->assertSame('failed', (string) $remapped['anchor_remap_confidence']);
        $this->assertSame('no_match_in_new_content', (string) $remapped['anchor_remap_reason']);

        $updatedMetadata = $connection->fetchOne('SELECT metadata FROM content_versions WHERE id = ?', [$secondVersionId]);
        $this->assertIsString($updatedMetadata);
        $metadata = json_decode($updatedMetadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, (int) ($metadata['anchor_remap']['copied_reviews'] ?? 0));
        $this->assertSame(1, (int) ($metadata['anchor_remap']['failed'] ?? 0));

        $connection->close();
        unlink($dbPath);
        $this->deleteDirectoryRecursively($directory);
    }

    private function deleteDirectoryRecursively(string $directory): void
    {
        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->deleteDirectoryRecursively($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
