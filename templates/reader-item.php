<?php
$title = 'Review';
$review_item ??= [];
$reader = is_array($review_item['reader'] ?? null) ? $review_item['reader'] : [
    'available' => false,
    'html' => null,
    'sections' => [],
    'paragraph_count' => 0,
    'word_count' => 0,
    'source_label' => 'Unavailable',
    'notice' => 'The source content could not be loaded.',
];
$draftRequiresAction = (bool) ($review_draft['requires_action'] ?? true);
$draftDetails = (string) ($review_draft['details'] ?? '');
$draftSelectedExcerpt = (string) ($review_draft['selected_excerpt'] ?? '');
$draftAnchorStartOffset = isset($review_draft['anchor_start_offset']) && $review_draft['anchor_start_offset'] !== null
    ? max(0, (int) $review_draft['anchor_start_offset'])
    : null;
$draftAnchorEndOffset = isset($review_draft['anchor_end_offset']) && $review_draft['anchor_end_offset'] !== null
    ? max(0, (int) $review_draft['anchor_end_offset'])
    : null;
$draftAnchorContainerPath = mb_trim((string) ($review_draft['anchor_container_path'] ?? ''));
$draftNoteId = 0;
$viewMode = (string) ($view_mode ?? 'compact-hidden');
$viewModeExplicit = !empty($view_mode_explicit);
$revisionNumber = max(1, (int) ($review_item['revision_number'] ?? 1));
$queueBackUrl = (string) ($queue_back_url ?? '/dashboard/review');
$queueQueryString = (string) ($queue_query_string ?? '');
$notes = array_values(array_filter(array_map(static fn (array $review): array => [
    'id' => (int) ($review['id'] ?? 0),
    'reviewer' => (string) ($review['reviewer'] ?? 'Anonymous reviewer'),
    'status_label' => (string) ($review['status_label'] ?? 'Open'),
    'status_tone' => (string) ($review['status_tone'] ?? 'warning'),
    'details' => mb_trim((string) ($review['details'] ?? '')),
    'selected_excerpt' => mb_trim((string) ($review['selected_excerpt'] ?? '')),
    'anchor_start_offset' => isset($review['anchor_start_offset']) && $review['anchor_start_offset'] !== null ? max(0, (int) $review['anchor_start_offset']) : null,
    'anchor_end_offset' => isset($review['anchor_end_offset']) && $review['anchor_end_offset'] !== null ? max(0, (int) $review['anchor_end_offset']) : null,
    'anchor_container_path' => mb_trim((string) ($review['anchor_container_path'] ?? '')),
    'requires_action' => (bool) ($review['requires_action'] ?? false),
    'created_at' => (string) ($review['created_at'] ?? ''),
    'resolved_at' => (string) ($review['resolved_at'] ?? ''),
    'resolution_decision_label' => (string) ($review['resolution_decision_label'] ?? ''),
    'resolution_actor_name' => (string) ($review['resolution_actor_name'] ?? ''),
    'resolution_actor_role' => (string) ($review['resolution_actor_role'] ?? ''),
    'resolution_recorded_at' => (string) ($review['resolution_recorded_at'] ?? ''),
], is_array($review_item['reviews'] ?? null) ? $review_item['reviews'] : []), static fn (array $note): bool => $note['id'] > 0));
$chapterNoteIds = array_values(array_map(static fn (array $note): int => (int) $note['id'], array_filter(
    $notes,
    static fn (array $note): bool => $note['selected_excerpt'] === ''
        && ($note['anchor_start_offset'] === null || $note['anchor_end_offset'] === null),
)));
$chapterNoteCount = count($chapterNoteIds);
$chapterNoteIdList = implode(',', $chapterNoteIds);
$encodedNotes = json_encode($notes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
ob_start();
?>
<div class="hero-panel">
    <?php if (!empty($flash_message) && is_array($flash_message)) { ?>
        <div class="notice <?php echo htmlspecialchars($flash_message['type'] ?? 'info', ENT_QUOTES, 'UTF-8'); ?>">
            <strong><?php echo htmlspecialchars(ucfirst((string) ($flash_message['type'] ?? 'Notice')), ENT_QUOTES, 'UTF-8'); ?>:</strong>
            <span><?php echo htmlspecialchars((string) ($flash_message['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php } ?>

    <section class="immersive-reader-shell" data-view-mode="<?php echo htmlspecialchars($viewMode, ENT_QUOTES, 'UTF-8'); ?>">
        <!-- Reader Toolbar -->
        <div class="reader-toolbar">
            <div class="reader-toolbar-left">
                <button class="button small secondary view-toggle view-toggle-fullscreen" data-view="fullscreen" title="Fullscreen Display">
                    <span class="icon" aria-hidden="true">🖥️</span>
                    <span>Fullscreen Display</span>
                </button>
                <div class="reader-title-wrap">
                    <h1><?php echo htmlspecialchars((string) $review_item['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
                    <?php if ($chapterNoteCount > 0) { ?>
                        <button
                            class="reader-title-note-anchor"
                            type="button"
                            data-note-anchor
                            data-note-ids="<?php echo htmlspecialchars($chapterNoteIdList, ENT_QUOTES, 'UTF-8'); ?>"
                            aria-label="<?php echo htmlspecialchars((string) ($chapterNoteCount . ' chapter note' . ($chapterNoteCount === 1 ? '' : 's')), ENT_QUOTES, 'UTF-8'); ?>"
                        >
                            <span class="reader-title-note-badge"><?php echo $chapterNoteCount; ?> note<?php echo $chapterNoteCount === 1 ? '' : 's'; ?></span>
                        </button>
                    <?php } ?>
                </div>
            </div>
            <div class="reader-toolbar-right">
                <div class="reader-view-selector">
                    <button class="view-toggle" data-view="compact-hidden" title="Pure Content" aria-label="Pure Content">📖</button>
                    <button class="view-toggle" data-view="compact-visible" title="Content + Panel" aria-label="Content + Panel">📋</button>
                </div>
                <a href="<?php echo htmlspecialchars($queueBackUrl, ENT_QUOTES, 'UTF-8'); ?>" class="button small secondary">← Back to queue</a>
            </div>
        </div>

        <!-- Fullscreen Text View (View 1) -->
        <div class="reader-view-fullscreen" hidden>
            <button class="fullscreen-exit-btn" title="Exit fullscreen">← Exit Fullscreen</button>
            <div class="reader-fullscreen-text">
                <header class="reader-fullscreen-header">
                    <div class="reader-title-wrap reader-title-wrap-fullscreen">
                        <h1><?php echo htmlspecialchars((string) $review_item['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
                    </div>
                </header>
                <article class="reader-text-content">
                    <?php if (!empty($reader['available']) && !empty($reader['html'])) { ?>
                        <div class="reader-rendered-html"><?php echo $reader['html']; ?></div>
                    <?php } elseif (!empty($reader['available']) && !empty($reader['sections'])) { ?>
                        <?php foreach ($reader['sections'] as $section) { ?>
                            <p><?php echo nl2br(htmlspecialchars((string) ($section['text'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
                        <?php } ?>
                    <?php } else { ?>
                        <p class="empty-state"><?php echo htmlspecialchars((string) ($reader['notice'] ?? 'No content available.'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php } ?>
                </article>
            </div>
        </div>

        <!-- Compact Views (Views 2, 3, 4) -->
        <div class="reader-view-compact">
            <div class="reader-main-grid" data-grid-mode="normal">
                <!-- Content Column -->
                <article class="reader-content-col" data-reader-content>
                    <?php if (!empty($reader['available']) && (!empty($reader['sections']) || !empty($reader['html']))) { ?>
                        <div class="reader-flow-text">
                            <?php if (!empty($reader['html'])) { ?>
                                <div class="reader-rendered-html"><?php echo $reader['html']; ?></div>
                            <?php } else { ?>
                                <?php foreach ($reader['sections'] as $section) { ?>
                                    <p><?php echo nl2br(htmlspecialchars((string) ($section['text'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
                                <?php } ?>
                            <?php } ?>
                        </div>

                        <?php if (!empty($reader['sections'])) { ?>
                        <div class="reader-sections-view">
                            <?php foreach ($reader['sections'] as $index => $section) { ?>
                                <section class="reader-section" id="<?php echo htmlspecialchars((string) ($section['anchor'] ?? ('section-' . ($index + 1))), ENT_QUOTES, 'UTF-8'); ?>">
                                    <header class="reader-section-header">
                                        <span class="reader-section-number">§<?php echo (int) $index + 1; ?></span>
                                        <?php if (!empty($section['type'])) { ?>
                                            <span class="status-pill status-neutral"><?php echo htmlspecialchars((string) $section['type'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php } ?>
                                    </header>
                                    <p><?php echo nl2br(htmlspecialchars((string) ($section['text'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
                                </section>
                            <?php } ?>
                        </div>

                        <?php } ?>
                    <?php } else { ?>
                        <p class="empty-state"><?php echo htmlspecialchars((string) ($reader['notice'] ?? 'No content available.'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php } ?>
                </article>

                <!-- Options Column -->
                <aside class="reader-options-col" data-visible="<?php echo $viewMode === 'fullscreen' ? 'false' : ($viewMode === 'compact-hidden' ? 'false' : 'true'); ?>">
                    <div class="reader-options-panel">
                        <!-- Item Metadata -->
                        <div class="option-group">
                            <h4>Item Details</h4>
                            <dl class="details-list">
                                <dt>Status</dt>
                                <dd><span class="status-pill status-<?php echo htmlspecialchars((string) $review_item['status_tone'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $review_item['status_label'], ENT_QUOTES, 'UTF-8'); ?></span></dd>
                                <dt>Author</dt>
                                <dd><?php echo htmlspecialchars((string) $review_item['author_name'], ENT_QUOTES, 'UTF-8'); ?></dd>
                                <dt>Version</dt>
                                <dd><?php echo $revisionNumber; ?></dd>
                                <dt>Length</dt>
                                <dd><?php echo (int) ($reader['paragraph_count'] ?? 0); ?> sections · <?php echo (int) ($reader['word_count'] ?? 0); ?> words</dd>
                            </dl>
                        </div>

                        <!-- Review Form -->
                        <div class="option-group">
                            <h4>Leave a Note</h4>
                            <form class="review-note-form" method="post" action="/dashboard/review/<?php echo (int) $review_item['id']; ?>">
                                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="return_queue_query" value="<?php echo htmlspecialchars($queueQueryString, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="view" value="<?php echo htmlspecialchars($viewMode, ENT_QUOTES, 'UTF-8'); ?>" data-reader-view-input>
                                <input type="hidden" name="selected_excerpt" value="<?php echo htmlspecialchars($draftSelectedExcerpt, ENT_QUOTES, 'UTF-8'); ?>" data-selected-excerpt-input>
                                <input type="hidden" name="anchor_start_offset" value="<?php echo $draftAnchorStartOffset !== null ? (int) $draftAnchorStartOffset : ''; ?>" data-anchor-start-offset-input>
                                <input type="hidden" name="anchor_end_offset" value="<?php echo $draftAnchorEndOffset !== null ? (int) $draftAnchorEndOffset : ''; ?>" data-anchor-end-offset-input>
                                <input type="hidden" name="anchor_container_path" value="<?php echo htmlspecialchars($draftAnchorContainerPath, ENT_QUOTES, 'UTF-8'); ?>" data-anchor-container-path-input>
                                <input type="hidden" name="note_id" value="0" data-note-id-input>
                                <input type="hidden" name="note_action" value="save" data-note-action-input>

                                <p class="reader-edit-state" data-edit-state hidden>
                                    <span data-edit-state-label></span>
                                    <button class="button small secondary" type="button" data-cancel-edit>Cancel edit</button>
                                </p>

                                <div class="reader-selection-tools">
                                    <p class="reader-selection-label">Selection marker</p>
                                    <p class="reader-selection-preview empty-state" data-selection-preview>No text selected.</p>
                                    <div class="action-group">
                                        <button class="button small secondary" type="button" data-dismiss-selection hidden>Dismiss selection</button>
                                    </div>
                                </div>

                                <label>
                                    <span>Comment</span>
                                    <textarea name="details" rows="5" placeholder="Your observations..."><?php echo htmlspecialchars($draftDetails, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                </label>

                                <label class="checkbox-row">
                                    <input type="checkbox" name="requires_action" value="1" <?php echo $draftRequiresAction ? 'checked' : ''; ?>>
                                    <span>Requires Action</span>
                                </label>

                                <div class="action-group review-note-actions">
                                    <button class="button primary button-full" type="submit" data-save-note-button>Save note</button>
                                    <div class="review-note-secondary-actions" data-review-note-secondary-actions data-editing="false">
                                        <button class="button small secondary" type="button" data-clear-comment>Clear comment</button>
                                        <button class="button small danger" type="button" data-delete-note-button hidden>Delete note</button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Review History -->
                        <div class="option-group">
                            <h4>Review History</h4>
                            <div class="review-history">
                                <?php if (!empty($notes)) { ?>
                                    <p class="reader-history-hint">Notes are shown inline on the title or highlighted text. Hover or focus them to view details and edit.</p>
                                <?php } else { ?>
                                    <p class="empty-state">No comments yet.</p>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const shell = document.querySelector('.immersive-reader-shell');
    const viewToggles = document.querySelectorAll('.view-toggle');
    const optionsCol = document.querySelector('.reader-options-col');
    const fullscreenView = document.querySelector('.reader-view-fullscreen');
    const compactView = document.querySelector('.reader-view-compact');
    const toolbar = document.querySelector('.reader-toolbar');
    const fullscreenExitBtn = document.querySelector('.fullscreen-exit-btn');
    const mainGrid = document.querySelector('.reader-main-grid');
    const readerViewInput = document.querySelector('[data-reader-view-input]');
    const readerContent = document.querySelector('[data-reader-content]');
    const primaryContentRoot = document.querySelector('.reader-view-compact .reader-flow-text');
    const fullscreenContentRoot = document.querySelector('.reader-view-fullscreen .reader-text-content');
    const selectionPreview = document.querySelector('[data-selection-preview]');
    const selectedExcerptInput = document.querySelector('[data-selected-excerpt-input]');
    const anchorStartOffsetInput = document.querySelector('[data-anchor-start-offset-input]');
    const anchorEndOffsetInput = document.querySelector('[data-anchor-end-offset-input]');
    const anchorContainerPathInput = document.querySelector('[data-anchor-container-path-input]');
    const noteIdInput = document.querySelector('[data-note-id-input]');
    const noteActionInput = document.querySelector('[data-note-action-input]');
    const reviewNoteSecondaryActions = document.querySelector('[data-review-note-secondary-actions]');
    const dismissSelectionButton = document.querySelector('[data-dismiss-selection]');
    const clearCommentButton = document.querySelector('[data-clear-comment]');
    const deleteNoteButton = document.querySelector('[data-delete-note-button]');
    const cancelEditButton = document.querySelector('[data-cancel-edit]');
    const editState = document.querySelector('[data-edit-state]');
    const editStateLabel = document.querySelector('[data-edit-state-label]');
    const saveNoteButton = document.querySelector('[data-save-note-button]');
    const detailsTextarea = document.querySelector('textarea[name="details"]');
    const requiresActionCheckbox = document.querySelector('input[name="requires_action"]');
    const panelPreferenceKey = 'sparkinsight.reviewer.panel.mode';
    const initialViewMode = <?php echo json_encode((string) $viewMode); ?>;
    const isViewModeExplicit = <?php echo $viewModeExplicit ? 'true' : 'false'; ?>;
    const allowedModes = new Set(['fullscreen', 'compact-hidden', 'compact-visible']);
    const noteRecords = Array.isArray(<?php echo $encodedNotes ?: '[]'; ?>) ? <?php echo $encodedNotes ?: '[]'; ?> : [];
    const noteLookup = new Map(noteRecords.map((note) => [Number(note.id), note]).filter((entry) => Number.isInteger(entry[0]) && entry[0] > 0));
    let currentSelectionText = '';
    let currentSelectionAnchor = { start: null, end: null, path: '' };
    let activeTooltipAnchor = null;
    let tooltipHideTimer = null;
    let searchOffsetByRoot = new WeakMap();
    let quickActionHideTimer = null;

    if (!shell || !fullscreenView || !compactView || !toolbar || !optionsCol || !mainGrid) {
        return;
    }

    // Never keep edit mode active across page loads.
    if (noteIdInput) {
        noteIdInput.value = '0';
    }
    if (noteActionInput) {
        noteActionInput.value = 'save';
    }
    if (editStateLabel) {
        editStateLabel.textContent = '';
    }
    if (editState) {
        editState.hidden = true;
    }

    function readPanelPreference() {
        try {
            const stored = window.localStorage.getItem(panelPreferenceKey);
            if (stored === 'compact-hidden' || stored === 'compact-visible') {
                return stored;
            }
        } catch (error) {
        }

        return null;
    }

    function writePanelPreference(mode) {
        if (mode !== 'compact-hidden' && mode !== 'compact-visible') {
            return;
        }

        try {
            window.localStorage.setItem(panelPreferenceKey, mode);
        } catch (error) {
        }
    }

    function setViewMode(mode) {
        if (!allowedModes.has(mode)) {
            mode = 'compact-hidden';
        }

        writePanelPreference(mode);

        hideTooltip();
        hideSelectionQuickAction();

        shell.setAttribute('data-view-mode', mode);
        if (readerViewInput) {
            readerViewInput.value = mode;
        }

        if (mode === 'fullscreen') {
            fullscreenView.hidden = false;
            compactView.hidden = true;
            toolbar.hidden = true;
            mainGrid.setAttribute('data-panel-visible', 'false');
            optionsCol.setAttribute('data-visible', 'false');
        } else {
            fullscreenView.hidden = true;
            compactView.hidden = false;
            toolbar.hidden = false;

            if (mode === 'compact-hidden') {
                mainGrid.setAttribute('data-panel-visible', 'false');
                optionsCol.setAttribute('data-visible', 'false');
            } else if (mode === 'compact-visible') {
                mainGrid.setAttribute('data-panel-visible', 'true');
                optionsCol.setAttribute('data-visible', 'true');
            }
        }
        mainGrid.setAttribute('data-grid-mode', 'normal');

        viewToggles.forEach(btn => {
            const btnMode = btn.getAttribute('data-view');
            btn.classList.toggle('active', btnMode === mode);
        });
    }

    viewToggles.forEach(btn => {
        btn.addEventListener('click', () => {
            const mode = btn.getAttribute('data-view');
            setViewMode(mode);
        });
    });

    if (fullscreenExitBtn) {
        fullscreenExitBtn.addEventListener('click', () => {
            setViewMode('compact-hidden');
        });
    }

    function updateSelectionPreview(text) {
        currentSelectionText = sanitizeExcerptText(text);

        if (selectedExcerptInput) {
            selectedExcerptInput.value = currentSelectionText;
        }

        if (!selectionPreview) {
            updateFormActionState();
            return;
        }

        if (currentSelectionText === '') {
            selectionPreview.textContent = 'No text selected.';
            selectionPreview.classList.add('empty-state');
            saveNoteButton.textContent = 'Save note';
            if (dismissSelectionButton) {
                dismissSelectionButton.hidden = true;
            }
            updateFormActionState();
            return;
        }

        selectionPreview.textContent = currentSelectionText;
        selectionPreview.classList.remove('empty-state');
        saveNoteButton.textContent = 'Save note for selection';
        if (dismissSelectionButton) {
            dismissSelectionButton.hidden = false;
        }
        updateFormActionState();
    }

    function createSelectionQuickAction() {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'reader-selection-quick-action';
        button.textContent = 'Create note from selection';
        button.hidden = true;
        button.setAttribute('aria-label', 'Create review note from selected text');

        button.addEventListener('mouseenter', () => {
            if (quickActionHideTimer) {
                clearTimeout(quickActionHideTimer);
            }
        });

        button.addEventListener('mouseleave', () => {
            hideSelectionQuickActionSoon(100);
        });

        button.addEventListener('mousedown', (event) => {
            event.preventDefault();
        });

        button.addEventListener('click', () => {
            if (currentSelectionText === '') {
                hideSelectionQuickAction();
                return;
            }

            if (noteIdInput) {
                noteIdInput.value = '0';
            }
            if (noteActionInput) {
                noteActionInput.value = 'save';
            }
            if (editStateLabel) {
                editStateLabel.textContent = '';
            }
            if (editState) {
                editState.hidden = true;
            }

            setViewMode('compact-visible');
            hideSelectionQuickAction();

            if (detailsTextarea) {
                detailsTextarea.focus();
            }
        });

        document.body.appendChild(button);
        return button;
    }

    const selectionQuickAction = createSelectionQuickAction();

    function hideSelectionQuickAction() {
        if (quickActionHideTimer) {
            clearTimeout(quickActionHideTimer);
            quickActionHideTimer = null;
        }

        if (selectionQuickAction) {
            selectionQuickAction.hidden = true;
        }
    }

    function hideSelectionQuickActionSoon(delayMs) {
        if (!selectionQuickAction || selectionQuickAction.hidden) {
            return;
        }

        if (quickActionHideTimer) {
            clearTimeout(quickActionHideTimer);
        }

        quickActionHideTimer = setTimeout(() => {
            hideSelectionQuickAction();
        }, delayMs);
    }

    function showSelectionQuickActionForRange(range, selectionRoot) {
        if (!selectionQuickAction || !range || currentSelectionText === '') {
            hideSelectionQuickAction();
            return;
        }

        if (!shell || !selectionRoot || !shell.contains(selectionRoot)) {
            hideSelectionQuickAction();
            return;
        }

        const mode = shell.getAttribute('data-view-mode') || '';
        if (mode !== 'fullscreen' && mode !== 'compact-hidden') {
            hideSelectionQuickAction();
            return;
        }

        const rects = range.getClientRects();
        let rect = null;
        if (rects && rects.length > 0) {
            rect = rects[0];
        }
        if (!rect) {
            rect = range.getBoundingClientRect();
        }

        if (!rect || rect.width <= 0 || rect.height <= 0) {
            hideSelectionQuickAction();
            return;
        }

        selectionQuickAction.hidden = false;
        const actionRect = selectionQuickAction.getBoundingClientRect();
        const margin = 10;
        const top = Math.max(12, rect.top - actionRect.height - margin);
        const centeredLeft = rect.left + (rect.width / 2) - (actionRect.width / 2);
        const maxLeft = Math.max(12, window.innerWidth - actionRect.width - 12);
        const left = Math.min(maxLeft, Math.max(12, centeredLeft));

        selectionQuickAction.style.top = Math.round(top) + 'px';
        selectionQuickAction.style.left = Math.round(left) + 'px';
    }

    function updateFormActionState() {
        if (!saveNoteButton) {
            return;
        }

        const editing = noteIdInput ? Number(noteIdInput.value || 0) > 0 : false;
        const hasOffsetAnchor = currentSelectionAnchor.start !== null && currentSelectionAnchor.end !== null;
        const forSelection = currentSelectionText !== '' || hasOffsetAnchor;

        if (editing) {
            saveNoteButton.textContent = forSelection ? 'Update note for selection' : 'Update note';
        } else {
            saveNoteButton.textContent = forSelection ? 'Save note for selection' : 'Save note';
        }

        if (editState) {
            editState.hidden = !editing;
        }

        if (deleteNoteButton) {
            deleteNoteButton.hidden = !editing;
            deleteNoteButton.style.setProperty('display', editing ? 'inline-flex' : 'none', 'important');
        }

        if (reviewNoteSecondaryActions) {
            reviewNoteSecondaryActions.setAttribute('data-editing', editing ? 'true' : 'false');
        }

        if (noteActionInput) {
            noteActionInput.value = 'save';
        }
    }

    function formatNoteTime(value) {
        if (!value) {
            return '';
        }

        const date = new Date(value.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return date.toLocaleString();
    }

    function sanitizeExcerptText(value) {
        if (!value) {
            return '';
        }

        return String(value).replace(/\s+/g, ' ').trim();
    }

    function normalizeOffsetValue(value) {
        if (value === null || value === undefined) {
            return null;
        }

        const raw = String(value).trim();
        if (!/^\d+$/.test(raw)) {
            return null;
        }

        return Math.max(0, Number(raw));
    }

    function setSelectionAnchorData(anchor) {
        const start = normalizeOffsetValue(anchor && anchor.start);
        const end = normalizeOffsetValue(anchor && anchor.end);
        const valid = start !== null && end !== null && end >= start;
        const path = anchor && anchor.path ? String(anchor.path).trim() : '';

        currentSelectionAnchor = {
            start: valid ? start : null,
            end: valid ? end : null,
            path: valid ? path : '',
        };

        if (anchorStartOffsetInput) {
            anchorStartOffsetInput.value = currentSelectionAnchor.start !== null ? String(currentSelectionAnchor.start) : '';
        }
        if (anchorEndOffsetInput) {
            anchorEndOffsetInput.value = currentSelectionAnchor.end !== null ? String(currentSelectionAnchor.end) : '';
        }
        if (anchorContainerPathInput) {
            anchorContainerPathInput.value = currentSelectionAnchor.path;
        }

        updateFormActionState();
    }

    function getNotesFromAnchor(anchor) {
        const idList = (anchor.getAttribute('data-note-ids') || '').split(',').map((value) => Number(value.trim())).filter((value) => Number.isInteger(value) && value > 0);
        return idList.map((id) => noteLookup.get(id)).filter(Boolean);
    }

    function createTooltip() {
        const tooltip = document.createElement('div');
        tooltip.className = 'reader-note-tooltip-popover';
        tooltip.hidden = true;
        tooltip.tabIndex = -1;
        document.body.appendChild(tooltip);

        tooltip.addEventListener('mouseenter', () => {
            if (tooltipHideTimer) {
                clearTimeout(tooltipHideTimer);
            }
        });

        tooltip.addEventListener('mouseleave', () => {
            hideTooltipSoon(120);
        });

        tooltip.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }

            const editButton = target.closest('[data-note-edit-id]');
            if (!editButton) {
                return;
            }

            const note = noteLookup.get(Number(editButton.getAttribute('data-note-edit-id') || 0));
            if (!note) {
                return;
            }

            startEditingNote(note);
            hideTooltip();
        });

        return tooltip;
    }

    const tooltip = createTooltip();

    function hideTooltip() {
        if (tooltipHideTimer) {
            clearTimeout(tooltipHideTimer);
            tooltipHideTimer = null;
        }

        if (activeTooltipAnchor) {
            activeTooltipAnchor.classList.remove('is-note-active');
        }

        activeTooltipAnchor = null;
        tooltip.hidden = true;
    }

    function hideTooltipSoon(delayMs) {
        if (tooltipHideTimer) {
            clearTimeout(tooltipHideTimer);
        }

        tooltipHideTimer = setTimeout(() => {
            hideTooltip();
        }, delayMs);
    }

    function renderTooltipContent(notes) {
        tooltip.textContent = '';

        const title = document.createElement('p');
        title.className = 'reader-note-tooltip-title';
        title.textContent = notes.length === 1 ? '1 note' : notes.length + ' notes';
        tooltip.appendChild(title);

        notes.forEach((note) => {
            const entry = document.createElement('article');
            entry.className = 'reader-note-tooltip-entry';

            const heading = document.createElement('div');
            heading.className = 'reader-note-tooltip-heading';

            const reviewer = document.createElement('strong');
            reviewer.textContent = note.reviewer || 'Anonymous reviewer';
            heading.appendChild(reviewer);

            const status = document.createElement('span');
            status.className = 'status-pill status-' + (note.status_tone || 'neutral');
            status.textContent = note.status_label || 'Open';
            heading.appendChild(status);
            entry.appendChild(heading);

            const text = document.createElement('p');
            text.className = 'reader-note-tooltip-text';
            text.textContent = note.details && note.details.trim() !== '' ? note.details : 'No comment.';
            entry.appendChild(text);

            const footer = document.createElement('div');
            footer.className = 'reader-note-tooltip-footer';

            const date = document.createElement('span');
            date.textContent = formatNoteTime(note.created_at || '');
            footer.appendChild(date);

            if ((note.resolution_decision_label || '') !== '' && (note.resolution_actor_name || '') !== '') {
                const resolution = document.createElement('span');
                const actorRole = (note.resolution_actor_role || '').trim();
                const roleText = actorRole !== '' ? actorRole : 'actor';
                const recordedAt = note.resolution_recorded_at || note.resolved_at || '';
                resolution.textContent = note.resolution_decision_label + ' by ' + note.resolution_actor_name + ' (' + roleText + ')' + (recordedAt !== '' ? ' · ' + formatNoteTime(recordedAt) : '');
                footer.appendChild(resolution);
            }

            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'button small secondary';
            edit.textContent = 'Edit';
            edit.setAttribute('data-note-edit-id', String(note.id || 0));
            footer.appendChild(edit);

            entry.appendChild(footer);
            tooltip.appendChild(entry);
        });
    }

    function showTooltip(anchor) {
        const notes = getNotesFromAnchor(anchor);
        if (notes.length === 0) {
            return;
        }

        if (tooltipHideTimer) {
            clearTimeout(tooltipHideTimer);
            tooltipHideTimer = null;
        }

        if (activeTooltipAnchor && activeTooltipAnchor !== anchor) {
            activeTooltipAnchor.classList.remove('is-note-active');
        }

        activeTooltipAnchor = anchor;
        activeTooltipAnchor.classList.add('is-note-active');
        renderTooltipContent(notes);
        tooltip.hidden = false;

        const rect = anchor.getBoundingClientRect();
        const tooltipRect = tooltip.getBoundingClientRect();

        const margin = 12;
        const canPlaceRight = rect.right + margin + tooltipRect.width <= window.innerWidth - margin;
        const canPlaceLeft = rect.left - margin - tooltipRect.width >= margin;

        let left;
        if (canPlaceRight) {
            left = rect.right + margin;
        } else if (canPlaceLeft) {
            left = rect.left - tooltipRect.width - margin;
        } else {
            left = Math.min(window.innerWidth - tooltipRect.width - margin, Math.max(margin, rect.left));
        }

        let top = rect.top + (rect.height / 2) - (tooltipRect.height / 2);
        top = Math.min(window.innerHeight - tooltipRect.height - margin, Math.max(margin, top));

        tooltip.style.top = Math.max(12, top) + 'px';
        tooltip.style.left = Math.max(12, left) + 'px';
    }

    function startEditingNote(note) {
        if (!noteIdInput || !detailsTextarea) {
            return;
        }

        noteIdInput.value = String(note.id || 0);
        if (noteActionInput) {
            noteActionInput.value = 'save';
        }
        detailsTextarea.value = note.details || '';
        setSelectionAnchorData({
            start: note.anchor_start_offset,
            end: note.anchor_end_offset,
            path: note.anchor_container_path || '',
        });
        updateSelectionPreview((note.selected_excerpt || '').trim());

        if (requiresActionCheckbox) {
            requiresActionCheckbox.checked = Boolean(note.requires_action);
        }

        if (editStateLabel) {
            const reviewerName = note.reviewer ? String(note.reviewer) : 'reviewer';
            editStateLabel.textContent = 'Editing note from ' + reviewerName + '.';
        }

        setViewMode('compact-visible');
        detailsTextarea.focus();
    }

    function buildHighlightRange(root, needle, minOffset) {
        if (!needle || !root) {
            return null;
        }

        function normalizeWithMap(value) {
            const map = [];
            let normalized = '';
            let inWhitespace = false;

            for (let index = 0; index < value.length; index++) {
                const character = value[index];
                if (/\s/.test(character)) {
                    if (!inWhitespace) {
                        normalized += ' ';
                        map.push(index);
                        inWhitespace = true;
                    }
                    continue;
                }

                inWhitespace = false;
                normalized += character;
                map.push(index);
            }

            return {
                text: normalized,
                map,
            };
        }

        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode(node) {
                if (!node || !node.nodeValue || node.nodeValue.trim() === '') {
                    return NodeFilter.FILTER_REJECT;
                }

                if (node.parentElement && node.parentElement.closest('[data-note-anchor]')) {
                    return NodeFilter.FILTER_REJECT;
                }

                return NodeFilter.FILTER_ACCEPT;
            }
        });

        const nodes = [];
        let fullText = '';
        let cursor = 0;

        while (walker.nextNode()) {
            const textNode = walker.currentNode;
            const value = textNode.nodeValue || '';
            nodes.push({ node: textNode, start: cursor, end: cursor + value.length });
            fullText += value;
            cursor += value.length;
        }

        if (fullText === '') {
            return null;
        }

        const normalizedHaystack = normalizeWithMap(fullText);
        const normalizedNeedle = sanitizeExcerptText(needle);
        if (normalizedNeedle === '') {
            return null;
        }

        const lowerFull = normalizedHaystack.text.toLowerCase();
        const lowerNeedle = normalizedNeedle.toLowerCase();
        const normalizedStart = lowerFull.indexOf(lowerNeedle, Math.max(0, minOffset));
        if (normalizedStart < 0) {
            return null;
        }

        const normalizedEnd = normalizedStart + lowerNeedle.length;
        const rawStart = normalizedHaystack.map[normalizedStart];
        const rawEndAnchor = normalizedHaystack.map[normalizedEnd - 1];
        if (rawStart === undefined || rawEndAnchor === undefined) {
            return null;
        }

        const matchEnd = rawEndAnchor + 1;

        function locate(offset) {
            for (const item of nodes) {
                if (offset >= item.start && offset <= item.end) {
                    return {
                        node: item.node,
                        offset: offset - item.start,
                    };
                }
            }

            return null;
        }

        const start = locate(rawStart);
        const end = locate(matchEnd);
        if (!start || !end) {
            return null;
        }

        const range = document.createRange();
        range.setStart(start.node, start.offset);
        range.setEnd(end.node, end.offset);

        return {
            range,
            nextOffset: normalizedEnd,
        };
    }

    function buildHighlightRangeFromOffsets(root, startOffset, endOffset) {
        const normalizedStart = normalizeOffsetValue(startOffset);
        const normalizedEnd = normalizeOffsetValue(endOffset);
        if (!root || normalizedStart === null || normalizedEnd === null || normalizedEnd <= normalizedStart) {
            return null;
        }

        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode(node) {
                if (!node || !node.nodeValue || node.nodeValue === '') {
                    return NodeFilter.FILTER_REJECT;
                }

                // Keep text inside existing anchors in the offset model so
                // subsequent highlights still map to original absolute offsets.
                return NodeFilter.FILTER_ACCEPT;
            }
        });

        const nodes = [];
        let cursor = 0;
        while (walker.nextNode()) {
            const textNode = walker.currentNode;
            const value = textNode.nodeValue || '';
            const length = value.length;
            if (length === 0) {
                continue;
            }
            nodes.push({ node: textNode, start: cursor, end: cursor + length });
            cursor += length;
        }

        if (normalizedEnd > cursor) {
            return null;
        }

        function locate(offset) {
            for (const item of nodes) {
                if (offset >= item.start && offset <= item.end) {
                    return {
                        node: item.node,
                        offset: offset - item.start,
                    };
                }
            }

            return null;
        }

        const start = locate(normalizedStart);
        const end = locate(normalizedEnd);
        if (!start || !end) {
            return null;
        }

        const range = document.createRange();
        range.setStart(start.node, start.offset);
        range.setEnd(end.node, end.offset);

        return { range };
    }

    function buildStructuralPathForNode(node, root) {
        if (!root) {
            return '';
        }

        let element = null;
        if (node instanceof Element) {
            element = node;
        } else if (node && node.parentElement) {
            element = node.parentElement;
        }

        if (!element || !root.contains(element)) {
            return '';
        }

        const segments = [];
        let cursor = element;
        while (cursor && cursor !== root) {
            const tagName = cursor.tagName.toLowerCase();
            let index = 1;
            let sibling = cursor.previousElementSibling;
            while (sibling) {
                if (sibling.tagName === cursor.tagName) {
                    index++;
                }
                sibling = sibling.previousElementSibling;
            }

            segments.unshift(tagName + '[' + index + ']');
            cursor = cursor.parentElement;
        }

        return segments.join('/');
    }

    function extractSelectionAnchor(selectionRange, contentRoot) {
        if (!contentRoot || !selectionRange) {
            return null;
        }

        try {
            function measureRangeTextLength(range) {
                if (!range) {
                    return 0;
                }

                const fragment = range.cloneContents();
                const text = fragment && typeof fragment.textContent === 'string' ? fragment.textContent : '';
                return text.length;
            }

            const startProbe = document.createRange();
            startProbe.selectNodeContents(contentRoot);
            startProbe.setEnd(selectionRange.startContainer, selectionRange.startOffset);

            const endProbe = document.createRange();
            endProbe.selectNodeContents(contentRoot);
            endProbe.setEnd(selectionRange.endContainer, selectionRange.endOffset);

            const startOffset = measureRangeTextLength(startProbe);
            const endOffset = measureRangeTextLength(endProbe);
            if (!Number.isFinite(startOffset) || !Number.isFinite(endOffset) || endOffset <= startOffset) {
                return null;
            }

            return {
                start: Math.max(0, startOffset),
                end: Math.max(0, endOffset),
                path: buildStructuralPathForNode(selectionRange.commonAncestorContainer, contentRoot),
            };
        } catch (error) {
            return null;
        }
    }

    function groupSelectionNotes() {
        const excerptGroups = new Map();
        const offsetGroups = new Map();

        noteRecords.forEach((note) => {
            const start = normalizeOffsetValue(note.anchor_start_offset);
            const end = normalizeOffsetValue(note.anchor_end_offset);
            if (start !== null && end !== null && end > start) {
                const key = start + ':' + end;
                if (!offsetGroups.has(key)) {
                    offsetGroups.set(key, {
                        start,
                        end,
                        notes: [],
                    });
                }

                offsetGroups.get(key).notes.push(note);
                return;
            }

            const excerpt = sanitizeExcerptText(note.selected_excerpt || '');
            if (excerpt === '') {
                return;
            }

            if (!excerptGroups.has(excerpt)) {
                excerptGroups.set(excerpt, []);
            }

            excerptGroups.get(excerpt).push(note);
        });

        return {
            // Apply offset-based anchors from right to left to avoid DOM mutations
            // shifting character positions for notes that start later in the text.
            offset: Array.from(offsetGroups.values()).sort((a, b) => b.start - a.start),
            excerpt: Array.from(excerptGroups.entries()).map(([excerpt, groupedNotes]) => ({ excerpt, notes: groupedNotes })),
        };
    }

    function trimAnchorBoundaryWhitespace(anchor) {
        const walker = document.createTreeWalker(anchor, NodeFilter.SHOW_TEXT, null);
        const textNodes = [];
        while (walker.nextNode()) {
            textNodes.push(walker.currentNode);
        }

        if (textNodes.length === 0) {
            return;
        }

        const first = textNodes[0];
        first.nodeValue = (first.nodeValue || '').replace(/^\s+/u, '');
        if ((first.nodeValue || '') === '') {
            first.parentNode?.removeChild(first);
        }

        const last = textNodes[textNodes.length - 1];
        last.nodeValue = (last.nodeValue || '').replace(/\s+$/u, '');
        if ((last.nodeValue || '') === '') {
            last.parentNode?.removeChild(last);
        }
    }

    function createNoteAnchor(noteIds) {
        const anchor = document.createElement('span');
        anchor.className = 'reader-note-highlight';
        anchor.setAttribute('tabindex', '0');
        anchor.setAttribute('role', 'button');
        anchor.setAttribute('data-note-anchor', '');
        anchor.setAttribute('data-note-ids', noteIds.join(','));

        return anchor;
    }

    function insertHighlightAnchorFromRange(range, noteIds) {
        if (!range || !noteIds || noteIds.length === 0) {
            return false;
        }

        const contents = range.extractContents();
        const anchor = createNoteAnchor(noteIds);
        anchor.appendChild(contents);
        trimAnchorBoundaryWhitespace(anchor);

        if (sanitizeExcerptText(anchor.textContent || '') === '') {
            return false;
        }

        range.insertNode(anchor);
        return true;
    }

    function buildHighlightRangesForExcerpt(root, rawExcerpt, minOffset) {
        const excerpt = String(rawExcerpt || '');
        const lineParts = excerpt
            .split(/\r?\n+/u)
            .map((part) => sanitizeExcerptText(part))
            .filter((part) => part !== '');

        if (lineParts.length <= 1) {
            const single = buildHighlightRange(root, excerpt, minOffset);
            return single ? [single] : [];
        }

        const results = [];
        let cursor = Math.max(0, minOffset);
        for (const part of lineParts) {
            const segment = buildHighlightRange(root, part, cursor);
            if (!segment) {
                continue;
            }

            results.push(segment);
            cursor = segment.nextOffset;
        }

        return results;
    }

    function decorateSelectionAnchors(root) {
        const grouped = groupSelectionNotes();
        if (grouped.offset.length === 0 && grouped.excerpt.length === 0) {
            return;
        }

        let offset = searchOffsetByRoot.get(root) || 0;

        grouped.offset.forEach((group) => {
            const noteIds = group.notes.map((note) => Number(note.id || 0)).filter((id) => id > 0);
            const rawExcerpt = (group.notes[0] && group.notes[0].selected_excerpt) || '';
            const expectedExcerpt = sanitizeExcerptText(rawExcerpt);
            let results = [];

            if (expectedExcerpt !== '') {
                results = buildHighlightRangesForExcerpt(root, rawExcerpt, offset);
                if (results.length > 0) {
                    offset = results[results.length - 1].nextOffset;
                }
            }

            if (results.length === 0) {
                const fallback = buildHighlightRangeFromOffsets(root, group.start, group.end);
                if (fallback) {
                    results = [fallback];
                }
            }

            if (results.length > 0 && expectedExcerpt !== '') {
                const actualExcerpt = sanitizeExcerptText(results.map((entry) => entry.range.toString()).join(' '));
                const overlapsExpected = actualExcerpt !== ''
                    && (actualExcerpt.includes(expectedExcerpt) || expectedExcerpt.includes(actualExcerpt));

                if (!overlapsExpected) {
                    const retry = buildHighlightRangesForExcerpt(root, rawExcerpt, 0);
                    if (retry.length > 0) {
                        results = retry;
                    }
                }
            }

            if (results.length === 0 || noteIds.length === 0) {
                return;
            }

            results.forEach((result) => {
                insertHighlightAnchorFromRange(result.range, noteIds);
            });
        });

        grouped.excerpt.forEach((group) => {
            const noteIds = group.notes.map((note) => Number(note.id || 0)).filter((id) => id > 0);
            const results = buildHighlightRangesForExcerpt(root, group.excerpt, offset);
            if (results.length === 0 || noteIds.length === 0) {
                return;
            }

            results.forEach((result) => {
                insertHighlightAnchorFromRange(result.range, noteIds);
            });
            offset = results[results.length - 1].nextOffset;
        });

        searchOffsetByRoot.set(root, offset);
    }

    function bindNoteAnchors() {
        document.querySelectorAll('[data-note-anchor]').forEach((anchor) => {
            anchor.addEventListener('mouseenter', () => {
                showTooltip(anchor);
            });

            anchor.addEventListener('mouseleave', () => {
                hideTooltipSoon(120);
            });

            anchor.addEventListener('focus', () => {
                showTooltip(anchor);
            });

            anchor.addEventListener('blur', () => {
                hideTooltipSoon(120);
            });

            anchor.addEventListener('click', (event) => {
                event.preventDefault();
                if (activeTooltipAnchor === anchor && !tooltip.hidden) {
                    hideTooltip();
                    return;
                }
                showTooltip(anchor);
            });
        });
    }

    function getSelectionRoot(range) {
        if (!range) {
            return null;
        }

        if (primaryContentRoot
            && primaryContentRoot.contains(range.startContainer)
            && primaryContentRoot.contains(range.endContainer)) {
            return primaryContentRoot;
        }

        if (fullscreenContentRoot
            && fullscreenContentRoot.contains(range.startContainer)
            && fullscreenContentRoot.contains(range.endContainer)) {
            return fullscreenContentRoot;
        }

        return null;
    }

    function captureSelectionFromReader() {
        if (!primaryContentRoot && !fullscreenContentRoot) {
            return;
        }

        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            hideSelectionQuickAction();
            return;
        }

        const range = selection.getRangeAt(0);
        const selectionRoot = getSelectionRoot(range);
        if (!selectionRoot) {
            hideSelectionQuickAction();
            return;
        }

        const text = sanitizeExcerptText(selection.toString());
        const anchor = selectionRoot === primaryContentRoot
            ? extractSelectionAnchor(range, selectionRoot)
            : null;
        setSelectionAnchorData(anchor);
        updateSelectionPreview(text.length > 500 ? text.slice(0, 500).trim() + '...' : text);
        if (text === '') {
            hideSelectionQuickAction();
            return;
        }

        showSelectionQuickActionForRange(range, selectionRoot);
    }

    if (primaryContentRoot || fullscreenContentRoot) {
        document.addEventListener('selectionchange', captureSelectionFromReader);
    }

    [primaryContentRoot, fullscreenContentRoot].filter(Boolean).forEach((root) => {
        root.addEventListener('mouseup', captureSelectionFromReader);
        root.addEventListener('keyup', captureSelectionFromReader);
        root.addEventListener('scroll', () => {
            hideSelectionQuickActionSoon(90);
        }, { passive: true });
    });

    document.addEventListener('mousedown', (event) => {
        if (!selectionQuickAction || selectionQuickAction.hidden) {
            return;
        }

        const target = event.target;
        if (target instanceof Node && selectionQuickAction.contains(target)) {
            return;
        }

        hideSelectionQuickActionSoon(0);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            hideSelectionQuickAction();
        }
    });

    window.addEventListener('scroll', () => {
        hideSelectionQuickActionSoon(0);
    }, true);

    window.addEventListener('resize', () => {
        hideSelectionQuickActionSoon(0);
    });

    if (readerContent) {
        readerContent.addEventListener('mouseleave', () => {
            hideSelectionQuickActionSoon(120);
        });
    }

    if (fullscreenContentRoot) {
        fullscreenContentRoot.addEventListener('mouseleave', () => {
            hideSelectionQuickActionSoon(120);
        });
    }

    if (dismissSelectionButton) {
        dismissSelectionButton.addEventListener('click', () => {
            updateSelectionPreview('');
            setSelectionAnchorData(null);
            if (noteActionInput) {
                noteActionInput.value = 'save';
            }

            const selection = window.getSelection();
            if (selection && selection.removeAllRanges) {
                selection.removeAllRanges();
            }

            if (primaryContentRoot) {
                primaryContentRoot.focus?.();
            }

            if (noteIdInput) {
                noteIdInput.value = '0';
            }
            if (editStateLabel) {
                editStateLabel.textContent = '';
            }
            if (editState) {
                editState.hidden = true;
            }
            updateFormActionState();
        });
    }

    if (clearCommentButton && detailsTextarea) {
        clearCommentButton.addEventListener('click', () => {
            detailsTextarea.value = '';
            if (noteIdInput) {
                noteIdInput.value = '0';
            }
            if (noteActionInput) {
                noteActionInput.value = 'save';
            }
            detailsTextarea.focus();
            if (editStateLabel) {
                editStateLabel.textContent = '';
            }
            if (editState) {
                editState.hidden = true;
            }
            updateFormActionState();
        });
    }

    if (cancelEditButton) {
        cancelEditButton.addEventListener('click', () => {
            if (noteIdInput) {
                noteIdInput.value = '0';
            }
            if (noteActionInput) {
                noteActionInput.value = 'save';
            }
            if (editStateLabel) {
                editStateLabel.textContent = '';
            }
            if (editState) {
                editState.hidden = true;
            }
            updateFormActionState();
        });
    }

    if (deleteNoteButton) {
        deleteNoteButton.addEventListener('click', () => {
            const noteId = noteIdInput ? Number(noteIdInput.value || 0) : 0;
            if (noteId <= 0) {
                return;
            }

            if (!window.confirm('Delete this note? This cannot be undone.')) {
                return;
            }

            if (noteActionInput) {
                noteActionInput.value = 'delete';
            }

            const form = deleteNoteButton.closest('form');
            if (form && typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            if (form && typeof form.submit === 'function') {
                form.submit();
            }
        });
    }

    document.addEventListener('scroll', () => {
        if (!tooltip.hidden && activeTooltipAnchor) {
            showTooltip(activeTooltipAnchor);
        }
    }, true);

    window.addEventListener('resize', () => {
        if (!tooltip.hidden && activeTooltipAnchor) {
            showTooltip(activeTooltipAnchor);
        }
    });

    const initialSelection = selectedExcerptInput ? selectedExcerptInput.value.trim() : '';
    setSelectionAnchorData({
        start: anchorStartOffsetInput ? anchorStartOffsetInput.value : null,
        end: anchorEndOffsetInput ? anchorEndOffsetInput.value : null,
        path: anchorContainerPathInput ? anchorContainerPathInput.value : '',
    });
    const contentRoots = [primaryContentRoot].filter(Boolean);

    contentRoots.forEach((root) => {
        decorateSelectionAnchors(root);
    });

    bindNoteAnchors();
    let resolvedInitialMode = allowedModes.has(initialViewMode) ? initialViewMode : 'compact-hidden';
    if (!isViewModeExplicit) {
        const preferredPanelMode = readPanelPreference();
        if (preferredPanelMode !== null) {
            resolvedInitialMode = preferredPanelMode;
        }
    }

    setViewMode(resolvedInitialMode);
    updateSelectionPreview(initialSelection);
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
