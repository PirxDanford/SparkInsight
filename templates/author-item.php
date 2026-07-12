<?php
$title = 'Author';
$author_item = isset($author_item) && is_array($author_item) ? $author_item : [];
$queueBackUrl = (string) ($queue_back_url ?? '/dashboard/author');
$queueQueryString = (string) ($queue_query_string ?? '');
$authorParentOptions = isset($author_parent_options) && is_array($author_parent_options) ? $author_parent_options : [];
ob_start();
?>
<div class="hero-panel">
    <?php if (!empty($flash_message) && is_array($flash_message)) { ?>
        <div class="notice <?php echo htmlspecialchars((string) ($flash_message['type'] ?? 'info'), ENT_QUOTES, 'UTF-8'); ?>">
            <strong><?php echo htmlspecialchars(ucfirst((string) ($flash_message['type'] ?? 'Notice')), ENT_QUOTES, 'UTF-8'); ?>:</strong>
            <span><?php echo htmlspecialchars((string) ($flash_message['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php } ?>

    <section class="card dashboard-section review-focus-panel">
        <div class="card-header review-focus-header">
            <div>
                <p class="eyebrow">Author review resolution</p>
                <h2><?php echo htmlspecialchars((string) ($author_item['title'] ?? 'Untitled'), ENT_QUOTES, 'UTF-8'); ?></h2>
                <p>
                    <?php echo htmlspecialchars((string) (($author_item['book_title'] ?? '') !== '' ? $author_item['book_title'] : 'No book title'), ENT_QUOTES, 'UTF-8'); ?>
                    · Imported <?php echo !empty($author_item['imported_at']) ? htmlspecialchars(date('Y-m-d', strtotime((string) $author_item['imported_at'])), ENT_QUOTES, 'UTF-8') : 'Unknown'; ?>
                </p>
            </div>
            <div class="review-focus-actions">
                <span class="status-pill status-<?php echo htmlspecialchars((string) ($author_item['status_tone'] ?? 'neutral'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) ($author_item['status_label'] ?? 'Ready for review'), ENT_QUOTES, 'UTF-8'); ?></span>
                <a class="button secondary" href="<?php echo htmlspecialchars($queueBackUrl, ENT_QUOTES, 'UTF-8'); ?>">Back to author queue</a>
            </div>
        </div>

        <section class="review-history-panel">
            <div class="card-header">
                <h3>Review notes</h3>
                <p>Resolve each note after applying your changes. Feedback status below shows which notes still need action.</p>
            </div>

            <?php if (!empty($author_item['summary']) && is_array($author_item['summary'])) { ?>
                <div class="reviewer-metrics" aria-label="Reviewer feedback status summary">
                    <?php foreach ($author_item['summary'] as $metric) { ?>
                        <article class="metric-card metric-<?php echo htmlspecialchars((string) ($metric['tone'] ?? 'neutral'), ENT_QUOTES, 'UTF-8'); ?>">
                            <p><?php echo htmlspecialchars((string) ($metric['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                            <strong><?php echo htmlspecialchars((string) ($metric['value'] ?? '0'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </article>
                    <?php } ?>
                </div>
            <?php } ?>

            <div class="review-history-list">
                <?php if (!empty($author_item['reviews']) && is_array($author_item['reviews'])) { ?>
                    <?php foreach ($author_item['reviews'] as $review) { ?>
                        <article class="review-note-card">
                            <?php
                                $hasSelectedExcerpt = mb_trim((string) ($review['selected_excerpt'] ?? '')) !== '';
                        $hasOffsetAnchor = ($review['anchor_start_offset'] ?? null) !== null && ($review['anchor_end_offset'] ?? null) !== null;
                        $isRangeNote = $hasSelectedExcerpt || $hasOffsetAnchor;
                        $reviewTitleRaw = mb_trim((string) ($review['title'] ?? ''));
                        $reviewTitleDisplay = preg_replace('/\breview\s+note\b/i', '', $reviewTitleRaw);
                        $reviewTitleDisplay = is_string($reviewTitleDisplay) ? mb_trim((string) preg_replace('/\s{2,}/u', ' ', $reviewTitleDisplay), " \t\n\r\0\x0B-:;,.()") : '';
                        ?>
                            <div class="review-note-card-header">
                                <div>
                                    <strong><?php echo htmlspecialchars((string) ($review['reviewer'] ?? 'Reviewer'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if (!$isRangeNote) { ?>
                                        <span class="status-pill status-info">Whole item note</span>
                                    <?php } ?>
                                    <?php if ($reviewTitleDisplay !== '') { ?>
                                        <p><?php echo htmlspecialchars($reviewTitleDisplay, ENT_QUOTES, 'UTF-8'); ?></p>
                                    <?php } ?>
                                </div>
                                <span class="status-pill status-<?php echo htmlspecialchars((string) ($review['status_tone'] ?? 'neutral'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) ($review['status_label'] ?? 'Open'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>

                            <dl class="details-list review-note-meta">
                                <dt>Feedback status</dt>
                                <dd>
                                    <span class="status-pill status-<?php echo htmlspecialchars((string) ($review['feedback_status_tone'] ?? 'neutral'), ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars((string) ($review['feedback_status_label'] ?? 'Open'), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </dd>
                                <dt>Next step</dt>
                                <dd><?php echo htmlspecialchars((string) ($review['feedback_status_detail'] ?? 'Review this note and decide how to proceed.'), ENT_QUOTES, 'UTF-8'); ?></dd>
                                <dt>Location status</dt>
                                <dd>
                                    <span class="status-pill status-<?php echo htmlspecialchars((string) ($review['location_status_tone'] ?? 'neutral'), ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars((string) ($review['location_status_label'] ?? 'Whole item note'), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </dd>
                                <dt>Location detail</dt>
                                <dd><?php echo htmlspecialchars((string) ($review['location_status_detail'] ?? 'No specific location detail is available.'), ENT_QUOTES, 'UTF-8'); ?></dd>
                                <?php if (!empty($review['location_needs_parent'])) { ?>
                                    <dt>Fallback context</dt>
                                    <dd><?php echo htmlspecialchars((string) ($review['location_fallback_context'] ?? 'No fallback context available.'), ENT_QUOTES, 'UTF-8'); ?></dd>
                                <?php } ?>
                                <dt>Submitted</dt>
                                <dd><?php echo !empty($review['created_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime((string) $review['created_at'])), ENT_QUOTES, 'UTF-8') : 'Unknown'; ?></dd>
                                <dt>Updated</dt>
                                <dd><?php echo !empty($review['updated_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime((string) $review['updated_at'])), ENT_QUOTES, 'UTF-8') : 'Unknown'; ?></dd>
                                <dt>Decision</dt>
                                <dd><?php echo !empty($review['resolution_decision_label']) ? htmlspecialchars((string) $review['resolution_decision_label'], ENT_QUOTES, 'UTF-8') : 'Pending'; ?></dd>
                                <dt>Resolved</dt>
                                <dd><?php echo !empty($review['resolved_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime((string) $review['resolved_at'])), ENT_QUOTES, 'UTF-8') : 'Not resolved yet'; ?></dd>
                            </dl>

                            <?php if (!empty($review['selected_excerpt'])) { ?>
                                <p><strong>Selection:</strong> <?php echo htmlspecialchars((string) $review['selected_excerpt'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php } elseif (($review['anchor_start_offset'] ?? null) !== null && ($review['anchor_end_offset'] ?? null) !== null) { ?>
                                <p><strong>Selection offsets:</strong> <?php echo (int) $review['anchor_start_offset']; ?>-<?php echo (int) $review['anchor_end_offset']; ?></p>
                            <?php } ?>

                            <?php $reviewDetails = mb_trim((string) ($review['details'] ?? '')); ?>
                            <?php $detailsPreview = $reviewDetails !== '' ? $reviewDetails : 'No comment provided.'; ?>
                            <?php if (mb_strlen($detailsPreview) > 220) { ?>
                                <?php $detailsPreview = mb_substr($detailsPreview, 0, 220) . '...'; ?>
                            <?php } ?>

                            <section class="review-feedback-block" aria-label="Reviewer feedback">
                                <p class="review-feedback-label">Feedback</p>
                                <p class="review-feedback-text"><?php echo htmlspecialchars($detailsPreview, ENT_QUOTES, 'UTF-8'); ?></p>
                            </section>

                            <section class="review-feedback-block" aria-label="Resolution context">
                                <p class="review-feedback-label">Resolution context</p>
                                <dl class="details-list review-note-meta">
                                    <dt>Original target context</dt>
                                    <dd><?php echo htmlspecialchars((string) ($review['original_target_context'] ?? 'No original target context available.'), ENT_QUOTES, 'UTF-8'); ?></dd>
                                    <dt>Current changed context</dt>
                                    <dd><?php echo htmlspecialchars((string) ($review['current_changed_context'] ?? 'No current changed context available.'), ENT_QUOTES, 'UTF-8'); ?></dd>
                                    <dt>Version linkage</dt>
                                    <dd>
                                        <strong><?php echo htmlspecialchars((string) ($review['version_linkage_label'] ?? 'No prior link'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <span><?php echo htmlspecialchars((string) ($review['version_linkage_detail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </dd>
                                </dl>
                            </section>

                            <div class="action-group" style="margin-top: 0.75rem;">
                                <?php if (($review['status'] ?? '') === 'needs_author_review') { ?>
                                    <?php if (!empty($review['location_needs_parent'])) { ?>
                                        <form method="post" action="/dashboard/author/<?php echo (int) ($author_item['id'] ?? 0); ?>/review/<?php echo (int) ($review['id'] ?? 0); ?>/resolve" class="review-reparent-form">
                                            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="return_queue_query" value="<?php echo htmlspecialchars($queueQueryString, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="resolution_action" value="resolved">
                                            <label>
                                                <span>Replacement parent</span>
                                                <select name="parent_content_version_id" required>
                                                    <option value="">Choose an authored item</option>
                                                    <?php foreach ($authorParentOptions as $parentOption) { ?>
                                                        <option value="<?php echo (int) ($parentOption['id'] ?? 0); ?>"><?php echo htmlspecialchars((string) ($parentOption['label'] ?? 'Untitled'), ENT_QUOTES, 'UTF-8'); ?></option>
                                                    <?php } ?>
                                                </select>
                                            </label>
                                            <button class="button small" type="submit">Reassign parent and resolve</button>
                                        </form>
                                    <?php } else { ?>
                                        <form method="post" action="/dashboard/author/<?php echo (int) ($author_item['id'] ?? 0); ?>/review/<?php echo (int) ($review['id'] ?? 0); ?>/resolve">
                                            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="return_queue_query" value="<?php echo htmlspecialchars($queueQueryString, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="resolution_action" value="resolved">
                                            <button class="button small" type="submit">Mark resolved</button>
                                        </form>
                                    <?php } ?>
                                    <form method="post" action="/dashboard/author/<?php echo (int) ($author_item['id'] ?? 0); ?>/review/<?php echo (int) ($review['id'] ?? 0); ?>/resolve">
                                        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="return_queue_query" value="<?php echo htmlspecialchars($queueQueryString, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="resolution_action" value="ignored">
                                        <button class="button small secondary" type="submit">Ignore feedback</button>
                                    </form>
                                    <form method="post" action="/dashboard/author/<?php echo (int) ($author_item['id'] ?? 0); ?>/review/<?php echo (int) ($review['id'] ?? 0); ?>/resolve">
                                        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="return_queue_query" value="<?php echo htmlspecialchars($queueQueryString, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="resolution_action" value="still_relevant">
                                        <button class="button small secondary" type="submit">Still relevant</button>
                                    </form>
                                <?php } ?>
                            </div>
                        </article>
                    <?php } ?>
                <?php } else { ?>
                    <p class="empty-state">No review notes available for this item yet.</p>
                <?php } ?>
            </div>
        </section>
    </section>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
