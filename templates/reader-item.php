<?php
$title = 'Review';
$review_item = $review_item ?? [];
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
$draftAnchorContainerPath = trim((string) ($review_draft['anchor_container_path'] ?? ''));
$draftNoteId = 0;
$viewMode = (string) ($view_mode ?? 'compact-hidden');
$viewModeExplicit = !empty($view_mode_explicit);
$revisionNumber = max(1, (int) ($review_item['revision_number'] ?? 1));
$queueBackUrl = (string) ($queue_back_url ?? '/dashboard/review');
$queueQueryString = (string) ($queue_query_string ?? '');
$notes = array_values(array_filter(array_map(static function (array $review): array {
    return [
        'id' => (int) ($review['id'] ?? 0),
        'reviewer' => (string) ($review['reviewer'] ?? 'Anonymous reviewer'),
        'status_label' => (string) ($review['status_label'] ?? 'Open'),
        'status_tone' => (string) ($review['status_tone'] ?? 'warning'),
        'details' => trim((string) ($review['details'] ?? '')),
        'selected_excerpt' => trim((string) ($review['selected_excerpt'] ?? '')),
        'anchor_start_offset' => isset($review['anchor_start_offset']) && $review['anchor_start_offset'] !== null ? max(0, (int) $review['anchor_start_offset']) : null,
        'anchor_end_offset' => isset($review['anchor_end_offset']) && $review['anchor_end_offset'] !== null ? max(0, (int) $review['anchor_end_offset']) : null,
        'anchor_container_path' => trim((string) ($review['anchor_container_path'] ?? '')),
        'requires_action' => (bool) ($review['requires_action'] ?? false),
        'created_at' => (string) ($review['created_at'] ?? ''),
    ];
}, is_array($review_item['reviews'] ?? null) ? $review_item['reviews'] : []), static fn (array $note): bool => $note['id'] > 0));
$chapterNoteIds = array_values(array_map(static fn (array $note): int => (int) $note['id'], array_filter(
    $notes,
    static fn (array $note): bool => $note['selected_excerpt'] === ''
        && ($note['anchor_start_offset'] === null || $note['anchor_end_offset'] === null)
)));
$chapterNoteCount = count($chapterNoteIds);
$chapterNoteIdList = implode(',', $chapterNoteIds);
$encodedNotes = json_encode($notes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
ob_start();
?>
<div class="hero-panel">
    <?php if (!empty($flash_message) && is_array($flash_message)): ?>
        <div class="notice <?= htmlspecialchars($flash_message['type'] ?? 'info', ENT_QUOTES, 'UTF-8') ?>">
            <strong><?= htmlspecialchars(ucfirst((string) ($flash_message['type'] ?? 'Notice')), ENT_QUOTES, 'UTF-8') ?>:</strong>
            <span><?= htmlspecialchars((string) ($flash_message['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

    <section class="immersive-reader-shell" data-view-mode="<?= htmlspecialchars($viewMode, ENT_QUOTES, 'UTF-8') ?>">
        <!-- Reader Toolbar -->
        <div class="reader-toolbar">
            <div class="reader-toolbar-left">
                <button class="button small secondary view-toggle view-toggle-fullscreen" data-view="fullscreen" title="Fullscreen Display">
                    <span class="icon" aria-hidden="true">🖥️</span>
                    <span>Fullscreen Display</span>
                </button>
                <div class="reader-title-wrap">
                    <h1><?= htmlspecialchars((string) $review_item['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                    <?php if ($chapterNoteCount > 0): ?>
                        <button
                            class="reader-title-note-anchor"
                            type="button"
                            data-note-anchor
                            data-note-ids="<?= htmlspecialchars($chapterNoteIdList, ENT_QUOTES, 'UTF-8') ?>"
                            aria-label="<?= htmlspecialchars((string) ($chapterNoteCount . ' chapter note' . ($chapterNoteCount === 1 ? '' : 's')), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <span class="reader-title-note-badge"><?= $chapterNoteCount ?> note<?= $chapterNoteCount === 1 ? '' : 's' ?></span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="reader-toolbar-right">
                <div class="reader-view-selector">
                    <button class="view-toggle" data-view="compact-hidden" title="Pure Content" aria-label="Pure Content">📖</button>
                    <button class="view-toggle" data-view="compact-visible" title="Content + Panel" aria-label="Content + Panel">📋</button>
                </div>
                <a href="<?= htmlspecialchars($queueBackUrl, ENT_QUOTES, 'UTF-8') ?>" class="button small secondary">← Back to queue</a>
            </div>
        </div>

        <!-- Fullscreen Text View (View 1) -->
        <div class="reader-view-fullscreen" hidden>
            <button class="fullscreen-exit-btn" title="Exit fullscreen">← Exit Fullscreen</button>
            <div class="reader-fullscreen-text">
                <header class="reader-fullscreen-header">
                    <div class="reader-title-wrap reader-title-wrap-fullscreen">
                        <h1><?= htmlspecialchars((string) $review_item['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                    </div>
                </header>
                <article class="reader-text-content">
                    <?php if (!empty($reader['available']) && !empty($reader['html'])): ?>
                        <div class="reader-rendered-html"><?= $reader['html'] ?></div>
                    <?php elseif (!empty($reader['available']) && !empty($reader['sections'])): ?>
                        <?php foreach ($reader['sections'] as $section): ?>
                            <p><?= nl2br(htmlspecialchars((string) ($section['text'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="empty-state"><?= htmlspecialchars((string) ($reader['notice'] ?? 'No content available.'), ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </article>
            </div>
        </div>

        <!-- Compact Views (Views 2, 3, 4) -->
        <div class="reader-view-compact">
            <div class="reader-main-grid" data-grid-mode="normal">
                <!-- Content Column -->
                <article class="reader-content-col" data-reader-content>
                    <?php if (!empty($reader['available']) && (!empty($reader['sections']) || !empty($reader['html']))): ?>
                        <div class="reader-flow-text">
                            <?php if (!empty($reader['html'])): ?>
                                <div class="reader-rendered-html"><?= $reader['html'] ?></div>
                            <?php else: ?>
                                <?php foreach ($reader['sections'] as $section): ?>
                                    <p><?= nl2br(htmlspecialchars((string) ($section['text'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($reader['sections'])): ?>
                        <div class="reader-sections-view">
                            <?php foreach ($reader['sections'] as $index => $section): ?>
                                <section class="reader-section" id="<?= htmlspecialchars((string) ($section['anchor'] ?? ('section-' . ($index + 1))), ENT_QUOTES, 'UTF-8') ?>">
                                    <header class="reader-section-header">
                                        <span class="reader-section-number">§<?= (int) $index + 1 ?></span>
                                        <?php if (!empty($section['type'])): ?>
                                            <span class="status-pill status-neutral"><?= htmlspecialchars((string) $section['type'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </header>
                                    <p><?= nl2br(htmlspecialchars((string) ($section['text'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>
                                </section>
                            <?php endforeach; ?>
                        </div>

                        <?php endif; ?>
                    <?php else: ?>
                        <p class="empty-state"><?= htmlspecialchars((string) ($reader['notice'] ?? 'No content available.'), ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </article>

                <!-- Options Column -->
                <aside class="reader-options-col" data-visible="<?= $viewMode === 'fullscreen' ? 'false' : ($viewMode === 'compact-hidden' ? 'false' : 'true') ?>">
                    <div class="reader-options-panel">
                        <!-- Item Metadata -->
                        <div class="option-group">
                            <h4>Item Details</h4>
                            <dl class="details-list">
                                <dt>Status</dt>
                                <dd><span class="status-pill status-<?= htmlspecialchars((string) $review_item['status_tone'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $review_item['status_label'], ENT_QUOTES, 'UTF-8') ?></span></dd>
                                <dt>Author</dt>
                                <dd><?= htmlspecialchars((string) $review_item['author_name'], ENT_QUOTES, 'UTF-8') ?></dd>
                                <dt>Version</dt>
                                <dd><?= $revisionNumber ?></dd>
                                <dt>Length</dt>
                                <dd><?= (int) ($reader['paragraph_count'] ?? 0) ?> sections · <?= (int) ($reader['word_count'] ?? 0) ?> words</dd>
                            </dl>
                        </div>

                        <!-- Review Form -->
                        <div class="option-group">
                            <h4>Leave a Note</h4>
                            <form class="review-note-form" method="post" action="/dashboard/review/<?= (int) $review_item['id'] ?>">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="return_queue_query" value="<?= htmlspecialchars($queueQueryString, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="view" value="<?= htmlspecialchars($viewMode, ENT_QUOTES, 'UTF-8') ?>" data-reader-view-input>
                                <input type="hidden" name="selected_excerpt" value="<?= htmlspecialchars($draftSelectedExcerpt, ENT_QUOTES, 'UTF-8') ?>" data-selected-excerpt-input>
                                <input type="hidden" name="anchor_start_offset" value="<?= $draftAnchorStartOffset !== null ? (int) $draftAnchorStartOffset : '' ?>" data-anchor-start-offset-input>
                                <input type="hidden" name="anchor_end_offset" value="<?= $draftAnchorEndOffset !== null ? (int) $draftAnchorEndOffset : '' ?>" data-anchor-end-offset-input>
                                <input type="hidden" name="anchor_container_path" value="<?= htmlspecialchars($draftAnchorContainerPath, ENT_QUOTES, 'UTF-8') ?>" data-anchor-container-path-input>
                                <input type="hidden" name="note_id" value="0" data-note-id-input>

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
                                    <textarea name="details" rows="5" placeholder="Your observations..."><?= htmlspecialchars($draftDetails, ENT_QUOTES, 'UTF-8') ?></textarea>
                                </label>

                                <label class="checkbox-row">
                                    <input type="checkbox" name="requires_action" value="1" <?= $draftRequiresAction ? 'checked' : '' ?>>
                                    <span>Requires Action</span>
                                </label>

                                <div class="action-group review-note-actions">
                                    <button class="button small secondary" type="button" data-clear-comment>Clear comment</button>
                                    <button class="button button-full" type="submit" data-save-note-button>Save note</button>
                                </div>
                            </form>
                        </div>

                        <!-- Review History -->
                        <div class="option-group">
                            <h4>Review History</h4>
                            <div class="review-history">
                                <?php if (!empty($notes)): ?>
                                    <p class="reader-history-hint">Notes are shown inline on the title or highlighted text. Hover or focus them to view details and edit.</p>
                                <?php else: ?>
                                    <p class="empty-state">No comments yet.</p>
                                <?php endif; ?>
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
    const selectionPreview = document.querySelector('[data-selection-preview]');
    const selectedExcerptInput = document.querySelector('[data-selected-excerpt-input]');
    const anchorStartOffsetInput = document.querySelector('[data-anchor-start-offset-input]');
    const anchorEndOffsetInput = document.querySelector('[data-anchor-end-offset-input]');
    const anchorContainerPathInput = document.querySelector('[data-anchor-container-path-input]');
    const noteIdInput = document.querySelector('[data-note-id-input]');
    const dismissSelectionButton = document.querySelector('[data-dismiss-selection]');
    const clearCommentButton = document.querySelector('[data-clear-comment]');
    const cancelEditButton = document.querySelector('[data-cancel-edit]');
    const editState = document.querySelector('[data-edit-state]');
    const editStateLabel = document.querySelector('[data-edit-state-label]');
    const saveNoteButton = document.querySelector('[data-save-note-button]');
    const detailsTextarea = document.querySelector('textarea[name="details"]');
    const requiresActionCheckbox = document.querySelector('input[name="requires_action"]');
    const panelPreferenceKey = 'sparkinsight.reviewer.panel.mode';
    const initialViewMode = <?= json_encode((string) $viewMode) ?>;
    const isViewModeExplicit = <?= $viewModeExplicit ? 'true' : 'false' ?>;
    const allowedModes = new Set(['fullscreen', 'compact-hidden', 'compact-visible']);
    const noteRecords = Array.isArray(<?= $encodedNotes ?: '[]' ?>) ? <?= $encodedNotes ?: '[]' ?> : [];
    const noteLookup = new Map(noteRecords.map((note) => [Number(note.id), note]).filter((entry) => Number.isInteger(entry[0]) && entry[0] > 0));
    let currentSelectionText = '';
    let currentSelectionAnchor = { start: null, end: null, path: '' };
    let activeTooltipAnchor = null;
    let tooltipHideTimer = null;
    let searchOffsetByRoot = new WeakMap();

    if (!shell || !fullscreenView || !compactView || !toolbar || !optionsCol || !mainGrid) {
        return;
    }

    // Never keep edit mode active across page loads.
    if (noteIdInput) {
        noteIdInput.value = '0';
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

                if (node.parentElement && node.parentElement.closest('[data-note-anchor]')) {
                    return NodeFilter.FILTER_REJECT;
                }

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

    function extractSelectionAnchor(selectionRange) {
        if (!readerContent || !selectionRange) {
            return null;
        }

        try {
            const startProbe = document.createRange();
            startProbe.selectNodeContents(readerContent);
            startProbe.setEnd(selectionRange.startContainer, selectionRange.startOffset);

            const endProbe = document.createRange();
            endProbe.selectNodeContents(readerContent);
            endProbe.setEnd(selectionRange.endContainer, selectionRange.endOffset);

            const startOffset = startProbe.toString().length;
            const endOffset = endProbe.toString().length;
            if (!Number.isFinite(startOffset) || !Number.isFinite(endOffset) || endOffset <= startOffset) {
                return null;
            }

            return {
                start: Math.max(0, startOffset),
                end: Math.max(0, endOffset),
                path: buildStructuralPathForNode(selectionRange.commonAncestorContainer, readerContent),
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
            offset: Array.from(offsetGroups.values()).sort((a, b) => a.start - b.start),
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

    function decorateSelectionAnchors(root) {
        const grouped = groupSelectionNotes();
        if (grouped.offset.length === 0 && grouped.excerpt.length === 0) {
            return;
        }

        let offset = searchOffsetByRoot.get(root) || 0;

        grouped.offset.forEach((group) => {
            const result = buildHighlightRangeFromOffsets(root, group.start, group.end);
            if (!result) {
                return;
            }

            const anchor = document.createElement('span');
            anchor.className = 'reader-note-highlight';
            anchor.setAttribute('tabindex', '0');
            anchor.setAttribute('role', 'button');
            anchor.setAttribute('data-note-anchor', '');
            anchor.setAttribute('data-note-ids', group.notes.map((note) => Number(note.id || 0)).filter((id) => id > 0).join(','));

            const contents = result.range.extractContents();
            anchor.appendChild(contents);
            trimAnchorBoundaryWhitespace(anchor);

            result.range.insertNode(anchor);
        });

        grouped.excerpt.forEach((group) => {
            const result = buildHighlightRange(root, group.excerpt, offset);
            if (!result) {
                return;
            }

            const anchor = document.createElement('span');
            anchor.className = 'reader-note-highlight';
            anchor.setAttribute('tabindex', '0');
            anchor.setAttribute('role', 'button');
            anchor.setAttribute('data-note-anchor', '');
            anchor.setAttribute('data-note-ids', group.notes.map((note) => Number(note.id || 0)).filter((id) => id > 0).join(','));

            const contents = result.range.extractContents();
            anchor.appendChild(contents);
            trimAnchorBoundaryWhitespace(anchor);

            result.range.insertNode(anchor);
            offset = result.nextOffset;
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

    function captureSelectionFromReader() {
        if (!readerContent) {
            return;
        }

        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            return;
        }

        const range = selection.getRangeAt(0);
        if (!readerContent.contains(range.commonAncestorContainer)) {
            return;
        }

        const text = sanitizeExcerptText(selection.toString());
        const anchor = extractSelectionAnchor(range);
        setSelectionAnchorData(anchor);
        updateSelectionPreview(text.length > 500 ? text.slice(0, 500).trim() + '...' : text);
    }

    if (readerContent) {
        document.addEventListener('selectionchange', captureSelectionFromReader);
        readerContent.addEventListener('mouseup', captureSelectionFromReader);
        readerContent.addEventListener('keyup', captureSelectionFromReader);
    }

    if (dismissSelectionButton) {
        dismissSelectionButton.addEventListener('click', () => {
            updateSelectionPreview('');
            setSelectionAnchorData(null);

            const selection = window.getSelection();
            if (selection && selection.removeAllRanges) {
                selection.removeAllRanges();
            }

            if (readerContent) {
                readerContent.focus?.();
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
            if (editStateLabel) {
                editStateLabel.textContent = '';
            }
            if (editState) {
                editState.hidden = true;
            }
            updateFormActionState();
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
    const contentRoots = [
        document.querySelector('.reader-view-compact .reader-flow-text')
    ].filter(Boolean);

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
