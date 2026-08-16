<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\PhpRenderer;
use SparkInsight\Controller\DashboardController;
use SparkInsight\Service\AuthorPdfExportService;
use SparkInsight\Service\BookPackageImportService;
use SparkInsight\Service\UserSession;
use stdClass;

/**
 * Covers the pure private helper methods of DashboardController via reflection.
 */
final class DashboardControllerHelpersTest extends TestCase
{
    private DashboardController $controller;

    private UserSession $session;

    /** @var Connection&\PHPUnit\Framework\MockObject\MockObject */
    private $connection;

    protected function setUp(): void
    {
        $renderer = $this->createMock(PhpRenderer::class);
        $this->session = new UserSession();
        $this->connection = $this->createMock(Connection::class);
        $pdfExportService = $this->createMock(AuthorPdfExportService::class);
        $bookPackageImportService = new class($this->connection) extends BookPackageImportService {
            public function __construct(Connection $connection)
            {
                parent::__construct($connection);
            }
        };

        $this->controller = new DashboardController($renderer, $this->session, $this->connection, $pdfExportService, $bookPackageImportService);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod($this->controller, $method);

        return $reflection->invoke($this->controller, ...$args);
    }

    public function testReviewStateKeyForLabelMapsAllLabels(): void
    {
        $this->assertSame('placeholder', $this->invokePrivate('reviewStateKeyForLabel', 'Placeholder'));
        $this->assertSame('in_review', $this->invokePrivate('reviewStateKeyForLabel', 'In review'));
        $this->assertSame('needs_author_reply', $this->invokePrivate('reviewStateKeyForLabel', 'Needs author reply'));
        $this->assertSame('resolved', $this->invokePrivate('reviewStateKeyForLabel', 'Resolved'));
        $this->assertSame('ready_for_review', $this->invokePrivate('reviewStateKeyForLabel', 'Something else'));
    }

    public function testLabelForResolutionDecisionMapsAllDecisions(): void
    {
        $this->assertSame('Ignored', $this->invokePrivate('labelForResolutionDecision', 'ignored'));
        $this->assertSame('Still relevant', $this->invokePrivate('labelForResolutionDecision', 'still_relevant'));
        $this->assertSame('Resolved', $this->invokePrivate('labelForResolutionDecision', 'resolved'));
        $this->assertSame('', $this->invokePrivate('labelForResolutionDecision', 'unknown'));
        $this->assertSame('', $this->invokePrivate('labelForResolutionDecision', null));
    }

    public function testNormalizeRequiresActionHandlesAllBranches(): void
    {
        $this->assertTrue($this->invokePrivate('normalizeRequiresAction', ['requires_action' => true]));
        $this->assertFalse($this->invokePrivate('normalizeRequiresAction', ['requires_action' => false]));
        $this->assertFalse($this->invokePrivate('normalizeRequiresAction', ['requires_action' => '0']));
        $this->assertFalse($this->invokePrivate('normalizeRequiresAction', ['requires_action' => ' off ']));
        $this->assertTrue($this->invokePrivate('normalizeRequiresAction', ['requires_action' => 'yes']));
        $this->assertTrue($this->invokePrivate('normalizeRequiresAction', ['status' => 'resolved']));
        $this->assertFalse($this->invokePrivate('normalizeRequiresAction', ['status' => 'open']));
        $this->assertTrue($this->invokePrivate('normalizeRequiresAction', []));
    }

    public function testNormalizeAnchorOffsetHandlesAllBranches(): void
    {
        $this->assertNull($this->invokePrivate('normalizeAnchorOffset', true));
        $this->assertNull($this->invokePrivate('normalizeAnchorOffset', [1]));
        $this->assertNull($this->invokePrivate('normalizeAnchorOffset', new stdClass()));
        $this->assertNull($this->invokePrivate('normalizeAnchorOffset', ''));
        $this->assertNull($this->invokePrivate('normalizeAnchorOffset', 'abc'));
        $this->assertNull($this->invokePrivate('normalizeAnchorOffset', '-5'));
        $this->assertSame(42, $this->invokePrivate('normalizeAnchorOffset', ' 42 '));
        $this->assertSame(7, $this->invokePrivate('normalizeAnchorOffset', 7));
    }

    public function testNormalizeAnchorContainerPathHandlesAllBranches(): void
    {
        $this->assertNull($this->invokePrivate('normalizeAnchorContainerPath', false));
        $this->assertNull($this->invokePrivate('normalizeAnchorContainerPath', ['x']));
        $this->assertNull($this->invokePrivate('normalizeAnchorContainerPath', new stdClass()));
        $this->assertNull($this->invokePrivate('normalizeAnchorContainerPath', '   '));
        $this->assertSame('chapter/scene', $this->invokePrivate('normalizeAnchorContainerPath', ' chapter/scene '));

        $long = str_repeat('a', 300);
        $result = $this->invokePrivate('normalizeAnchorContainerPath', $long);
        $this->assertSame(255, mb_strlen((string) $result));
    }

    public function testFormatContentStatusLabelHandlesAllBranches(): void
    {
        $this->assertSame('Unknown', $this->invokePrivate('formatContentStatusLabel', '  '));
        $this->assertSame('Needs Revision', $this->invokePrivate('formatContentStatusLabel', 'needs_revision'));
        $this->assertSame('Ready', $this->invokePrivate('formatContentStatusLabel', 'ready'));
    }

    public function testToneForContentStatusMapsAllStatuses(): void
    {
        $this->assertSame('success', $this->invokePrivate('toneForContentStatus', 'ready'));
        $this->assertSame('neutral', $this->invokePrivate('toneForContentStatus', 'placeholder'));
        $this->assertSame('warning', $this->invokePrivate('toneForContentStatus', 'draft'));
        $this->assertSame('info', $this->invokePrivate('toneForContentStatus', 'needs_revision'));
        $this->assertSame('neutral', $this->invokePrivate('toneForContentStatus', 'other'));
    }

    public function testNormalizeAuthorExportIdsHandlesAllBranches(): void
    {
        $this->assertSame([], $this->invokePrivate('normalizeAuthorExportIds', 'not-an-array'));
        $this->assertSame([3, 5, 7], $this->invokePrivate('normalizeAuthorExportIds', [3, '5', 0, -2, 7, 3, 'abc']));

        $many = range(1, 250);
        $result = $this->invokePrivate('normalizeAuthorExportIds', $many);
        $this->assertCount(200, $result);
        $this->assertSame(1, $result[0]);
        $this->assertSame(200, $result[199]);
    }

    public function testAuthorNextStepForStatusMapsAllStatuses(): void
    {
        $this->assertSame('Complete content structure', $this->invokePrivate('authorNextStepForStatus', 'placeholder'));
        $this->assertSame('Monitor active review', $this->invokePrivate('authorNextStepForStatus', 'in_review'));
        $this->assertSame('Address reviewer notes', $this->invokePrivate('authorNextStepForStatus', 'needs_author_reply'));
        $this->assertSame('Ready for export', $this->invokePrivate('authorNextStepForStatus', 'resolved'));
        $this->assertSame('Await first review', $this->invokePrivate('authorNextStepForStatus', 'other'));
    }

    public function testExtractClientIpPrefersForwardedHeader(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/', ['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeader('X-Forwarded-For', ' 203.0.113.5 , 198.51.100.2');

        $this->assertSame('203.0.113.5', $this->invokePrivate('extractClientIp', $request));
    }

    public function testExtractClientIpFallsBackToRemoteAddr(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/', ['REMOTE_ADDR' => '10.0.0.9']);

        $this->assertSame('10.0.0.9', $this->invokePrivate('extractClientIp', $request));
    }

    public function testExtractClientIpReturnsEmptyStringWhenNoSource(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/', []);

        $this->assertSame('', $this->invokePrivate('extractClientIp', $request));
    }

    public function testExtractScrivenerQueueMetadataHandlesAllInputShapes(): void
    {
        $this->assertSame(['uuid' => 'x'], $this->invokePrivate('extractScrivenerQueueMetadata', ['scrivener' => ['uuid' => 'x']]));
        $this->assertSame([], $this->invokePrivate('extractScrivenerQueueMetadata', ['other' => 1]));
        $this->assertSame([], $this->invokePrivate('extractScrivenerQueueMetadata', null));
        $this->assertSame([], $this->invokePrivate('extractScrivenerQueueMetadata', '   '));
        $this->assertSame([], $this->invokePrivate('extractScrivenerQueueMetadata', '{invalid json'));
        $this->assertSame([], $this->invokePrivate('extractScrivenerQueueMetadata', '"just a string"'));
        $this->assertSame([], $this->invokePrivate('extractScrivenerQueueMetadata', '{"other": 1}'));
        $this->assertSame(
            ['uuid' => 'abc'],
            $this->invokePrivate('extractScrivenerQueueMetadata', '{"scrivener": {"uuid": "abc"}}'),
        );
    }

    public function testNormalizeReviewerQueueFiltersAppliesDefaultsAndClamps(): void
    {
        $defaults = $this->invokePrivate('normalizeReviewerQueueFilters', []);
        $this->assertSame([
            'q' => '',
            'priority' => 'all',
            'state' => 'all',
            'sort' => 'binder_asc',
            'page' => 1,
            'per_page' => 10,
        ], $defaults);

        $normalized = $this->invokePrivate('normalizeReviewerQueueFilters', [
            'priority' => 'HIGH',
            'state' => 'bogus',
            'sort' => 'due_desc',
            'q' => str_repeat('x', 150),
            'per_page' => 13,
            'page' => -4,
        ]);

        $this->assertSame('high', $normalized['priority']);
        $this->assertSame('all', $normalized['state']);
        $this->assertSame('due_desc', $normalized['sort']);
        $this->assertSame(120, mb_strlen((string) $normalized['q']));
        $this->assertSame(25, $normalized['per_page']);
        $this->assertSame(1, $normalized['page']);

        $invalid = $this->invokePrivate('normalizeReviewerQueueFilters', [
            'priority' => 'nope',
            'sort' => 'nope',
        ]);
        $this->assertSame('all', $invalid['priority']);
        $this->assertSame('binder_asc', $invalid['sort']);
    }

    public function testSanitizeReviewerQueueQueryStringNormalizesInput(): void
    {
        $this->assertSame('', $this->invokePrivate('sanitizeReviewerQueueQueryString', '   '));

        $sanitized = $this->invokePrivate('sanitizeReviewerQueueQueryString', 'state=bogus&sort=chapter_asc&per_page=50&page=2&q=demo');
        parse_str((string) $sanitized, $parsed);

        $this->assertSame('all', $parsed['state']);
        $this->assertSame('chapter_asc', $parsed['sort']);
        $this->assertSame('50', $parsed['per_page']);
        $this->assertSame('2', $parsed['page']);
        $this->assertSame('demo', $parsed['q']);
    }

    public function testApplyReviewerQueueFiltersFiltersByStatePriorityAndSearch(): void
    {
        $queue = [
            ['id' => 1, 'title' => 'Alpha', 'book_title' => 'Book A', 'author' => 'Ann', 'source' => 'scrivener', 'priority' => 'high', 'status_key' => 'in_review'],
            ['id' => 2, 'title' => 'Beta', 'book_title' => 'Book B', 'author' => 'Bob', 'source' => 'fdx', 'priority' => 'low', 'status_key' => 'resolved'],
        ];

        $this->assertCount(2, $this->invokePrivate('applyReviewerQueueFilters', $queue, []));

        $byState = $this->invokePrivate('applyReviewerQueueFilters', $queue, ['state' => 'resolved']);
        $this->assertSame([2], array_column($byState, 'id'));

        $byPriority = $this->invokePrivate('applyReviewerQueueFilters', $queue, ['priority' => 'high']);
        $this->assertSame([1], array_column($byPriority, 'id'));

        $bySearch = $this->invokePrivate('applyReviewerQueueFilters', $queue, ['q' => 'book b']);
        $this->assertSame([2], array_column($bySearch, 'id'));

        $noMatch = $this->invokePrivate('applyReviewerQueueFilters', $queue, ['q' => 'missing']);
        $this->assertSame([], $noMatch);
    }

    public function testSortReviewerQueueItemsSortsByBinderPath(): void
    {
        $queue = [
            ['id' => 3, 'order_path' => '', 'list_path' => 'B/Item', 'is_directory' => false],
            ['id' => 1, 'order_path' => '1.2', 'list_path' => 'A/Second', 'is_directory' => false],
            ['id' => 2, 'order_path' => '1.1', 'list_path' => 'A/First', 'is_directory' => false],
            ['id' => 4, 'order_path' => '1.2', 'list_path' => 'A/Dir', 'is_directory' => true],
        ];

        $sorted = $this->invokePrivate('sortReviewerQueueItems', $queue, 'binder_asc');
        $this->assertSame([2, 4, 1, 3], array_column($sorted, 'id'));

        $ties = $this->invokePrivate('sortReviewerQueueItems', [
            ['id' => 7, 'order_path' => '', 'list_path' => 'Same', 'is_directory' => false],
            ['id' => 5, 'order_path' => '', 'list_path' => 'Same', 'is_directory' => false],
        ], 'binder_asc');
        $this->assertSame([5, 7], array_column($ties, 'id'));

        $mixed = $this->invokePrivate('sortReviewerQueueItems', [
            ['id' => 1, 'order_path' => '1.1', 'list_path' => 'A/First', 'is_directory' => false],
            ['id' => 2, 'order_path' => '', 'list_path' => 'B/Item', 'is_directory' => false],
            ['id' => 3, 'order_path' => '', 'list_path' => 'A/Alt', 'is_directory' => false],
        ], 'binder_asc');
        $this->assertSame([1, 3, 2], array_column($mixed, 'id'));
    }

    public function testSortReviewerQueueItemsSortsByChapterAndFallsBackToTitle(): void
    {
        $queue = [
            ['id' => 1, 'display_title' => 'Beta', 'title' => 'Ignored'],
            ['id' => 2, 'display_title' => '', 'title' => 'Alpha'],
            ['id' => 3, 'display_title' => 'Beta', 'title' => 'Ignored'],
        ];

        $asc = $this->invokePrivate('sortReviewerQueueItems', $queue, 'chapter_asc');
        $this->assertSame([2, 1, 3], array_column($asc, 'id'));

        $desc = $this->invokePrivate('sortReviewerQueueItems', $queue, 'chapter_desc');
        $this->assertSame([3, 1, 2], array_column($desc, 'id'));

        $leftOnly = $this->invokePrivate('sortReviewerQueueItems', [
            ['id' => 2, 'due' => ''],
            ['id' => 1, 'due' => '2026-01-01'],
        ], 'due_asc');
        $this->assertSame([1, 2], array_column($leftOnly, 'id'));

        $rightOnly = $this->invokePrivate('sortReviewerQueueItems', [
            ['id' => 1, 'due' => '2026-01-01'],
            ['id' => 2, 'due' => ''],
        ], 'due_asc');
        $this->assertSame([1, 2], array_column($rightOnly, 'id'));
    }

    public function testSortReviewerQueueItemsSortsByDueDate(): void
    {
        $queue = [
            ['id' => 1, 'due' => 'No due date'],
            ['id' => 2, 'due' => '2026-01-02'],
            ['id' => 3, 'due' => '2026-01-01'],
            ['id' => 4, 'due' => ''],
        ];

        $asc = $this->invokePrivate('sortReviewerQueueItems', $queue, 'due_asc');
        $this->assertSame([3, 2, 1, 4], array_column($asc, 'id'));

        $desc = $this->invokePrivate('sortReviewerQueueItems', $queue, 'due_desc');
        $this->assertSame([2, 3, 1, 4], array_column($desc, 'id'));

        $ties = $this->invokePrivate('sortReviewerQueueItems', [
            ['id' => 5, 'due' => '2026-01-01'],
            ['id' => 4, 'due' => '2026-01-01'],
        ], 'due_asc');
        $this->assertSame([4, 5], array_column($ties, 'id'));
    }

    public function testSortReviewerQueueItemsImportedDescFallback(): void
    {
        $queue = [
            ['id' => 1],
            ['id' => 9],
            ['id' => 5],
        ];

        $sorted = $this->invokePrivate('sortReviewerQueueItems', $queue, 'imported_desc');
        $this->assertSame([9, 5, 1], array_column($sorted, 'id'));
    }

    public function testNormalizeAuthorQueueFiltersAppliesDefaultsAndClamps(): void
    {
        $defaults = $this->invokePrivate('normalizeAuthorQueueFilters', []);
        $this->assertSame([
            'q' => '',
            'state' => 'all',
            'sort' => 'binder_asc',
            'page' => 1,
            'per_page' => 25,
        ], $defaults);

        $normalized = $this->invokePrivate('normalizeAuthorQueueFilters', [
            'state' => 'in_review',
            'sort' => 'nope',
            'q' => str_repeat('y', 130),
            'per_page' => 100,
            'page' => 0,
        ]);

        $this->assertSame('in_review', $normalized['state']);
        $this->assertSame('binder_asc', $normalized['sort']);
        $this->assertSame(120, mb_strlen((string) $normalized['q']));
        $this->assertSame(100, $normalized['per_page']);
        $this->assertSame(1, $normalized['page']);

        $invalid = $this->invokePrivate('normalizeAuthorQueueFilters', [
            'state' => 'bogus',
            'per_page' => 13,
        ]);
        $this->assertSame('all', $invalid['state']);
        $this->assertSame(25, $invalid['per_page']);
    }

    public function testSanitizeAuthorQueueQueryStringNormalizesInput(): void
    {
        $this->assertSame('', $this->invokePrivate('sanitizeAuthorQueueQueryString', ''));

        $sanitized = $this->invokePrivate('sanitizeAuthorQueueQueryString', 'state=resolved&sort=bad&page=3');
        parse_str((string) $sanitized, $parsed);

        $this->assertSame('resolved', $parsed['state']);
        $this->assertSame('binder_asc', $parsed['sort']);
        $this->assertSame('3', $parsed['page']);
    }

    public function testApplyAuthorQueueFiltersFiltersByStateAndSearch(): void
    {
        $queue = [
            ['id' => 1, 'title' => 'Alpha', 'book_title' => 'Book A', 'source' => 'scrivener', 'list_path' => 'A/Alpha', 'status_key' => 'in_review'],
            ['id' => 2, 'title' => 'Beta', 'book_title' => 'Book A', 'source' => 'fdx', 'list_path' => 'A/Beta', 'status_key' => 'resolved'],
        ];

        $this->assertCount(2, $this->invokePrivate('applyAuthorQueueFilters', $queue, []));

        $byState = $this->invokePrivate('applyAuthorQueueFilters', $queue, ['state' => 'in_review']);
        $this->assertSame([1], array_column($byState, 'id'));

        $bySearch = $this->invokePrivate('applyAuthorQueueFilters', $queue, ['q' => 'fdx']);
        $this->assertSame([2], array_column($bySearch, 'id'));

        $this->assertSame([], $this->invokePrivate('applyAuthorQueueFilters', $queue, ['q' => 'nothing-matches']));
    }

    public function testSortAuthorQueueItemsSortsByBinderPath(): void
    {
        $queue = [
            ['id' => 1, 'order_path' => '1.2', 'list_path' => 'A/Second'],
            ['id' => 2, 'order_path' => '1.1', 'list_path' => 'A/First'],
            ['id' => 3, 'order_path' => '', 'list_path' => 'B/Item'],
            ['id' => 4, 'order_path' => '', 'list_path' => ''],
        ];

        $sorted = $this->invokePrivate('sortAuthorQueueItems', $queue, 'binder_asc');
        $this->assertSame([2, 1, 3, 4], array_column($sorted, 'id'));

        $byListPath = $this->invokePrivate('sortAuthorQueueItems', [
            ['id' => 1, 'order_path' => '', 'list_path' => 'B/Item'],
            ['id' => 2, 'order_path' => '', 'list_path' => 'A/Item'],
        ], 'binder_asc');
        $this->assertSame([2, 1], array_column($byListPath, 'id'));
    }

    public function testSortAuthorQueueItemsSortsByChapter(): void
    {
        $queue = [
            ['id' => 2, 'display_title' => 'Beta', 'title' => 'x'],
            ['id' => 1, 'display_title' => '', 'title' => 'Alpha'],
        ];

        $asc = $this->invokePrivate('sortAuthorQueueItems', $queue, 'chapter_asc');
        $this->assertSame([1, 2], array_column($asc, 'id'));

        $desc = $this->invokePrivate('sortAuthorQueueItems', $queue, 'chapter_desc');
        $this->assertSame([2, 1], array_column($desc, 'id'));

        $ties = $this->invokePrivate('sortAuthorQueueItems', [
            ['id' => 9, 'display_title' => 'Same', 'title' => ''],
            ['id' => 4, 'display_title' => 'Same', 'title' => ''],
        ], 'chapter_asc');
        $this->assertSame([4, 9], array_column($ties, 'id'));
    }

    public function testSortAuthorQueueItemsImportedDescFallback(): void
    {
        $queue = [
            ['id' => 1, 'imported_sort_ts' => 100],
            ['id' => 2, 'imported_sort_ts' => 300],
            ['id' => 3, 'imported_sort_ts' => 300],
        ];

        $sorted = $this->invokePrivate('sortAuthorQueueItems', $queue, 'imported_desc');
        $this->assertSame([3, 2, 1], array_column($sorted, 'id'));
    }

    public function testInjectQueueDirectoryStructureReturnsEmptyForEmptyQueue(): void
    {
        $this->assertSame([], $this->invokePrivate('injectQueueDirectoryStructure', []));
    }

    public function testBuildAuthorMetricsAggregatesQueueRows(): void
    {
        $metrics = $this->invokePrivate('buildAuthorMetrics', [
            ['is_directory' => true, 'status_key' => 'structure'],
            ['is_directory' => false, 'status_key' => 'placeholder', 'review_count' => 0, 'resolved_review_count' => 1],
            ['is_directory' => false, 'status_key' => 'needs_author_reply', 'review_count' => 2, 'resolved_review_count' => 3],
            ['is_directory' => false, 'status_key' => 'resolved', 'review_count' => 4, 'resolved_review_count' => 2],
        ]);

        $this->assertSame('1', $metrics[0]['value']); // Drafts In Progress
        $this->assertSame('1', $metrics[1]['value']); // Waiting For Review
        $this->assertSame('6', $metrics[2]['value']); // Resolved Comments
        $this->assertSame('1', $metrics[3]['value']); // Needs Revision
    }

    public function testBuildReviewerMetricsReturnsZerosWithoutUser(): void
    {
        $metrics = $this->invokePrivate('buildReviewerMetrics');

        $this->assertSame('0', $metrics[0]['value']);
        $this->assertSame('Assigned Reviews', $metrics[0]['label']);
    }

    public function testBuildReviewerMetricsQueriesDatabaseForLoggedInReviewer(): void
    {
        $this->session->setUser(['id' => 5, 'roles' => ['reviewer']]);

        $results = [];
        foreach ([3, 2, 1, 4] as $count) {
            $result = $this->createMock(\Doctrine\DBAL\Result::class);
            $result->method('fetchOne')->willReturn($count);
            $results[] = $result;
        }

        $this->connection->expects($this->exactly(4))
            ->method('executeQuery')
            ->willReturnOnConsecutiveCalls(...$results);

        $metrics = $this->invokePrivate('buildReviewerMetrics');

        $this->assertSame('3', $metrics[0]['value']);
        $this->assertSame('2', $metrics[1]['value']);
        $this->assertSame('1', $metrics[2]['value']);
        $this->assertSame('4', $metrics[3]['value']);
    }

    public function testBuildReviewerMetricsFallsBackToZerosOnDatabaseError(): void
    {
        $this->session->setUser(['id' => 5, 'roles' => ['reviewer']]);

        $this->connection->method('executeQuery')
            ->willThrowException(new RuntimeException('db down'));

        $metrics = $this->invokePrivate('buildReviewerMetrics');

        $this->assertSame('0', $metrics[0]['value']);
        $this->assertSame('0', $metrics[3]['value']);
    }

    public function testRecordAuthorExportEventSkipsInvalidInput(): void
    {
        $this->connection->expects($this->never())->method('executeStatement');

        $this->invokePrivate('recordAuthorExportEvent', 0, [1], 'standard', false, '', '');
        $this->invokePrivate('recordAuthorExportEvent', 5, [], 'standard', false, '', '');
    }

    public function testRecordAuthorExportEventInsertsAuditRow(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('INSERT INTO export_events'),
                $this->callback(static fn (array $params): bool => $params[0] === 5
                        && $params[1] === '[1,2]'
                        && $params[2] === 'standard'
                        && $params[3] === 'PDF 1.7'
                        && $params[4] === 0
                        && $params[5] === '203.0.113.5'
                        && is_string($params[6])
                        && is_string($params[7])),
            );

        $this->invokePrivate('recordAuthorExportEvent', 5, [1, 2], 'standard', false, '203.0.113.5', 'Agent');
    }

    public function testRecordAuthorExportEventSwallowsDatabaseErrors(): void
    {
        $this->connection->method('executeStatement')
            ->willThrowException(new RuntimeException('db down'));

        $this->invokePrivate('recordAuthorExportEvent', 5, [1], 'standard', true, '', '');

        $this->addToAssertionCount(1);
    }

    public function testInjectQueueDirectoryStructureCreatesAncestorDirectories(): void
    {
        $queue = [
            [
                'id' => 10,
                'title' => 'Scene 1',
                'book_title' => 'Book A',
                'author' => 'Ann',
                'list_path' => 'Book A/Chapter 1/Scene 1',
                'order_path' => '1.1.1',
                'is_directory' => false,
            ],
            [
                'id' => 11,
                'title' => 'Scene 2',
                'book_title' => 'Book A',
                'author' => 'Ann',
                'list_path' => 'Book A/Chapter 1/Scene 2',
                'order_path' => '1.1.2',
                'is_directory' => false,
            ],
            [
                'id' => 12,
                'title' => 'Chapter 2',
                'book_title' => 'Book A',
                'author' => 'Ann',
                'list_path' => 'Book A/Chapter 2',
                'order_path' => '',
                'is_directory' => true,
            ],
            [
                'id' => 13,
                'title' => 'Loose Item',
                'book_title' => 'Book A',
                'author' => 'Ann',
                'list_path' => '',
                'order_path' => '',
                'is_directory' => false,
            ],
            [
                'id' => 14,
                'title' => 'Scene 4',
                'book_title' => 'Book A',
                'author' => 'Ann',
                'list_path' => 'Book A\Chapter 2\Scene 4',
                'order_path' => '',
                'is_directory' => false,
            ],
            [
                'id' => 15,
                'title' => 'Top Item',
                'book_title' => 'Book A',
                'author' => 'Ann',
                'list_path' => 'Top Item',
                'order_path' => '',
                'is_directory' => false,
            ],
        ];

        $structured = $this->invokePrivate('injectQueueDirectoryStructure', $queue);

        $byId = [];
        foreach ($structured as $item) {
            if (($item['id'] ?? 0) === 10) {
                $byId['scene1'] = $item;
            }
        }

        $this->assertSame('Scene 1', $byId['scene1']['display_title']);
        $this->assertSame(2, $byId['scene1']['depth']);

        $paths = array_column($structured, 'list_path');
        $this->assertContains('Book A', $paths);
        $this->assertContains('Book A/Chapter 1', $paths);

        // Ancestor directories are synthesized exactly once.
        $this->assertCount(1, array_keys($paths, 'Book A', true));

        $bookDir = $structured[array_search('Book A', $paths, true)];
        $this->assertTrue($bookDir['is_directory']);
        $this->assertTrue($bookDir['has_children']);
        $this->assertSame(2, $bookDir['child_folder_count']);

        $chapter1 = $structured[array_search('Book A/Chapter 1', $paths, true)];
        $this->assertSame(2, $chapter1['child_item_count']);
        $this->assertSame('Book A', $chapter1['parent_path']);
        $this->assertSame('1.1', $chapter1['order_path']);

        $chapter2 = $structured[array_search('Book A/Chapter 2', $paths, true)];
        $this->assertFalse($chapter2['has_children']);
        $this->assertSame(1, $chapter2['child_item_count']);

        $loose = $structured[array_search('', $paths, true)];
        $this->assertSame('', $loose['parent_path']);
        $this->assertSame(0, $loose['child_item_count']);
    }
}
