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

final class DashboardAuthorFlowIntegrationTest extends TestCase
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
        $this->seedAuthorFixture();

        $this->session = new UserSession();
        $this->session->setUser([
            'id' => 21,
            'name' => 'Author User',
            'roles' => ['author'],
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

    public function testResolveAuthorReviewUpdatesStatusAndPreservesQueueState(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/dashboard/author/301/review/901/resolve')
            ->withParsedBody([
                '_csrf' => $this->session->getCsrfToken(),
                'resolution_action' => 'resolved',
                'return_queue_query' => 'q=Chapter&state=needs_author_reply&sort=binder_asc&page=2&per_page=25',
            ]);

        $result = $this->controller->resolveAuthorReview($request, new Response(), ['id' => 301, 'reviewId' => 901]);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(
            '/dashboard/author/301?q=Chapter&state=needs_author_reply&sort=binder_asc&page=2&per_page=25',
            $result->getHeaderLine('Location')
        );

        $row = $this->connection->fetchAssociative(
            'SELECT status, resolved_at, resolution_decision, resolution_actor_id, resolution_actor_role, resolution_recorded_at FROM reviews WHERE id = ?',
            [901]
        );

        $this->assertIsArray($row);
        $this->assertSame('resolved', (string) $row['status']);
        $this->assertNotNull($row['resolved_at']);
        $this->assertSame('resolved', (string) $row['resolution_decision']);
        $this->assertSame(21, (int) $row['resolution_actor_id']);
        $this->assertSame('author', (string) $row['resolution_actor_role']);
        $this->assertNotNull($row['resolution_recorded_at']);

        $events = $this->connection->fetchAllAssociative(
            'SELECT source_content_version_id, target_content_version_id, previous_status, target_status, resolution_decision, actor_id, actor_role, recorded_at FROM review_resolution_events WHERE review_id = ? ORDER BY id ASC',
            [901]
        );

        $this->assertCount(1, $events);
        $this->assertSame(301, (int) $events[0]['source_content_version_id']);
        $this->assertSame(301, (int) $events[0]['target_content_version_id']);
        $this->assertSame('needs_author_review', (string) $events[0]['previous_status']);
        $this->assertSame('resolved', (string) $events[0]['target_status']);
        $this->assertSame('resolved', (string) $events[0]['resolution_decision']);
        $this->assertSame(21, (int) $events[0]['actor_id']);
        $this->assertSame('author', (string) $events[0]['actor_role']);
        $this->assertNotNull($events[0]['recorded_at']);
    }

    public function testResolveOrphanedAuthorReviewRequiresParentAndCanReassignIt(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/dashboard/author/301/review/902/resolve')
            ->withParsedBody([
                '_csrf' => $this->session->getCsrfToken(),
                'resolution_action' => 'resolved',
            ]);

        $blockedResult = $this->controller->resolveAuthorReview($request, new Response(), ['id' => 301, 'reviewId' => 902]);

        $this->assertSame(302, $blockedResult->getStatusCode());
        $this->assertSame('/dashboard/author/301', $blockedResult->getHeaderLine('Location'));

        $row = $this->connection->fetchAssociative(
            'SELECT status, content_version_id, resolution_decision, resolved_at FROM reviews WHERE id = ?',
            [902]
        );

        $this->assertIsArray($row);
        $this->assertSame('needs_author_review', (string) $row['status']);
        $this->assertSame(301, (int) $row['content_version_id']);
        $this->assertNull($row['resolution_decision']);
        $this->assertNull($row['resolved_at']);
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM review_resolution_events WHERE review_id = ?', [902]));

        $reparentRequest = (new ServerRequestFactory())
            ->createServerRequest('POST', '/dashboard/author/301/review/902/resolve')
            ->withParsedBody([
                '_csrf' => $this->session->getCsrfToken(),
                'resolution_action' => 'resolved',
                'parent_content_version_id' => '302',
            ]);

        $reparentResult = $this->controller->resolveAuthorReview($reparentRequest, new Response(), ['id' => 301, 'reviewId' => 902]);

        $this->assertSame(302, $reparentResult->getStatusCode());
        $this->assertSame('/dashboard/author/302', $reparentResult->getHeaderLine('Location'));

        $updatedRow = $this->connection->fetchAssociative(
            'SELECT status, content_version_id, resolution_decision, resolution_actor_role, resolved_at FROM reviews WHERE id = ?',
            [902]
        );

        $this->assertIsArray($updatedRow);
        $this->assertSame('resolved', (string) $updatedRow['status']);
        $this->assertSame(302, (int) $updatedRow['content_version_id']);
        $this->assertSame('resolved', (string) $updatedRow['resolution_decision']);
        $this->assertSame('author', (string) $updatedRow['resolution_actor_role']);
        $this->assertNotNull($updatedRow['resolved_at']);

        $events = $this->connection->fetchAllAssociative(
            'SELECT source_content_version_id, target_content_version_id, previous_status, target_status, resolution_decision FROM review_resolution_events WHERE review_id = ? ORDER BY id ASC',
            [902]
        );

        $this->assertCount(1, $events);
        $this->assertSame(301, (int) $events[0]['source_content_version_id']);
        $this->assertSame(302, (int) $events[0]['target_content_version_id']);
        $this->assertSame('needs_author_review', (string) $events[0]['previous_status']);
        $this->assertSame('resolved', (string) $events[0]['target_status']);
        $this->assertSame('resolved', (string) $events[0]['resolution_decision']);
    }

    public function testResolveAuthorReviewStillRelevantKeepsNeedsAuthorReplyState(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/dashboard/author/301/review/902/resolve')
            ->withParsedBody([
                '_csrf' => $this->session->getCsrfToken(),
                'resolution_action' => 'still_relevant',
                'return_queue_query' => 'q=Chapter&state=needs_author_reply&sort=binder_asc&page=2&per_page=25',
            ]);

        $result = $this->controller->resolveAuthorReview($request, new Response(), ['id' => 301, 'reviewId' => 902]);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(
            '/dashboard/author/301?q=Chapter&state=needs_author_reply&sort=binder_asc&page=2&per_page=25',
            $result->getHeaderLine('Location')
        );

        $row = $this->connection->fetchAssociative(
            'SELECT status, content_version_id, resolved_at, resolution_decision, resolution_actor_id, resolution_actor_role, resolution_recorded_at FROM reviews WHERE id = ?',
            [902]
        );

        $this->assertIsArray($row);
        $this->assertSame('needs_author_review', (string) $row['status']);
        $this->assertSame(301, (int) $row['content_version_id']);
        $this->assertNull($row['resolved_at']);
        $this->assertSame('still_relevant', (string) $row['resolution_decision']);
        $this->assertSame(21, (int) $row['resolution_actor_id']);
        $this->assertSame('author', (string) $row['resolution_actor_role']);
        $this->assertNotNull($row['resolution_recorded_at']);

        $events = $this->connection->fetchAllAssociative(
            'SELECT source_content_version_id, target_content_version_id, previous_status, target_status, resolution_decision FROM review_resolution_events WHERE review_id = ? ORDER BY id ASC',
            [902]
        );

        $this->assertCount(1, $events);
        $this->assertSame(301, (int) $events[0]['source_content_version_id']);
        $this->assertSame(301, (int) $events[0]['target_content_version_id']);
        $this->assertSame('needs_author_review', (string) $events[0]['previous_status']);
        $this->assertSame('needs_author_review', (string) $events[0]['target_status']);
        $this->assertSame('still_relevant', (string) $events[0]['resolution_decision']);
    }

    public function testAuthorItemRendersOwnedReviewHistory(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/dashboard/author/301')
            ->withQueryParams([
                'q' => 'Chapter',
                'state' => 'needs_author_reply',
                'sort' => 'binder_asc',
                'page' => '2',
                'per_page' => '25',
            ]);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with(
                $this->isInstanceOf(Response::class),
                'author-item.php',
                $this->callback(function (array $data): bool {
                    $reviews = $data['author_item']['reviews'] ?? [];

                    if ((int) ($data['author_item']['id'] ?? 0) !== 301 || !is_array($reviews) || count($reviews) < 1) {
                        return false;
                    }

                    $review = null;
                    foreach ($reviews as $candidate) {
                        if ((int) ($candidate['id'] ?? 0) === 901) {
                            $review = $candidate;
                            break;
                        }
                    }

                    return is_array($review)
                        && (string) ($review['resolution_decision_label'] ?? '') === ''
                        && (string) ($review['feedback_status_label'] ?? '') === 'Action requested'
                        && (string) ($review['feedback_status_detail'] ?? '') === 'Reviewer is waiting for your response.'
                        && (string) ($review['original_target_context'] ?? '') === 'First paragraph'
                        && (string) ($review['current_changed_context'] ?? '') === 'First paragraph'
                        && (string) ($review['version_linkage_label'] ?? '') === 'No prior link'
                        && str_contains((string) ($review['version_linkage_detail'] ?? ''), 'Chapter 3 (v1)')
                        && (string) ($data['queue_query_string'] ?? '') === 'q=Chapter&state=needs_author_reply&sort=binder_asc&page=2&per_page=25';
                })
            )
            ->willReturn(new Response());

        $result = $this->controller->authorItem($request, new Response(), ['id' => 301]);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function testAuthorItemSurfacesOrphanedReviewLocationAndParentOptions(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/dashboard/author/301')
            ->withQueryParams([]);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with(
                $this->isInstanceOf(Response::class),
                'author-item.php',
                $this->callback(function (array $data): bool {
                    $reviews = $data['author_item']['reviews'] ?? [];
                    $parentOptions = $data['author_parent_options'] ?? [];

                    if (!is_array($reviews) || count($reviews) !== 2 || !is_array($parentOptions)) {
                        return false;
                    }

                    $orphanedReview = null;
                    foreach ($reviews as $review) {
                        if ((int) ($review['id'] ?? 0) === 902) {
                            $orphanedReview = $review;
                            break;
                        }
                    }

                    if (!is_array($orphanedReview)) {
                        return false;
                    }

                    if ((string) ($orphanedReview['location_status_label'] ?? '') !== 'Needs parent') {
                        return false;
                    }

                    if ((bool) ($orphanedReview['location_needs_parent'] ?? false) !== true) {
                        return false;
                    }

                    if (!str_starts_with((string) ($orphanedReview['location_status_detail'] ?? ''), 'Could not find a matching location in the current content.')) {
                        return false;
                    }

                    if ((string) ($orphanedReview['location_fallback_context'] ?? '') !== 'Opening excerpt that no longer maps.') {
                        return false;
                    }

                    if ((string) ($orphanedReview['original_target_context'] ?? '') !== 'First paragraph') {
                        return false;
                    }

                    if ((string) ($orphanedReview['current_changed_context'] ?? '') !== 'Opening excerpt that no longer maps.') {
                        return false;
                    }

                    if ((string) ($orphanedReview['version_linkage_label'] ?? '') !== 'Linked from prior version') {
                        return false;
                    }

                    if ((string) ($orphanedReview['version_linkage_detail'] ?? '') !== 'Chapter 3 (v1) -> Chapter 3 (v1)') {
                        return false;
                    }

                    foreach ($parentOptions as $option) {
                        if ((int) ($option['id'] ?? 0) === 302 && str_contains((string) ($option['label'] ?? ''), 'Chapter 3')) {
                            return true;
                        }
                    }

                    return false;
                })
            )
            ->willReturn(new Response());

        $result = $this->controller->authorItem($request, new Response(), ['id' => 301]);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function testAuthorItemDoesNotLeakCrossAuthorRemapContext(): void
    {
        $now = '2026-07-06 10:00:00';

        $this->connection->insert('users', [
            'id' => 24,
            'provider' => 'github',
            'provider_id' => 'author-24',
            'email' => 'other-author@example.com',
            'name' => 'Other Author',
            'avatar' => null,
            'roles' => '["author"]',
            'status' => 'active',
            'invitation_used' => null,
            'last_login' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->connection->insert('content_versions', [
            'id' => 401,
            'title' => 'Foreign Chapter',
            'book_title' => 'Book Two',
            'version_label' => 'v1',
            'source' => 'foreign-chapter.fdx',
            'content_text' => 'Private foreign content',
            'content_rtf' => '',
            'author_id' => 24,
            'status' => 'ready',
            'metadata' => null,
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->connection->insert('reviews', [
            'id' => 990,
            'content_version_id' => 401,
            'reviewer_id' => 22,
            'title' => 'Foreign review note',
            'status' => 'needs_author_review',
            'details' => 'Private foreign details',
            'selected_excerpt' => 'Private foreign excerpt',
            'anchor_start_offset' => 0,
            'anchor_end_offset' => 22,
            'anchor_container_path' => 'article[1]/p[1]',
            'created_at' => $now,
            'updated_at' => $now,
            'resolved_at' => null,
            'resolution_decision' => null,
            'resolution_actor_id' => null,
            'resolution_actor_role' => null,
            'resolution_recorded_at' => null,
            'anchor_remap_state' => null,
            'anchor_remap_confidence' => null,
            'anchor_remap_reason' => null,
            'anchor_remapped_from_review_id' => null,
            'anchor_remapped_from_content_version_id' => null,
        ]);

        $this->connection->executeStatement(
            'UPDATE reviews SET anchor_remapped_from_review_id = ?, anchor_remapped_from_content_version_id = ? WHERE id = ?',
            [990, 401, 902]
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/dashboard/author/301');

        $this->renderer->expects($this->once())
            ->method('render')
            ->with(
                $this->isInstanceOf(Response::class),
                'author-item.php',
                $this->callback(function (array $data): bool {
                    $reviews = $data['author_item']['reviews'] ?? [];

                    if (!is_array($reviews)) {
                        return false;
                    }

                    $orphanedReview = null;
                    foreach ($reviews as $review) {
                        if ((int) ($review['id'] ?? 0) === 902) {
                            $orphanedReview = $review;
                            break;
                        }
                    }

                    if (!is_array($orphanedReview)) {
                        return false;
                    }

                    return (string) ($orphanedReview['original_target_context'] ?? '') === 'Opening excerpt that no longer maps.'
                        && (string) ($orphanedReview['version_linkage_label'] ?? '') === 'No prior link'
                        && !str_contains((string) ($orphanedReview['original_target_context'] ?? ''), 'Private foreign excerpt')
                        && !str_contains((string) ($orphanedReview['current_changed_context'] ?? ''), 'Private foreign details')
                        && !str_contains((string) ($orphanedReview['version_linkage_detail'] ?? ''), 'Foreign Chapter');
                })
            )
            ->willReturn(new Response());

        $result = $this->controller->authorItem($request, new Response(), ['id' => 301]);

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
                anchor_remap_state TEXT NULL,
                anchor_remap_confidence TEXT NULL,
                anchor_remap_reason TEXT NULL,
                anchor_remapped_from_review_id INTEGER NULL,
                anchor_remapped_from_content_version_id INTEGER NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                resolved_at TEXT NULL,
                resolution_decision TEXT NULL,
                resolution_actor_id INTEGER NULL,
                resolution_actor_role TEXT NULL,
                resolution_recorded_at TEXT NULL,
                FOREIGN KEY(content_version_id) REFERENCES content_versions(id) ON DELETE CASCADE,
                FOREIGN KEY(reviewer_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY(resolution_actor_id) REFERENCES users(id) ON DELETE SET NULL
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE review_resolution_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                review_id INTEGER NOT NULL,
                source_content_version_id INTEGER NOT NULL,
                target_content_version_id INTEGER NOT NULL,
                previous_status TEXT NULL,
                target_status TEXT NOT NULL,
                resolution_decision TEXT NOT NULL,
                actor_id INTEGER NULL,
                actor_role TEXT NULL,
                recorded_at TEXT NOT NULL,
                FOREIGN KEY(review_id) REFERENCES reviews(id) ON DELETE CASCADE,
                FOREIGN KEY(source_content_version_id) REFERENCES content_versions(id) ON DELETE CASCADE,
                FOREIGN KEY(target_content_version_id) REFERENCES content_versions(id) ON DELETE CASCADE,
                FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL
            )'
        );
    }

    private function seedAuthorFixture(): void
    {
        $now = '2026-07-06 10:00:00';

        $this->connection->insert('users', [
            'id' => 21,
            'provider' => 'github',
            'provider_id' => 'author-21',
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
            'id' => 22,
            'provider' => 'github',
            'provider_id' => 'reviewer-22',
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
            'id' => 301,
            'title' => 'Chapter 3',
            'book_title' => 'Book One',
            'version_label' => 'v1',
            'source' => 'chapter-3.fdx',
            'content_text' => "First paragraph\n\nSecond paragraph",
            'content_rtf' => '',
            'author_id' => 21,
            'status' => 'ready',
            'metadata' => null,
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->connection->insert('reviews', [
            'id' => 901,
            'content_version_id' => 301,
            'reviewer_id' => 22,
            'title' => 'Chapter 3 review note',
            'status' => 'needs_author_review',
            'details' => 'Please improve opening lines.',
            'selected_excerpt' => 'First paragraph',
            'anchor_start_offset' => 0,
            'anchor_end_offset' => 15,
            'anchor_container_path' => 'article[1]/p[1]',
            'created_at' => $now,
            'updated_at' => $now,
            'resolved_at' => null,
            'resolution_decision' => null,
            'resolution_actor_id' => null,
            'resolution_actor_role' => null,
            'resolution_recorded_at' => null,
            'anchor_remap_state' => null,
            'anchor_remap_confidence' => null,
            'anchor_remap_reason' => null,
            'anchor_remapped_from_review_id' => null,
            'anchor_remapped_from_content_version_id' => null,
        ]);

        $this->connection->insert('content_versions', [
            'id' => 302,
            'title' => 'Chapter 3 (Reparented)',
            'book_title' => 'Book One',
            'version_label' => 'v2',
            'source' => 'chapter-3-reparented.fdx',
            'content_text' => "Opening excerpt that no longer maps.\n\nSecond paragraph",
            'content_rtf' => '',
            'author_id' => 21,
            'status' => 'ready',
            'metadata' => null,
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->connection->insert('reviews', [
            'id' => 902,
            'content_version_id' => 301,
            'reviewer_id' => 22,
            'title' => 'Chapter 3 orphaned note',
            'status' => 'needs_author_review',
            'details' => 'The note moved with the draft, but the anchor no longer resolves.',
            'selected_excerpt' => 'Opening excerpt that no longer maps.',
            'anchor_start_offset' => null,
            'anchor_end_offset' => null,
            'anchor_container_path' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'resolved_at' => null,
            'resolution_decision' => null,
            'resolution_actor_id' => null,
            'resolution_actor_role' => null,
            'resolution_recorded_at' => null,
            'anchor_remap_state' => 'failed',
            'anchor_remap_confidence' => 'failed',
            'anchor_remap_reason' => 'no_match_in_new_content',
            'anchor_remapped_from_review_id' => 901,
            'anchor_remapped_from_content_version_id' => 301,
        ]);
    }
}
