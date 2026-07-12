<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\PhpRenderer;
use SparkInsight\Controller\DashboardController;
use SparkInsight\Service\AuthorPdfExportService;
use SparkInsight\Service\UserSession;

class DashboardControllerTest extends TestCase
{
    private PhpRenderer $renderer;
    private UserSession $session;
    private Connection $connection;
    /** @var AuthorPdfExportService&MockObject */
    private $pdfExportService;
    private DashboardController $controller;

    protected function setUp(): void
    {
        $this->renderer = $this->createMock(PhpRenderer::class);
        $this->session = new UserSession();
        $this->connection = $this->createMock(Connection::class);
        $this->pdfExportService = $this->createMock(AuthorPdfExportService::class);
        $this->controller = new DashboardController($this->renderer, $this->session, $this->connection, $this->pdfExportService);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    public function testInvokeWhenNotLoggedIn(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/login')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->__invoke($request, $response);

        $this->assertSame($response, $result);
    }

    public function testInvokeWhenLoggedIn(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $user = ['id' => 1, 'name' => 'Author User', 'roles' => ['author']];
        $this->session->setUser($user);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/dashboard/author')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->__invoke($request, $response);

        $this->assertSame($response, $result);
    }

    public function testInvokeRedirectsPureAdminToAdminDashboard(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $this->session->setUser(['id' => 1, 'name' => 'Admin', 'roles' => ['admin']]);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/dashboard/admin')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->__invoke($request, $response);

        $this->assertSame($response, $result);
    }

    public function testInvokeRedirectsReviewerToReviewTab(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $this->session->setUser(['id' => 1, 'name' => 'Reviewer', 'roles' => ['reviewer']]);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/dashboard/review')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->__invoke($request, $response);

        $this->assertSame($response, $result);
    }

    public function testReviewRendersForAuthor(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([]);

        $response = $this->createMock(ResponseInterface::class);

        $user = ['id' => 1, 'name' => 'Dual User', 'roles' => ['author']];
        $this->session->setUser($user);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'dashboard.php', $this->callback(function (array $data) use ($user): bool {
                return $data['user'] === $user
                    && $data['title'] === 'Review'
                    && $data['dashboard_mode'] === 'review'
                    && isset($data['reviewer_queue'])
                    && $data['author_queue'] === [];
            }))
            ->willReturn($response);

        $result = $this->controller->review($request, $response);

        $this->assertSame($response, $result);
    }

    public function testReviewIncludesGroupedOtherAssignedItemsByState(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([]);

        $response = $this->createMock(ResponseInterface::class);

        $user = ['id' => 42, 'name' => 'Reviewer User', 'roles' => ['reviewer']];
        $this->session->setUser($user);

        $readyQueueResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $readyQueueResult->expects($this->once())->method('fetchAllAssociative')->willReturn([]);

        $otherItemsResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $otherItemsResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'id' => 5,
                    'title' => 'Empty Draft A',
                    'book_title' => 'Book One',
                    'status' => 'placeholder',
                    'imported_at' => '2026-06-20 10:00:00',
                    'priority' => 'normal',
                    'due_at' => null,
                    'review_count' => 0,
                    'open_review_count' => 0,
                    'resolved_review_count' => 0,
                    'needs_author_reply_count' => 0,
                ],
                [
                    'id' => 6,
                    'title' => 'Empty Draft B',
                    'book_title' => 'Book One',
                    'status' => 'placeholder',
                    'imported_at' => '2026-06-20 09:00:00',
                    'priority' => 'high',
                    'due_at' => '2026-06-22 00:00:00',
                    'review_count' => 1,
                    'open_review_count' => 0,
                    'resolved_review_count' => 1,
                    'needs_author_reply_count' => 0,
                ],
            ]);

        $metricResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $metricResult->method('fetchOne')->willReturn('0');

        $this->connection->expects($this->exactly(6))
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql) use ($metricResult, $readyQueueResult, $otherItemsResult) {
                if (str_contains($sql, "WHERE cv.status = 'ready' AND ra.reviewer_id = ?")) {
                    return $readyQueueResult;
                }

                if (str_contains($sql, "WHERE ra.reviewer_id = ? AND cv.status <> 'ready'")) {
                    return $otherItemsResult;
                }

                return $metricResult;
            });

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'dashboard.php', $this->callback(function (array $data) use ($user): bool {
                if ($data['user'] !== $user || $data['dashboard_mode'] !== 'review') {
                    return false;
                }

                if (!isset($data['reviewer_other_items_by_state']) || !is_array($data['reviewer_other_items_by_state'])) {
                    return false;
                }

                $groups = $data['reviewer_other_items_by_state'];
                if (count($groups) !== 1) {
                    return false;
                }

                $group = $groups[0];
                if (($group['status_key'] ?? null) !== 'placeholder' || ($group['status_label'] ?? null) !== 'Placeholder') {
                    return false;
                }

                return count((array) ($group['items'] ?? [])) === 2;
            }))
            ->willReturn($response);

        $result = $this->controller->review($request, $response);

        $this->assertSame($response, $result);
    }

    public function testReviewQueueSupportsNaturalChapterSortAndStateFilter(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([
                'sort' => 'chapter_asc',
                'state' => 'ready_for_review',
                'priority' => 'all',
                'q' => 'Chapter',
                'per_page' => '25',
                'page' => '1',
            ]);

        $response = $this->createMock(ResponseInterface::class);

        $user = ['id' => 12, 'name' => 'Reviewer User', 'roles' => ['reviewer']];
        $this->session->setUser($user);

        $readyQueueResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $readyQueueResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'id' => 101,
                    'title' => 'Chapter 10',
                    'book_title' => 'Book One',
                    'version_label' => 'v10',
                    'source' => 'chapter-10.fdx',
                    'imported_at' => '2026-06-20 10:00:00',
                    'status' => 'ready',
                    'author_name' => 'Author One',
                    'priority' => 'normal',
                    'due_at' => null,
                    'review_count' => 0,
                    'open_review_count' => 0,
                    'resolved_review_count' => 0,
                    'needs_author_reply_count' => 0,
                ],
                [
                    'id' => 102,
                    'title' => 'Chapter 2',
                    'book_title' => 'Book One',
                    'version_label' => 'v2',
                    'source' => 'chapter-2.fdx',
                    'imported_at' => '2026-06-20 10:00:00',
                    'status' => 'ready',
                    'author_name' => 'Author One',
                    'priority' => 'normal',
                    'due_at' => null,
                    'review_count' => 0,
                    'open_review_count' => 0,
                    'resolved_review_count' => 0,
                    'needs_author_reply_count' => 0,
                ],
            ]);

        $otherItemsResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $otherItemsResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $metricResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $metricResult->method('fetchOne')->willReturn('0');

        $this->connection->expects($this->exactly(6))
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql) use ($metricResult, $readyQueueResult, $otherItemsResult) {
                if (str_contains($sql, "WHERE cv.status = 'ready' AND ra.reviewer_id = ?")) {
                    return $readyQueueResult;
                }

                if (str_contains($sql, "WHERE ra.reviewer_id = ? AND cv.status <> 'ready'")) {
                    return $otherItemsResult;
                }

                return $metricResult;
            });

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'dashboard.php', $this->callback(function (array $data): bool {
                $queue = $data['reviewer_queue'] ?? [];
                if (!is_array($queue) || count($queue) !== 2) {
                    return false;
                }

                if ((string) ($queue[0]['title'] ?? '') !== 'Chapter 2') {
                    return false;
                }

                if ((string) ($queue[1]['title'] ?? '') !== 'Chapter 10') {
                    return false;
                }

                return (string) (($data['reviewer_queue_filters']['sort'] ?? '')) === 'chapter_asc';
            }))
            ->willReturn($response);

        $result = $this->controller->review($request, $response);

        $this->assertSame($response, $result);
    }

    public function testReviewQueueDoesNotDuplicateExistingFolderRows(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([
                'sort' => 'binder_asc',
                'state' => 'ready_for_review',
                'priority' => 'all',
                'q' => '',
                'per_page' => '25',
                'page' => '1',
            ]);

        $response = $this->createMock(ResponseInterface::class);

        $user = ['id' => 12, 'name' => 'Reviewer User', 'roles' => ['reviewer']];
        $this->session->setUser($user);

        $readyQueueResult = $this->createMock(
            \Doctrine\DBAL\Result::class
        );
        $readyQueueResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'id' => 201,
                    'title' => 'Foundations',
                    'book_title' => 'Book One',
                    'version_label' => 'v1',
                    'source' => 'foundations.fdx',
                    'imported_at' => '2026-06-20 10:00:00',
                    'status' => 'ready',
                    'author_name' => 'Author One',
                    'priority' => 'normal',
                    'due_at' => null,
                    'review_count' => 0,
                    'open_review_count' => 0,
                    'resolved_review_count' => 0,
                    'needs_author_reply_count' => 0,
                    'metadata' => json_encode([
                        'scrivener' => [
                            'list_path' => 'The Book/Front Matter/Foundations',
                            'order_path' => '0001.0001.0001',
                            'kind' => 'directory',
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
                [
                    'id' => 202,
                    'title' => 'Scene One',
                    'book_title' => 'Book One',
                    'version_label' => 'v2',
                    'source' => 'scene-one.fdx',
                    'imported_at' => '2026-06-20 10:00:00',
                    'status' => 'ready',
                    'author_name' => 'Author One',
                    'priority' => 'normal',
                    'due_at' => null,
                    'review_count' => 0,
                    'open_review_count' => 0,
                    'resolved_review_count' => 0,
                    'needs_author_reply_count' => 0,
                    'metadata' => json_encode([
                        'scrivener' => [
                            'list_path' => 'The Book/Front Matter/Foundations/Scene One',
                            'order_path' => '0001.0001.0001.0001',
                            'kind' => 'text',
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
            ]);

        $otherItemsResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $otherItemsResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $metricResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $metricResult->method('fetchOne')->willReturn('0');

        $this->connection->expects($this->exactly(6))
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql) use ($metricResult, $readyQueueResult, $otherItemsResult) {
                if (str_contains($sql, "WHERE cv.status = 'ready' AND ra.reviewer_id = ?")) {
                    return $readyQueueResult;
                }

                if (str_contains($sql, "WHERE ra.reviewer_id = ? AND cv.status <> 'ready'")) {
                    return $otherItemsResult;
                }

                return $metricResult;
            });

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'dashboard.php', $this->callback(function (array $data): bool {
                $queue = $data['reviewer_queue'] ?? [];
                if (!is_array($queue)) {
                    return false;
                }

                $foundationsCount = 0;
                $foundationsRow = null;
                foreach ($queue as $row) {
                    if ((string) ($row['list_path'] ?? '') === 'The Book/Front Matter/Foundations') {
                        $foundationsCount++;
                        $foundationsRow = $row;
                    }
                }

                return count($queue) === 4
                    && $foundationsCount === 1
                    && is_array($foundationsRow)
                    && !empty($foundationsRow['is_openable'])
                    && str_contains((string) ($foundationsRow['url'] ?? ''), '/dashboard/review/201');
            }))
            ->willReturn($response);

        $result = $this->controller->review($request, $response);

        $this->assertSame($response, $result);
    }

    public function testAuthorsRendersForAuthor(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([]);
        $response = $this->createMock(ResponseInterface::class);

        $user = ['id' => 1, 'name' => 'Author', 'roles' => ['author']];
        $this->session->setUser($user);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'dashboard.php', $this->callback(function (array $data) use ($user): bool {
                return $data['user'] === $user
                    && $data['title'] === 'Author'
                    && $data['dashboard_mode'] === 'author'
                    && isset($data['author_queue'])
                    && $data['reviewer_queue'] === [];
            }))
            ->willReturn($response);

        $result = $this->controller->author($request, $response);

        $this->assertSame($response, $result);
    }

    public function testAuthorItemRendersResolutionContext(): void
    {
        $this->session->setUser(['id' => 11, 'name' => 'Author User', 'roles' => ['author']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([
                'q' => 'Chapter',
                'state' => 'needs_author_reply',
                'sort' => 'binder_asc',
                'page' => '2',
                'per_page' => '25',
            ]);

        $response = $this->createMock(ResponseInterface::class);

        $itemResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $itemResult->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 77,
                'title' => 'Chapter 7',
                'book_title' => 'Book One',
                'version_label' => 'v1',
                'source' => 'chapter-7.fdx',
                'content_rtf' => '',
                'content_text' => "Para one\n\nPara two",
                'metadata' => null,
                'imported_at' => '2026-07-05 08:00:00',
                'content_status' => 'ready',
                'review_count' => 1,
                'open_review_count' => 0,
                'resolved_review_count' => 0,
                'needs_author_reply_count' => 1,
            ]);

        $historyResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $historyResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'id' => 501,
                    'reviewer_id' => 3,
                    'title' => 'Chapter 7 review note',
                    'status' => 'needs_author_review',
                    'details' => 'Please tighten this paragraph.',
                    'selected_excerpt' => 'Para one',
                    'anchor_start_offset' => 0,
                    'anchor_end_offset' => 8,
                    'anchor_container_path' => 'article[1]/p[1]',
                    'created_at' => '2026-07-05 09:00:00',
                    'updated_at' => '2026-07-05 09:00:00',
                    'resolved_at' => null,
                    'resolution_decision' => null,
                    'resolution_actor_id' => null,
                    'resolution_actor_role' => null,
                    'resolution_recorded_at' => null,
                    'resolution_actor_name' => null,
                    'reviewer_name' => 'Reviewer One',
                ],
            ]);

        $parentQueueResult = $this->createMock(
            \Doctrine\DBAL\Result::class
        );
        $parentQueueResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $this->connection->expects($this->exactly(3))
            ->method('executeQuery')
            ->willReturnOnConsecutiveCalls($itemResult, $historyResult, $parentQueueResult);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'author-item.php', $this->callback(function (array $data): bool {
                return $data['title'] === 'Author'
                    && isset($data['author_item'])
                    && (int) ($data['author_item']['id'] ?? 0) === 77
                    && (string) ($data['author_item']['reviews'][0]['resolution_decision_label'] ?? '') === ''
                    && (string) ($data['author_item']['reviews'][0]['feedback_status_label'] ?? '') === 'Action requested'
                    && (string) ($data['author_item']['reviews'][0]['feedback_status_detail'] ?? '') === 'Reviewer is waiting for your response.'
                    && (string) ($data['author_item']['reviews'][0]['original_target_context'] ?? '') === 'Para one'
                    && (string) ($data['author_item']['reviews'][0]['current_changed_context'] ?? '') === 'Para one'
                    && (string) ($data['author_item']['reviews'][0]['version_linkage_label'] ?? '') === 'No prior link'
                    && str_contains((string) ($data['author_item']['reviews'][0]['version_linkage_detail'] ?? ''), 'Chapter 7 (v1)')
                    && (string) ($data['queue_query_string'] ?? '') === 'q=Chapter&state=needs_author_reply&sort=binder_asc&page=2&per_page=25'
                    && str_contains((string) ($data['queue_back_url'] ?? ''), '/dashboard/author?');
            }))
            ->willReturn($response);

        $result = $this->controller->authorItem($request, $response, ['id' => 77]);

        $this->assertSame($response, $result);
    }

    public function testResolveAuthorReviewMarksNoteResolvedAndRedirectsBackToItem(): void
    {
        $this->session->setUser(['id' => 11, 'name' => 'Author User', 'roles' => ['author']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $this->session->getCsrfToken(),
                'resolution_action' => 'resolved',
                'return_queue_query' => 'q=Chapter&state=needs_author_reply&sort=binder_asc&page=2&per_page=25',
            ]);

        $response = $this->createMock(ResponseInterface::class);

        $this->connection->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                'SELECT r.id, r.status, r.content_version_id, r.anchor_remap_state, r.anchor_remap_reason, r.anchor_remapped_from_content_version_id FROM reviews r INNER JOIN content_versions cv ON cv.id = r.content_version_id WHERE r.id = ? AND r.content_version_id = ? AND cv.author_id = ?',
                [501, 77, 11]
            )
            ->willReturn([
                'id' => 501,
                'status' => 'needs_author_review',
            ]);

        $executeStatementCall = 0;
        $this->connection->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$executeStatementCall): int {
                $executeStatementCall++;

                if ($executeStatementCall === 1) {
                    $this->assertSame(
                        'UPDATE reviews SET status = ?, updated_at = ?, resolved_at = ?, resolution_decision = ?, resolution_actor_id = ?, resolution_actor_role = ?, resolution_recorded_at = ? WHERE id = ? AND content_version_id = ?',
                        $sql
                    );
                    $this->assertSame('resolved', (string) ($params[0] ?? ''));
                    $this->assertIsString($params[1] ?? null);
                    $this->assertIsString($params[2] ?? null);
                    $this->assertSame('resolved', (string) ($params[3] ?? ''));
                    $this->assertSame(11, (int) ($params[4] ?? 0));
                    $this->assertSame('author', (string) ($params[5] ?? ''));
                    $this->assertIsString($params[6] ?? null);
                    $this->assertSame(501, (int) ($params[7] ?? 0));
                    $this->assertSame(77, (int) ($params[8] ?? 0));

                    return 1;
                }

                $this->assertSame(
                    'INSERT INTO review_resolution_events (review_id, source_content_version_id, target_content_version_id, previous_status, target_status, resolution_decision, actor_id, actor_role, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    $sql
                );
                $this->assertSame(501, (int) ($params[0] ?? 0));
                $this->assertSame(77, (int) ($params[1] ?? 0));
                $this->assertSame(77, (int) ($params[2] ?? 0));
                $this->assertSame('needs_author_review', (string) ($params[3] ?? ''));
                $this->assertSame('resolved', (string) ($params[4] ?? ''));
                $this->assertSame('resolved', (string) ($params[5] ?? ''));
                $this->assertSame(11, (int) ($params[6] ?? 0));
                $this->assertSame('author', (string) ($params[7] ?? ''));
                $this->assertIsString($params[8] ?? null);

                return 1;
            });

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/dashboard/author/77?q=Chapter&state=needs_author_reply&sort=binder_asc&page=2&per_page=25')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->resolveAuthorReview($request, $response, ['id' => 77, 'reviewId' => 501]);

        $this->assertSame($response, $result);
    }

    public function testResolveAuthorReviewMarksNoteIgnoredAndRedirectsBackToItem(): void
    {
        $this->session->setUser(['id' => 11, 'name' => 'Author User', 'roles' => ['author']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $this->session->getCsrfToken(),
                'resolution_action' => 'ignored',
                'return_queue_query' => 'q=Chapter&state=needs_author_reply&sort=binder_asc&page=2&per_page=25',
            ]);

        $response = $this->createMock(ResponseInterface::class);

        $this->connection->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 501,
                'status' => 'needs_author_review',
            ]);

        $executeStatementCall = 0;
        $this->connection->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$executeStatementCall): int {
                $executeStatementCall++;

                if ($executeStatementCall === 1) {
                    $this->assertSame(
                        'UPDATE reviews SET status = ?, updated_at = ?, resolved_at = ?, resolution_decision = ?, resolution_actor_id = ?, resolution_actor_role = ?, resolution_recorded_at = ? WHERE id = ? AND content_version_id = ?',
                        $sql
                    );
                    $this->assertSame('resolved', (string) ($params[0] ?? ''));
                    $this->assertIsString($params[1] ?? null);
                    $this->assertIsString($params[2] ?? null);
                    $this->assertSame('ignored', (string) ($params[3] ?? ''));
                    $this->assertSame(11, (int) ($params[4] ?? 0));
                    $this->assertSame('author', (string) ($params[5] ?? ''));
                    $this->assertIsString($params[6] ?? null);
                    $this->assertSame(501, (int) ($params[7] ?? 0));
                    $this->assertSame(77, (int) ($params[8] ?? 0));

                    return 1;
                }

                $this->assertSame(
                    'INSERT INTO review_resolution_events (review_id, source_content_version_id, target_content_version_id, previous_status, target_status, resolution_decision, actor_id, actor_role, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    $sql
                );
                $this->assertSame(501, (int) ($params[0] ?? 0));
                $this->assertSame(77, (int) ($params[1] ?? 0));
                $this->assertSame(77, (int) ($params[2] ?? 0));
                $this->assertSame('needs_author_review', (string) ($params[3] ?? ''));
                $this->assertSame('resolved', (string) ($params[4] ?? ''));
                $this->assertSame('ignored', (string) ($params[5] ?? ''));
                $this->assertSame(11, (int) ($params[6] ?? 0));
                $this->assertSame('author', (string) ($params[7] ?? ''));
                $this->assertIsString($params[8] ?? null);

                return 1;
            });

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/dashboard/author/77?q=Chapter&state=needs_author_reply&sort=binder_asc&page=2&per_page=25')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->resolveAuthorReview($request, $response, ['id' => 77, 'reviewId' => 501]);

        $this->assertSame($response, $result);
    }

    public function testResolveAuthorReviewMarksNoteStillRelevantAndRedirectsBackToItem(): void
    {
        $this->session->setUser(['id' => 11, 'name' => 'Author User', 'roles' => ['author']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $this->session->getCsrfToken(),
                'resolution_action' => 'still_relevant',
                'return_queue_query' => 'q=Chapter&state=needs_author_reply&sort=binder_asc&page=2&per_page=25',
            ]);

        $response = $this->createMock(ResponseInterface::class);

        $this->connection->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 501,
                'status' => 'needs_author_review',
            ]);

        $executeStatementCall = 0;
        $this->connection->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$executeStatementCall): int {
                $executeStatementCall++;

                if ($executeStatementCall === 1) {
                    $this->assertSame(
                        'UPDATE reviews SET status = ?, updated_at = ?, resolved_at = ?, resolution_decision = ?, resolution_actor_id = ?, resolution_actor_role = ?, resolution_recorded_at = ? WHERE id = ? AND content_version_id = ?',
                        $sql
                    );
                    $this->assertSame('needs_author_review', (string) ($params[0] ?? ''));
                    $this->assertIsString($params[1] ?? null);
                    $this->assertNull($params[2] ?? null);
                    $this->assertSame('still_relevant', (string) ($params[3] ?? ''));
                    $this->assertSame(11, (int) ($params[4] ?? 0));
                    $this->assertSame('author', (string) ($params[5] ?? ''));
                    $this->assertIsString($params[6] ?? null);
                    $this->assertSame(501, (int) ($params[7] ?? 0));
                    $this->assertSame(77, (int) ($params[8] ?? 0));

                    return 1;
                }

                $this->assertSame(
                    'INSERT INTO review_resolution_events (review_id, source_content_version_id, target_content_version_id, previous_status, target_status, resolution_decision, actor_id, actor_role, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    $sql
                );
                $this->assertSame(501, (int) ($params[0] ?? 0));
                $this->assertSame(77, (int) ($params[1] ?? 0));
                $this->assertSame(77, (int) ($params[2] ?? 0));
                $this->assertSame('needs_author_review', (string) ($params[3] ?? ''));
                $this->assertSame('needs_author_review', (string) ($params[4] ?? ''));
                $this->assertSame('still_relevant', (string) ($params[5] ?? ''));
                $this->assertSame(11, (int) ($params[6] ?? 0));
                $this->assertSame('author', (string) ($params[7] ?? ''));
                $this->assertIsString($params[8] ?? null);

                return 1;
            });

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/dashboard/author/77?q=Chapter&state=needs_author_reply&sort=binder_asc&page=2&per_page=25')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->resolveAuthorReview($request, $response, ['id' => 77, 'reviewId' => 501]);

        $this->assertSame($response, $result);
    }

    public function testAuthorsRedirectsReviewerToReview(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $this->session->setUser(['id' => 1, 'name' => 'Reviewer', 'roles' => ['reviewer']]);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/dashboard/review')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->author($request, $response);

        $this->assertSame($response, $result);
    }

    public function testReviewItemRendersReviewContext(): void
    {
        $this->session->setUser(['id' => 7, 'name' => 'Reviewer', 'roles' => ['reviewer']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([
                'q' => 'Chapter',
                'sort' => 'chapter_asc',
                'page' => '2',
                'per_page' => '25',
            ]);

        $response = $this->createMock(ResponseInterface::class);

        $itemResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $itemResult->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 9,
                'title' => 'Chapter 2',
                'version_label' => 'v1',
                'source' => 'chapter-2.fdx',
                'content_rtf' => '',
                'content_text' => "Stored first paragraph\n\nStored second paragraph",
                'imported_at' => '2026-06-18 12:00:00',
                'content_status' => 'ready',
                'author_name' => 'Author User',
                'review_count' => 2,
                'open_review_count' => 1,
                'resolved_review_count' => 1,
                'needs_author_reply_count' => 0,
            ]);

        $historyResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $historyResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'id' => 21,
                    'title' => 'Chapter 2 review note',
                    'status' => 'resolved',
                    'details' => 'Please tighten the opening paragraph.',
                    'created_at' => '2026-06-18 12:30:00',
                    'updated_at' => '2026-06-18 12:30:00',
                    'resolved_at' => '2026-06-18 13:00:00',
                    'resolution_decision' => 'ignored',
                    'resolution_actor_id' => 11,
                    'resolution_actor_role' => 'author',
                    'resolution_recorded_at' => '2026-06-18 13:00:00',
                    'resolution_actor_name' => 'Author User',
                    'reviewer_name' => 'Reviewer One',
                ],
            ]);

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnOnConsecutiveCalls($itemResult, $historyResult);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'reader-item.php', $this->callback(function (array $data): bool {
                return $data['title'] === 'Review'
                    && isset($data['review_item'])
                    && (int) $data['review_item']['id'] === 9
                    && $data['review_item']['status_label'] === 'In review'
                    && isset($data['review_item']['reader'])
                    && is_array($data['review_item']['reader'])
                    && !empty($data['review_item']['reader']['available'])
                    && empty($data['review_item']['reader']['html'])
                    && $data['review_item']['reviews'][0]['status_label'] === 'Resolved'
                    && $data['review_item']['reviews'][0]['resolution_decision_label'] === 'Ignored'
                    && $data['review_item']['reviews'][0]['resolution_actor_name'] === 'Author User'
                    && isset($data['csrf_token'])
                    && $data['view_mode'] === 'compact-hidden'
                    && isset($data['queue_back_url'])
                    && str_contains((string) $data['queue_back_url'], '/dashboard/review?')
                    && isset($data['queue_query_string'])
                    && str_contains((string) $data['queue_query_string'], 'sort=chapter_asc');
            }))
            ->willReturn($response);

        $result = $this->controller->reviewItem($request, $response, ['id' => 9]);

        $this->assertSame($response, $result);
    }

    public function testReviewItemLabelsPlaceholderContentAsPlaceholder(): void
    {
        $this->session->setUser(['id' => 7, 'name' => 'Reviewer', 'roles' => ['reviewer']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([]);

        $response = $this->createMock(ResponseInterface::class);

        $itemResult = $this->createMock(
            \Doctrine\DBAL\Result::class
        );
        $itemResult->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 11,
                'title' => 'Empty Draft',
                'version_label' => 'v1',
                'source' => 'empty-draft.fdx',
                'content_rtf' => '',
                'imported_at' => '2026-06-18 12:00:00',
                'content_status' => 'placeholder',
                'author_name' => 'Author User',
                'review_count' => 0,
                'open_review_count' => 0,
                'resolved_review_count' => 0,
                'needs_author_reply_count' => 0,
            ]);

        $historyResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $historyResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnOnConsecutiveCalls($itemResult, $historyResult);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'reader-item.php', $this->callback(function (array $data): bool {
                return isset($data['review_item'])
                    && (int) $data['review_item']['id'] === 11
                    && $data['review_item']['status_label'] === 'Placeholder';
            }))
            ->willReturn($response);

        $result = $this->controller->reviewItem($request, $response, ['id' => 11]);

        $this->assertSame($response, $result);
    }

    public function testReviewItemUsesScrivenerMetadataWhenSourcePathIsEmpty(): void
    {
        $this->session->setUser(['id' => 7, 'name' => 'Reviewer', 'roles' => ['reviewer']]);

        $projectRoot = dirname(__DIR__, 3);
        $projectDir = $projectRoot . '/scrivener/test-import.scriv';
        $dataDir = $projectDir . '/Files/Data/A1111111-1111-1111-1111-111111111111';
        mkdir($dataDir, 0777, true);
        file_put_contents($dataDir . '/content.rtf', '{\rtf1\ansi\deff0 Metadata fallback text\par Second line}');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([]);

        $response = $this->createMock(ResponseInterface::class);

        $itemResult = $this->createMock(
            \Doctrine\DBAL\Result::class
        );
        $itemResult->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 12,
                'title' => 'What Is A Community?',
                'book_title' => 'Book One',
                'version_label' => 'v1',
                'revision_number' => 1,
                'source' => '',
                'content_rtf' => '',
                'metadata' => json_encode([
                    'scrivener' => [
                        'project_file' => 'scrivener/test-import.scriv/book.scrivx',
                        'uuid' => 'A1111111-1111-1111-1111-111111111111',
                    ],
                ], JSON_THROW_ON_ERROR),
                'imported_at' => '2026-06-18 12:00:00',
                'content_status' => 'ready',
                'author_name' => 'Author User',
                'review_count' => 0,
                'open_review_count' => 0,
                'resolved_review_count' => 0,
                'needs_author_reply_count' => 0,
            ]);

        $historyResult = $this->createMock(
            \Doctrine\DBAL\Result::class
        );
        $historyResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnOnConsecutiveCalls($itemResult, $historyResult);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'reader-item.php', $this->callback(function (array $data): bool {
                return isset($data['review_item'])
                    && (int) $data['review_item']['id'] === 12
                    && !empty($data['review_item']['reader']['available'])
                    && ($data['review_item']['reader']['notice'] ?? null) === null;
            }))
            ->willReturn($response);

        $result = $this->controller->reviewItem($request, $response, ['id' => 12]);

        $this->assertSame($response, $result);

        unlink($dataDir . '/content.rtf');
        rmdir($dataDir);
        rmdir($projectDir . '/Files/Data');
        rmdir($projectDir . '/Files');
        rmdir($projectDir);
    }

    public function testReviewItemRejectsMetadataTraversalOutsideProjectRoot(): void
    {
        $this->session->setUser(['id' => 7, 'name' => 'Reviewer', 'roles' => ['reviewer']]);

        $projectRoot = dirname(__DIR__, 3);
        $outsideProjectDir = dirname($projectRoot) . '/security-traversal.scriv';
        $uuid = 'A2222222-2222-2222-2222-222222222222';
        $outsideDataDir = $outsideProjectDir . '/Files/Data/' . $uuid;
        mkdir($outsideDataDir, 0777, true);
        file_put_contents($outsideDataDir . '/content.rtf', '{\rtf1\ansi\deff0 Outside project text\par Should never load}');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([]);

        $response = $this->createMock(ResponseInterface::class);

        $itemResult = $this->createMock(
            \Doctrine\DBAL\Result::class
        );
        $itemResult->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 14,
                'title' => 'Traversal Attempt',
                'book_title' => 'Book One',
                'version_label' => 'v1',
                'revision_number' => 1,
                'source' => '',
                'content_rtf' => '',
                'content_text' => '',
                'metadata' => json_encode([
                    'scrivener' => [
                        'project_file' => '../security-traversal.scriv/book.scrivx',
                        'uuid' => $uuid,
                    ],
                ], JSON_THROW_ON_ERROR),
                'imported_at' => '2026-06-18 12:00:00',
                'content_status' => 'ready',
                'author_name' => 'Author User',
                'review_count' => 0,
                'open_review_count' => 0,
                'resolved_review_count' => 0,
                'needs_author_reply_count' => 0,
            ]);

        $historyResult = $this->createMock(
            \Doctrine\DBAL\Result::class
        );
        $historyResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnOnConsecutiveCalls($itemResult, $historyResult);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'reader-item.php', $this->callback(function (array $data): bool {
                return isset($data['review_item'])
                    && (int) $data['review_item']['id'] === 14
                    && empty($data['review_item']['reader']['available'])
                    && str_contains((string) ($data['review_item']['reader']['notice'] ?? ''), 'unavailable');
            }))
            ->willReturn($response);

        $result = $this->controller->reviewItem($request, $response, ['id' => 14]);

        $this->assertSame($response, $result);

        unlink($outsideDataDir . '/content.rtf');
        rmdir($outsideDataDir);
        rmdir($outsideProjectDir . '/Files/Data');
        rmdir($outsideProjectDir . '/Files');
        rmdir($outsideProjectDir);
    }

    public function testSubmitReviewStoresNoteAndRedirectsBackToItem(): void
    {
        $this->session->setUser(['id' => 7, 'name' => 'Reviewer', 'roles' => ['reviewer']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $this->session->getCsrfToken(),
                'requires_action' => '1',
                'details' => 'This section now reads cleanly.',
                'selected_excerpt' => 'Stored first paragraph',
                'anchor_start_offset' => '25',
                'anchor_end_offset' => '47',
                'anchor_container_path' => 'article[1]/p[2]',
                'view' => 'compact-visible',
                'return_queue_query' => 'q=Chapter&sort=chapter_asc&page=2&per_page=25',
            ]);

        $response = $this->createMock(ResponseInterface::class);

        $itemResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $itemResult->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 9,
                'title' => 'Chapter 2',
                'version_label' => 'v1',
                'source' => 'chapter-2.fdx',
                'content_rtf' => '',
                'imported_at' => '2026-06-18 12:00:00',
                'content_status' => 'ready',
                'author_name' => 'Author User',
                'review_count' => 0,
                'open_review_count' => 0,
                'resolved_review_count' => 0,
                'needs_author_reply_count' => 0,
            ]);

        $historyResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $historyResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnOnConsecutiveCalls($itemResult, $historyResult);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'INSERT INTO reviews (content_version_id, reviewer_id, title, status, details, selected_excerpt, anchor_start_offset, anchor_end_offset, anchor_container_path, created_at, updated_at, resolved_at, resolution_decision, resolution_actor_id, resolution_actor_role, resolution_recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $this->callback(function ($params): bool {
                    return is_array($params)
                        && (int) $params[0] === 9
                        && (int) $params[1] === 7
                        && $params[3] === 'needs_author_review'
                        && $params[4] === 'This section now reads cleanly.'
                        && $params[5] === 'Stored first paragraph'
                        && (int) $params[6] === 25
                        && (int) $params[7] === 47
                        && $params[8] === 'article[1]/p[2]'
                        && $params[11] === null
                        && $params[12] === null
                        && $params[13] === null
                        && $params[14] === null
                        && $params[15] === null;
                })
            )
            ->willReturn(1);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/dashboard/review/9?q=Chapter&priority=all&state=all&sort=chapter_asc&page=2&per_page=25&view=compact-visible')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->submitReview($request, $response, ['id' => 9]);

        $this->assertSame($response, $result);
    }

    public function testSubmitReviewUpdatesExistingOwnedNoteAndRedirectsBackToItem(): void
    {
        $this->session->setUser(['id' => 7, 'name' => 'Reviewer', 'roles' => ['reviewer']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $this->session->getCsrfToken(),
                'note_id' => '44',
                'requires_action' => '0',
                'details' => 'Adjusted wording and style.',
                'selected_excerpt' => 'Stored second paragraph',
                'anchor_start_offset' => '62',
                'anchor_end_offset' => '90',
                'anchor_container_path' => 'article[1]/p[4]',
                'view' => 'compact-visible',
                'return_queue_query' => 'q=Chapter&sort=chapter_asc&page=1&per_page=25',
            ]);

        $response = $this->createMock(ResponseInterface::class);

        $itemResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $itemResult->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 9,
                'title' => 'Chapter 2',
                'version_label' => 'v1',
                'source' => 'chapter-2.fdx',
                'content_rtf' => '',
                'imported_at' => '2026-06-18 12:00:00',
                'content_status' => 'ready',
                'author_name' => 'Author User',
                'review_count' => 1,
                'open_review_count' => 1,
                'resolved_review_count' => 0,
                'needs_author_reply_count' => 0,
            ]);

        $historyResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $historyResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnOnConsecutiveCalls($itemResult, $historyResult);

        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                'SELECT id FROM reviews WHERE id = ? AND content_version_id = ? AND reviewer_id = ?',
                [44, 9, 7]
            )
            ->willReturn('44');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE reviews SET title = ?, status = ?, details = ?, selected_excerpt = ?, anchor_start_offset = ?, anchor_end_offset = ?, anchor_container_path = ?, updated_at = ?, resolved_at = ?, resolution_decision = ?, resolution_actor_id = ?, resolution_actor_role = ?, resolution_recorded_at = ? WHERE id = ? AND content_version_id = ? AND reviewer_id = ?',
                $this->callback(function ($params): bool {
                    return is_array($params)
                        && $params[0] === 'Chapter 2 review note'
                        && $params[1] === 'open'
                        && $params[2] === 'Adjusted wording and style.'
                        && $params[3] === 'Stored second paragraph'
                        && (int) $params[4] === 62
                        && (int) $params[5] === 90
                        && $params[6] === 'article[1]/p[4]'
                        && $params[8] === null
                        && $params[9] === null
                        && $params[10] === null
                        && $params[11] === null
                        && $params[12] === null
                        && (int) $params[13] === 44
                        && (int) $params[14] === 9
                        && (int) $params[15] === 7;
                })
            )
            ->willReturn(1);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/dashboard/review/9?q=Chapter&priority=all&state=all&sort=chapter_asc&page=1&per_page=25&view=compact-visible')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->submitReview($request, $response, ['id' => 9]);

        $this->assertSame($response, $result);
    }

    public function testSubmitReviewDeletesExistingOwnedNoteAndRedirectsBackToItem(): void
    {
        $this->session->setUser(['id' => 7, 'name' => 'Reviewer', 'roles' => ['reviewer']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $this->session->getCsrfToken(),
                'note_id' => '44',
                'note_action' => 'delete',
                'view' => 'compact-visible',
                'return_queue_query' => 'q=Chapter&sort=chapter_asc&page=1&per_page=25',
            ]);

        $response = $this->createMock(ResponseInterface::class);

        $itemResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $itemResult->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 9,
                'title' => 'Chapter 2',
                'version_label' => 'v1',
                'source' => 'chapter-2.fdx',
                'content_rtf' => '',
                'imported_at' => '2026-06-18 12:00:00',
                'content_status' => 'ready',
                'author_name' => 'Author User',
                'review_count' => 1,
                'open_review_count' => 1,
                'resolved_review_count' => 0,
                'needs_author_reply_count' => 0,
            ]);

        $historyResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $historyResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnOnConsecutiveCalls($itemResult, $historyResult);

        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                'SELECT id FROM reviews WHERE id = ? AND content_version_id = ? AND reviewer_id = ?',
                [44, 9, 7]
            )
            ->willReturn('44');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'DELETE FROM reviews WHERE id = ? AND content_version_id = ? AND reviewer_id = ?',
                [44, 9, 7]
            )
            ->willReturn(1);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/dashboard/review/9?q=Chapter&priority=all&state=all&sort=chapter_asc&page=1&per_page=25&view=compact-visible')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->submitReview($request, $response, ['id' => 9]);

        $this->assertSame($response, $result);
    }

    public function testReviewItemConvertsStoredRtfToHtmlAndStripsScrivenerMarkers(): void
    {
        $this->session->setUser(['id' => 7, 'name' => 'Reviewer', 'roles' => ['reviewer']]);

        $sampleRtf = <<<'RTF'
    {\rtf1\ansi{\fonttbl{\f0 Sitka Text;}}\pard {\f0 <$Scr_Ps::0><$Scr_H::1>Opening Remarks<!$Scr_H::1><!$Scr_Ps::0>}\par {\f0 See }{\field{\*\fldinst HYPERLINK "https://example.com/article"}{\fldrslt\f0 Example Link}}{\f0  for more.}}
    RTF;

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([]);

        $response = $this->createMock(ResponseInterface::class);

        $itemResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $itemResult->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 13,
                'title' => 'Opening Remarks',
                'version_label' => 'v1',
                'source' => './scrivener/book.scriv/Files/Data/X/content.rtf',
                'content_rtf' => $sampleRtf,
                'content_text' => "Opening Remarks\n\nSee Example Link for more.",
                'imported_at' => '2026-06-18 12:00:00',
                'content_status' => 'ready',
                'author_name' => 'Author User',
                'review_count' => 0,
                'open_review_count' => 0,
                'resolved_review_count' => 0,
                'needs_author_reply_count' => 0,
            ]);

        $historyResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $historyResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnOnConsecutiveCalls($itemResult, $historyResult);

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'reader-item.php', $this->callback(function (array $data): bool {
                $html = (string) (($data['review_item']['reader']['html'] ?? ''));

                return isset($data['review_item'])
                    && (int) $data['review_item']['id'] === 13
                    && !empty($data['review_item']['reader']['available'])
                    && str_contains($html, 'Opening Remarks')
                    && !str_contains($html, '$Scr_')
                    && str_contains($html, '<a href="https://example.com/article"');
            }))
            ->willReturn($response);

        $result = $this->controller->reviewItem($request, $response, ['id' => 13]);

        $this->assertSame($response, $result);
    }

    public function testExportAuthorPdfRejectsInvalidCsrf(): void
    {
        $this->session->setUser(['id' => 1, 'name' => 'Author', 'roles' => ['author']]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/dashboard/author/export')
            ->withParsedBody([
                '_csrf' => 'invalid-token',
                'single_item_id' => '9',
                'export_password' => 'thisisalongpassword',
            ]);

        $response = new Response();
        $result = $this->controller->exportAuthorPdf($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/author', $result->getHeaderLine('Location'));
    }

    public function testExportAuthorPdfReturnsPdfForSingleItem(): void
    {
        $this->session->setUser(['id' => 1, 'name' => 'Author', 'roles' => ['author']]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/dashboard/author/export')
            ->withParsedBody([
                '_csrf' => $this->session->getCsrfToken(),
                'single_item_id' => '42',
                'export_password' => '',
            ]);

        $queryResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $queryResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'id' => 42,
                    'title' => 'Chapter 4',
                    'book_title' => 'Book One',
                    'version_label' => 'v2',
                    'source' => 'chapter-4.fdx',
                    'content_text' => 'Exported content text.',
                    'content_rtf' => '',
                    'imported_at' => '2026-07-05 08:30:00',
                    'content_status' => 'ready',
                    'review_count' => 2,
                    'open_review_count' => 0,
                    'resolved_review_count' => 2,
                    'needs_author_reply_count' => 0,
                ],
            ]);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($queryResult);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        $this->pdfExportService->expects($this->once())
            ->method('generate')
            ->with(
                $this->callback(function (array $items): bool {
                    return count($items) === 1 && (int) ($items[0]['id'] ?? 0) === 42;
                }),
                'standard',
                null
            )
            ->willReturn('%PDF-1.7 test');

        $result = $this->controller->exportAuthorPdf($request, new Response());

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('application/pdf', $result->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="book-one-chapter-4-', $result->getHeaderLine('Content-Disposition'));
        $this->assertSame('%PDF-1.7 test', (string) $result->getBody());
    }

    public function testExportAuthorPdfUsesExcerptFilenameAndPreservesSelectedOrder(): void
    {
        $this->session->setUser(['id' => 1, 'name' => 'Author', 'roles' => ['author']]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/dashboard/author/export')
            ->withParsedBody([
                '_csrf' => $this->session->getCsrfToken(),
                'item_ids' => ['7', '3', '9'],
                'export_password' => '',
            ]);

        $queryResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $queryResult->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'id' => 3,
                    'title' => 'Chapter 3',
                    'book_title' => 'Book One',
                    'version_label' => 'v1',
                    'source' => 'chapter-3.fdx',
                    'content_text' => 'Text 3',
                    'content_rtf' => '',
                    'imported_at' => '2026-07-05 08:30:00',
                    'content_status' => 'ready',
                    'review_count' => 0,
                    'open_review_count' => 0,
                    'resolved_review_count' => 0,
                    'needs_author_reply_count' => 0,
                ],
                [
                    'id' => 9,
                    'title' => 'Chapter 9',
                    'book_title' => 'Book One',
                    'version_label' => 'v1',
                    'source' => 'chapter-9.fdx',
                    'content_text' => 'Text 9',
                    'content_rtf' => '',
                    'imported_at' => '2026-07-05 08:30:00',
                    'content_status' => 'ready',
                    'review_count' => 0,
                    'open_review_count' => 0,
                    'resolved_review_count' => 0,
                    'needs_author_reply_count' => 0,
                ],
                [
                    'id' => 7,
                    'title' => 'Chapter 7',
                    'book_title' => 'Book One',
                    'version_label' => 'v1',
                    'source' => 'chapter-7.fdx',
                    'content_text' => 'Text 7',
                    'content_rtf' => '',
                    'imported_at' => '2026-07-05 08:30:00',
                    'content_status' => 'ready',
                    'review_count' => 0,
                    'open_review_count' => 0,
                    'resolved_review_count' => 0,
                    'needs_author_reply_count' => 0,
                ],
            ]);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($queryResult);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        $this->pdfExportService->expects($this->once())
            ->method('generate')
            ->with(
                $this->callback(function (array $items): bool {
                    $ids = array_values(array_map(static fn (array $item): int => (int) ($item['id'] ?? 0), $items));

                    return $ids === [7, 3, 9];
                }),
                'standard',
                null
            )
            ->willReturn('%PDF-1.7 test');

        $result = $this->controller->exportAuthorPdf($request, new Response());

        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringContainsString('attachment; filename="book-one-excerpt-', $result->getHeaderLine('Content-Disposition'));
        $this->assertSame('%PDF-1.7 test', (string) $result->getBody());
    }
}
