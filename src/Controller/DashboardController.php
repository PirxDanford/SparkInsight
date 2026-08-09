<?php

declare(strict_types=1);

namespace SparkInsight\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;
use Psr\Http\Message\UploadedFileInterface;
use SparkInsight\Service\AuthorPdfExportService;
use SparkInsight\Service\BookPackageImportService;
use SparkInsight\Service\UserSession;
use SparkInsight\Support\ReaderPresentationBuilder;
use Throwable;

final class DashboardController
{
    private const REVIEW_STATUSES = ['open', 'resolved', 'needs_author_review'];

    private const REVIEW_RESOLUTION_DECISIONS = ['resolved', 'ignored', 'still_relevant'];

    public function __construct(
        private readonly PhpRenderer $renderer,
        private readonly UserSession $session,
        private readonly Connection $connection,
        private readonly ?AuthorPdfExportService $authorPdfExportService = null,
        private readonly ?BookPackageImportService $bookPackageImportService = null,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        if (!$this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = $this->session->getUser();
        $roles = $this->getUserRoles($user);

        if ($this->isPureAdmin($roles)) {
            return $response->withHeader('Location', '/dashboard/admin')->withStatus(302);
        }

        if ($this->canAuthor($roles)) {
            return $response->withHeader('Location', '/dashboard/author')->withStatus(302);
        }

        return $response->withHeader('Location', '/dashboard/review')->withStatus(302);
    }

    public function review(Request $request, Response $response): Response
    {
        if (!$this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = $this->session->getUser();
        $roles = $this->getUserRoles($user);

        if ($this->isPureAdmin($roles)) {
            return $response->withHeader('Location', '/dashboard/admin')->withStatus(302);
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
        if (!$this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = $this->session->getUser();
        $roles = $this->getUserRoles($user);

        if ($this->isPureAdmin($roles)) {
            return $response->withHeader('Location', '/dashboard/admin')->withStatus(302);
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
        if (!$this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = $this->session->getUser();
        $roles = $this->getUserRoles($user);

        if ($this->isPureAdmin($roles)) {
            return $response->withHeader('Location', '/dashboard/admin')->withStatus(302);
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
                    'details' => is_array($data) ? mb_trim((string) ($data['details'] ?? '')) : '',
                    'selected_excerpt' => is_array($data) ? mb_trim((string) ($data['selected_excerpt'] ?? '')) : '',
                    'anchor_start_offset' => is_array($data) ? $this->normalizeAnchorOffset($data['anchor_start_offset'] ?? null) : null,
                    'anchor_end_offset' => is_array($data) ? $this->normalizeAnchorOffset($data['anchor_end_offset'] ?? null) : null,
                    'anchor_container_path' => is_array($data) ? $this->normalizeAnchorContainerPath($data['anchor_container_path'] ?? null) : null,
                    'requires_action' => is_array($data) ? $this->normalizeRequiresAction($data) : true,
                ],
            ]);
        }

        $details = mb_trim((string) ($data['details'] ?? ''));
        $selectedExcerpt = is_array($data) ? mb_trim((string) ($data['selected_excerpt'] ?? '')) : '';
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
        $noteAction = is_array($data) ? mb_strtolower(mb_trim((string) ($data['note_action'] ?? 'save'))) : 'save';
        if ($noteAction !== 'delete') {
            $noteAction = 'save';
        }
        $requiresAction = $this->normalizeRequiresAction($data);
        $status = $requiresAction ? 'needs_author_review' : 'open';
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $existingNoteId = 0;
        if ($noteId > 0) {
            $existingNoteId = (int) $this->connection->fetchOne(
                'SELECT id FROM reviews WHERE id = ? AND content_version_id = ? AND reviewer_id = ?',
                [$noteId, $contentVersionId, (int) ($user['id'] ?? 0)],
            );
        }

        if ($existingNoteId > 0) {
            if ($noteAction === 'delete') {
                $this->connection->executeStatement(
                    'DELETE FROM reviews WHERE id = ? AND content_version_id = ? AND reviewer_id = ?',
                    [
                        $existingNoteId,
                        $contentVersionId,
                        (int) ($user['id'] ?? 0),
                    ],
                );
                $this->session->setFlash('success', 'Review note deleted.');

                return $response->withHeader('Location', $itemUrl)->withStatus(302);
            }

            $this->connection->executeStatement(
                'UPDATE reviews SET title = ?, status = ?, details = ?, selected_excerpt = ?, anchor_start_offset = ?, anchor_end_offset = ?, anchor_container_path = ?, updated_at = ?, resolved_at = ?, resolution_decision = ?, resolution_actor_id = ?, resolution_actor_role = ?, resolution_recorded_at = ? WHERE id = ? AND content_version_id = ? AND reviewer_id = ?',
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
                    null,
                    null,
                    null,
                    null,
                    $existingNoteId,
                    $contentVersionId,
                    (int) ($user['id'] ?? 0),
                ],
            );
            $this->session->setFlash('success', 'Review note updated.');

            return $response->withHeader('Location', $itemUrl)->withStatus(302);
        }

        if ($noteAction === 'delete') {
            $this->session->setFlash('warning', 'That note could not be deleted. It may no longer exist.');

            return $response->withHeader('Location', $itemUrl)->withStatus(302);
        }

        $this->connection->executeStatement(
            'INSERT INTO reviews (content_version_id, reviewer_id, title, status, details, selected_excerpt, anchor_start_offset, anchor_end_offset, anchor_container_path, created_at, updated_at, resolved_at, resolution_decision, resolution_actor_id, resolution_actor_role, resolution_recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                null,
                null,
                null,
                null,
            ],
        );

        $this->session->setFlash('success', 'Review update saved.');

        return $response->withHeader('Location', $itemUrl)->withStatus(302);
    }

    public function author(Request $request, Response $response): Response
    {
        if (!$this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = $this->session->getUser();
        $roles = $this->getUserRoles($user);

        if ($this->isPureAdmin($roles)) {
            return $response->withHeader('Location', '/dashboard/admin')->withStatus(302);
        }

        if (!$this->canAuthor($roles)) {
            if ($this->canReview($roles)) {
                return $response->withHeader('Location', '/dashboard/review')->withStatus(302);
            }

            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $queueFilters = $this->normalizeAuthorQueueFilters($request->getQueryParams());

        return $this->renderDashboard($response, $user, 'author', [
            'author_queue_filters' => $queueFilters,
        ]);
    }

    public function authorItem(Request $request, Response $response, array $args): Response
    {
        if (!$this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = $this->session->getUser();
        $roles = $this->getUserRoles($user);

        if ($this->isPureAdmin($roles)) {
            return $response->withHeader('Location', '/dashboard/admin')->withStatus(302);
        }

        if (!$this->canAuthor($roles)) {
            if ($this->canReview($roles)) {
                return $response->withHeader('Location', '/dashboard/review')->withStatus(302);
            }

            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $queryParams = (array) $request->getQueryParams();
        $queueFilters = $this->normalizeAuthorQueueFilters($queryParams);
        $queueQueryString = $this->buildAuthorQueueQueryString($queueFilters);
        $queueBackUrl = '/dashboard/author' . ($queueQueryString !== '' ? '?' . $queueQueryString : '');

        $contentVersionId = (int) ($args['id'] ?? 0);
        $item = $contentVersionId > 0 ? $this->buildAuthorItem((int) ($user['id'] ?? 0), $contentVersionId) : null;
        if ($item === null) {
            $this->session->setFlash('warning', 'That author item could not be opened.');

            return $response->withHeader('Location', $queueBackUrl)->withStatus(302);
        }

        return $this->renderer->render($response, 'author-item.php', [
            'title' => 'Author',
            'dashboard_mode' => 'author',
            'user' => $user,
            'author_item' => $item,
            'csrf_token' => $this->session->getCsrfToken(),
            'queue_back_url' => $queueBackUrl,
            'queue_query_string' => $queueQueryString,
            'flash_message' => $this->session->getFlash(),
            'author_parent_options' => $this->buildAuthorParentOptions($user, $contentVersionId),
        ]);
    }

    public function resolveAuthorReview(Request $request, Response $response, array $args): Response
    {
        if (!$this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = $this->session->getUser();
        $roles = $this->getUserRoles($user);

        if ($this->isPureAdmin($roles)) {
            return $response->withHeader('Location', '/dashboard/admin')->withStatus(302);
        }

        if (!$this->canAuthor($roles)) {
            if ($this->canReview($roles)) {
                return $response->withHeader('Location', '/dashboard/review')->withStatus(302);
            }

            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $contentVersionId = max(0, (int) ($args['id'] ?? 0));
        $reviewId = max(0, (int) ($args['reviewId'] ?? 0));
        $data = $request->getParsedBody();
        $returnQueueQuery = is_array($data)
            ? $this->sanitizeAuthorQueueQueryString((string) ($data['return_queue_query'] ?? ''))
            : '';
        $itemUrl = '/dashboard/author/' . $contentVersionId . ($returnQueueQuery !== '' ? '?' . $returnQueueQuery : '');

        if (!is_array($data) || !$this->validateReviewSubmissionCsrf($data)) {
            $this->session->setFlash('error', 'Please refresh and try the note action again.');

            return $response->withHeader('Location', $itemUrl)->withStatus(302);
        }

        if ($contentVersionId <= 0 || $reviewId <= 0) {
            $this->session->setFlash('warning', 'That review note could not be updated.');

            return $response->withHeader('Location', $itemUrl)->withStatus(302);
        }

        $authorId = (int) ($user['id'] ?? 0);
        if ($authorId <= 0) {
            return $response->withHeader('Location', '/dashboard/author')->withStatus(302);
        }

        $action = is_array($data) ? mb_strtolower(mb_trim((string) ($data['resolution_action'] ?? 'resolve'))) : 'resolve';
        $resolutionDecision = $this->normalizeReviewResolutionDecision($action);
        $targetStatus = in_array($resolutionDecision, ['resolved', 'ignored'], true) ? 'resolved' : 'needs_author_review';
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $existing = $this->connection->fetchAssociative(
                'SELECT r.id, r.status, r.content_version_id, r.anchor_remap_state, r.anchor_remap_reason, r.anchor_remapped_from_content_version_id FROM reviews r INNER JOIN content_versions cv ON cv.id = r.content_version_id WHERE r.id = ? AND r.content_version_id = ? AND cv.author_id = ?',
                [$reviewId, $contentVersionId, $authorId],
            );
        } catch (Throwable) {
            $existing = false;
        }

        if (!is_array($existing) || $existing === []) {
            $this->session->setFlash('warning', 'That review note could not be updated.');

            return $response->withHeader('Location', $itemUrl)->withStatus(302);
        }

        $needsParent = $this->isUnresolvedReviewLocation($existing);
        $requiresParentSelection = $needsParent && $resolutionDecision === 'resolved';
        $parentContentVersionId = is_array($data) ? max(0, (int) ($data['parent_content_version_id'] ?? 0)) : 0;
        $resolvedContentVersionId = $contentVersionId;
        $sourceContentVersionId = (int) ($existing['content_version_id'] ?? $contentVersionId);
        $previousStatus = mb_trim((string) ($existing['status'] ?? ''));

        if ($requiresParentSelection) {
            if ($parentContentVersionId <= 0) {
                $this->session->setFlash('warning', 'Choose a replacement parent item before resolving this orphaned note.');

                return $response->withHeader('Location', $itemUrl)->withStatus(302);
            }

            try {
                $parentExists = (int) $this->connection->fetchOne(
                    'SELECT id FROM content_versions WHERE id = ? AND author_id = ?',
                    [$parentContentVersionId, $authorId],
                );
            } catch (Throwable) {
                $parentExists = 0;
            }

            if ($parentExists <= 0 || $parentContentVersionId === $contentVersionId) {
                $this->session->setFlash('warning', 'Choose a different authored item to use as the new parent.');

                return $response->withHeader('Location', $itemUrl)->withStatus(302);
            }

            $resolvedContentVersionId = $parentContentVersionId;
        }

        try {
            $this->connection->beginTransaction();

            $this->connection->executeStatement(
                'UPDATE reviews SET status = ?, updated_at = ?, resolved_at = ?, resolution_decision = ?, resolution_actor_id = ?, resolution_actor_role = ?, resolution_recorded_at = ? WHERE id = ? AND content_version_id = ?',
                [
                    $targetStatus,
                    $now,
                    $targetStatus === 'resolved' ? $now : null,
                    $resolutionDecision,
                    $authorId,
                    'author',
                    $now,
                    $reviewId,
                    $contentVersionId,
                ],
            );

            if ($resolvedContentVersionId !== $contentVersionId) {
                $this->connection->executeStatement(
                    'UPDATE reviews SET content_version_id = ? WHERE id = ? AND content_version_id = ?',
                    [$resolvedContentVersionId, $reviewId, $contentVersionId],
                );
            }

            $this->recordReviewResolutionEvent(
                reviewId: $reviewId,
                sourceContentVersionId: $sourceContentVersionId,
                targetContentVersionId: $resolvedContentVersionId,
                previousStatus: $previousStatus !== '' ? $previousStatus : null,
                targetStatus: $targetStatus,
                resolutionDecision: $resolutionDecision,
                actorId: $authorId,
                actorRole: 'author',
                recordedAt: $now,
            );

            $this->connection->commit();
        } catch (Throwable) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $this->session->setFlash('warning', 'That review note could not be updated.');

            return $response->withHeader('Location', $itemUrl)->withStatus(302);
        }

        if ($resolvedContentVersionId !== $contentVersionId) {
            $itemUrl = '/dashboard/author/' . $resolvedContentVersionId . ($returnQueueQuery !== '' ? '?' . $returnQueueQuery : '');
        }

        $this->session->setFlash(
            'success',
            match ($resolutionDecision) {
                'resolved' => $requiresParentSelection ? 'Review note reparented and marked as resolved.' : 'Review note marked as resolved.',
                'ignored' => 'Review note marked as ignored.',
                'still_relevant' => 'Review note marked as still relevant.',
                default => 'Review note marked as resolved.',
            },
        );

        return $response->withHeader('Location', $itemUrl)->withStatus(302);
    }

    public function deleteAuthorBook(Request $request, Response $response, array $args): Response
    {
        if (!$this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = $this->session->getUser();
        $roles = $this->getUserRoles($user);
        if (!$this->canAuthor($roles)) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $data = (array) ($request->getParsedBody() ?? []);
        if (!is_array($data) || !$this->validateReviewSubmissionCsrf($data)) {
            $this->session->setFlash('error', 'Delete request could not be verified. Please try again.');

            return $response->withHeader('Location', '/dashboard/author')->withStatus(302);
        }

        $contentVersionId = max(0, (int) ($args['id'] ?? 0));
        $confirmed = mb_strtolower(mb_trim((string) ($data['confirm_delete'] ?? ''))) === 'yes';
        if ($contentVersionId <= 0 || !$confirmed) {
            $this->session->setFlash('warning', 'Please confirm the deletion before continuing.');

            return $response->withHeader('Location', '/dashboard/author')->withStatus(302);
        }

        $authorId = (int) ($user['id'] ?? 0);
        $this->connection->executeStatement(
            'DELETE FROM content_versions WHERE id = ? AND author_id = ?',
            [$contentVersionId, $authorId],
        );
        $this->session->setFlash('success', 'Book deleted.');

        return $response->withHeader('Location', '/dashboard/author')->withStatus(302);
    }

    public function uploadAuthorBookPackage(Request $request, Response $response): Response
    {
        if (!$this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = $this->session->getUser();
        $roles = $this->getUserRoles($user);
        if (!$this->canAuthor($roles)) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $data = (array) ($request->getParsedBody() ?? []);
        if (!$this->validateReviewSubmissionCsrf($data)) {
            $this->session->setFlash('error', 'Invalid form submission. Please try again.');

            return $response->withHeader('Location', '/dashboard/author')->withStatus(302);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $packageFile = $uploadedFiles['book_package'] ?? null;
        if (!$packageFile instanceof UploadedFileInterface || $packageFile->getError() !== UPLOAD_ERR_OK) {
            $this->session->setFlash('error', 'Select a valid book package ZIP before uploading.');

            return $response->withHeader('Location', '/dashboard/author')->withStatus(302);
        }

        $tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparkinsight-book-upload-' . bin2hex(random_bytes(8));
        $tempPackagePath = $tempDirectory . DIRECTORY_SEPARATOR . 'uploaded-book-package.zip';

        try {
            if (!mkdir($tempDirectory, 0700, true) && !is_dir($tempDirectory)) {
                throw new \RuntimeException('Could not create a temporary upload directory.');
            }

            $packageFile->moveTo($tempPackagePath);
            $importService = $this->bookPackageImportService ?? new BookPackageImportService($this->connection);
            $result = $importService->importFromPackage($tempPackagePath, (int) ($user['id'] ?? 0));

            $this->session->setFlash('success', sprintf('Book package uploaded and imported (%s).', (string) ($result['book_title'] ?: 'book')));
        } catch (Throwable $e) {
            $this->session->setFlash('error', 'Book package upload failed: ' . $e->getMessage());
        } finally {
            if (is_dir($tempDirectory)) {
                @unlink($tempPackagePath);
                @rmdir($tempDirectory);
            }
        }

        return $response->withHeader('Location', '/dashboard/author')->withStatus(302);
    }

    public function exportAuthorPdf(Request $request, Response $response): Response
    {
        if (!$this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = $this->session->getUser();
        $roles = $this->getUserRoles($user);

        if ($this->isPureAdmin($roles)) {
            return $response->withHeader('Location', '/dashboard/admin')->withStatus(302);
        }

        if (!$this->canAuthor($roles)) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!is_array($data) || !$this->validateReviewSubmissionCsrf($data)) {
            $this->session->setFlash('error', 'Export request could not be verified. Please try again.');

            return $response->withHeader('Location', '/dashboard/author')->withStatus(302);
        }

        $singleItemId = max(0, (int) ($data['single_item_id'] ?? 0));
        $selectedIds = $singleItemId > 0
            ? [$singleItemId]
            : $this->normalizeAuthorExportIds($data['item_ids'] ?? []);

        if ($selectedIds === []) {
            $this->session->setFlash('warning', 'Please select at least one item to export.');

            return $response->withHeader('Location', '/dashboard/author')->withStatus(302);
        }

        $password = mb_trim((string) ($data['export_password'] ?? ''));
        if ($password !== '' && mb_strlen($password) < 12) {
            $this->session->setFlash('error', 'Password-protected export requires a password with at least 12 characters.');

            return $response->withHeader('Location', '/dashboard/author')->withStatus(302);
        }

        $authorId = (int) ($user['id'] ?? 0);
        if ($authorId <= 0) {
            return $response->withHeader('Location', '/dashboard/author')->withStatus(302);
        }

        $items = $this->buildAuthorExportItems($authorId, $selectedIds);
        if ($items === []) {
            $this->session->setFlash('warning', 'No exportable author items were found for the selected selection.');

            return $response->withHeader('Location', '/dashboard/author')->withStatus(302);
        }

        $pdfExportService = $this->authorPdfExportService ?? new AuthorPdfExportService();

        try {
            $rawPdf = $pdfExportService->generate($items, 'standard', $password !== '' ? $password : null);
        } catch (InvalidArgumentException $exception) {
            $this->session->setFlash('error', $exception->getMessage());

            return $response->withHeader('Location', '/dashboard/author')->withStatus(302);
        } catch (Throwable $exception) {
            $message = mb_trim($exception->getMessage());
            $this->session->setFlash('error', 'PDF export failed: ' . ($message !== '' ? $message : 'unexpected error'));

            return $response->withHeader('Location', '/dashboard/author')->withStatus(302);
        }

        $response->getBody()->write($rawPdf);

        $filename = $this->buildAuthorExportFilename($items);
        $this->recordAuthorExportEvent(
            userId: $authorId,
            itemIds: array_values(array_map(static fn (array $item): int => (int) ($item['id'] ?? 0), $items)),
            profile: 'standard',
            passwordProtected: $password !== '',
            ipAddress: $this->extractClientIp($request),
            userAgent: mb_trim((string) ($request->getHeaderLine('User-Agent') ?: '')),
        );

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) mb_strlen($rawPdf))
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'");
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
        $authorQueueFilters = $isAuthorView ? ($extra['author_queue_filters'] ?? $this->normalizeAuthorQueueFilters([])) : [];
        if (!is_array($authorQueueFilters)) {
            $authorQueueFilters = $this->normalizeAuthorQueueFilters([]);
        }
        $authorQueueResult = $isAuthorView ? $this->buildAuthorQueue($user, $authorQueueFilters) : ['items' => [], 'pagination' => []];
        $authorQueue = is_array($authorQueueResult['items'] ?? null) ? $authorQueueResult['items'] : [];

        return $this->renderer->render($response, 'dashboard.php', [
            'title' => $isAuthorView ? 'Author' : 'Review',
            'dashboard_mode' => $mode,
            'user' => $user,
            'csrf_token' => $this->session->getCsrfToken(),
            'flash_message' => $flashMessage,
            'reviewer_metrics' => $isReviewerView ? $this->buildReviewerMetrics() : [],
            'reviewer_queue' => $reviewerQueue,
            'reviewer_queue_context' => $isReviewerView ? $this->buildReviewerQueueContext($reviewerQueue) : [],
            'reviewer_queue_filters' => $reviewerQueueFilters,
            'reviewer_queue_pagination' => $isReviewerView ? (array) ($reviewerQueueResult['pagination'] ?? []) : [],
            'reviewer_other_items_by_state' => $reviewerOtherItemsByState,
            'author_metrics' => $isAuthorView ? $this->buildAuthorMetrics($authorQueue) : [],
            'author_queue_filters' => $authorQueueFilters,
            'author_queue_pagination' => $isAuthorView ? (array) ($authorQueueResult['pagination'] ?? []) : [],
            'author_queue_context' => $isAuthorView ? $this->buildAuthorQueueContext($authorQueue) : [],
            'author_queue' => $authorQueue,
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
        } catch (Throwable) {
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
                [$reviewerId],
            )->fetchAllAssociative();
        } catch (Throwable) {
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
            $source = mb_trim((string) ($row['source'] ?? ''));
            $kind = (string) ($scrivenerMeta['kind'] ?? 'text');
            $isDirectory = $kind === 'directory';
            $resolvedSource = $source !== '' ? $this->resolveReadableSourcePath($source) : null;
            $displayTitle = $listPath !== '' ? (string) basename(str_replace('\\', '/', $listPath)) : (string) $row['title'];
            $depth = $listPath !== '' ? max(0, count(array_filter(explode('/', $listPath), static fn (string $part): bool => $part !== '')) - 1) : 0;

            $queue[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'display_title' => $displayTitle,
                'book_title' => mb_trim((string) ($row['book_title'] ?? '')),
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
        $priority = mb_strtolower(mb_trim((string) ($queryParams['priority'] ?? 'all')));
        $allowedPriorities = ['all', 'lowest', 'low', 'normal', 'high', 'highest'];
        if (!in_array($priority, $allowedPriorities, true)) {
            $priority = 'all';
        }

        $state = mb_strtolower(mb_trim((string) ($queryParams['state'] ?? 'all')));
        $allowedStates = ['all', 'in_review', 'needs_author_reply', 'resolved', 'ready_for_review', 'placeholder'];
        if (!in_array($state, $allowedStates, true)) {
            $state = 'all';
        }

        $sort = mb_strtolower(mb_trim((string) ($queryParams['sort'] ?? 'binder_asc')));
        $allowedSorts = ['binder_asc', 'due_asc', 'due_desc', 'chapter_asc', 'chapter_desc', 'imported_desc'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'binder_asc';
        }

        $search = mb_trim((string) ($queryParams['q'] ?? ''));
        if (mb_strlen($search) > 120) {
            $search = mb_substr($search, 0, 120);
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
        $queryString = mb_trim($queryString);
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
        $search = mb_strtolower(mb_trim((string) ($filters['q'] ?? '')));
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

            $haystack = mb_strtolower(
                implode(' ', [
                    (string) ($item['title'] ?? ''),
                    (string) ($item['book_title'] ?? ''),
                    (string) ($item['author'] ?? ''),
                    (string) ($item['source'] ?? ''),
                ]),
            );

            return str_contains($haystack, $search);
        }));
    }

    private function sortReviewerQueueItems(array $queue, string $sort): array
    {
        $sorted = array_values($queue);

        usort($sorted, function (array $a, array $b) use ($sort): int {
            if ($sort === 'binder_asc') {
                $leftOrder = mb_trim((string) ($a['order_path'] ?? ''));
                $rightOrder = mb_trim((string) ($b['order_path'] ?? ''));

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

                $leftPath = mb_trim((string) ($a['list_path'] ?? ''));
                $rightPath = mb_trim((string) ($b['list_path'] ?? ''));
                if ($leftPath !== '' && $rightPath !== '' && $leftPath !== $rightPath) {
                    return strnatcasecmp($leftPath, $rightPath);
                }

                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            }

            if ($sort === 'chapter_asc' || $sort === 'chapter_desc') {
                $left = mb_trim((string) (($a['display_title'] ?? '') !== '' ? $a['display_title'] : ($a['title'] ?? '')));
                $right = mb_trim((string) (($b['display_title'] ?? '') !== '' ? $b['display_title'] : ($b['title'] ?? '')));
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
        if ($value === '' || mb_strtolower($value) === 'no due date') {
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

        if (!is_string($rawMetadata) || mb_trim($rawMetadata) === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawMetadata, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
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

            $listPath = mb_trim((string) ($item['list_path'] ?? ''));
            if ($listPath !== '') {
                $seenDirectories[$listPath] = true;
            }
        }

        foreach ($queue as $item) {
            $listPath = mb_trim((string) ($item['list_path'] ?? ''));
            $orderPath = mb_trim((string) ($item['order_path'] ?? ''));
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
                $listPath = mb_trim((string) ($item['list_path'] ?? ''));
                if ($listPath !== '') {
                    $directoryPaths[] = $listPath;
                }
            }
        }

        foreach ($structured as $index => $item) {
            if (empty($item['is_directory'])) {
                continue;
            }

            $listPath = mb_trim((string) ($item['list_path'] ?? ''));
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
                        $candidatePath = mb_trim((string) ($candidate['list_path'] ?? ''));
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
            $listPath = mb_trim((string) ($item['list_path'] ?? ''));
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
            $listPath = mb_trim((string) ($item['list_path'] ?? ''));
            if ($listPath !== '') {
                $normalizedPath = str_replace('\\', '/', $listPath);
                $parts = array_values(array_filter(explode('/', $normalizedPath), static fn (string $part): bool => $part !== ''));
                $parentPath = count($parts) > 1 ? implode('/', array_slice($parts, 0, -1)) : '';
                $structured[$index]['parent_path'] = $parentPath;
            } else {
                $structured[$index]['parent_path'] = '';
            }

            if (!empty($item['is_directory'])) {
                $pathKey = mb_trim((string) ($item['list_path'] ?? ''));
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
                [$reviewerId],
            )->fetchAllAssociative();
        } catch (Throwable) {
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
                'book_title' => mb_trim((string) ($row['book_title'] ?? '')),
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
        $bookTitle = mb_trim((string) ($first['book_title'] ?? ''));

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
                [$reviewerId, $contentVersionId],
            )->fetchAssociative();

            if (!is_array($item) || $item === []) {
                return null;
            }

            $history = $this->fetchReviewHistory($contentVersionId);
        } catch (Throwable) {
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
            $reviewCount,
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
                'details' => mb_trim((string) ($row['details'] ?? '')),
                'selected_excerpt' => mb_trim((string) ($row['selected_excerpt'] ?? '')),
                'anchor_start_offset' => array_key_exists('anchor_start_offset', $row) && $row['anchor_start_offset'] !== null ? max(0, (int) $row['anchor_start_offset']) : null,
                'anchor_end_offset' => array_key_exists('anchor_end_offset', $row) && $row['anchor_end_offset'] !== null ? max(0, (int) $row['anchor_end_offset']) : null,
                'anchor_container_path' => array_key_exists('anchor_container_path', $row) && $row['anchor_container_path'] !== null ? mb_trim((string) $row['anchor_container_path']) : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'resolved_at' => (string) ($row['resolved_at'] ?? ''),
                'resolution_decision' => self::normalizeResolutionDecisionValue($row['resolution_decision'] ?? null),
                'resolution_decision_label' => self::labelForResolutionDecision(self::normalizeResolutionDecisionValue($row['resolution_decision'] ?? null)),
                'resolution_actor_id' => array_key_exists('resolution_actor_id', $row) && $row['resolution_actor_id'] !== null ? (int) $row['resolution_actor_id'] : null,
                'resolution_actor_role' => mb_trim((string) ($row['resolution_actor_role'] ?? '')),
                'resolution_actor_name' => mb_trim((string) ($row['resolution_actor_name'] ?? '')),
                'resolution_recorded_at' => (string) ($row['resolution_recorded_at'] ?? ''),
            ];
        }, $history);

        return [
            'id' => (int) $item['id'],
            'title' => (string) $item['title'],
            'book_title' => mb_trim((string) ($item['book_title'] ?? '')),
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
        return (new ReaderPresentationBuilder(dirname(__DIR__, 2)))->buildPresentation(
            $contentText,
            $contentRtf,
            $source,
            $rawMetadata,
        );
    }

    private function fetchReviewHistory(int $contentVersionId): array
    {
        try {
            return $this->connection->executeQuery(
                'SELECT
                    r.id,
                    r.reviewer_id,
                    r.title,
                    r.status,
                    r.details,
                    r.selected_excerpt,
                    r.anchor_start_offset,
                    r.anchor_end_offset,
                    r.anchor_container_path,
                    r.anchor_remap_state,
                    r.anchor_remap_confidence,
                    r.anchor_remap_reason,
                    r.anchor_remapped_from_review_id,
                    r.anchor_remapped_from_content_version_id,
                    r.created_at,
                    r.updated_at,
                    r.resolved_at,
                    r.resolution_decision,
                    r.resolution_actor_id,
                    r.resolution_actor_role,
                    r.resolution_recorded_at,
                    u.name AS reviewer_name,
                    resolver.name AS resolution_actor_name,
                    source_version.title AS anchor_remapped_from_content_version_title,
                    source_version.version_label AS anchor_remapped_from_content_version_label,
                    source_review.title AS anchor_remapped_from_review_title
                FROM reviews r
                LEFT JOIN users u ON u.id = r.reviewer_id
                LEFT JOIN users resolver ON resolver.id = r.resolution_actor_id
                LEFT JOIN content_versions source_version ON source_version.id = r.anchor_remapped_from_content_version_id
                LEFT JOIN reviews source_review ON source_review.id = r.anchor_remapped_from_review_id
                WHERE r.content_version_id = ?
                ORDER BY r.created_at DESC, r.id DESC',
                [$contentVersionId],
            )->fetchAllAssociative();
        } catch (Throwable) {
            return $this->connection->executeQuery(
                'SELECT
                    r.id,
                    r.reviewer_id,
                    r.title,
                    r.status,
                    r.details,
                    r.selected_excerpt,
                    r.anchor_start_offset,
                    r.anchor_end_offset,
                    r.anchor_container_path,
                    NULL AS anchor_remap_state,
                    NULL AS anchor_remap_confidence,
                    NULL AS anchor_remap_reason,
                    NULL AS anchor_remapped_from_review_id,
                    NULL AS anchor_remapped_from_content_version_id,
                    r.created_at,
                    r.updated_at,
                    r.resolved_at,
                    r.resolution_decision,
                    r.resolution_actor_id,
                    r.resolution_actor_role,
                    r.resolution_recorded_at,
                    u.name AS reviewer_name,
                    resolver.name AS resolution_actor_name,
                    NULL AS anchor_remapped_from_content_version_title,
                    NULL AS anchor_remapped_from_content_version_label,
                    NULL AS anchor_remapped_from_review_title
                FROM reviews r
                LEFT JOIN users u ON u.id = r.reviewer_id
                LEFT JOIN users resolver ON resolver.id = r.resolution_actor_id
                WHERE r.content_version_id = ?
                ORDER BY r.created_at DESC, r.id DESC',
                [$contentVersionId],
            )->fetchAllAssociative();
        }
    }

    private function normalizeReaderViewMode(string $mode): string
    {
        $mode = mb_trim($mode);

        return in_array($mode, ['fullscreen', 'compact-hidden', 'compact-visible'], true) ? $mode : 'compact-hidden';
    }

    private function resolveReadableSourcePath(string $source): ?string
    {
        $normalized = mb_trim(str_replace('\\', '/', $source));
        $normalized = mb_ltrim($normalized, '/');

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

    private static function labelForResolutionDecision(?string $decision): string
    {
        return match ($decision) {
            'ignored' => 'Ignored',
            'still_relevant' => 'Still relevant',
            'resolved' => 'Resolved',
            default => '',
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

    private static function authorFeedbackStatusForReview(string $status, ?string $resolutionDecision): array
    {
        if ($status === 'needs_author_review') {
            return ['Action requested', 'info', 'Reviewer is waiting for your response.'];
        }

        if ($status === 'open') {
            return ['Informational', 'neutral', 'Feedback shared for awareness.'];
        }

        return match ($resolutionDecision) {
            'ignored' => ['Ignored', 'warning', 'You marked this feedback as ignored.'],
            'still_relevant' => ['Reopened', 'info', 'Reviewer marked this feedback as still relevant.'],
            'resolved' => ['Resolved', 'success', 'You marked this feedback as resolved.'],
            default => ['Closed', 'success', 'This feedback is currently closed.'],
        };
    }

    private function normalizeReviewStatus(string $status): string
    {
        return in_array($status, self::REVIEW_STATUSES, true) ? $status : 'open';
    }

    private function normalizeReviewResolutionDecision(string $action): string
    {
        return match ($action) {
            'ignored', 'ignore' => 'ignored',
            'still_relevant', 'reopen' => 'still_relevant',
            default => 'resolved',
        };
    }

    private static function normalizeResolutionDecisionValue(mixed $decision): ?string
    {
        $value = mb_strtolower(mb_trim((string) $decision));

        return in_array($value, self::REVIEW_RESOLUTION_DECISIONS, true) ? $value : null;
    }

    private function recordReviewResolutionEvent(
        int $reviewId,
        int $sourceContentVersionId,
        int $targetContentVersionId,
        ?string $previousStatus,
        string $targetStatus,
        string $resolutionDecision,
        int $actorId,
        string $actorRole,
        string $recordedAt,
    ): void {
        $this->connection->executeStatement(
            'INSERT INTO review_resolution_events (review_id, source_content_version_id, target_content_version_id, previous_status, target_status, resolution_decision, actor_id, actor_role, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $reviewId,
                $sourceContentVersionId,
                $targetContentVersionId,
                $previousStatus,
                $targetStatus,
                $resolutionDecision,
                $actorId,
                $actorRole,
                $recordedAt,
            ],
        );
    }

    private function normalizeRequiresAction(array $data): bool
    {
        if (array_key_exists('requires_action', $data)) {
            $value = $data['requires_action'];
            if (is_bool($value)) {
                return $value;
            }

            return !in_array(mb_strtolower(mb_trim((string) $value)), ['0', 'false', 'off', 'no', ''], true);
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

        $raw = mb_trim((string) $value);
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

        $path = mb_trim((string) $value);
        if ($path === '') {
            return null;
        }

        if (mb_strlen($path) > 255) {
            $path = mb_substr($path, 0, 255);
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
        $normalized = mb_trim(str_replace('_', ' ', $status));
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
        $csrfToken = mb_trim((string) ($data['_csrf'] ?? ''));

        return $this->session->validateCsrfToken($csrfToken);
    }

    private function buildAuthorMetrics(array $authorQueue): array
    {
        $draftsInProgress = 0;
        $waitingForReview = 0;
        $resolvedComments = 0;
        $needsRevision = 0;

        foreach ($authorQueue as $row) {
            if (!empty($row['is_directory'])) {
                continue;
            }

            $statusKey = (string) ($row['status_key'] ?? 'ready_for_review');
            $reviewCount = (int) ($row['review_count'] ?? 0);
            $resolvedReviewCount = (int) ($row['resolved_review_count'] ?? 0);

            if ($statusKey === 'placeholder') {
                $draftsInProgress++;
            }

            if ($reviewCount === 0) {
                $waitingForReview++;
            }

            if ($statusKey === 'needs_author_reply') {
                $needsRevision++;
            }

            $resolvedComments += $resolvedReviewCount;
        }

        return [
            ['label' => 'Drafts In Progress', 'value' => (string) $draftsInProgress, 'tone' => 'neutral'],
            ['label' => 'Waiting For Review', 'value' => (string) $waitingForReview, 'tone' => 'warning'],
            ['label' => 'Resolved Comments', 'value' => (string) $resolvedComments, 'tone' => 'success'],
            ['label' => 'Needs Revision', 'value' => (string) $needsRevision, 'tone' => 'info'],
        ];
    }

    private function normalizeAuthorQueueFilters(array $queryParams): array
    {
        $state = mb_strtolower(mb_trim((string) ($queryParams['state'] ?? 'all')));
        $allowedStates = ['all', 'in_review', 'needs_author_reply', 'resolved', 'ready_for_review', 'placeholder'];
        if (!in_array($state, $allowedStates, true)) {
            $state = 'all';
        }

        $sort = mb_strtolower(mb_trim((string) ($queryParams['sort'] ?? 'binder_asc')));
        $allowedSorts = ['binder_asc', 'chapter_asc', 'chapter_desc', 'imported_desc'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'binder_asc';
        }

        $search = mb_trim((string) ($queryParams['q'] ?? ''));
        if (mb_strlen($search) > 120) {
            $search = mb_substr($search, 0, 120);
        }

        $perPage = (int) ($queryParams['per_page'] ?? 25);
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
            'state' => $state,
            'sort' => $sort,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    private function buildAuthorQueueQueryString(array $filters): string
    {
        $normalized = $this->normalizeAuthorQueueFilters($filters);

        return (string) http_build_query([
            'q' => (string) ($normalized['q'] ?? ''),
            'state' => (string) ($normalized['state'] ?? 'all'),
            'sort' => (string) ($normalized['sort'] ?? 'binder_asc'),
            'page' => (int) ($normalized['page'] ?? 1),
            'per_page' => (int) ($normalized['per_page'] ?? 25),
        ]);
    }

    private function sanitizeAuthorQueueQueryString(string $queryString): string
    {
        $queryString = mb_trim($queryString);
        if ($queryString === '') {
            return '';
        }

        parse_str($queryString, $parsed);
        if (!is_array($parsed)) {
            return '';
        }

        return $this->buildAuthorQueueQueryString($parsed);
    }

    private function applyAuthorQueueFilters(array $queue, array $filters): array
    {
        $search = mb_strtolower(mb_trim((string) ($filters['q'] ?? '')));
        $state = (string) ($filters['state'] ?? 'all');

        return array_values(array_filter($queue, static function (array $item) use ($search, $state): bool {
            if ($state !== 'all' && (string) ($item['status_key'] ?? '') !== $state) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            $haystack = mb_strtolower(
                implode(' ', [
                    (string) ($item['title'] ?? ''),
                    (string) ($item['book_title'] ?? ''),
                    (string) ($item['source'] ?? ''),
                    (string) ($item['list_path'] ?? ''),
                ]),
            );

            return str_contains($haystack, $search);
        }));
    }

    private function sortAuthorQueueItems(array $queue, string $sort): array
    {
        $sorted = array_values($queue);

        usort($sorted, static function (array $a, array $b) use ($sort): int {
            if ($sort === 'binder_asc') {
                $leftOrder = mb_trim((string) ($a['order_path'] ?? ''));
                $rightOrder = mb_trim((string) ($b['order_path'] ?? ''));

                if ($leftOrder !== '' && $rightOrder !== '' && $leftOrder !== $rightOrder) {
                    return strnatcasecmp($leftOrder, $rightOrder);
                }

                if ($leftOrder !== '' && $rightOrder === '') {
                    return -1;
                }

                if ($leftOrder === '' && $rightOrder !== '') {
                    return 1;
                }

                $leftPath = mb_trim((string) ($a['list_path'] ?? ''));
                $rightPath = mb_trim((string) ($b['list_path'] ?? ''));
                if ($leftPath !== '' && $rightPath !== '' && $leftPath !== $rightPath) {
                    return strnatcasecmp($leftPath, $rightPath);
                }

                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            }

            if ($sort === 'chapter_asc' || $sort === 'chapter_desc') {
                $left = mb_trim((string) (($a['display_title'] ?? '') !== '' ? $a['display_title'] : ($a['title'] ?? '')));
                $right = mb_trim((string) (($b['display_title'] ?? '') !== '' ? $b['display_title'] : ($b['title'] ?? '')));
                $compare = strnatcasecmp($left, $right);
                if ($compare === 0) {
                    $compare = ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
                }

                return $sort === 'chapter_desc' ? -$compare : $compare;
            }

            // imported_desc fallback
            $leftImported = (int) ($a['imported_sort_ts'] ?? 0);
            $rightImported = (int) ($b['imported_sort_ts'] ?? 0);
            if ($leftImported !== $rightImported) {
                return $rightImported <=> $leftImported;
            }

            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        });

        return $sorted;
    }

    private function buildAuthorQueueContext(array $queue): array
    {
        if ($queue === []) {
            return [
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

        $bookTitle = mb_trim((string) ($first['book_title'] ?? ''));

        return [
            'book_title' => $bookTitle !== '' ? $bookTitle : null,
        ];
    }

    private function buildAuthorQueue(?array $user, array $filters = []): array
    {
        $authorId = (int) ($user['id'] ?? 0);
        if ($authorId <= 0) {
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
                    cv.metadata,
                    cv.version_label,
                    cv.source,
                    cv.status AS content_status,
                    cv.imported_at,
                    COUNT(r.id) AS review_count,
                    COUNT(DISTINCT r.reviewer_id) AS reviewer_count,
                    SUM(CASE WHEN r.status = 'open' THEN 1 ELSE 0 END) AS open_review_count,
                    SUM(CASE WHEN r.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_review_count,
                    SUM(CASE WHEN r.status = 'needs_author_review' THEN 1 ELSE 0 END) AS needs_author_reply_count,
                    MAX(r.updated_at) AS last_review_at
                FROM content_versions cv
                LEFT JOIN reviews r ON r.content_version_id = cv.id
                WHERE cv.author_id = ?
                GROUP BY cv.id, cv.title, cv.book_title, cv.metadata, cv.version_label, cv.source, cv.status, cv.imported_at
                ORDER BY cv.imported_at DESC, cv.id DESC
                LIMIT 500",
                [$authorId],
            )->fetchAllAssociative();
        } catch (Throwable) {
            return [
                'items' => [],
                'pagination' => $this->buildEmptyQueuePagination($filters),
            ];
        }

        $queueQueryString = $this->buildAuthorQueueQueryString($filters);
        $queue = [];
        foreach ($rows as $row) {
            $openCount = (int) ($row['open_review_count'] ?? 0);
            $resolvedCount = (int) ($row['resolved_review_count'] ?? 0);
            $needsAuthorReplyCount = (int) ($row['needs_author_reply_count'] ?? 0);
            $reviewCount = (int) ($row['review_count'] ?? 0);

            [$statusLabel, $statusTone] = $this->deriveReviewState(
                (string) ($row['content_status'] ?? 'ready'),
                $openCount,
                $resolvedCount,
                $needsAuthorReplyCount,
                $reviewCount,
            );

            $statusKey = $this->reviewStateKeyForLabel($statusLabel);
            $updatedAtRaw = (string) ($row['last_review_at'] ?? '');
            if ($updatedAtRaw === '') {
                $updatedAtRaw = (string) ($row['imported_at'] ?? '');
            }
            $scrivenerMeta = $this->extractScrivenerQueueMetadata($row['metadata'] ?? null);
            $listPath = (string) ($scrivenerMeta['list_path'] ?? '');
            $orderPath = (string) ($scrivenerMeta['order_path'] ?? '');
            $kind = (string) ($scrivenerMeta['kind'] ?? 'text');
            $isDirectory = $kind === 'directory';
            $displayTitle = $listPath !== '' ? (string) basename(str_replace('\\', '/', $listPath)) : (string) ($row['title'] ?? 'Untitled');
            $depth = $listPath !== '' ? max(0, count(array_filter(explode('/', $listPath), static fn (string $part): bool => $part !== '')) - 1) : 0;
            $importedAtRaw = (string) ($row['imported_at'] ?? '');
            $importedSortTs = strtotime($importedAtRaw);

            $queue[] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? 'Untitled'),
                'display_title' => $displayTitle,
                'book_title' => mb_trim((string) ($row['book_title'] ?? '')),
                'version_label' => (string) ($row['version_label'] ?? 'n/a'),
                'source' => (string) ($row['source'] ?? ''),
                'status' => $statusLabel,
                'status_key' => $statusKey,
                'status_tone' => $statusTone,
                'reviewer_count' => max(0, (int) ($row['reviewer_count'] ?? 0)),
                'review_count' => $reviewCount,
                'resolved_review_count' => $resolvedCount,
                'updated' => $updatedAtRaw !== '' ? date('Y-m-d H:i', strtotime($updatedAtRaw)) : 'Unknown',
                'next_step' => $this->authorNextStepForStatus($statusKey),
                'list_path' => $listPath,
                'order_path' => $orderPath,
                'depth' => $depth,
                'is_directory' => $isDirectory,
                'is_openable' => !$isDirectory && (int) ($row['id'] ?? 0) > 0,
                'url' => !$isDirectory && (int) ($row['id'] ?? 0) > 0
                    ? '/dashboard/author/' . (int) ($row['id'] ?? 0) . ($queueQueryString !== '' ? '?' . $queueQueryString : '')
                    : null,
                'imported_sort_ts' => is_int($importedSortTs) ? $importedSortTs : 0,
            ];
        }

        $filteredQueue = $this->applyAuthorQueueFilters($queue, $filters);
        $filteredQueue = $this->injectQueueDirectoryStructure($filteredQueue);
        $sortedQueue = $this->sortAuthorQueueItems($filteredQueue, (string) ($filters['sort'] ?? 'binder_asc'));

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
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

    private function buildAuthorItem(int $authorId, int $contentVersionId): ?array
    {
        if ($authorId <= 0 || $contentVersionId <= 0) {
            return null;
        }

        try {
            $item = $this->connection->executeQuery(
                "SELECT
                    cv.id,
                    cv.title,
                    cv.book_title,
                    cv.version_label,
                    cv.source,
                    cv.content_rtf,
                    cv.content_text,
                    cv.metadata,
                    cv.imported_at,
                    cv.status AS content_status,
                    COUNT(r.id) AS review_count,
                    SUM(CASE WHEN r.status = 'open' THEN 1 ELSE 0 END) AS open_review_count,
                    SUM(CASE WHEN r.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_review_count,
                    SUM(CASE WHEN r.status = 'needs_author_review' THEN 1 ELSE 0 END) AS needs_author_reply_count
                FROM content_versions cv
                LEFT JOIN reviews r ON r.content_version_id = cv.id
                WHERE cv.author_id = ? AND cv.id = ?
                GROUP BY cv.id, cv.title, cv.book_title, cv.version_label, cv.source, cv.content_rtf, cv.content_text, cv.metadata, cv.imported_at, cv.status",
                [$authorId, $contentVersionId],
            )->fetchAssociative();

            if (!is_array($item) || $item === []) {
                return null;
            }

            $history = $this->connection->executeQuery(
                'SELECT
                    r.id,
                    r.reviewer_id,
                    r.title,
                    r.status,
                    r.details,
                    r.selected_excerpt,
                    r.anchor_start_offset,
                    r.anchor_end_offset,
                    r.anchor_container_path,
                    r.anchor_remap_state,
                    r.anchor_remap_confidence,
                    r.anchor_remap_reason,
                    r.anchor_remapped_from_review_id,
                    r.anchor_remapped_from_content_version_id,
                    r.created_at,
                    r.updated_at,
                    r.resolved_at,
                    r.resolution_decision,
                    r.resolution_actor_id,
                    r.resolution_actor_role,
                    r.resolution_recorded_at,
                    u.name AS reviewer_name,
                    resolver.name AS resolution_actor_name,
                    source_version.title AS anchor_remapped_from_content_version_title,
                    source_version.version_label AS anchor_remapped_from_content_version_label,
                    source_review.title AS anchor_remapped_from_review_title,
                    source_review.selected_excerpt AS anchor_remapped_from_selected_excerpt,
                    source_review.details AS anchor_remapped_from_details
                FROM reviews r
                INNER JOIN content_versions current_version ON current_version.id = r.content_version_id
                LEFT JOIN users u ON u.id = r.reviewer_id
                LEFT JOIN users resolver ON resolver.id = r.resolution_actor_id
                LEFT JOIN content_versions source_version ON source_version.id = r.anchor_remapped_from_content_version_id AND source_version.author_id = current_version.author_id
                LEFT JOIN reviews source_review ON source_review.id = r.anchor_remapped_from_review_id AND source_review.content_version_id = source_version.id
                WHERE r.content_version_id = ? AND current_version.author_id = ?
                ORDER BY r.created_at DESC, r.id DESC',
                [$contentVersionId, $authorId],
            )->fetchAllAssociative();
        } catch (Throwable) {
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
            $reviewCount,
        );

        $currentVersionTitle = mb_trim((string) ($item['title'] ?? ''));
        $currentVersionLabel = mb_trim((string) ($item['version_label'] ?? ''));

        $reviews = array_map(static function (array $row) use ($currentVersionTitle, $currentVersionLabel): array {
            $status = (string) ($row['status'] ?? 'open');
            $resolutionDecision = self::normalizeResolutionDecisionValue($row['resolution_decision'] ?? null);
            [$feedbackStatusLabel, $feedbackStatusTone, $feedbackStatusDetail] = self::authorFeedbackStatusForReview($status, $resolutionDecision);
            [$locationStatusLabel, $locationStatusTone, $locationStatusDetail, $locationFallbackContext, $locationNeedsParent] = self::authorLocationStatusForReview($row);
            [$originalTargetContext, $currentChangedContext] = self::authorResolutionContextsForReview($row);
            [$versionLinkageLabel, $versionLinkageDetail] = self::authorVersionLinkageForReview($row, $currentVersionTitle, $currentVersionLabel);

            return [
                'id' => (int) ($row['id'] ?? 0),
                'reviewer_id' => (int) ($row['reviewer_id'] ?? 0),
                'title' => (string) ($row['title'] ?? 'Review note'),
                'reviewer' => (string) ($row['reviewer_name'] ?? 'Anonymous reviewer'),
                'status' => $status,
                'status_label' => self::labelForReviewStatus($status),
                'status_tone' => self::toneForReviewStatus($status),
                'feedback_status_label' => $feedbackStatusLabel,
                'feedback_status_tone' => $feedbackStatusTone,
                'feedback_status_detail' => $feedbackStatusDetail,
                'location_status_label' => $locationStatusLabel,
                'location_status_tone' => $locationStatusTone,
                'location_status_detail' => $locationStatusDetail,
                'location_fallback_context' => $locationFallbackContext,
                'location_needs_parent' => $locationNeedsParent,
                'original_target_context' => $originalTargetContext,
                'current_changed_context' => $currentChangedContext,
                'version_linkage_label' => $versionLinkageLabel,
                'version_linkage_detail' => $versionLinkageDetail,
                'details' => mb_trim((string) ($row['details'] ?? '')),
                'selected_excerpt' => mb_trim((string) ($row['selected_excerpt'] ?? '')),
                'anchor_start_offset' => array_key_exists('anchor_start_offset', $row) && $row['anchor_start_offset'] !== null ? max(0, (int) $row['anchor_start_offset']) : null,
                'anchor_end_offset' => array_key_exists('anchor_end_offset', $row) && $row['anchor_end_offset'] !== null ? max(0, (int) $row['anchor_end_offset']) : null,
                'anchor_container_path' => array_key_exists('anchor_container_path', $row) && $row['anchor_container_path'] !== null ? mb_trim((string) $row['anchor_container_path']) : null,
                'anchor_remap_state' => mb_trim((string) ($row['anchor_remap_state'] ?? '')),
                'anchor_remap_confidence' => mb_trim((string) ($row['anchor_remap_confidence'] ?? '')),
                'anchor_remap_reason' => mb_trim((string) ($row['anchor_remap_reason'] ?? '')),
                'anchor_remapped_from_review_id' => array_key_exists('anchor_remapped_from_review_id', $row) && $row['anchor_remapped_from_review_id'] !== null ? (int) $row['anchor_remapped_from_review_id'] : null,
                'anchor_remapped_from_content_version_id' => array_key_exists('anchor_remapped_from_content_version_id', $row) && $row['anchor_remapped_from_content_version_id'] !== null ? (int) $row['anchor_remapped_from_content_version_id'] : null,
                'anchor_remapped_from_content_version_title' => mb_trim((string) ($row['anchor_remapped_from_content_version_title'] ?? '')),
                'anchor_remapped_from_content_version_label' => mb_trim((string) ($row['anchor_remapped_from_content_version_label'] ?? '')),
                'anchor_remapped_from_review_title' => mb_trim((string) ($row['anchor_remapped_from_review_title'] ?? '')),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'resolved_at' => (string) ($row['resolved_at'] ?? ''),
                'resolution_decision' => $resolutionDecision,
                'resolution_decision_label' => self::labelForResolutionDecision($resolutionDecision),
                'resolution_actor_id' => array_key_exists('resolution_actor_id', $row) && $row['resolution_actor_id'] !== null ? (int) $row['resolution_actor_id'] : null,
                'resolution_actor_role' => mb_trim((string) ($row['resolution_actor_role'] ?? '')),
                'resolution_actor_name' => mb_trim((string) ($row['resolution_actor_name'] ?? '')),
                'resolution_recorded_at' => (string) ($row['resolution_recorded_at'] ?? ''),
            ];
        }, $history);

        return [
            'id' => (int) $item['id'],
            'title' => (string) $item['title'],
            'book_title' => mb_trim((string) ($item['book_title'] ?? '')),
            'version_label' => (string) $item['version_label'],
            'source' => (string) ($item['source'] ?? ''),
            'imported_at' => (string) $item['imported_at'],
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

    /**
     * @return array{0:string,1:string,2:string,3:string,4:bool}
     */
    private static function authorLocationStatusForReview(array $row): array
    {
        $remapState = mb_strtolower(mb_trim((string) ($row['anchor_remap_state'] ?? '')));
        $remapReason = mb_strtolower(mb_trim((string) ($row['anchor_remap_reason'] ?? '')));
        $remapConfidence = mb_strtolower(mb_trim((string) ($row['anchor_remap_confidence'] ?? '')));
        $reviewTitle = mb_strtolower(mb_trim((string) ($row['title'] ?? '')));
        $selectedExcerpt = mb_trim((string) ($row['selected_excerpt'] ?? ''));
        $details = mb_trim((string) ($row['details'] ?? ''));
        $anchorStart = array_key_exists('anchor_start_offset', $row) && $row['anchor_start_offset'] !== null ? (int) $row['anchor_start_offset'] : null;
        $anchorEnd = array_key_exists('anchor_end_offset', $row) && $row['anchor_end_offset'] !== null ? (int) $row['anchor_end_offset'] : null;
        $anchorContainerPath = mb_trim((string) ($row['anchor_container_path'] ?? ''));
        $parentVersionTitle = mb_trim((string) ($row['anchor_remapped_from_content_version_title'] ?? ''));
        $parentVersionLabel = mb_trim((string) ($row['anchor_remapped_from_content_version_label'] ?? ''));
        $parentReviewTitle = mb_trim((string) ($row['anchor_remapped_from_review_title'] ?? ''));

        $fallbackContext = $selectedExcerpt !== ''
            ? $selectedExcerpt
            : ($details !== '' ? $details : 'No fallback context available.');

        $hasOrphanedHint = str_contains($reviewTitle, 'orphaned')
            || str_contains(mb_strtolower($details), 'no longer resolves')
            || str_contains(mb_strtolower($details), 'moved with the draft');

        if ($remapState === 'failed' || $remapReason === 'no_match_in_new_content' || $hasOrphanedHint) {
            $detail = 'Could not find a matching location in the current content. Reassign a parent item before resolving this note.';
            $parentContext = $parentVersionTitle !== ''
                ? mb_trim($parentVersionTitle . ($parentVersionLabel !== '' ? ' ' . $parentVersionLabel : ''))
                : $parentReviewTitle;

            if ($parentContext !== '') {
                $detail .= ' Original parent: ' . $parentContext . '.';
            }

            return ['Needs parent', 'warning', $detail, $fallbackContext, true];
        }

        if ($remapState === 'mapped') {
            $detail = $remapConfidence === 'high'
                ? 'Anchor remapped automatically from the previous version.'
                : 'Anchor remapped automatically, but the match confidence is lower than ideal.';

            return ['Auto-mapped', $remapConfidence === 'high' ? 'success' : 'info', $detail, $fallbackContext, false];
        }

        if ($selectedExcerpt !== '' || $anchorStart !== null || $anchorEnd !== null || $anchorContainerPath !== '') {
            return ['Anchored', 'success', 'Location is available in the current content.', $fallbackContext, false];
        }

        return ['Whole item note', 'neutral', 'No specific location was captured for this note.', $fallbackContext, false];
    }

    private function isUnresolvedReviewLocation(array $row): bool
    {
        return self::authorLocationStatusForReview($row)[4];
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function authorResolutionContextsForReview(array $row): array
    {
        $sourceExcerpt = mb_trim((string) ($row['anchor_remapped_from_selected_excerpt'] ?? ''));
        $sourceDetails = mb_trim((string) ($row['anchor_remapped_from_details'] ?? ''));
        $currentExcerpt = mb_trim((string) ($row['selected_excerpt'] ?? ''));
        $currentDetails = mb_trim((string) ($row['details'] ?? ''));
        $anchorStart = array_key_exists('anchor_start_offset', $row) && $row['anchor_start_offset'] !== null ? max(0, (int) $row['anchor_start_offset']) : null;
        $anchorEnd = array_key_exists('anchor_end_offset', $row) && $row['anchor_end_offset'] !== null ? max(0, (int) $row['anchor_end_offset']) : null;

        $originalTarget = $sourceExcerpt !== ''
            ? $sourceExcerpt
            : ($sourceDetails !== '' ? $sourceDetails : ($currentExcerpt !== '' ? $currentExcerpt : 'No original target context available.'));

        if ($currentExcerpt !== '') {
            $currentChanged = $currentExcerpt;
        } elseif ($anchorStart !== null && $anchorEnd !== null) {
            $currentChanged = 'Offsets ' . $anchorStart . '-' . $anchorEnd;
        } elseif ($currentDetails !== '') {
            $currentChanged = $currentDetails;
        } else {
            $currentChanged = 'No current changed context available.';
        }

        return [$originalTarget, $currentChanged];
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function authorVersionLinkageForReview(array $row, string $currentVersionTitle, string $currentVersionLabel): array
    {
        $sourceVersionTitle = mb_trim((string) ($row['anchor_remapped_from_content_version_title'] ?? ''));
        $sourceVersionLabel = mb_trim((string) ($row['anchor_remapped_from_content_version_label'] ?? ''));

        $sourceVersion = $sourceVersionTitle !== ''
            ? $sourceVersionTitle . ($sourceVersionLabel !== '' ? ' (' . $sourceVersionLabel . ')' : '')
            : 'Current version only';

        $currentVersion = $currentVersionTitle !== ''
            ? $currentVersionTitle . ($currentVersionLabel !== '' ? ' (' . $currentVersionLabel . ')' : '')
            : 'Current version';

        if ($sourceVersionTitle !== '') {
            return ['Linked from prior version', $sourceVersion . ' -> ' . $currentVersion];
        }

        return ['No prior link', $currentVersion . ' (no earlier linked version recorded)'];
    }

    private function buildAuthorParentOptions(?array $user, int $excludeContentVersionId): array
    {
        $queue = $this->buildAuthorQueue($user, [
            'q' => '',
            'priority' => 'all',
            'state' => 'all',
            'sort' => 'binder_asc',
            'page' => 1,
            'per_page' => 100,
        ]);

        $items = is_array($queue['items'] ?? null) ? $queue['items'] : [];
        $options = [];

        foreach ($items as $item) {
            if (empty($item['is_openable'])) {
                continue;
            }

            $itemId = (int) ($item['id'] ?? 0);
            if ($itemId <= 0 || $itemId === $excludeContentVersionId) {
                continue;
            }

            $bookTitle = mb_trim((string) ($item['book_title'] ?? ''));
            $displayTitle = mb_trim((string) ($item['display_title'] ?? ($item['title'] ?? 'Untitled')));
            $versionLabel = mb_trim((string) ($item['version_label'] ?? ''));
            $labelParts = array_filter([
                $bookTitle !== '' ? $bookTitle : null,
                $displayTitle !== '' ? $displayTitle : null,
                $versionLabel !== '' ? '(' . $versionLabel . ')' : null,
            ], static fn (?string $value): bool => $value !== null && $value !== '');

            $options[] = [
                'id' => $itemId,
                'label' => implode(' · ', $labelParts),
                'status_label' => (string) ($item['status'] ?? ''),
                'status_tone' => (string) ($item['status_tone'] ?? 'neutral'),
            ];
        }

        return $options;
    }

    private function buildAuthorExportItems(int $authorId, array $selectedIds): array
    {
        if ($authorId <= 0 || $selectedIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $params = array_merge([$authorId], $selectedIds);

        try {
            $rows = $this->connection->executeQuery(
                "SELECT
                    cv.id,
                    cv.title,
                    cv.book_title,
                    cv.metadata,
                    cv.version_label,
                    cv.source,
                    cv.content_text,
                    cv.content_rtf,
                    cv.imported_at,
                    cv.status AS content_status,
                    COUNT(r.id) AS review_count,
                    SUM(CASE WHEN r.status = 'open' THEN 1 ELSE 0 END) AS open_review_count,
                    SUM(CASE WHEN r.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_review_count,
                    SUM(CASE WHEN r.status = 'needs_author_review' THEN 1 ELSE 0 END) AS needs_author_reply_count
                FROM content_versions cv
                LEFT JOIN reviews r ON r.content_version_id = cv.id
                WHERE cv.author_id = ? AND cv.id IN (" . $placeholders . ')
                GROUP BY cv.id, cv.title, cv.book_title, cv.metadata, cv.version_label, cv.source, cv.content_text, cv.content_rtf, cv.imported_at, cv.status
                ORDER BY cv.imported_at DESC, cv.id DESC',
                $params,
            )->fetchAllAssociative();
        } catch (Throwable) {
            return [];
        }

        $rowsById = [];
        foreach ($rows as $row) {
            $rowId = (int) ($row['id'] ?? 0);
            if ($rowId > 0) {
                $rowsById[$rowId] = $row;
            }
        }

        $items = [];
        foreach ($selectedIds as $selectedId) {
            $row = $rowsById[(int) $selectedId] ?? null;
            if (!is_array($row)) {
                continue;
            }

            $openCount = (int) ($row['open_review_count'] ?? 0);
            $resolvedCount = (int) ($row['resolved_review_count'] ?? 0);
            $needsAuthorReplyCount = (int) ($row['needs_author_reply_count'] ?? 0);
            $reviewCount = (int) ($row['review_count'] ?? 0);
            [$statusLabel] = $this->deriveReviewState(
                (string) ($row['content_status'] ?? 'ready'),
                $openCount,
                $resolvedCount,
                $needsAuthorReplyCount,
                $reviewCount,
            );

            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? 'Untitled'),
                'book_title' => mb_trim((string) ($row['book_title'] ?? '')),
                'metadata' => $row['metadata'] ?? null,
                'version_label' => (string) ($row['version_label'] ?? 'n/a'),
                'source' => (string) ($row['source'] ?? ''),
                'content_text' => (string) ($row['content_text'] ?? ''),
                'content_rtf' => (string) ($row['content_rtf'] ?? ''),
                'imported_at' => (string) ($row['imported_at'] ?? ''),
                'status_label' => $statusLabel,
            ];
        }

        return $items;
    }

    private function normalizeAuthorExportIds(mixed $rawIds): array
    {
        if (!is_array($rawIds)) {
            return [];
        }

        $ids = [];
        foreach ($rawIds as $rawId) {
            $id = max(0, (int) $rawId);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values(array_slice($ids, 0, 200));
    }

    private function authorNextStepForStatus(string $statusKey): string
    {
        return match ($statusKey) {
            'placeholder' => 'Complete content structure',
            'in_review' => 'Monitor active review',
            'needs_author_reply' => 'Address reviewer notes',
            'resolved' => 'Ready for export',
            default => 'Await first review',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function buildAuthorExportFilename(array $items): string
    {
        $timestamp = date('Ymd_His');
        $bookTitle = '';
        foreach ($items as $item) {
            $bookTitle = mb_trim((string) ($item['book_title'] ?? ''));
            if ($bookTitle !== '') {
                break;
            }
        }
        $bookSlug = $this->slugifyFilenameSegment($bookTitle, 'book');

        if (count($items) === 1) {
            $title = $this->slugifyFilenameSegment(mb_trim((string) ($items[0]['title'] ?? '')), 'item');

            return $bookSlug . '-' . $title . '-' . $timestamp . '.pdf';
        }

        return $bookSlug . '-excerpt-' . $timestamp . '.pdf';
    }

    private function slugifyFilenameSegment(string $value, string $fallback): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', mb_strtolower(mb_trim($value))) ?? '';
        $slug = mb_trim($slug, '-');

        return $slug !== '' ? $slug : $fallback;
    }

    /**
     * @param array<int, int> $itemIds
     */
    private function recordAuthorExportEvent(int $userId, array $itemIds, string $profile, bool $passwordProtected, string $ipAddress, string $userAgent): void
    {
        if ($userId <= 0 || $itemIds === []) {
            return;
        }

        try {
            $this->connection->executeStatement(
                'INSERT INTO export_events (user_id, content_version_ids_json, export_profile, pdf_standard, password_protected, download_ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    json_encode(array_values($itemIds), JSON_THROW_ON_ERROR),
                    $profile,
                    $passwordProtected ? 'PDF 1.7 (encrypted)' : 'PDF 1.7',
                    $passwordProtected ? 1 : 0,
                    $ipAddress !== '' ? $ipAddress : null,
                    $userAgent !== '' ? mb_substr($userAgent, 0, 512) : null,
                    (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (Throwable) {
            // Export should still succeed when audit persistence is unavailable.
        }
    }

    private function extractClientIp(Request $request): string
    {
        $headerIp = mb_trim((string) $request->getHeaderLine('X-Forwarded-For'));
        if ($headerIp !== '') {
            $first = mb_trim((string) explode(',', $headerIp)[0]);
            if ($first !== '') {
                return mb_substr($first, 0, 64);
            }
        }

        $serverParams = $request->getServerParams();
        $remoteAddr = mb_trim((string) ($serverParams['REMOTE_ADDR'] ?? ''));

        return $remoteAddr !== '' ? mb_substr($remoteAddr, 0, 64) : '';
    }
}
