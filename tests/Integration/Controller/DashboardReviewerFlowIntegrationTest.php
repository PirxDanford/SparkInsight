<?php

declare(strict_types=1);

namespace SparkInsightTest\Integration\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\PhpRenderer;
use SparkInsight\Controller\DashboardController;
use SparkInsight\Service\UserSession;

final class DashboardReviewerFlowIntegrationTest extends TestCase
{
    private Connection $connection;
    private UserSession $session;
    private PhpRenderer&MockObject $renderer;
    private DashboardController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->createSchema();
        $this->seedReviewerFixture();

        $this->session = new UserSession();
        $this->session->setUser([
            'id' => 7,
            'name' => 'Reviewer User',
            'roles' => ['reviewer'],
        ]);

        $this->renderer = $this->createMock(PhpRenderer::class);
        $this->controller = new DashboardController($this->renderer, $this->session, $this->connection);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];

        parent::tearDown();
    }

    public function testSubmitReviewPersistsWholeItemAnchorAndKeepsQueueReturnState(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/dashboard/review/101')
            ->withParsedBody([
                '_csrf' => $this->session->getCsrfToken(),
                'requires_action' => '1',
                'details' => 'Whole-item note without explicit text selection.',
                'selected_excerpt' => '',
                'anchor_start_offset' => '',
                'anchor_end_offset' => '',
                'anchor_container_path' => '',
                'view' => 'compact-visible',
                'return_queue_query' => 'q=Chapter+2&priority=high&state=in_review&sort=chapter_desc&page=3&per_page=50',
            ]);

        $result = $this->controller->submitReview($request, new Response(), ['id' => 101]);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(
            '/dashboard/review/101?q=Chapter+2&priority=high&state=in_review&sort=chapter_desc&page=3&per_page=50&view=compact-visible',
            $result->getHeaderLine('Location')
        );

        $row = $this->connection->fetchAssociative(
            'SELECT reviewer_id, status, details, selected_excerpt, anchor_start_offset, anchor_end_offset, anchor_container_path FROM reviews WHERE content_version_id = ? ORDER BY id DESC LIMIT 1',
            [101]
        );

        $this->assertIsArray($row);
        $this->assertSame(7, (int) $row['reviewer_id']);
        $this->assertSame('needs_author_review', (string) $row['status']);
        $this->assertSame('Whole-item note without explicit text selection.', (string) $row['details']);
        $this->assertNull($row['selected_excerpt']);
        $this->assertNull($row['anchor_start_offset']);
        $this->assertNull($row['anchor_end_offset']);
        $this->assertNull($row['anchor_container_path']);
    }

    public function testSubmitReviewPersistsPositionAndRangeAnchors(): void
    {
        $firstRequest = (new ServerRequestFactory())
            ->createServerRequest('POST', '/dashboard/review/101')
            ->withParsedBody([
                '_csrf' => $this->session->getCsrfToken(),
                'requires_action' => '0',
                'details' => 'Position note.',
                'selected_excerpt' => 'cursor location',
                'anchor_start_offset' => '42',
                'anchor_end_offset' => '42',
                'anchor_container_path' => 'article[1]/p[3]',
                'view' => 'fullscreen',
                'return_queue_query' => 'q=Chapter+2&priority=all&state=all&sort=binder_asc&page=1&per_page=25',
            ]);

        $firstResult = $this->controller->submitReview($firstRequest, new Response(), ['id' => 101]);

        $this->assertSame(302, $firstResult->getStatusCode());
        $this->assertSame(
            '/dashboard/review/101?q=Chapter+2&priority=all&state=all&sort=binder_asc&page=1&per_page=25&view=fullscreen',
            $firstResult->getHeaderLine('Location')
        );

        $noteId = (int) $this->connection->fetchOne('SELECT id FROM reviews WHERE content_version_id = ? ORDER BY id DESC LIMIT 1', [101]);
        $this->assertGreaterThan(0, $noteId);

        $secondRequest = (new ServerRequestFactory())
            ->createServerRequest('POST', '/dashboard/review/101')
            ->withParsedBody([
                '_csrf' => $this->session->getCsrfToken(),
                'note_id' => (string) $noteId,
                'requires_action' => '1',
                'details' => 'Range note update.',
                'selected_excerpt' => 'selected phrase',
                'anchor_start_offset' => '42',
                'anchor_end_offset' => '61',
                'anchor_container_path' => 'article[1]/p[4]',
                'view' => 'compact-hidden',
                'return_queue_query' => 'q=Chapter+2&priority=all&state=all&sort=binder_asc&page=1&per_page=25',
            ]);

        $secondResult = $this->controller->submitReview($secondRequest, new Response(), ['id' => 101]);

        $this->assertSame(302, $secondResult->getStatusCode());
        $this->assertSame(
            '/dashboard/review/101?q=Chapter+2&priority=all&state=all&sort=binder_asc&page=1&per_page=25&view=compact-hidden',
            $secondResult->getHeaderLine('Location')
        );

        $row = $this->connection->fetchAssociative(
            'SELECT id, status, selected_excerpt, anchor_start_offset, anchor_end_offset, anchor_container_path FROM reviews WHERE id = ?',
            [$noteId]
        );

        $this->assertIsArray($row);
        $this->assertSame($noteId, (int) $row['id']);
        $this->assertSame('needs_author_review', (string) $row['status']);
        $this->assertSame('selected phrase', (string) $row['selected_excerpt']);
        $this->assertSame(42, (int) $row['anchor_start_offset']);
        $this->assertSame(61, (int) $row['anchor_end_offset']);
        $this->assertSame('article[1]/p[4]', (string) $row['anchor_container_path']);

        $reviewCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reviews WHERE content_version_id = ?', [101]);
        $this->assertSame(1, $reviewCount);
    }

    public function testReviewItemKeepsSanitizedQueueReturnStateInViewContext(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/dashboard/review/101')
            ->withQueryParams([
                'q' => 'Chapter 2',
                'priority' => 'HIGH',
                'state' => 'in_review',
                'sort' => 'chapter_desc',
                'page' => '-4',
                'per_page' => '15',
                'ignored' => '1',
            ]);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with(
                $this->isInstanceOf(Response::class),
                'reader-item.php',
                $this->callback(function (array $data): bool {
                    return (int) ($data['review_item']['id'] ?? 0) === 101
                        && (string) ($data['queue_query_string'] ?? '') === 'q=Chapter+2&priority=high&state=in_review&sort=chapter_desc&page=1&per_page=25'
                        && (string) ($data['queue_back_url'] ?? '') === '/dashboard/review?q=Chapter+2&priority=high&state=in_review&sort=chapter_desc&page=1&per_page=25';
                })
            )
            ->willReturn(new Response());

        $result = $this->controller->reviewItem($request, new Response(), ['id' => 101]);

        $this->assertSame(200, $result->getStatusCode());
    }

    private function createSchema(): void
    {
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        $this->connection->executeStatement(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                provider TEXT NOT NULL,
                provider_id TEXT NOT NULL,
                email TEXT NOT NULL,
                name TEXT NOT NULL,
                avatar TEXT NULL,
                roles TEXT NOT NULL,
                status TEXT NOT NULL,
                invitation_used TEXT NULL,
                last_login TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE content_versions (
                id INTEGER PRIMARY KEY,
                title TEXT NOT NULL,
                book_title TEXT NULL,
                version_label TEXT NOT NULL,
                source TEXT NULL,
                content_text TEXT NULL,
                content_rtf TEXT NULL,
                author_id INTEGER NULL,
                status TEXT NOT NULL,
                metadata TEXT NULL,
                imported_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY(author_id) REFERENCES users(id) ON DELETE SET NULL
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE review_assignments (
                id INTEGER PRIMARY KEY,
                content_version_id INTEGER NOT NULL,
                reviewer_id INTEGER NOT NULL,
                priority TEXT NOT NULL,
                due_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY(content_version_id) REFERENCES content_versions(id) ON DELETE CASCADE,
                FOREIGN KEY(reviewer_id) REFERENCES users(id) ON DELETE CASCADE
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE reviews (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                content_version_id INTEGER NOT NULL,
                reviewer_id INTEGER NULL,
                title TEXT NOT NULL,
                status TEXT NOT NULL,
                details TEXT NULL,
                selected_excerpt TEXT NULL,
                anchor_start_offset INTEGER NULL,
                anchor_end_offset INTEGER NULL,
                anchor_container_path TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                resolved_at TEXT NULL,
                FOREIGN KEY(content_version_id) REFERENCES content_versions(id) ON DELETE CASCADE,
                FOREIGN KEY(reviewer_id) REFERENCES users(id) ON DELETE SET NULL
            )'
        );
    }

    private function seedReviewerFixture(): void
    {
        $now = '2026-07-05 10:00:00';

        $this->connection->insert('users', [
            'id' => 6,
            'provider' => 'github',
            'provider_id' => 'author-6',
            'email' => 'author@example.com',
            'name' => 'Author User',
            'avatar' => null,
            'roles' => '["author"]',
            'status' => 'active',
            'invitation_used' => null,
            'last_login' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->connection->insert('users', [
            'id' => 7,
            'provider' => 'github',
            'provider_id' => 'reviewer-7',
            'email' => 'reviewer@example.com',
            'name' => 'Reviewer User',
            'avatar' => null,
            'roles' => '["reviewer"]',
            'status' => 'active',
            'invitation_used' => null,
            'last_login' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->connection->insert('content_versions', [
            'id' => 101,
            'title' => 'Chapter 2',
            'book_title' => 'Book One',
            'version_label' => 'v1',
            'source' => 'chapter-2.fdx',
            'content_text' => "First paragraph\n\nSecond paragraph",
            'content_rtf' => '',
            'author_id' => 6,
            'status' => 'ready',
            'metadata' => null,
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->connection->insert('review_assignments', [
            'id' => 1,
            'content_version_id' => 101,
            'reviewer_id' => 7,
            'priority' => 'normal',
            'due_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
