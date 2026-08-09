<?php
$title ??= 'Review';
$reviewer_queue_filters = isset($reviewer_queue_filters) && is_array($reviewer_queue_filters) ? $reviewer_queue_filters : [];
$reviewer_queue_pagination = isset($reviewer_queue_pagination) && is_array($reviewer_queue_pagination) ? $reviewer_queue_pagination : [];
$author_queue_filters = isset($author_queue_filters) && is_array($author_queue_filters) ? $author_queue_filters : [];
$author_queue_pagination = isset($author_queue_pagination) && is_array($author_queue_pagination) ? $author_queue_pagination : [];
$author_queue_context = isset($author_queue_context) && is_array($author_queue_context) ? $author_queue_context : [];
$formatQueuePath = static function (string $path): string {
    $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $path)), static fn (string $segment): bool => mb_trim($segment) !== ''));

    return implode(' / ', $segments);
};
ob_start();
?>
<div class="hero-panel">
    <?php if (!empty($flash_message) && is_array($flash_message)) { ?>
        <div class="notice <?php echo htmlspecialchars($flash_message['type'] ?? 'info', ENT_QUOTES, 'UTF-8'); ?>">
            <strong><?php echo htmlspecialchars(ucfirst((string) ($flash_message['type'] ?? 'Notice')), ENT_QUOTES, 'UTF-8'); ?>:</strong>
            <span><?php echo htmlspecialchars((string) ($flash_message['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php } ?>

    <?php if (($dashboard_mode ?? 'review') === 'review') { ?>
        <?php if (!empty($review_item) && is_array($review_item)) { ?>
            <?php
                $draftStatus = (string) ($review_draft['status'] ?? 'open');
            $draftDetails = (string) ($review_draft['details'] ?? '');
            $reader = is_array($review_item['reader'] ?? null) ? $review_item['reader'] : [
                'available' => false,
                'sections' => [],
                'paragraph_count' => 0,
                'word_count' => 0,
                'source_label' => 'Unavailable',
                'notice' => 'The source content could not be loaded.',
            ];
            ?>
            <section class="card dashboard-section review-focus-panel">
                <div class="card-header review-focus-header">
                    <div>
                        <p class="eyebrow">Open review item</p>
                        <h2><?php echo htmlspecialchars((string) $review_item['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        <p><?php echo htmlspecialchars((string) $review_item['author_name'], ENT_QUOTES, 'UTF-8'); ?> · Imported <?php echo htmlspecialchars(date('Y-m-d', strtotime((string) $review_item['imported_at'])), ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <div class="review-focus-actions">
                        <span class="status-pill status-<?php echo htmlspecialchars((string) $review_item['status_tone'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $review_item['status_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <a class="button secondary" href="/dashboard/review">Back to queue</a>
                    </div>
                </div>

                <section class="card immersive-reader-shell" aria-label="Read-only content display">
                    <div class="card-header immersive-reader-header">
                        <div>
                            <h3>Reader view</h3>
                            <p>Text-first display for focused review, with quick jumps between sections.</p>
                        </div>
                        <div class="reader-source-meta">
                            <span><strong>Source:</strong> <?php echo htmlspecialchars((string) ($reader['source_label'] ?? 'Unavailable'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span><strong>Paragraphs:</strong> <?php echo (int) ($reader['paragraph_count'] ?? 0); ?></span>
                            <span><strong>Words:</strong> <?php echo (int) ($reader['word_count'] ?? 0); ?></span>
                        </div>
                    </div>

                    <?php if (!empty($reader['available']) && !empty($reader['sections']) && is_array($reader['sections'])) { ?>
                        <div class="immersive-reader-grid">
                            <aside class="reader-outline" aria-label="Section navigation">
                                <p class="eyebrow eyebrow-subtle">Sections</p>
                                <ol class="reader-outline-list">
                                    <?php foreach ($reader['sections'] as $index => $section) { ?>
                                        <li>
                                            <a href="#<?php echo htmlspecialchars((string) ($section['anchor'] ?? ('section-' . ($index + 1))), ENT_QUOTES, 'UTF-8'); ?>">
                                                <span class="reader-outline-index"><?php echo (int) $index + 1; ?></span>
                                                <span><?php echo htmlspecialchars((string) ($section['label'] ?? 'Section'), ENT_QUOTES, 'UTF-8'); ?></span>
                                            </a>
                                        </li>
                                    <?php } ?>
                                </ol>
                            </aside>

                            <article class="reader-surface" aria-label="Content text">
                                <?php foreach ($reader['sections'] as $index => $section) { ?>
                                    <section class="reader-section" id="<?php echo htmlspecialchars((string) ($section['anchor'] ?? ('section-' . ($index + 1))), ENT_QUOTES, 'UTF-8'); ?>">
                                        <header class="reader-section-header">
                                            <span class="reader-section-number">Section <?php echo (int) $index + 1; ?></span>
                                            <?php if (!empty($section['type'])) { ?>
                                                <span class="status-pill status-neutral"><?php echo htmlspecialchars((string) $section['type'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php } ?>
                                        </header>
                                        <p><?php echo nl2br(htmlspecialchars((string) ($section['text'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
                                    </section>
                                <?php } ?>
                            </article>
                        </div>
                    <?php } else { ?>
                        <p class="empty-state"><?php echo htmlspecialchars((string) ($reader['notice'] ?? 'No reader content available.'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php } ?>
                </section>

                <div class="review-focus-grid">
                    <section class="card review-summary-panel">
                        <div class="card-header">
                            <h3>Item snapshot</h3>
                            <p>Context details while reviewing the text.</p>
                        </div>

                        <dl class="details-list review-meta-list">
                            <dt>Submission</dt>
                            <dd><?php echo htmlspecialchars((string) $review_item['title'], ENT_QUOTES, 'UTF-8'); ?></dd>
                            <dt>Version</dt>
                            <dd><?php echo htmlspecialchars((string) $review_item['version_label'], ENT_QUOTES, 'UTF-8'); ?></dd>
                            <dt>Author</dt>
                            <dd><?php echo htmlspecialchars((string) $review_item['author_name'], ENT_QUOTES, 'UTF-8'); ?></dd>
                            <dt>Source</dt>
                            <dd><?php echo htmlspecialchars((string) ($review_item['source'] ?: 'Not provided'), ENT_QUOTES, 'UTF-8'); ?></dd>
                            <dt>State</dt>
                            <dd><?php echo htmlspecialchars((string) $review_item['status_label'], ENT_QUOTES, 'UTF-8'); ?></dd>
                        </dl>

                        <div class="review-summary-metrics">
                            <?php foreach (($review_item['summary'] ?? []) as $metric) { ?>
                                <article class="metric-card metric-<?php echo htmlspecialchars((string) $metric['tone'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <p><?php echo htmlspecialchars((string) $metric['label'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <strong><?php echo htmlspecialchars((string) $metric['value'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                </article>
                            <?php } ?>
                        </div>
                    </section>

                    <section class="card review-thread-panel">
                        <div class="card-header">
                            <h3>Leave a review note</h3>
                            <p>Capture observations without leaving the reader flow.</p>
                        </div>

                        <form class="review-note-form" method="post" action="/dashboard/review/<?php echo (int) $review_item['id']; ?>">
                            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                            <label>
                                <span>Status</span>
                                <select name="status">
                                    <option value="open" <?php echo $draftStatus === 'open' ? 'selected' : ''; ?>>Open</option>
                                    <option value="needs_author_review" <?php echo $draftStatus === 'needs_author_review' ? 'selected' : ''; ?>>Needs author reply</option>
                                    <option value="resolved" <?php echo $draftStatus === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                </select>
                            </label>

                            <label>
                                <span>Comment</span>
                                <textarea name="details" rows="7" placeholder="Describe what stands out, what should change, or what already looks solid."><?php echo htmlspecialchars($draftDetails, ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </label>

                            <div class="action-group">
                                <button class="button" type="submit">Save review update</button>
                            </div>
                        </form>
                    </section>
                </div>

                <section class="review-history-panel">
                    <div class="card-header">
                        <h3>Review history</h3>
                        <p>Recent notes and status changes for this submission.</p>
                    </div>

                    <div class="review-history-list">
                        <?php if (!empty($review_item['reviews'])) { ?>
                            <?php foreach (($review_item['reviews'] ?? []) as $review) { ?>
                                <article class="review-note-card">
                                    <div class="review-note-card-header">
                                        <div>
                                            <strong><?php echo htmlspecialchars((string) $review['reviewer'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <p><?php echo htmlspecialchars((string) $review['title'], ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <span class="status-pill status-<?php echo htmlspecialchars((string) $review['status_tone'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $review['status_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <dl class="details-list review-note-meta">
                                        <dt>Submitted</dt>
                                        <dd><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string) $review['created_at'])), ENT_QUOTES, 'UTF-8'); ?></dd>
                                        <dt>Decision</dt>
                                        <dd><?php echo !empty($review['resolution_decision_label']) ? htmlspecialchars((string) $review['resolution_decision_label'], ENT_QUOTES, 'UTF-8') : 'Pending'; ?></dd>
                                        <dt>Resolved</dt>
                                        <dd><?php echo !empty($review['resolved_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime((string) $review['resolved_at'])), ENT_QUOTES, 'UTF-8') : 'Not resolved yet'; ?></dd>
                                        <?php if (!empty($review['resolution_actor_name'])) { ?>
                                            <dt>Resolved by</dt>
                                            <dd><?php echo htmlspecialchars((string) $review['resolution_actor_name'], ENT_QUOTES, 'UTF-8'); ?></dd>
                                        <?php } ?>
                                    </dl>
                                    <p><?php echo htmlspecialchars($review['details'] !== '' ? (string) $review['details'] : 'No comment provided.', ENT_QUOTES, 'UTF-8'); ?></p>
                                </article>
                            <?php } ?>
                        <?php } else { ?>
                            <p class="empty-state">No comments yet. Be the first reviewer to leave guidance.</p>
                        <?php } ?>
                    </div>
                </section>
            </section>
        <?php } ?>

        <section class="dashboard-section">
            <div class="card-header">
                <div class="dashboard-title-row">
                    <h2>Review Dashboard</h2>
                    <button
                        type="button"
                        class="button small secondary dashboard-settings-toggle"
                        data-reviewer-settings-toggle
                        aria-expanded="false"
                        aria-controls="reviewer-dashboard-settings"
                        aria-label="Open review dashboard settings"
                    >
                        <span aria-hidden="true">⚙</span>
                    </button>
                </div>
                <p>Reviewer-only context for items assigned to you.</p>
            </div>

            <section class="reviewer-dashboard-settings" id="reviewer-dashboard-settings" data-reviewer-dashboard-settings hidden>
                <div class="reviewer-dashboard-settings-header">
                    <h3>Reader Settings</h3>
                    <button type="button" class="button small secondary" data-reviewer-settings-close>Close</button>
                </div>
                <div class="reviewer-dashboard-settings-grid">
                    <label>
                        <span>Default item mode on open</span>
                        <select data-reviewer-default-view>
                            <option value="compact-hidden">Pure Content</option>
                            <option value="compact-visible">Content + Panel</option>
                        </select>
                    </label>
                </div>
            </section>

            <div class="reviewer-metrics">
                <?php foreach (($reviewer_metrics ?? []) as $metric) { ?>
                    <article class="metric-card metric-<?php echo htmlspecialchars($metric['tone'], ENT_QUOTES, 'UTF-8'); ?>">
                        <p><?php echo htmlspecialchars($metric['label'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <strong><?php echo htmlspecialchars($metric['value'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    </article>
                <?php } ?>
            </div>
        </section>

        <section class="card dashboard-section">
            <div class="card-header">
                <h2>Ready For Review</h2>
                <p>Current set of assigned submissions for one book queue.</p>
            </div>

            <form class="queue-filters" method="get" action="/dashboard/review">
                <label class="queue-filter-search">
                    <span>Search</span>
                    <input type="text" name="q" value="<?php echo htmlspecialchars((string) (($reviewer_queue_filters['q'] ?? '') ?: ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Title, book, author...">
                </label>

                <label class="queue-filter-priority">
                    <span>Priority</span>
                    <select name="priority">
                        <?php $currentPriority = (string) ($reviewer_queue_filters['priority'] ?? 'all'); ?>
                        <option value="all" <?php echo $currentPriority === 'all' ? 'selected' : ''; ?>>All priorities</option>
                        <option value="highest" <?php echo $currentPriority === 'highest' ? 'selected' : ''; ?>>Highest</option>
                        <option value="high" <?php echo $currentPriority === 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="normal" <?php echo $currentPriority === 'normal' ? 'selected' : ''; ?>>Normal</option>
                        <option value="low" <?php echo $currentPriority === 'low' ? 'selected' : ''; ?>>Low</option>
                        <option value="lowest" <?php echo $currentPriority === 'lowest' ? 'selected' : ''; ?>>Lowest</option>
                    </select>
                </label>

                <label class="queue-filter-state">
                    <span>Review State</span>
                    <select name="state">
                        <?php $currentState = (string) ($reviewer_queue_filters['state'] ?? 'all'); ?>
                        <option value="all" <?php echo $currentState === 'all' ? 'selected' : ''; ?>>All states</option>
                        <option value="placeholder" <?php echo $currentState === 'placeholder' ? 'selected' : ''; ?>>Structure</option>
                        <option value="ready_for_review" <?php echo $currentState === 'ready_for_review' ? 'selected' : ''; ?>>Ready for review</option>
                        <option value="in_review" <?php echo $currentState === 'in_review' ? 'selected' : ''; ?>>In review</option>
                        <option value="needs_author_reply" <?php echo $currentState === 'needs_author_reply' ? 'selected' : ''; ?>>Needs author reply</option>
                        <option value="resolved" <?php echo $currentState === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                    </select>
                </label>

                <label class="queue-filter-sort">
                    <span>Sort</span>
                    <select name="sort">
                        <?php $currentSort = (string) ($reviewer_queue_filters['sort'] ?? 'binder_asc'); ?>
                        <option value="binder_asc" <?php echo $currentSort === 'binder_asc' ? 'selected' : ''; ?>>Binder order</option>
                        <option value="due_asc" <?php echo $currentSort === 'due_asc' ? 'selected' : ''; ?>>Due date (soonest)</option>
                        <option value="due_desc" <?php echo $currentSort === 'due_desc' ? 'selected' : ''; ?>>Due date (latest)</option>
                        <option value="chapter_asc" <?php echo $currentSort === 'chapter_asc' ? 'selected' : ''; ?>>Chapter title (A-Z)</option>
                        <option value="chapter_desc" <?php echo $currentSort === 'chapter_desc' ? 'selected' : ''; ?>>Chapter title (Z-A)</option>
                        <option value="imported_desc" <?php echo $currentSort === 'imported_desc' ? 'selected' : ''; ?>>Most recently imported</option>
                    </select>
                </label>

                <label class="queue-filter-per-page">
                    <span>Per page</span>
                    <select name="per_page">
                        <?php $currentPerPage = (int) ($reviewer_queue_filters['per_page'] ?? 10); ?>
                        <option value="10" <?php echo $currentPerPage === 10 ? 'selected' : ''; ?>>10</option>
                        <option value="25" <?php echo $currentPerPage === 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?php echo $currentPerPage === 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $currentPerPage === 100 ? 'selected' : ''; ?>>100</option>
                    </select>
                </label>

                <div class="queue-filter-actions">
                    <button type="submit" class="button small">Apply</button>
                    <a href="/dashboard/review" class="button small secondary">Reset</a>
                </div>
            </form>

            <div class="queue-context">
                <p>
                    <strong>Author:</strong>
                    <?php echo htmlspecialchars((string) (($reviewer_queue_context['author'] ?? null) ?? 'Not available'), ENT_QUOTES, 'UTF-8'); ?>
                    &nbsp;|&nbsp;
                    <strong>Book:</strong>
                    <?php echo htmlspecialchars((string) (($reviewer_queue_context['book_title'] ?? null) ?? 'Not set'), ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>

            <div class="queue-tree-tools" aria-label="Queue tree controls">
                <button type="button" class="button small secondary" data-tree-collapse-all data-tree-table-target="reviewer-ready-queue">Collapse all folders</button>
                <button type="button" class="button small secondary" data-tree-expand-all data-tree-table-target="reviewer-ready-queue">Expand all folders</button>
            </div>

            <div class="queue-table-wrap">
                <table class="queue-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Due</th>
                            <th>Priority</th>
                        </tr>
                    </thead>
                    <tbody data-tree-table="reviewer-ready-queue">
                        <?php if (!empty($reviewer_queue)) { ?>
                            <?php $reviewerQueueRows = array_values($reviewer_queue ?? []); ?>
                            <?php for ($index = 0, $reviewerQueueRowCount = count($reviewerQueueRows); $index < $reviewerQueueRowCount; $index++) { ?>
                                <?php
                                    $row = $reviewerQueueRows[$index];
                                $depth = max(0, (int) ($row['depth'] ?? 0));
                                $displayTitle = (string) (($row['display_title'] ?? '') !== '' ? $row['display_title'] : ($row['title'] ?? 'Untitled'));
                                $hasChildren = !empty($row['is_directory']) && !empty($row['has_children']);
                                $listPath = (string) ($row['list_path'] ?? '');
                                $parentPath = (string) ($row['parent_path'] ?? '');
                                $parentPathDisplay = $parentPath !== '' ? $formatQueuePath($parentPath) : '';
                                $pathDisplay = $listPath !== '' ? $formatQueuePath($listPath) : '';
                                $childFolderCount = max(0, (int) ($row['child_folder_count'] ?? 0));
                                $childItemCount = max(0, (int) ($row['child_item_count'] ?? 0));
                                ?>
                                <tr class="queue-row <?php echo !empty($row['is_directory']) ? 'queue-row-directory' : 'queue-row-content'; ?>" data-tree-row="true" data-kind="<?php echo htmlspecialchars(!empty($row['is_directory']) ? 'directory' : 'item', ENT_QUOTES, 'UTF-8'); ?>" data-depth="<?php echo $depth; ?>" data-list-path="<?php echo htmlspecialchars((string) ($row['list_path'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-collapsed="false">
                                    <td>
                                        <div class="queue-tree-label <?php echo !empty($row['is_directory']) ? 'queue-tree-directory' : 'queue-tree-item'; ?>" style="--queue-depth: <?php echo $depth; ?>;">
                                            <?php if (!empty($row['is_directory']) && $hasChildren) { ?>
                                                <button class="queue-tree-toggle" type="button" data-tree-toggle aria-expanded="true" aria-label="Collapse folder">
                                                    <span class="queue-tree-toggle-icon" data-tree-toggle-icon aria-hidden="true">▾</span>
                                                    <span class="queue-tree-toggle-text" aria-hidden="true">📁</span>
                                                </button>
                                            <?php } else { ?>
                                                <span class="queue-tree-glyph" aria-hidden="true"><?php echo !empty($row['is_directory']) ? '▸' : '•'; ?></span>
                                            <?php } ?>
                                            <?php if (!empty($row['is_openable']) && !empty($row['url'])) { ?>
                                                <a class="queue-item-link <?php echo !empty($row['is_directory']) ? 'queue-item-link--directory' : 'queue-item-link--content'; ?>" href="<?php echo htmlspecialchars((string) $row['url'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php echo htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8'); ?>
                                                </a>
                                            <?php } else { ?>
                                                <span class="queue-item-title-static <?php echo !empty($row['is_directory']) ? 'queue-item-title-directory' : 'queue-item-title-content'; ?>"><?php echo htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php } ?>
                                            <?php if (!empty($row['is_directory'])) { ?>
                                                <span class="queue-folder-count-badge" title="Direct children in this folder"><?php echo $childFolderCount; ?> folders · <?php echo $childItemCount; ?> items</span>
                                            <?php } ?>
                                        </div>
                                        <?php if (!empty($row['is_directory'])) { ?>
                                            <span class="queue-item-meta">Folder</span>
                                            <?php if ($pathDisplay !== '') { ?>
                                                <span class="queue-item-path">Path: <?php echo htmlspecialchars($pathDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <span class="queue-item-meta"><?php echo htmlspecialchars((string) $row['comments'], ENT_QUOTES, 'UTF-8'); ?> notes</span>
                                            <?php if ($parentPathDisplay !== '') { ?>
                                                <span class="queue-item-path">Parent: <?php echo htmlspecialchars($parentPathDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php } ?>
                                        <?php } ?>
                                    </td>
                                    <?php if (!empty($row['is_directory'])) { ?>
                                        <td colspan="3"></td>
                                    <?php } else { ?>
                                        <td><span class="status-pill status-<?php echo htmlspecialchars((string) $row['status_tone'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $row['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td><?php echo htmlspecialchars((string) $row['due'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><span class="priority priority-<?php echo htmlspecialchars((string) $row['priority'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst((string) $row['priority']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <?php } ?>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="4">No queue items match your current filter.</td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <?php
                $pagination = is_array($reviewer_queue_pagination ?? null) ? $reviewer_queue_pagination : [];
        $baseFilters = is_array($reviewer_queue_filters ?? null) ? $reviewer_queue_filters : [];
        $prevFilters = $baseFilters;
        $nextFilters = $baseFilters;
        $prevFilters['page'] = (int) (($pagination['prev_page'] ?? 1) ?: 1);
        $nextFilters['page'] = (int) (($pagination['next_page'] ?? 1) ?: 1);
        ?>
            <div class="queue-pagination">
                <p>
                    Showing page <?php echo (int) ($pagination['page'] ?? 1); ?> of <?php echo (int) ($pagination['total_pages'] ?? 1); ?>
                    (<?php echo (int) ($pagination['total'] ?? 0); ?> item(s))
                </p>
                <div class="queue-pagination-actions">
                    <?php if (!empty($pagination['has_prev'])) { ?>
                        <a class="button small secondary" href="/dashboard/review?<?php echo htmlspecialchars((string) http_build_query($prevFilters), ENT_QUOTES, 'UTF-8'); ?>">Previous</a>
                    <?php } else { ?>
                        <span class="button small secondary" aria-disabled="true">Previous</span>
                    <?php } ?>

                    <?php if (!empty($pagination['has_next'])) { ?>
                        <a class="button small secondary" href="/dashboard/review?<?php echo htmlspecialchars((string) http_build_query($nextFilters), ENT_QUOTES, 'UTF-8'); ?>">Next</a>
                    <?php } else { ?>
                        <span class="button small secondary" aria-disabled="true">Next</span>
                    <?php } ?>
                </div>
            </div>
        </section>

        <section class="card dashboard-section">
            <div class="card-header">
                <h2>Not Yet Ready</h2>
                <p>Assigned submissions that are not yet in the ready queue, grouped by content state.</p>
            </div>

            <?php if (!empty($reviewer_other_items_by_state) && is_array($reviewer_other_items_by_state)) { ?>
                <?php foreach ($reviewer_other_items_by_state as $group) { ?>
                    <div class="queue-context">
                        <p>
                            <strong>State:</strong>
                            <span class="status-pill status-<?php echo htmlspecialchars((string) ($group['status_tone'] ?? 'neutral'), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars((string) ($group['status_label'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            &nbsp;|&nbsp;
                            <strong>Items:</strong>
                            <?php echo count((array) ($group['items'] ?? [])); ?>
                        </p>
                    </div>

                    <div class="queue-tree-tools" aria-label="Queue tree controls">
                        <button type="button" class="button small secondary" data-tree-collapse-all data-tree-table-target="reviewer-state-<?php echo htmlspecialchars((string) ($group['status_key'] ?? 'unknown'), ENT_QUOTES, 'UTF-8'); ?>">Collapse all folders</button>
                        <button type="button" class="button small secondary" data-tree-expand-all data-tree-table-target="reviewer-state-<?php echo htmlspecialchars((string) ($group['status_key'] ?? 'unknown'), ENT_QUOTES, 'UTF-8'); ?>">Expand all folders</button>
                    </div>

                    <div class="queue-table-wrap">
                        <table class="queue-table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Review State</th>
                                    <th>Due</th>
                                    <th>Priority</th>
                                </tr>
                            </thead>
                            <tbody data-tree-table="reviewer-state-<?php echo htmlspecialchars((string) ($group['status_key'] ?? 'unknown'), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php $groupRows = array_values($group['items'] ?? []); ?>
                                <?php for ($index = 0, $groupRowCount = count($groupRows); $index < $groupRowCount; $index++) { ?>
                                    <?php
                                    $row = $groupRows[$index];
                                    $depth = max(0, (int) ($row['depth'] ?? 0));
                                    $displayTitle = (string) (($row['display_title'] ?? '') !== '' ? $row['display_title'] : ($row['title'] ?? 'Untitled'));
                                    $hasChildren = !empty($row['is_directory']) && !empty($row['has_children']);
                                    $listPath = (string) ($row['list_path'] ?? '');
                                    $parentPath = (string) ($row['parent_path'] ?? '');
                                    $parentPathDisplay = $parentPath !== '' ? $formatQueuePath($parentPath) : '';
                                    $pathDisplay = $listPath !== '' ? $formatQueuePath($listPath) : '';
                                    $childFolderCount = max(0, (int) ($row['child_folder_count'] ?? 0));
                                    $childItemCount = max(0, (int) ($row['child_item_count'] ?? 0));
                                    ?>
                                    <tr class="queue-row <?php echo !empty($row['is_directory']) ? 'queue-row-directory' : 'queue-row-content'; ?>" data-tree-row="true" data-kind="<?php echo htmlspecialchars(!empty($row['is_directory']) ? 'directory' : 'item', ENT_QUOTES, 'UTF-8'); ?>" data-depth="<?php echo $depth; ?>" data-list-path="<?php echo htmlspecialchars((string) ($row['list_path'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-collapsed="false">
                                        <td>
                                            <div class="queue-tree-label <?php echo !empty($row['is_directory']) ? 'queue-tree-directory' : 'queue-tree-item'; ?>" style="--queue-depth: <?php echo $depth; ?>;">
                                                <?php if (!empty($row['is_directory']) && $hasChildren) { ?>
                                                    <button class="queue-tree-toggle" type="button" data-tree-toggle aria-expanded="true" aria-label="Collapse folder">
                                                        <span class="queue-tree-toggle-icon" data-tree-toggle-icon aria-hidden="true">▾</span>
                                                        <span class="queue-tree-toggle-text" aria-hidden="true">📁</span>
                                                    </button>
                                                <?php } else { ?>
                                                    <span class="queue-tree-glyph" aria-hidden="true"><?php echo !empty($row['is_directory']) ? '▸' : '•'; ?></span>
                                                <?php } ?>
                                                <?php if (!empty($row['is_openable']) && !empty($row['url'])) { ?>
                                                    <a class="queue-item-link <?php echo !empty($row['is_directory']) ? 'queue-item-link--directory' : 'queue-item-link--content'; ?>" href="<?php echo htmlspecialchars((string) $row['url'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <?php echo htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8'); ?>
                                                    </a>
                                                <?php } else { ?>
                                                    <span class="queue-item-title-static <?php echo !empty($row['is_directory']) ? 'queue-item-title-directory' : 'queue-item-title-content'; ?>"><?php echo htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php } ?>
                                                <?php if (!empty($row['is_directory'])) { ?>
                                                    <span class="queue-folder-count-badge" title="Direct children in this folder"><?php echo $childFolderCount; ?> folders · <?php echo $childItemCount; ?> items</span>
                                                <?php } ?>
                                            </div>
                                            <?php if (!empty($row['is_directory'])) { ?>
                                                <span class="queue-item-meta">Folder</span>
                                                <?php if ($pathDisplay !== '') { ?>
                                                    <span class="queue-item-path">Path: <?php echo htmlspecialchars($pathDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php } ?>
                                            <?php } else { ?>
                                                <span class="queue-item-meta"><?php echo htmlspecialchars((string) $row['comments'], ENT_QUOTES, 'UTF-8'); ?> notes</span>
                                                <?php if ($parentPathDisplay !== '') { ?>
                                                    <span class="queue-item-path">Parent: <?php echo htmlspecialchars($parentPathDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php } ?>
                                            <?php } ?>
                                        </td>
                                        <?php if (!empty($row['is_directory'])) { ?>
                                            <td colspan="3"></td>
                                        <?php } else { ?>
                                            <td><span class="status-pill status-<?php echo htmlspecialchars((string) $row['status_tone'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $row['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <td><?php echo htmlspecialchars((string) $row['due'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><span class="priority priority-<?php echo htmlspecialchars((string) $row['priority'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst((string) $row['priority']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <?php } ?>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>
            <?php } else { ?>
                <p class="empty-state">No additional assigned items outside the ready queue.</p>
            <?php } ?>
        </section>

        <section class="card dashboard-section">
            <div class="card-header">
                <h2>Reviewer Actions</h2>
                <p>Prototype action points for final reviewer workflow implementation.</p>
            </div>

            <ul class="action-checklist">
                <li>Open a submission directly from the queue and keep the surrounding list in view</li>
                <li>Use a quick status selector to mark work as open, needs reply, or resolved</li>
                <li>Leave short context notes so the next reviewer or author knows what changed</li>
                <li>Track review history on the item page without losing your place in the queue</li>
            </ul>
        </section>
    <?php } else { ?>
        <section class="dashboard-section">
            <div class="card-header">
                <h2>Review Dashboard</h2>
                <p>Author-only context focused on writing progress and reviewer feedback.</p>
            </div>

            <div class="reviewer-metrics">
                <?php foreach (($author_metrics ?? []) as $metric) { ?>
                    <article class="metric-card metric-<?php echo htmlspecialchars($metric['tone'], ENT_QUOTES, 'UTF-8'); ?>">
                        <p><?php echo htmlspecialchars($metric['label'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <strong><?php echo htmlspecialchars($metric['value'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    </article>
                <?php } ?>
            </div>
        </section>

        <section class="card dashboard-section">
            <div class="card-header">
                <h2>Your Author Queue</h2>
                <p>Resolve reviewer feedback, keep binder hierarchy visible, and export selected items.</p>
            </div>

            <form class="queue-filters" method="post" action="/dashboard/author/upload-book-package" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <label class="queue-filter-search" style="max-width: 30rem;">
                    <span>Upload book package</span>
                    <input type="file" name="book_package" accept=".zip" required>
                </label>
                <div class="queue-filter-actions">
                    <button type="submit" class="button small">Import package</button>
                </div>
            </form>

            <form class="queue-filters" method="get" action="/dashboard/author">
                <label class="queue-filter-search">
                    <span>Search</span>
                    <input type="text" name="q" value="<?php echo htmlspecialchars((string) (($author_queue_filters['q'] ?? '') ?: ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Title, book, source path...">
                </label>

                <label class="queue-filter-state">
                    <span>Review State</span>
                    <select name="state">
                        <?php $currentAuthorState = (string) ($author_queue_filters['state'] ?? 'all'); ?>
                        <option value="all" <?php echo $currentAuthorState === 'all' ? 'selected' : ''; ?>>All states</option>
                        <option value="placeholder" <?php echo $currentAuthorState === 'placeholder' ? 'selected' : ''; ?>>Structure</option>
                        <option value="ready_for_review" <?php echo $currentAuthorState === 'ready_for_review' ? 'selected' : ''; ?>>Ready for review</option>
                        <option value="in_review" <?php echo $currentAuthorState === 'in_review' ? 'selected' : ''; ?>>In review</option>
                        <option value="needs_author_reply" <?php echo $currentAuthorState === 'needs_author_reply' ? 'selected' : ''; ?>>Needs author reply</option>
                        <option value="resolved" <?php echo $currentAuthorState === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                    </select>
                </label>

                <label class="queue-filter-sort">
                    <span>Sort</span>
                    <select name="sort">
                        <?php $currentAuthorSort = (string) ($author_queue_filters['sort'] ?? 'binder_asc'); ?>
                        <option value="binder_asc" <?php echo $currentAuthorSort === 'binder_asc' ? 'selected' : ''; ?>>Binder order</option>
                        <option value="chapter_asc" <?php echo $currentAuthorSort === 'chapter_asc' ? 'selected' : ''; ?>>Chapter title (A-Z)</option>
                        <option value="chapter_desc" <?php echo $currentAuthorSort === 'chapter_desc' ? 'selected' : ''; ?>>Chapter title (Z-A)</option>
                        <option value="imported_desc" <?php echo $currentAuthorSort === 'imported_desc' ? 'selected' : ''; ?>>Most recently imported</option>
                    </select>
                </label>

                <label class="queue-filter-per-page">
                    <span>Per page</span>
                    <?php $currentAuthorPerPage = (int) ($author_queue_filters['per_page'] ?? 25); ?>
                    <select name="per_page">
                        <option value="10" <?php echo $currentAuthorPerPage === 10 ? 'selected' : ''; ?>>10</option>
                        <option value="25" <?php echo $currentAuthorPerPage === 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?php echo $currentAuthorPerPage === 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $currentAuthorPerPage === 100 ? 'selected' : ''; ?>>100</option>
                    </select>
                </label>

                <div class="queue-filter-actions">
                    <button type="submit" class="button small">Apply</button>
                    <a href="/dashboard/author" class="button small secondary">Reset</a>
                </div>
            </form>

            <div class="queue-context">
                <p>
                    <strong>Book:</strong>
                    <?php echo htmlspecialchars((string) (($author_queue_context['book_title'] ?? null) ?? 'Not set'), ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>

            <div class="queue-tree-tools" aria-label="Queue tree controls">
                <button type="button" class="button small secondary" data-tree-collapse-all data-tree-table-target="author-queue">Collapse all folders</button>
                <button type="button" class="button small secondary" data-tree-expand-all data-tree-table-target="author-queue">Expand all folders</button>
            </div>

            <form method="post" action="/dashboard/author/export">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <div class="queue-filters" style="margin-bottom: 1rem;">
                    <label class="queue-filter-search" style="max-width: 28rem;">
                        <span>Password (optional)</span>
                        <input type="password" name="export_password" minlength="12" placeholder="Leave blank for a standard PDF">
                    </label>

                    <div class="queue-filter-actions">
                        <button type="submit" class="button small">Export selected items</button>
                    </div>
                </div>

                <p class="queue-context">
                    <strong>Note:</strong>
                    Leave the password blank for a standard PDF, or provide at least 12 characters to encrypt the export.
                </p>

                <div class="queue-table-wrap">
                    <table class="queue-table">
                        <thead>
                            <tr>
                                <th>Select</th>
                                <th>Submission</th>
                                <th>Reviewers</th>
                                <th>Status</th>
                                <th>Updated</th>
                                <th>Next Step</th>
                            </tr>
                        </thead>
                        <tbody data-tree-table="author-queue">
                            <?php if (!empty($author_queue) && is_array($author_queue)) { ?>
                                <?php $authorRows = array_values($author_queue ?? []); ?>
                                <?php for ($index = 0, $authorRowCount = count($authorRows); $index < $authorRowCount; $index++) { ?>
                                    <?php
                                        $row = $authorRows[$index];
                                    $depth = max(0, (int) ($row['depth'] ?? 0));
                                    $displayTitle = (string) (($row['display_title'] ?? '') !== '' ? $row['display_title'] : ($row['title'] ?? 'Untitled'));
                                    $hasChildren = !empty($row['is_directory']) && !empty($row['has_children']);
                                    $listPath = (string) ($row['list_path'] ?? '');
                                    $parentPath = (string) ($row['parent_path'] ?? '');
                                    $parentPathDisplay = $parentPath !== '' ? $formatQueuePath($parentPath) : '';
                                    $pathDisplay = $listPath !== '' ? $formatQueuePath($listPath) : '';
                                    $childFolderCount = max(0, (int) ($row['child_folder_count'] ?? 0));
                                    $childItemCount = max(0, (int) ($row['child_item_count'] ?? 0));
                                    ?>
                                    <tr class="queue-row <?php echo !empty($row['is_directory']) ? 'queue-row-directory' : 'queue-row-content'; ?>" data-tree-row="true" data-kind="<?php echo htmlspecialchars(!empty($row['is_directory']) ? 'directory' : 'item', ENT_QUOTES, 'UTF-8'); ?>" data-depth="<?php echo $depth; ?>" data-list-path="<?php echo htmlspecialchars((string) ($row['list_path'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-collapsed="false">
                                        <td>
                                            <?php if (empty($row['is_directory'])) { ?>
                                                <input
                                                    type="checkbox"
                                                    name="item_ids[]"
                                                    value="<?php echo (int) ($row['id'] ?? 0); ?>"
                                                    aria-label="Select <?php echo htmlspecialchars((string) ($row['title'] ?? 'item'), ENT_QUOTES, 'UTF-8'); ?>"
                                                >
                                            <?php } ?>
                                        </td>
                                        <td>
                                            <div class="queue-tree-label <?php echo !empty($row['is_directory']) ? 'queue-tree-directory' : 'queue-tree-item'; ?>" style="--queue-depth: <?php echo $depth; ?>;">
                                                <?php if (!empty($row['is_directory']) && $hasChildren) { ?>
                                                    <button class="queue-tree-toggle" type="button" data-tree-toggle aria-expanded="true" aria-label="Collapse folder">
                                                        <span class="queue-tree-toggle-icon" data-tree-toggle-icon aria-hidden="true">▾</span>
                                                        <span class="queue-tree-toggle-text" aria-hidden="true">📁</span>
                                                    </button>
                                                <?php } else { ?>
                                                    <span class="queue-tree-glyph" aria-hidden="true"><?php echo !empty($row['is_directory']) ? '▸' : '•'; ?></span>
                                                <?php } ?>
                                                <?php if (!empty($row['is_openable']) && !empty($row['url'])) { ?>
                                                    <a class="queue-item-link <?php echo !empty($row['is_directory']) ? 'queue-item-link--directory' : 'queue-item-link--content'; ?>" href="<?php echo htmlspecialchars((string) $row['url'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <?php echo htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8'); ?>
                                                    </a>
                                                <?php } else { ?>
                                                    <span class="queue-item-title-static <?php echo !empty($row['is_directory']) ? 'queue-item-title-directory' : 'queue-item-title-content'; ?>"><?php echo htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php } ?>
                                                <?php if (empty($row['is_directory']) && !empty($row['is_openable'])) { ?>
                                                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="confirm_delete" value="yes">
                                                    <button type="submit" class="button small secondary" formaction="/dashboard/author/<?php echo (int) ($row['id'] ?? 0); ?>/delete" formmethod="post" onclick="return confirm('Delete this book entry? This cannot be undone.');" style="margin-left:0.75rem;">Delete</button>
                                                <?php } ?>
                                                <?php if (!empty($row['is_directory'])) { ?>
                                                    <span class="queue-folder-count-badge" title="Direct children in this folder"><?php echo $childFolderCount; ?> folders · <?php echo $childItemCount; ?> items</span>
                                                <?php } ?>
                                            </div>
                                            <?php if (!empty($row['is_directory'])) { ?>
                                                <span class="queue-item-meta">Folder</span>
                                                <?php if ($pathDisplay !== '') { ?>
                                                    <span class="queue-item-path">Path: <?php echo htmlspecialchars($pathDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php } ?>
                                            <?php } else { ?>
                                                <span class="queue-item-meta"><?php echo htmlspecialchars((string) ($row['book_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php if ($parentPathDisplay !== '') { ?>
                                                    <span class="queue-item-path">Parent: <?php echo htmlspecialchars($parentPathDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php } ?>
                                            <?php } ?>
                                        </td>
                                        <?php if (!empty($row['is_directory'])) { ?>
                                            <td colspan="4"></td>
                                        <?php } else { ?>
                                            <td><?php echo (int) ($row['reviewer_count'] ?? 0); ?></td>
                                            <td><span class="status-pill status-<?php echo htmlspecialchars((string) ($row['status_tone'] ?? 'neutral'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) ($row['status'] ?? 'Ready for review'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <td><?php echo htmlspecialchars((string) ($row['updated'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($row['next_step'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <?php } ?>
                                    </tr>
                                <?php } ?>
                            <?php } else { ?>
                                <tr>
                                    <td colspan="6">No authored submissions are available yet.</td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </form>

            <?php
                $authorPagination = is_array($author_queue_pagination ?? null) ? $author_queue_pagination : [];
        $authorBaseFilters = is_array($author_queue_filters ?? null) ? $author_queue_filters : [];
        $authorPrevFilters = $authorBaseFilters;
        $authorNextFilters = $authorBaseFilters;
        $authorPrevFilters['page'] = (int) (($authorPagination['prev_page'] ?? 1) ?: 1);
        $authorNextFilters['page'] = (int) (($authorPagination['next_page'] ?? 1) ?: 1);
        ?>
            <div class="queue-pagination">
                <p>
                    Showing page <?php echo (int) ($authorPagination['page'] ?? 1); ?> of <?php echo (int) ($authorPagination['total_pages'] ?? 1); ?>
                    (<?php echo (int) ($authorPagination['total'] ?? 0); ?> item(s))
                </p>
                <div class="queue-pagination-actions">
                    <?php if (!empty($authorPagination['has_prev'])) { ?>
                        <a class="button small secondary" href="/dashboard/author?<?php echo htmlspecialchars((string) http_build_query($authorPrevFilters), ENT_QUOTES, 'UTF-8'); ?>">Previous</a>
                    <?php } else { ?>
                        <span class="button small secondary" aria-disabled="true">Previous</span>
                    <?php } ?>

                    <?php if (!empty($authorPagination['has_next'])) { ?>
                        <a class="button small secondary" href="/dashboard/author?<?php echo htmlspecialchars((string) http_build_query($authorNextFilters), ENT_QUOTES, 'UTF-8'); ?>">Next</a>
                    <?php } else { ?>
                        <span class="button small secondary" aria-disabled="true">Next</span>
                    <?php } ?>
                </div>
            </div>
        </section>

        <section class="card dashboard-section">
            <div class="card-header">
                <h2>Author Actions</h2>
                <p>Author resolution workflow now runs through item drill-in screens.</p>
            </div>

            <ul class="action-checklist">
                <li>Open an authored item directly from the queue to review all notes</li>
                <li>Resolve or re-open individual notes as writing changes are applied</li>
                <li>Use status filters to focus only on items that still need author follow-up</li>
                <li>Export selected items once note resolution is complete</li>
            </ul>
        </section>
    <?php } ?>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const dashboardStateKey = <?php echo json_encode('sparkinsight.dashboard.state:' . (string) ($dashboard_mode ?? 'review')); ?>;
        const queueFiltersForm = document.querySelector('.queue-filters');
        const treeTables = document.querySelectorAll('[data-tree-table]');
        const collapseAllButtons = document.querySelectorAll('[data-tree-collapse-all]');
        const expandAllButtons = document.querySelectorAll('[data-tree-expand-all]');
        const queueItemLinks = document.querySelectorAll('.queue-item-link--content');
        const reviewerSettingsPanel = document.querySelector('[data-reviewer-dashboard-settings]');
        const reviewerSettingsToggle = document.querySelector('[data-reviewer-settings-toggle]');
        const reviewerSettingsClose = document.querySelector('[data-reviewer-settings-close]');
        const reviewerDefaultViewSelect = document.querySelector('[data-reviewer-default-view]');
        const reviewerPanelPreferenceKey = 'sparkinsight.reviewer.panel.mode';
        let suppressStateSave = false;

        function readReviewerPanelPreference() {
            try {
                const stored = window.localStorage.getItem(reviewerPanelPreferenceKey);
                if (stored === 'compact-hidden' || stored === 'compact-visible') {
                    return stored;
                }
            } catch (error) {
            }

            return 'compact-hidden';
        }

        function writeReviewerPanelPreference(mode) {
            if (mode !== 'compact-hidden' && mode !== 'compact-visible') {
                return;
            }

            try {
                window.localStorage.setItem(reviewerPanelPreferenceKey, mode);
            } catch (error) {
            }
        }

        function setReviewerSettingsPanelVisibility(visible) {
            if (!reviewerSettingsPanel || !reviewerSettingsToggle) {
                return;
            }

            reviewerSettingsPanel.hidden = !visible;
            reviewerSettingsToggle.setAttribute('aria-expanded', visible ? 'true' : 'false');
        }

        function readDashboardState() {
            try {
                const raw = window.localStorage.getItem(dashboardStateKey);
                if (!raw) {
                    return null;
                }

                const parsed = JSON.parse(raw);
                return parsed && typeof parsed === 'object' ? parsed : null;
            } catch (error) {
                return null;
            }
        }

        function writeDashboardState(state) {
            try {
                window.localStorage.setItem(dashboardStateKey, JSON.stringify(state));
            } catch (error) {
            }
        }

        function currentFiltersFromUrl() {
            const params = new URLSearchParams(window.location.search);
            const filters = {};

            ['q', 'priority', 'state', 'sort', 'page', 'per_page'].forEach((key) => {
                const value = params.get(key);
                if (value !== null && value !== '') {
                    filters[key] = value;
                }
            });

            return filters;
        }

        function buildUrlFromFilters(filters) {
            const params = new URLSearchParams();

            ['q', 'priority', 'state', 'sort', 'page', 'per_page'].forEach((key) => {
                const value = filters[key];
                if (value !== undefined && value !== null && String(value) !== '') {
                    params.set(key, String(value));
                }
            });

            const query = params.toString();
            return query === '' ? window.location.pathname : window.location.pathname + '?' + query + window.location.hash;
        }

        function captureTreeState() {
            const treeState = {};

            treeTables.forEach((tableBody) => {
                const tableKey = tableBody.dataset.treeTable || 'default';
                tableBody.querySelectorAll('tr[data-tree-row][data-kind="directory"]').forEach((row) => {
                    const listPath = row.dataset.listPath || '';
                    if (listPath !== '') {
                        treeState[tableKey + '::' + listPath] = row.dataset.collapsed === 'true';
                    }
                });
            });

            return treeState;
        }

        function saveDashboardStateFromUrl() {
            if (suppressStateSave) {
                return;
            }

            const state = readDashboardState() || {};
            state.filters = currentFiltersFromUrl();
            state.treeState = captureTreeState();
            state.scrollY = Math.max(0, Math.round(window.scrollY || 0));
            state.updatedAt = Date.now();
            writeDashboardState(state);
        }

        function saveDashboardState() {
            saveDashboardStateFromUrl();
        }

        function saveDashboardStateFromForm() {
            if (!queueFiltersForm) {
                return;
            }

            const formValues = Object.fromEntries(new FormData(queueFiltersForm).entries());
            const state = readDashboardState() || {};
            state.filters = {
                q: String(formValues.q ?? ''),
                priority: String(formValues.priority ?? 'all'),
                state: String(formValues.state ?? 'all'),
                sort: String(formValues.sort ?? 'binder_asc'),
                page: '1',
                per_page: String(formValues.per_page ?? '10'),
            };
            state.treeState = captureTreeState();
            state.scrollY = Math.max(0, Math.round(window.scrollY || 0));
            state.updatedAt = Date.now();
            writeDashboardState(state);
        }

        function restoreSavedFilters() {
            if (!queueFiltersForm) {
                return false;
            }

            if (window.location.search && window.location.search !== '?') {
                return false;
            }

            const savedState = readDashboardState();
            const savedFilters = savedState && typeof savedState === 'object' ? savedState.filters : null;
            if (!savedFilters || typeof savedFilters !== 'object' || Object.keys(savedFilters).length === 0) {
                return false;
            }

            const targetUrl = buildUrlFromFilters(savedFilters);
            if (targetUrl !== window.location.pathname + window.location.search + window.location.hash) {
                suppressStateSave = true;
                window.location.replace(targetUrl);
                return true;
            }

            return false;
        }

        function restoreCollapsedState() {
            const savedState = readDashboardState();
            const treeState = savedState && typeof savedState.treeState === 'object' && !Array.isArray(savedState.treeState)
                ? savedState.treeState
                : null;

            treeTables.forEach((tableBody) => {
                const tableKey = tableBody.dataset.treeTable || 'default';
                tableBody.querySelectorAll('tr[data-tree-row][data-kind="directory"]').forEach((row) => {
                    const listPath = row.dataset.listPath || '';
                    if (listPath === '' || treeState === null) {
                        return;
                    }

                    const stateKey = tableKey + '::' + listPath;
                    if (Object.prototype.hasOwnProperty.call(treeState, stateKey)) {
                        row.dataset.collapsed = treeState[stateKey] ? 'true' : 'false';
                    }
                });
            });
        }

        function restoreScrollPosition() {
            const savedState = readDashboardState();
            const y = savedState && typeof savedState.scrollY === 'number'
                ? savedState.scrollY
                : null;

            if (y === null || y <= 0) {
                return;
            }

            window.requestAnimationFrame(() => {
                window.scrollTo(0, y);
            });
        }

        function refreshTreeVisibility(tableBody) {
            const rows = Array.from(tableBody.querySelectorAll('tr[data-tree-row]'));
            const collapsedAncestors = [];

            rows.forEach((row) => {
                const depth = Number(row.dataset.depth || '0');

                while (collapsedAncestors.length > 0 && collapsedAncestors[collapsedAncestors.length - 1] >= depth) {
                    collapsedAncestors.pop();
                }

                row.hidden = collapsedAncestors.length > 0;

                const isCollapsed = row.dataset.collapsed === 'true';
                const toggle = row.querySelector('[data-tree-toggle]');
                const toggleIcon = row.querySelector('[data-tree-toggle-icon]');

                if (toggle) {
                    toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
                    toggle.setAttribute('aria-label', isCollapsed ? 'Expand folder' : 'Collapse folder');
                    toggle.classList.toggle('is-collapsed', isCollapsed);
                    if (toggleIcon) {
                        toggleIcon.textContent = isCollapsed ? '▸' : '▾';
                    }
                }

                if ((row.dataset.kind || '') === 'directory' && isCollapsed) {
                    collapsedAncestors.push(depth);
                }
            });
        }

        if (reviewerDefaultViewSelect) {
            reviewerDefaultViewSelect.value = readReviewerPanelPreference();
            reviewerDefaultViewSelect.addEventListener('change', () => {
                writeReviewerPanelPreference(String(reviewerDefaultViewSelect.value || 'compact-hidden'));
            });
        }

        if (reviewerSettingsToggle) {
            reviewerSettingsToggle.addEventListener('click', () => {
                const currentlyVisible = reviewerSettingsPanel ? !reviewerSettingsPanel.hidden : false;
                setReviewerSettingsPanelVisibility(!currentlyVisible);
            });
        }

        if (reviewerSettingsClose) {
            reviewerSettingsClose.addEventListener('click', () => {
                setReviewerSettingsPanelVisibility(false);
            });
        }

        if (restoreSavedFilters()) {
            return;
        }

        restoreCollapsedState();

        treeTables.forEach((tableBody) => {
            tableBody.querySelectorAll('[data-tree-toggle]').forEach((toggle) => {
                toggle.addEventListener('click', () => {
                    const row = toggle.closest('tr[data-tree-row]');
                    if (!row) {
                        return;
                    }

                    row.dataset.collapsed = row.dataset.collapsed === 'true' ? 'false' : 'true';
                    refreshTreeVisibility(tableBody);
                    saveDashboardStateFromUrl();
                });
            });

            refreshTreeVisibility(tableBody);
        });

        collapseAllButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const targetTableKey = button.getAttribute('data-tree-table-target') || '';
                const tableBody = document.querySelector('[data-tree-table="' + CSS.escape(targetTableKey) + '"]');
                if (!tableBody) {
                    return;
                }

                tableBody.querySelectorAll('tr[data-tree-row][data-kind="directory"]').forEach((row) => {
                    if (row.querySelector('[data-tree-toggle]')) {
                        row.dataset.collapsed = 'true';
                    }
                });

                refreshTreeVisibility(tableBody);
                saveDashboardStateFromUrl();
            });
        });

        expandAllButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const targetTableKey = button.getAttribute('data-tree-table-target') || '';
                const tableBody = document.querySelector('[data-tree-table="' + CSS.escape(targetTableKey) + '"]');
                if (!tableBody) {
                    return;
                }

                tableBody.querySelectorAll('tr[data-tree-row][data-kind="directory"]').forEach((row) => {
                    row.dataset.collapsed = 'false';
                });

                refreshTreeVisibility(tableBody);
                saveDashboardStateFromUrl();
            });
        });

        queueItemLinks.forEach((link) => {
            link.addEventListener('click', () => {
                saveDashboardStateFromUrl();
            });
        });

        if (queueFiltersForm) {
            queueFiltersForm.addEventListener('submit', () => {
                saveDashboardStateFromForm();
            });
        }

        restoreScrollPosition();

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                saveDashboardState();
            }
        });

        window.addEventListener('pagehide', saveDashboardStateFromUrl);
        window.addEventListener('beforeunload', saveDashboardStateFromUrl);
        window.addEventListener('pageshow', (event) => {
            if (!event.persisted) {
                return;
            }

            restoreCollapsedState();
            treeTables.forEach((tableBody) => {
                refreshTreeVisibility(tableBody);
            });
            restoreScrollPosition();
        });
    });
    </script>

</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
