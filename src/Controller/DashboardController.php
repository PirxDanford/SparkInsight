<?php

declare(strict_types=1);

namespace SparkInsight\Controller;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RtfHtmlPhp\Document as RtfDocument;
use Slim\Views\PhpRenderer;
use SparkInsight\Support\ScrivenerHtmlFormatter;
use SparkInsight\Service\UserSession;

final class DashboardController
{
    private const REVIEW_STATUSES = ['open', 'resolved', 'needs_author_review'];

    public function __construct(
        private readonly PhpRenderer $renderer,
        private readonly UserSession $session,
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        if (! $this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = $this->session->getUser();
        $roles = $this->getUserRoles($user);

        if ($this->isPureAdmin($roles)) {
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }

        if ($this->canAuthor($roles)) {
            return $response->withHeader('Location', '/dashboard/author')->withStatus(302);
        }

        return $response->withHeader('Location', '/dashboard/review')->withStatus(302);
    }

    public function review(Request $request, Response $response): Response
    {
        if (! $this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = $this->session->getUser();
        $roles = $this->getUserRoles($user);

        if ($this->isPureAdmin($roles)) {
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }

        if (!$this->canReview($roles)) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $queueFilters = $this->normalizeReviewerQueueFilters($request->getQueryParams());

        return $this->renderDashboard($response, $user, 'review', [
            'reviewer_queue_filters' => $queueFilters,
        ]);
    }

    public function reviewItem(Request $request, Response $response, array $args): Response
    {
        if (! $this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = $this->session->getUser();
        $roles = $this->getUserRoles($user);

        if ($this->isPureAdmin($roles)) {
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }

        if (!$this->canReview($roles)) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $queryParams = (array) $request->getQueryParams();
        $queueFilters = $this->normalizeReviewerQueueFilters($queryParams);
        $queueQueryString = $this->buildReviewerQueueQueryString($queueFilters);
        $queueBackUrl = '/dashboard/review' . ($queueQueryString !== '' ? '?' . $queueQueryString : '');

        $contentVersionId = (int) ($args['id'] ?? 0);
        $item = $contentVersionId > 0 ? $this->buildReviewItem($contentVersionId) : null;

        if ($item === null) {
            $this->session->setFlash('warning', 'That review item could not be opened.');

            return $response->withHeader('Location', $queueBackUrl)->withStatus(302);
        }

        $viewModeExplicit = array_key_exists('view', $queryParams);
        $viewMode = $this->normalizeReaderViewMode((string) ($queryParams['view'] ?? 'compact-hidden'));
        
        return $this->renderer->render($response, 'reader-item.php', [
            'title' => 'Review',
            'dashboard_mode' => 'review',
            'user' => $user,
            'review_item' => $item,
            'view_mode' => $viewMode,
            'view_mode_explicit' => $viewModeExplicit,
            'csrf_token' => $this->session->getCsrfToken(),
            'queue_back_url' => $queueBackUrl,
            'queue_query_string' => $queueQueryString,
        ]);
    }

    public function submitReview(Request $request, Response $response, array $args): Response
    {
        if (! $this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = $this->session->getUser();
        $roles = $this->getUserRoles($user);

        if ($this->isPureAdmin($roles)) {
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }

        if (!$this->canReview($roles)) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $contentVersionId = (int) ($args['id'] ?? 0);
        $data = $request->getParsedBody();
        $returnQueueQuery = is_array($data)
            ? $this->sanitizeReviewerQueueQueryString((string) ($data['return_queue_query'] ?? ''))
            : '';
        $viewMode = is_array($data) ? $this->normalizeReaderViewMode((string) ($data['view'] ?? 'compact-hidden')) : 'compact-hidden';
        $itemUrlQuery = $returnQueueQuery;
        parse_str($itemUrlQuery, $itemUrlParams);
        if (!is_array($itemUrlParams)) {
            $itemUrlParams = [];
        }
        $itemUrlParams['view'] = $viewMode;
        $itemUrl = '/dashboard/review/' . $contentVersionId . '?' . http_build_query($itemUrlParams);

        $item = $contentVersionId > 0 ? $this->buildReviewItem($contentVersionId) : null;
        if ($item === null) {
            $this->session->setFlash('warning', 'That review item could not be opened.');

            $queueUrl = '/dashboard/review' . ($returnQueueQuery !== '' ? '?' . $returnQueueQuery : '');

            return $response->withHeader('Location', $queueUrl)->withStatus(302);
        }

        if (!is_array($data) || !$this->validateReviewSubmissionCsrf($data)) {
            return $this->renderDashboard($response, $user, 'review', [
                'review_item' => $item,
                'csrf_token' => $this->session->getCsrfToken(),
                'flash_message' => [
                    'type' => 'error',
                    'message' => 'Please refresh the item and submit again.',
                ],
                'review_draft' => [
                    'details' => is_array($data) ? trim((string) ($data['details'] ?? '')) : '',
                    'selected_excerpt' => is_array($data) ? trim((string) ($data['selected_excerpt'] ?? '')) : '',
                    'anchor_start_offset' => is_array($data) ? $this->normalizeAnchorOffset($data['anchor_start_offset'] ?? null) : null,
                    'anchor_end_offset' => is_array($data) ? $this->normalizeAnchorOffset($data['anchor_end_offset'] ?? null) : null,
                    'anchor_container_path' => is_array($data) ? $this->normalizeAnchorContainerPath($data['anchor_container_path'] ?? null) : null,
                    'requires_action' => is_array($data) ? $this->normalizeRequiresAction($data) : true,
                ],
            ]);
        }

        $details = trim((string) ($data['details'] ?? ''));
        $selectedExcerpt = is_array($data) ? trim((string) ($data['selected_excerpt'] ?? '')) : '';
        $anchorStartOffset = $this->normalizeAnchorOffset($data['anchor_start_offset'] ?? null);
        $anchorEndOffset = $this->normalizeAnchorOffset($data['anchor_end_offset'] ?? null);
        if ($anchorStartOffset === null || $anchorEndOffset === null || $anchorEndOffset < $anchorStartOffset) {
            $anchorStartOffset = null;
            $anchorEndOffset = null;
        }
        $anchorContainerPath = $this->normalizeAnchorContainerPath($data['anchor_container_path'] ?? null);
        if ($anchorStartOffset === null || $anchorEndOffset === null) {
            $anchorContainerPath = null;
        }
        $noteId = is_array($data) ? max(0, (int) ($data['note_id'] ?? 0)) : 0;
        $requiresAction = $this->normalizeRequiresAction($data);
        $status = $requiresAction ? 'needs_author_review' : 'open';
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $existingNoteId = 0;
        if ($noteId > 0) {
            $existingNoteId = (int) $this->connection->fetchOne(
                'SELECT id FROM reviews WHERE id = ? AND content_version_id = ? AND reviewer_id = ?',
                [$noteId, $contentVersionId, (int) ($user['id'] ?? 0)]
            );
        }

        if ($existingNoteId > 0) {
            $this->connection->executeStatement(
                'UPDATE reviews SET title = ?, status = ?, details = ?, selected_excerpt = ?, anchor_start_offset = ?, anchor_end_offset = ?, anchor_container_path = ?, updated_at = ?, resolved_at = ? WHERE id = ? AND content_version_id = ? AND reviewer_id = ?',
                [
                    sprintf('%s review note', (string) $item['title']),
                    $status,
                    $details,
                    $selectedExcerpt !== '' ? $selectedExcerpt : null,
                    $anchorStartOffset,
                    $anchorEndOffset,
                    $anchorContainerPath,
                    $now,
                    $status === 'resolved' ? $now : null,
                    $existingNoteId,
                    $contentVersionId,
                    (int) ($user['id'] ?? 0),
                ]
            );
            $this->session->setFlash('success', 'Review note updated.');

            return $response->withHeader('Location', $itemUrl)->withStatus(302);
        }

        $this->connection->executeStatement(
            'INSERT INTO reviews (content_version_id, reviewer_id, title, status, details, selected_excerpt, anchor_start_offset, anchor_end_offset, anchor_container_path, created_at, updated_at, resolved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $contentVersionId,
                (int) ($user['id'] ?? 0),
                sprintf('%s review note', (string) $item['title']),
                $status,
                $details,
                $selectedExcerpt !== '' ? $selectedExcerpt : null,
                $anchorStartOffset,
                $anchorEndOffset,
                $anchorContainerPath,
                $now,
                $now,
                $status === 'resolved' ? $now : null,
            ]
        );

        $this->session->setFlash('success', 'Review update saved.');

        return $response->withHeader('Location', $itemUrl)->withStatus(302);
    }

    public function author(Request $request, Response $response): Response
    {
        if (! $this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = $this->session->getUser();
        $roles = $this->getUserRoles($user);

        if ($this->isPureAdmin($roles)) {
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }

        if (!$this->canAuthor($roles)) {
            if ($this->canReview($roles)) {
                return $response->withHeader('Location', '/dashboard/review')->withStatus(302);
            }

            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        return $this->renderDashboard($response, $user, 'author');
    }

    private function renderDashboard(Response $response, ?array $user, string $mode, array $extra = []): Response
    {
        $isReviewerView = $mode === 'review';
        $isAuthorView = $mode === 'author';
        $flashMessage = array_key_exists('flash_message', $extra) ? $extra['flash_message'] : $this->session->getFlash();
        $reviewerQueueFilters = $isReviewerView ? ($extra['reviewer_queue_filters'] ?? $this->normalizeReviewerQueueFilters([])) : [];
        if (!is_array($reviewerQueueFilters)) {
            $reviewerQueueFilters = $this->normalizeReviewerQueueFilters([]);
        }
        $reviewerQueueResult = $isReviewerView ? $this->buildReviewerQueue($user, $reviewerQueueFilters) : ['items' => [], 'pagination' => []];
        $reviewerQueue = is_array($reviewerQueueResult['items'] ?? null) ? $reviewerQueueResult['items'] : [];
        $reviewerOtherItemsByState = $isReviewerView ? $this->buildReviewerOtherItemsByState($user) : [];

        return $this->renderer->render($response, 'dashboard.php', [
            'title' => $isAuthorView ? 'Author' : 'Review',
            'dashboard_mode' => $mode,
            'user' => $user,
            'flash_message' => $flashMessage,
            'reviewer_metrics' => $isReviewerView ? $this->buildReviewerMetrics() : [],
            'reviewer_queue' => $reviewerQueue,
            'reviewer_queue_context' => $isReviewerView ? $this->buildReviewerQueueContext($reviewerQueue) : [],
            'reviewer_queue_filters' => $reviewerQueueFilters,
            'reviewer_queue_pagination' => $isReviewerView ? (array) ($reviewerQueueResult['pagination'] ?? []) : [],
            'reviewer_other_items_by_state' => $reviewerOtherItemsByState,
            'author_metrics' => $isAuthorView ? $this->buildAuthorMetrics() : [],
            'author_queue' => $isAuthorView ? $this->buildAuthorQueue() : [],
        ] + $extra);
    }

    private function getUserRoles(?array $user): array
    {
        return array_values(array_unique((array) ($user['roles'] ?? [])));
    }

    private function canAuthor(array $roles): bool
    {
        return in_array('author', $roles, true);
    }

    private function canReview(array $roles): bool
    {
        return in_array('reviewer', $roles, true) || $this->canAuthor($roles);
    }

    private function isPureAdmin(array $roles): bool
    {
        return in_array('admin', $roles, true)
            && !in_array('reviewer', $roles, true)
            && !in_array('author', $roles, true);
    }

    private function buildReviewerMetrics(): array
    {
        $reviewerId = (int) ($this->session->getUser()['id'] ?? 0);
        if ($reviewerId <= 0) {
            return [
                ['label' => 'Assigned Reviews', 'value' => '0', 'tone' => 'neutral'],
                ['label' => 'Open Review Threads', 'value' => '0', 'tone' => 'warning'],
                ['label' => 'Resolved This Week', 'value' => '0', 'tone' => 'success'],
                ['label' => 'Needs Author Reply', 'value' => '0', 'tone' => 'info'],
            ];
        }

        try {
            $assignedReviews = (int) $this->connection
                ->executeQuery("SELECT COUNT(*) FROM review_assignments ra INNER JOIN content_versions cv ON cv.id = ra.content_version_id WHERE ra.reviewer_id = ? AND cv.status = 'ready'", [$reviewerId])
                ->fetchOne();

            $openThreads = (int) $this->connection
                ->executeQuery("SELECT COUNT(*) FROM reviews r INNER JOIN review_assignments ra ON ra.content_version_id = r.content_version_id WHERE ra.reviewer_id = ? AND r.status = 'open'", [$reviewerId])
                ->fetchOne();

            $resolvedThisWeek = (int) $this->connection
                ->executeQuery("SELECT COUNT(*) FROM reviews r INNER JOIN review_assignments ra ON ra.content_version_id = r.content_version_id WHERE ra.reviewer_id = ? AND r.status = 'resolved' AND r.resolved_at IS NOT NULL AND r.resolved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", [$reviewerId])
                ->fetchOne();

            $needsAuthorReply = (int) $this->connection
                ->executeQuery("SELECT COUNT(*) FROM reviews r INNER JOIN review_assignments ra ON ra.content_version_id = r.content_version_id WHERE ra.reviewer_id = ? AND r.status = 'needs_author_review'", [$reviewerId])
                ->fetchOne();
        } catch (\Throwable) {
            return [
                ['label' => 'Assigned Reviews', 'value' => '0', 'tone' => 'neutral'],
                ['label' => 'Open Review Threads', 'value' => '0', 'tone' => 'warning'],
                ['label' => 'Resolved This Week', 'value' => '0', 'tone' => 'success'],
                ['label' => 'Needs Author Reply', 'value' => '0', 'tone' => 'info'],
            ];
        }

        return [
            ['label' => 'Assigned Reviews', 'value' => (string) $assignedReviews, 'tone' => 'neutral'],
            ['label' => 'Open Review Threads', 'value' => (string) $openThreads, 'tone' => 'warning'],
            ['label' => 'Resolved This Week', 'value' => (string) $resolvedThisWeek, 'tone' => 'success'],
            ['label' => 'Needs Author Reply', 'value' => (string) $needsAuthorReply, 'tone' => 'info'],
        ];
    }

    private function buildReviewerQueue(?array $user, array $filters = []): array
    {
        $reviewerId = (int) ($user['id'] ?? 0);
        if ($reviewerId <= 0) {
            return [
                'items' => [],
                'pagination' => $this->buildEmptyQueuePagination($filters),
            ];
        }

        try {
            $rows = $this->connection->executeQuery(
                "SELECT
                    cv.id,
                    cv.title,
                    cv.book_title,
                    cv.version_label,
                    cv.source,
                    cv.metadata,
                    cv.imported_at,
                    cv.status,
                    u.name AS author_name,
                    ra.priority,
                    ra.due_at,
                    COUNT(r.id) AS review_count,
                    SUM(CASE WHEN r.status = 'open' THEN 1 ELSE 0 END) AS open_review_count,
                    SUM(CASE WHEN r.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_review_count,
                    SUM(CASE WHEN r.status = 'needs_author_review' THEN 1 ELSE 0 END) AS needs_author_reply_count
                FROM review_assignments ra
                INNER JOIN content_versions cv ON cv.id = ra.content_version_id
                LEFT JOIN users u ON u.id = cv.author_id
                LEFT JOIN reviews r ON r.content_version_id = cv.id
                WHERE cv.status = 'ready' AND ra.reviewer_id = ?
                    GROUP BY cv.id, cv.title, cv.book_title, cv.version_label, cv.source, cv.metadata, cv.imported_at, cv.status, u.name, ra.priority, ra.due_at
                ORDER BY (ra.due_at IS NULL) ASC, ra.due_at ASC, cv.imported_at DESC, cv.id ASC
                LIMIT 500",
                [$reviewerId]
            )->fetchAllAssociative();
        } catch (\Throwable) {
            return [
                'items' => [],
                'pagination' => $this->buildEmptyQueuePagination($filters),
            ];
        }

        $queueQueryString = $this->buildReviewerQueueQueryString($filters);
        $queue = [];
        foreach ($rows as $row) {
            $openCount = (int) ($row['open_review_count'] ?? 0);
            $resolvedCount = (int) ($row['resolved_review_count'] ?? 0);
            $needsAuthorReplyCount = (int) ($row['needs_author_reply_count'] ?? 0);
            $reviewCount = (int) ($row['review_count'] ?? 0);

            [$status, $statusTone] = $this->deriveReviewState((string) ($row['status'] ?? 'ready'), $openCount, $resolvedCount, $needsAuthorReplyCount, $reviewCount);
            $priority = $this->normalizePriority((string) ($row['priority'] ?? 'normal'));
            $dueAt = (string) ($row['due_at'] ?? '');
            $scrivenerMeta = $this->extractScrivenerQueueMetadata($row['metadata'] ?? null);
            $listPath = (string) ($scrivenerMeta['list_path'] ?? '');
            $orderPath = (string) ($scrivenerMeta['order_path'] ?? '');
            $source = trim((string) ($row['source'] ?? ''));
            $kind = (string) ($scrivenerMeta['kind'] ?? 'text');
            $isDirectory = $kind === 'directory';
            $resolvedSource = $source !== '' ? $this->resolveReadableSourcePath($source) : null;
            $displayTitle = $listPath !== '' ? (string) basename(str_replace('\\', '/', $listPath)) : (string) $row['title'];
            $depth = $listPath !== '' ? max(0, count(array_filter(explode('/', $listPath), static fn (string $part): bool => $part !== '')) - 1) : 0;

            $queue[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'display_title' => $displayTitle,
                'book_title' => trim((string) ($row['book_title'] ?? '')),
                'source' => (string) ($row['source'] ?? ''),
                'author' => (string) ($row['author_name'] ?? 'Unknown author'),
                'status' => $status,
                'status_key' => $this->reviewStateKeyForLabel($status),
                'status_tone' => $statusTone,
                'due' => $dueAt !== '' ? date('Y-m-d', strtotime($dueAt)) : 'No due date',
                'comments' => $reviewCount,
                'priority' => $priority,
                'list_path' => $listPath,
                'order_path' => $orderPath,
                'depth' => $depth,
                'is_directory' => $isDirectory,
                'is_openable' => (int) $row['id'] > 0,
                'url' => (int) $row['id'] > 0 ? '/dashboard/review/' . (int) $row['id'] . ($queueQueryString !== '' ? '?' . $queueQueryString : '') : null,
            ];
        }

        $filteredQueue = $this->applyReviewerQueueFilters($queue, $filters);
        $filteredQueue = $this->injectQueueDirectoryStructure($filteredQueue);
        $sortedQueue = $this->sortReviewerQueueItems($filteredQueue, (string) ($filters['sort'] ?? 'binder_asc'));

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 10)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = count($sortedQueue);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;
        $pagedQueue = array_values(array_slice($sortedQueue, $offset, $perPage));

        return [
            'items' => $pagedQueue,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_prev' => $page > 1,
                'has_next' => $page < $totalPages,
                'prev_page' => $page > 1 ? $page - 1 : null,
                'next_page' => $page < $totalPages ? $page + 1 : null,
            ],
        ];
    }

    private function normalizeReviewerQueueFilters(array $queryParams): array
    {
        $priority = strtolower(trim((string) ($queryParams['priority'] ?? 'all')));
        $allowedPriorities = ['all', 'lowest', 'low', 'normal', 'high', 'highest'];
        if (!in_array($priority, $allowedPriorities, true)) {
            $priority = 'all';
        }

        $state = strtolower(trim((string) ($queryParams['state'] ?? 'all')));
        $allowedStates = ['all', 'in_review', 'needs_author_reply', 'resolved', 'ready_for_review', 'placeholder'];
        if (!in_array($state, $allowedStates, true)) {
            $state = 'all';
        }

        $sort = strtolower(trim((string) ($queryParams['sort'] ?? 'binder_asc')));
        $allowedSorts = ['binder_asc', 'due_asc', 'due_desc', 'chapter_asc', 'chapter_desc', 'imported_desc'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'binder_asc';
        }

        $search = trim((string) ($queryParams['q'] ?? ''));
        if (strlen($search) > 120) {
            $search = substr($search, 0, 120);
        }

        $perPage = (int) ($queryParams['per_page'] ?? 10);
        $allowedPerPage = [10, 25, 50, 100];
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 25;
        }

        $page = (int) ($queryParams['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }

        return [
            'q' => $search,
            'priority' => $priority,
            'state' => $state,
            'sort' => $sort,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    private function buildReviewerQueueQueryString(array $filters): string
    {
        $normalized = $this->normalizeReviewerQueueFilters($filters);

        return (string) http_build_query([
            'q' => (string) ($normalized['q'] ?? ''),
            'priority' => (string) ($normalized['priority'] ?? 'all'),
            'state' => (string) ($normalized['state'] ?? 'all'),
            'sort' => (string) ($normalized['sort'] ?? 'binder_asc'),
            'page' => (int) ($normalized['page'] ?? 1),
            'per_page' => (int) ($normalized['per_page'] ?? 10),
        ]);
    }

    private function sanitizeReviewerQueueQueryString(string $queryString): string
    {
        $queryString = trim($queryString);
        if ($queryString === '') {
            return '';
        }

        parse_str($queryString, $parsed);
        if (!is_array($parsed)) {
            return '';
        }

        return $this->buildReviewerQueueQueryString($parsed);
    }

    private function buildEmptyQueuePagination(array $filters): array
    {
        return [
            'page' => max(1, (int) ($filters['page'] ?? 1)),
            'per_page' => max(1, (int) ($filters['per_page'] ?? 10)),
            'total' => 0,
            'total_pages' => 1,
            'has_prev' => false,
            'has_next' => false,
            'prev_page' => null,
            'next_page' => null,
        ];
    }

    private function applyReviewerQueueFilters(array $queue, array $filters): array
    {
        $search = strtolower(trim((string) ($filters['q'] ?? '')));
        $priority = (string) ($filters['priority'] ?? 'all');
        $state = (string) ($filters['state'] ?? 'all');

        return array_values(array_filter($queue, static function (array $item) use ($search, $priority, $state): bool {
            if ($priority !== 'all' && (string) ($item['priority'] ?? '') !== $priority) {
                return false;
            }

            if ($state !== 'all' && (string) ($item['status_key'] ?? '') !== $state) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            $haystack = strtolower(
                implode(' ', [
                    (string) ($item['title'] ?? ''),
                    (string) ($item['book_title'] ?? ''),
                    (string) ($item['author'] ?? ''),
                    (string) ($item['source'] ?? ''),
                ])
            );

            return str_contains($haystack, $search);
        }));
    }

    private function sortReviewerQueueItems(array $queue, string $sort): array
    {
        $sorted = array_values($queue);

        usort($sorted, function (array $a, array $b) use ($sort): int {
            if ($sort === 'binder_asc') {
                $leftOrder = trim((string) ($a['order_path'] ?? ''));
                $rightOrder = trim((string) ($b['order_path'] ?? ''));

                if ($leftOrder !== '' && $rightOrder !== '' && $leftOrder !== $rightOrder) {
                    return strnatcasecmp($leftOrder, $rightOrder);
                }

                if ($leftOrder !== '' && $rightOrder === '') {
                    return -1;
                }

                if ($leftOrder === '' && $rightOrder !== '') {
                    return 1;
                }

                $leftIsDirectory = !empty($a['is_directory']);
                $rightIsDirectory = !empty($b['is_directory']);
                if ($leftIsDirectory !== $rightIsDirectory) {
                    return $leftIsDirectory ? -1 : 1;
                }

                $leftPath = trim((string) ($a['list_path'] ?? ''));
                $rightPath = trim((string) ($b['list_path'] ?? ''));
                if ($leftPath !== '' && $rightPath !== '' && $leftPath !== $rightPath) {
                    return strnatcasecmp($leftPath, $rightPath);
                }

                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            }

            if ($sort === 'chapter_asc' || $sort === 'chapter_desc') {
                $left = trim((string) (($a['display_title'] ?? '') !== '' ? $a['display_title'] : ($a['title'] ?? '')));
                $right = trim((string) (($b['display_title'] ?? '') !== '' ? $b['display_title'] : ($b['title'] ?? '')));
                $compare = strnatcasecmp($left, $right);
                if ($compare === 0) {
                    $compare = ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
                }

                return $sort === 'chapter_desc' ? -$compare : $compare;
            }

            if ($sort === 'due_desc' || $sort === 'due_asc') {
                $leftDue = $this->safeTimestamp((string) ($a['due'] ?? ''));
                $rightDue = $this->safeTimestamp((string) ($b['due'] ?? ''));

                if ($leftDue === null && $rightDue === null) {
                    return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
                }

                if ($leftDue === null) {
                    return 1;
                }

                if ($rightDue === null) {
                    return -1;
                }

                $compare = $leftDue <=> $rightDue;
                if ($compare === 0) {
                    $compare = ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
                }

                return $sort === 'due_desc' ? -$compare : $compare;
            }

            // imported_desc fallback
            $leftId = (int) ($a['id'] ?? 0);
            $rightId = (int) ($b['id'] ?? 0);

            return $rightId <=> $leftId;
        });

        return $sorted;
    }

    private function safeTimestamp(string $value): ?int
    {
        if ($value === '' || strtolower($value) === 'no due date') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return $timestamp;
    }

    private function reviewStateKeyForLabel(string $label): string
    {
        return match ($label) {
            'Placeholder' => 'placeholder',
            'In review' => 'in_review',
            'Needs author reply' => 'needs_author_reply',
            'Resolved' => 'resolved',
            default => 'ready_for_review',
        };
    }

    private function extractScrivenerQueueMetadata(mixed $rawMetadata): array
    {
        if (is_array($rawMetadata)) {
            $scrivener = $rawMetadata['scrivener'] ?? null;
            return is_array($scrivener) ? $scrivener : [];
        }

        if (!is_string($rawMetadata) || trim($rawMetadata) === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawMetadata, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $scrivener = $decoded['scrivener'] ?? null;
        return is_array($scrivener) ? $scrivener : [];
    }

    private function injectQueueDirectoryStructure(array $queue): array
    {
        if ($queue === []) {
            return [];
        }

        $structured = [];
        $seenDirectories = [];

        foreach ($queue as $item) {
            if (empty($item['is_directory'])) {
                continue;
            }

            $listPath = trim((string) ($item['list_path'] ?? ''));
            if ($listPath !== '') {
                $seenDirectories[$listPath] = true;
            }
        }

        foreach ($queue as $item) {
            $listPath = trim((string) ($item['list_path'] ?? ''));
            $orderPath = trim((string) ($item['order_path'] ?? ''));
            if ($listPath !== '') {
                $parts = array_values(array_filter(explode('/', $listPath), static fn (string $part): bool => $part !== ''));
                $orderParts = $orderPath !== '' ? explode('.', $orderPath) : [];

                $ancestorPath = '';
                for ($index = 0; $index < max(0, count($parts) - 1); $index++) {
                    $ancestorPath = $ancestorPath === '' ? $parts[$index] : $ancestorPath . '/' . $parts[$index];
                    if (isset($seenDirectories[$ancestorPath])) {
                        continue;
                    }

                    $ancestorOrderPath = '';
                    if ($orderParts !== []) {
                        $ancestorOrderPath = implode('.', array_slice($orderParts, 0, $index + 1));
                    }

                    $structured[] = [
                        'id' => 0,
                        'title' => $parts[$index],
                        'display_title' => $parts[$index],
                        'book_title' => (string) ($item['book_title'] ?? ''),
                        'source' => '',
                        'author' => (string) ($item['author'] ?? 'Unknown author'),
                        'status' => 'Structure',
                        'status_key' => 'structure',
                        'status_tone' => 'neutral',
                        'due' => 'No due date',
                        'comments' => 0,
                        'priority' => 'normal',
                        'list_path' => $ancestorPath,
                        'order_path' => $ancestorOrderPath,
                        'depth' => $index,
                        'is_directory' => true,
                        'is_openable' => false,
                        'url' => null,
                    ];
                    $seenDirectories[$ancestorPath] = true;
                }

                $item['display_title'] = (string) basename(str_replace('\\', '/', $listPath));
                $item['depth'] = max(0, count($parts) - 1);
            }

            $structured[] = $item;
        }

        $directoryPaths = [];
        foreach ($structured as $item) {
            if (!empty($item['is_directory'])) {
                $listPath = trim((string) ($item['list_path'] ?? ''));
                if ($listPath !== '') {
                    $directoryPaths[] = $listPath;
                }
            }
        }

        foreach ($structured as $index => $item) {
            if (empty($item['is_directory'])) {
                continue;
            }

            $listPath = trim((string) ($item['list_path'] ?? ''));
            $hasChildren = false;
            if ($listPath !== '') {
                $prefix = $listPath . '/';
                foreach ($directoryPaths as $candidatePath) {
                    if ($candidatePath !== $listPath && str_starts_with($candidatePath, $prefix)) {
                        $hasChildren = true;
                        break;
                    }
                }

                if (!$hasChildren) {
                    foreach ($structured as $candidate) {
                        $candidatePath = trim((string) ($candidate['list_path'] ?? ''));
                        if ($candidatePath !== $listPath && str_starts_with($candidatePath, $prefix)) {
                            $hasChildren = true;
                            break;
                        }
                    }
                }
            }

            $structured[$index]['has_children'] = $hasChildren;
        }

        $directoryChildStats = [];
        foreach ($structured as $item) {
            $listPath = trim((string) ($item['list_path'] ?? ''));
            if ($listPath === '') {
                continue;
            }

            $normalizedPath = str_replace('\\', '/', $listPath);
            $parts = array_values(array_filter(explode('/', $normalizedPath), static fn (string $part): bool => $part !== ''));
            if (count($parts) <= 1) {
                continue;
            }

            $parentPath = implode('/', array_slice($parts, 0, -1));
            if ($parentPath === '') {
                continue;
            }

            if (!isset($directoryChildStats[$parentPath])) {
                $directoryChildStats[$parentPath] = [
                    'child_item_count' => 0,
                    'child_folder_count' => 0,
                ];
            }

            if (!empty($item['is_directory'])) {
                $directoryChildStats[$parentPath]['child_folder_count']++;
            } else {
                $directoryChildStats[$parentPath]['child_item_count']++;
            }
        }

        foreach ($structured as $index => $item) {
            $listPath = trim((string) ($item['list_path'] ?? ''));
            if ($listPath !== '') {
                $normalizedPath = str_replace('\\', '/', $listPath);
                $parts = array_values(array_filter(explode('/', $normalizedPath), static fn (string $part): bool => $part !== ''));
                $parentPath = count($parts) > 1 ? implode('/', array_slice($parts, 0, -1)) : '';
                $structured[$index]['parent_path'] = $parentPath;
            } else {
                $structured[$index]['parent_path'] = '';
            }

            if (!empty($item['is_directory'])) {
                $pathKey = trim((string) ($item['list_path'] ?? ''));
                $stats = $directoryChildStats[$pathKey] ?? [
                    'child_item_count' => 0,
                    'child_folder_count' => 0,
                ];

                $structured[$index]['child_item_count'] = (int) ($stats['child_item_count'] ?? 0);
                $structured[$index]['child_folder_count'] = (int) ($stats['child_folder_count'] ?? 0);
            } else {
                $structured[$index]['child_item_count'] = 0;
                $structured[$index]['child_folder_count'] = 0;
            }
        }

        return $structured;
    }

    private function buildReviewerOtherItemsByState(?array $user): array
    {
        $reviewerId = (int) ($user['id'] ?? 0);
        if ($reviewerId <= 0) {
            return [];
        }

        try {
            $rows = $this->connection->executeQuery(
                "SELECT
                    cv.id,
                    cv.title,
                    cv.book_title,
                    cv.metadata,
                    cv.status,
                    cv.imported_at,
                    ra.priority,
                    ra.due_at,
                    COUNT(r.id) AS review_count,
                    SUM(CASE WHEN r.status = 'open' THEN 1 ELSE 0 END) AS open_review_count,
                    SUM(CASE WHEN r.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_review_count,
                    SUM(CASE WHEN r.status = 'needs_author_review' THEN 1 ELSE 0 END) AS needs_author_reply_count
                FROM review_assignments ra
                INNER JOIN content_versions cv ON cv.id = ra.content_version_id
                LEFT JOIN reviews r ON r.content_version_id = cv.id
                WHERE ra.reviewer_id = ? AND cv.status <> 'ready'
                GROUP BY cv.id, cv.title, cv.book_title, cv.metadata, cv.status, cv.imported_at, ra.priority, ra.due_at
                ORDER BY cv.status ASC, ra.due_at ASC, cv.imported_at DESC
                LIMIT 200",
                [$reviewerId]
            )->fetchAllAssociative();
        } catch (\Throwable) {
            return [];
        }

        $groups = [];
        foreach ($rows as $row) {
            $statusKey = (string) ($row['status'] ?? 'unknown');
            if (!array_key_exists($statusKey, $groups)) {
                $groups[$statusKey] = [
                    'status_key' => $statusKey,
                    'status_label' => $this->formatContentStatusLabel($statusKey),
                    'status_tone' => $this->toneForContentStatus($statusKey),
                    'items' => [],
                ];
            }

            $openCount = (int) ($row['open_review_count'] ?? 0);
            $resolvedCount = (int) ($row['resolved_review_count'] ?? 0);
            $needsAuthorReplyCount = (int) ($row['needs_author_reply_count'] ?? 0);
            $reviewCount = (int) ($row['review_count'] ?? 0);
            [$reviewState, $reviewStateTone] = $this->deriveReviewState($statusKey, $openCount, $resolvedCount, $needsAuthorReplyCount, $reviewCount);

            $dueAt = (string) ($row['due_at'] ?? '');
            $scrivenerMeta = $this->extractScrivenerQueueMetadata($row['metadata'] ?? null);
            $listPath = (string) ($scrivenerMeta['list_path'] ?? '');
            $orderPath = (string) ($scrivenerMeta['order_path'] ?? '');
            $kind = (string) ($scrivenerMeta['kind'] ?? 'text');
            $isDirectory = $kind === 'directory';
            $displayTitle = $listPath !== '' ? (string) basename(str_replace('\\', '/', $listPath)) : (string) ($row['title'] ?? 'Untitled');
            $depth = $listPath !== '' ? max(0, count(array_filter(explode('/', $listPath), static fn (string $part): bool => $part !== '')) - 1) : 0;

            $groups[$statusKey]['items'][] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? 'Untitled'),
                'display_title' => $displayTitle,
                'book_title' => trim((string) ($row['book_title'] ?? '')),
                'content_status' => $statusKey,
                'status' => $isDirectory ? 'Structure' : $reviewState,
                'status_tone' => $isDirectory ? 'neutral' : $reviewStateTone,
                'due' => $dueAt !== '' ? date('Y-m-d', strtotime($dueAt)) : 'No due date',
                'priority' => $this->normalizePriority((string) ($row['priority'] ?? 'normal')),
                'comments' => $reviewCount,
                'list_path' => $listPath,
                'order_path' => $orderPath,
                'depth' => $depth,
                'is_directory' => $isDirectory,
                'is_openable' => false,
                'url' => null,
            ];
        }

        foreach ($groups as $statusKey => $group) {
            $items = is_array($group['items'] ?? null) ? $group['items'] : [];
            $items = $this->injectQueueDirectoryStructure($items);
            $items = $this->sortReviewerQueueItems($items, 'binder_asc');
            $groups[$statusKey]['items'] = array_values($items);
        }

        return array_values($groups);
    }

    private function buildReviewerQueueContext(array $queue): array
    {
        if ($queue === []) {
            return [
                'author' => null,
                'book_title' => null,
            ];
        }

        $first = null;
        foreach ($queue as $item) {
            if (!empty($item['is_directory'])) {
                continue;
            }

            $first = $item;
            break;
        }

        if ($first === null) {
            $first = $queue[0];
        }
        $bookTitle = trim((string) ($first['book_title'] ?? ''));

        return [
            'author' => (string) ($first['author'] ?? 'Unknown author'),
            'book_title' => $bookTitle !== '' ? $bookTitle : null,
        ];
    }

    private function buildReviewItem(int $contentVersionId): ?array
    {
        $reviewerId = (int) ($this->session->getUser()['id'] ?? 0);
        if ($reviewerId <= 0) {
            return null;
        }

        try {
            $item = $this->connection->executeQuery(
                "SELECT
                    cv.id,
                    cv.title,
                    cv.book_title,
                    cv.version_label,
                    (
                        SELECT COUNT(*)
                        FROM content_versions cv2
                        WHERE cv2.title = cv.title
                          AND (
                              cv2.imported_at < cv.imported_at
                              OR (cv2.imported_at = cv.imported_at AND cv2.id <= cv.id)
                          )
                    ) AS revision_number,
                    cv.source,
                    cv.content_rtf,
                    cv.content_text,
                    cv.metadata,
                    cv.imported_at,
                    cv.status AS content_status,
                    u.name AS author_name,
                    ra.priority,
                    ra.due_at,
                    COUNT(r.id) AS review_count,
                    SUM(CASE WHEN r.status = 'open' THEN 1 ELSE 0 END) AS open_review_count,
                    SUM(CASE WHEN r.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_review_count,
                    SUM(CASE WHEN r.status = 'needs_author_review' THEN 1 ELSE 0 END) AS needs_author_reply_count
                FROM content_versions cv
                INNER JOIN review_assignments ra ON ra.content_version_id = cv.id AND ra.reviewer_id = ?
                LEFT JOIN users u ON u.id = cv.author_id
                LEFT JOIN reviews r ON r.content_version_id = cv.id
                WHERE cv.status = 'ready' AND cv.id = ?
                    GROUP BY cv.id, cv.title, cv.book_title, cv.version_label, cv.source, cv.content_rtf, cv.content_text, cv.imported_at, cv.status, u.name, ra.priority, ra.due_at",
                [$reviewerId, $contentVersionId]
            )->fetchAssociative();

            if (!is_array($item) || $item === []) {
                return null;
            }

            $history = $this->connection->executeQuery(
                "SELECT
                    r.id,
                    r.reviewer_id,
                    r.title,
                    r.status,
                    r.details,
                    r.selected_excerpt,
                    r.anchor_start_offset,
                    r.anchor_end_offset,
                    r.anchor_container_path,
                    r.created_at,
                    r.updated_at,
                    r.resolved_at,
                    u.name AS reviewer_name
                FROM reviews r
                LEFT JOIN users u ON u.id = r.reviewer_id
                WHERE r.content_version_id = ?
                ORDER BY r.created_at DESC, r.id DESC",
                [$contentVersionId]
            )->fetchAllAssociative();
        } catch (\Throwable) {
            return null;
        }

        $openCount = (int) ($item['open_review_count'] ?? 0);
        $resolvedCount = (int) ($item['resolved_review_count'] ?? 0);
        $needsAuthorReplyCount = (int) ($item['needs_author_reply_count'] ?? 0);
        $reviewCount = (int) ($item['review_count'] ?? 0);

        [$statusLabel, $statusTone] = $this->deriveReviewState(
            (string) ($item['content_status'] ?? 'ready'),
            $openCount,
            $resolvedCount,
            $needsAuthorReplyCount,
            $reviewCount
        );

        $reviews = array_map(static function (array $row): array {
            $status = (string) ($row['status'] ?? 'open');

            return [
                'id' => (int) ($row['id'] ?? 0),
                'reviewer_id' => (int) ($row['reviewer_id'] ?? 0),
                'title' => (string) ($row['title'] ?? 'Review note'),
                'reviewer' => (string) ($row['reviewer_name'] ?? 'Anonymous reviewer'),
                'status' => $status,
                'status_label' => self::labelForReviewStatus($status),
                'status_tone' => self::toneForReviewStatus($status),
                'requires_action' => $status === 'needs_author_review',
                'details' => trim((string) ($row['details'] ?? '')),
                'selected_excerpt' => trim((string) ($row['selected_excerpt'] ?? '')),
                'anchor_start_offset' => array_key_exists('anchor_start_offset', $row) && $row['anchor_start_offset'] !== null ? max(0, (int) $row['anchor_start_offset']) : null,
                'anchor_end_offset' => array_key_exists('anchor_end_offset', $row) && $row['anchor_end_offset'] !== null ? max(0, (int) $row['anchor_end_offset']) : null,
                'anchor_container_path' => array_key_exists('anchor_container_path', $row) && $row['anchor_container_path'] !== null ? trim((string) $row['anchor_container_path']) : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'resolved_at' => (string) ($row['resolved_at'] ?? ''),
            ];
        }, $history);

        return [
            'id' => (int) $item['id'],
            'title' => (string) $item['title'],
            'book_title' => trim((string) ($item['book_title'] ?? '')),
            'version_label' => (string) $item['version_label'],
            'revision_number' => max(1, (int) ($item['revision_number'] ?? 1)),
            'source' => (string) ($item['source'] ?? ''),
            'imported_at' => (string) $item['imported_at'],
            'author_name' => (string) ($item['author_name'] ?? 'Unknown author'),
            'priority' => $this->normalizePriority((string) ($item['priority'] ?? 'normal')),
            'due' => !empty($item['due_at']) ? date('Y-m-d', strtotime((string) $item['due_at'])) : 'No due date',
            'content_status' => (string) ($item['content_status'] ?? 'ready'),
            'status_label' => $statusLabel,
            'status_tone' => $statusTone,
            'summary' => [
                ['label' => 'Comments', 'value' => (string) $reviewCount, 'tone' => 'neutral'],
                ['label' => 'Open', 'value' => (string) $openCount, 'tone' => 'warning'],
                ['label' => 'Resolved', 'value' => (string) $resolvedCount, 'tone' => 'success'],
                ['label' => 'Needs author reply', 'value' => (string) $needsAuthorReplyCount, 'tone' => 'info'],
            ],
            'reader' => $this->buildReaderPresentation((string) ($item['content_text'] ?? ''), (string) ($item['content_rtf'] ?? ''), (string) ($item['source'] ?? ''), $item['metadata'] ?? null),
            'reviews' => $reviews,
        ];
    }

    private function buildReaderPresentation(string $contentText, string $contentRtf, string $source, mixed $rawMetadata = null): array
    {
        $html = $this->convertRtfToHtml($contentRtf);
        $storedSections = $this->buildReaderSectionsFromPlainText($contentText);
        if ($html !== null || $storedSections !== []) {
            $wordCount = 0;
            foreach ($storedSections as $section) {
                $wordCount += str_word_count((string) ($section['text'] ?? ''));
            }

            return [
                'available' => true,
                'source_label' => $source !== '' ? $source : 'Stored import content',
                'html' => $html,
                'sections' => $storedSections,
                'paragraph_count' => count($storedSections),
                'word_count' => $wordCount,
                'notice' => null,
            ];
        }

        $resolvedPath = $this->resolveReadableSourcePath($source);
        if ($resolvedPath === null) {
            $resolvedPath = $this->resolveReadableSourcePathFromMetadata($rawMetadata);
        }
        if ($resolvedPath === null) {
            return [
                'available' => false,
                'source_label' => $source !== '' ? $source : 'Unavailable',
                'html' => null,
                'sections' => [],
                'paragraph_count' => 0,
                'word_count' => 0,
                'notice' => 'Original source file is currently unavailable for this item.',
            ];
        }

        $rawXml = @file_get_contents($resolvedPath);
        if ($rawXml === false) {
            return [
                'available' => false,
                'source_label' => $source !== '' ? $source : basename($resolvedPath),
                'html' => null,
                'sections' => [],
                'paragraph_count' => 0,
                'word_count' => 0,
                'notice' => 'Source file could not be read. Please try re-importing this item.',
            ];
        }

        $html = $this->convertRtfToHtml($rawXml);
        $sections = $this->extractReaderSections($rawXml);
        if ($sections === []) {
            $sections = $this->extractReaderSectionsFromRtf($rawXml);
        }

        if ($sections === [] && $html === null) {
            return [
                'available' => false,
                'source_label' => $source !== '' ? $source : basename($resolvedPath),
                'html' => null,
                'sections' => [],
                'paragraph_count' => 0,
                'word_count' => 0,
                'notice' => 'No readable text blocks were found in this source file.',
            ];
        }

        $wordCount = 0;
        foreach ($sections as $section) {
            $wordCount += str_word_count((string) ($section['text'] ?? ''));
        }

        return [
            'available' => true,
            'source_label' => $source !== '' ? $source : basename($resolvedPath),
            'html' => $html,
            'sections' => $sections,
            'paragraph_count' => count($sections),
            'word_count' => $wordCount,
            'notice' => null,
        ];
    }

    private function convertRtfToHtml(string $rawRtf): ?string
    {
        $rawRtf = trim($rawRtf);
        if ($rawRtf === '' || !str_starts_with($rawRtf, '{\\rtf')) {
            return null;
        }

        // Scrivener injects its own pseudo-tags into text runs; strip only those known markers.
        $cleanedRtf = preg_replace('/<!?\$Scr_[^>]+>/u', '', $rawRtf);
        if (!is_string($cleanedRtf) || $cleanedRtf === '') {
            return null;
        }

        try {
            $document = new RtfDocument($cleanedRtf);
            $formatter = new ScrivenerHtmlFormatter('UTF-8');
            $html = trim($formatter->Format($document));
        } catch (\Throwable) {
            return null;
        }

        if ($html === '') {
            return null;
        }

        $html = preg_replace('/<!?\$Scr_[^>]+>/u', '', $html) ?? $html;
        $html = $this->restoreRtfHyperlinks($html, $rawRtf);

        return trim($html) !== '' ? trim($html) : null;
    }

    private function restoreRtfHyperlinks(string $html, string $rawRtf): string
    {
        $segments = explode('{\\field', $rawRtf);
        if (count($segments) < 2) {
            return $html;
        }

        foreach (array_slice($segments, 1) as $segment) {
            if (!preg_match('/HYPERLINK\s+"([^"]+)"/i', $segment, $urlMatch)) {
                continue;
            }

            $url = trim((string) ($urlMatch[1] ?? ''));
            $fldrsltPosition = stripos($segment, '\fldrslt');
            if ($url === '' || $fldrsltPosition === false) {
                continue;
            }

            $labelChunk = substr($segment, $fldrsltPosition + strlen('\fldrslt'));
            if (!is_string($labelChunk) || $labelChunk === '') {
                continue;
            }

            $closingPosition = strpos($labelChunk, '}}');
            if ($closingPosition !== false) {
                $labelChunk = substr($labelChunk, 0, $closingPosition);
            }

            $label = trim($this->extractPlainTextFromRtf('{' . $labelChunk . '}'));
            if ($label === '') {
                continue;
            }

            $quotedLabel = preg_quote($label, '/');
            $replacement = '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
            $updatedHtml = preg_replace('/' . $quotedLabel . '/u', $replacement, $html, 1);
            if (is_string($updatedHtml)) {
                $html = $updatedHtml;
            }
        }

        return $html;
    }

    private function normalizeReaderViewMode(string $mode): string
    {
        $mode = trim($mode);

        return in_array($mode, ['fullscreen', 'compact-hidden', 'compact-visible'], true) ? $mode : 'compact-hidden';
    }

    private function resolveReadableSourcePath(string $source): ?string
    {
        $normalized = trim(str_replace('\\', '/', $source));
        $normalized = ltrim($normalized, '/');

        if ($normalized === '' || str_contains($normalized, '..')) {
            return null;
        }

        $projectRoot = dirname(__DIR__, 2);
        $candidates = [
            $projectRoot . '/' . $normalized,
            $projectRoot . '/scrivener/' . $normalized,
            $projectRoot . '/scrivener/Draft/' . $normalized,
            $projectRoot . '/scrivener/Notes/' . $normalized,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractReaderSections(string $rawXml): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string(trim($rawXml));
        if ($xml === false) {
            libxml_clear_errors();

            return [];
        }

        $sections = [];
        $paragraphNodes = $xml->xpath('//Paragraph | //paragraph');
        if ($paragraphNodes !== false) {
            $sections = $this->buildSectionsFromParagraphNodes($paragraphNodes);
        }

        if ($sections !== []) {
            return $sections;
        }

        $textNodes = $xml->xpath('//TEXT | //Text | //text');
        if ($textNodes === false) {
            return [];
        }

        $index = 1;
        foreach ($textNodes as $textNode) {
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $textNode));
            if ($text === '') {
                continue;
            }

            $sections[] = [
                'anchor' => 'section-' . $index,
                'label' => $this->buildReaderSectionLabel($text, $index),
                'type' => null,
                'text' => $text,
            ];
            $index++;
        }

        return $sections;
    }

    private function extractReaderSectionsFromRtf(string $rawRtf): array
    {
        $plainText = $this->extractPlainTextFromRtf($rawRtf);
        return $this->buildReaderSectionsFromPlainText($plainText);
    }

    private function buildReaderSectionsFromPlainText(string $plainText): array
    {
        if ($plainText === '') {
            return [];
        }

        $sections = [];
        $lines = preg_split('/(?:\r\n|\r|\n)+/', $plainText) ?: [];
        $index = 1;

        foreach ($lines as $line) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($text === '') {
                continue;
            }

            $sections[] = [
                'anchor' => 'section-' . $index,
                'label' => $this->buildReaderSectionLabel($text, $index),
                'type' => null,
                'text' => $text,
            ];
            $index++;
        }

        return $sections;
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

    private function resolveReadableSourcePathFromMetadata(mixed $rawMetadata): ?string
    {
        $metadata = $this->extractScrivenerQueueMetadata($rawMetadata);
        if ($metadata === []) {
            return null;
        }

        $projectFile = trim((string) ($metadata['project_file'] ?? ''));
        $uuid = trim((string) ($metadata['uuid'] ?? ''));
        if ($projectFile === '' || $uuid === '') {
            return null;
        }

        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $uuid)) {
            return null;
        }

        $projectFile = str_replace('\\', '/', $projectFile);
        if (str_starts_with($projectFile, '/') || preg_match('/^[A-Za-z]:\//', $projectFile)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $projectFile), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return null;
        }

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        $projectFile = implode('/', $segments);
        $projectDirectory = dirname($projectFile);
        if ($projectDirectory === '.' || $projectDirectory === '') {
            return null;
        }

        $projectRoot = dirname(__DIR__, 2);
        $candidate = $projectRoot . '/' . $projectDirectory . '/Files/Data/' . $uuid . '/content.rtf';
        if (!is_file($candidate)) {
            return null;
        }

        $candidateRealPath = realpath($candidate);
        $projectRootRealPath = realpath($projectRoot);
        if (!is_string($candidateRealPath) || !is_string($projectRootRealPath)) {
            return null;
        }

        $normalizedCandidate = strtolower(str_replace('\\', '/', $candidateRealPath));
        $normalizedRoot = rtrim(strtolower(str_replace('\\', '/', $projectRootRealPath)), '/');
        if (!str_starts_with($normalizedCandidate, $normalizedRoot . '/')) {
            return null;
        }

        return $candidateRealPath;
    }

    private function buildSectionsFromParagraphNodes(array $paragraphNodes): array
    {
        $sections = [];
        $index = 1;

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

            if ($text === '') {
                continue;
            }

            $type = trim((string) ($paragraphNode['Type'] ?? $paragraphNode['type'] ?? ''));

            $sections[] = [
                'anchor' => 'section-' . $index,
                'label' => $this->buildReaderSectionLabel($text, $index),
                'type' => $type !== '' ? $type : null,
                'text' => $text,
            ];
            $index++;
        }

        return $sections;
    }

    private function buildReaderSectionLabel(string $text, int $index): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($normalized === '') {
            return 'Section ' . $index;
        }

        $words = preg_split('/\s+/u', $normalized);
        if (!is_array($words) || $words === []) {
            return 'Section ' . $index;
        }

        $previewWords = array_slice($words, 0, 8);
        $preview = trim(implode(' ', $previewWords));

        if (count($words) > 8) {
            $preview .= '...';
        }

        return $preview !== '' ? $preview : 'Section ' . $index;
    }

    private function deriveReviewState(string $contentStatus, int $openCount, int $resolvedCount, int $needsAuthorReplyCount, int $reviewCount): array
    {
        if ($contentStatus === 'placeholder') {
            return ['Placeholder', 'neutral'];
        }

        if ($openCount > 0) {
            return ['In review', 'warning'];
        }

        if ($needsAuthorReplyCount > 0) {
            return ['Needs author reply', 'info'];
        }

        if ($resolvedCount > 0 || $reviewCount > 0) {
            return ['Resolved', 'success'];
        }

        return ['Ready for review', 'neutral'];
    }

    private static function labelForReviewStatus(string $status): string
    {
        return match ($status) {
            'resolved' => 'Resolved',
            'needs_author_review' => 'Needs author reply',
            default => 'Open',
        };
    }

    private static function toneForReviewStatus(string $status): string
    {
        return match ($status) {
            'resolved' => 'success',
            'needs_author_review' => 'info',
            default => 'warning',
        };
    }

    private function normalizeReviewStatus(string $status): string
    {
        return in_array($status, self::REVIEW_STATUSES, true) ? $status : 'open';
    }

    private function normalizeRequiresAction(array $data): bool
    {
        if (array_key_exists('requires_action', $data)) {
            $value = $data['requires_action'];
            if (is_bool($value)) {
                return $value;
            }

            return !in_array(strtolower(trim((string) $value)), ['0', 'false', 'off', 'no', ''], true);
        }

        if (array_key_exists('status', $data)) {
            return $this->normalizeReviewStatus((string) $data['status']) !== 'open';
        }

        return true;
    }

    private function normalizeAnchorOffset(mixed $value): ?int
    {
        if (is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            return null;
        }

        return max(0, (int) $raw);
    }

    private function normalizeAnchorContainerPath(mixed $value): ?string
    {
        if (is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }

        $path = trim((string) $value);
        if ($path === '') {
            return null;
        }

        if (strlen($path) > 255) {
            $path = substr($path, 0, 255);
        }

        return $path === '' ? null : $path;
    }

    private function normalizePriority(string $priority): string
    {
        return match ($priority) {
            'lowest', 'low', 'normal', 'high', 'highest' => $priority,
            default => 'normal',
        };
    }

    private function formatContentStatusLabel(string $status): string
    {
        $normalized = trim(str_replace('_', ' ', $status));
        if ($normalized === '') {
            return 'Unknown';
        }

        return ucwords($normalized);
    }

    private function toneForContentStatus(string $status): string
    {
        return match ($status) {
            'ready' => 'success',
            'placeholder' => 'neutral',
            'draft' => 'warning',
            'needs_revision' => 'info',
            default => 'neutral',
        };
    }

    private function validateReviewSubmissionCsrf(array $data): bool
    {
        $csrfToken = trim((string) ($data['_csrf'] ?? ''));

        return $this->session->validateCsrfToken($csrfToken);
    }

    private function buildAuthorMetrics(): array
    {
        return [
            ['label' => 'Drafts In Progress', 'value' => '4', 'tone' => 'neutral'],
            ['label' => 'Waiting For Review', 'value' => '2', 'tone' => 'warning'],
            ['label' => 'Resolved Comments', 'value' => '7', 'tone' => 'success'],
            ['label' => 'Needs Revision', 'value' => '1', 'tone' => 'info'],
        ];
    }

    private function buildAuthorQueue(): array
    {
        return [
            [
                'title' => 'Chapter 1: Foundations',
                'reviewer' => 'A. Carter',
                'status' => 'Commented',
                'updated' => '2h ago',
                'next_step' => 'Address notes',
            ],
            [
                'title' => 'Chapter 3: Case Study',
                'reviewer' => 'S. Malik',
                'status' => 'In review',
                'updated' => 'Today',
                'next_step' => 'Await feedback',
            ],
            [
                'title' => 'Appendix B: References',
                'reviewer' => 'L. Harper',
                'status' => 'Approved',
                'updated' => 'Yesterday',
                'next_step' => 'Ready to publish',
            ],
        ];
    }
}
