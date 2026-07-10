<?php
$title = 'Invitations';
$user = $user ?? null;
ob_start();
?>
<div class="page-shell">
    <div class="card">
        <div class="card-header">
            <h2>Invitation Management</h2>
            <p>Generate and review invitation codes for new users.</p>
            <p class="meta">Total invitations: <?= htmlspecialchars((int) ($totalInvitations ?? 0), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <?php if (!empty($flash_message ?? null)): ?>
            <div class="notification-box <?= htmlspecialchars($flash_message['type'] ?? 'info', ENT_QUOTES, 'UTF-8') ?>">
                <div class="notification-content">
                    <span class="notification-icon"><?= ($flash_message['type'] ?? 'info') === 'success' ? '✅' : 'ℹ️' ?></span>
                    <span class="notification-text"><?= htmlspecialchars($flash_message['message'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        <?php endif; ?>

        <form method="get" action="/admin/invitations" class="form-card">
            <div class="form-row">
                <label for="filter-email">Filter by email</label>
                <input id="filter-email" name="filter_email" type="email" placeholder="Filter invitations by email" value="<?= htmlspecialchars($filter_email ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-row">
                <label for="filter-role">Filter by role</label>
                <select id="filter-role" name="filter_role">
                    <option value="">Any role</option>
                    <?php foreach (['reviewer', 'author', 'admin'] as $roleOption): ?>
                        <option value="<?= $roleOption ?>" <?= ($filter_role ?? '') === $roleOption ? 'selected' : '' ?>><?= ucfirst($roleOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="filter-status">Filter by status</label>
                <select id="filter-status" name="filter_status">
                    <option value="">Any status</option>
                    <?php foreach (['pending', 'used', 'expired'] as $statusOption): ?>
                        <option value="<?= $statusOption ?>" <?= ($filter_status ?? '') === $statusOption ? 'selected' : '' ?>><?= ucfirst($statusOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row form-actions">
                <button type="submit" class="button">Apply Filters</button>
                <a class="button secondary" href="/admin/invitations">Reset</a>
            </div>
        </form>

        <form method="post" action="/admin/invitations" class="form-card">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-row">
                <label for="invitation-email">Email (optional)</label>
                <input id="invitation-email" name="email" type="email" placeholder="Restrict invitation to a specific email" value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-row">
                <label>Roles</label>
                <div class="checkbox-group">
                    <?php foreach (['reviewer', 'author', 'admin'] as $roleOption): ?>
                        <label>
                            <input type="checkbox" name="roles[]" value="<?= $roleOption ?>" <?= in_array($roleOption, $roles ?? ['reviewer']) ? 'checked' : '' ?>>
                            <?= ucfirst($roleOption) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-row">
                <label for="expires-hours">Expires in (hours)</label>
                <input id="expires-hours" name="hours" type="number" min="1" value="<?= htmlspecialchars($hours ?? 168, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <button type="submit" class="button">Create Invitation</button>
        </form>
    </div>

    <?php if (!empty($invitationCode)): ?>
        <div class="card">
            <div class="card-header">
                <h3>Recently created invitation</h3>
            </div>
            <div class="notification-box success">
                <div class="notification-content">
                    <span class="notification-icon">🎉</span>
                    <span class="notification-text">Invitation created: <strong><?= htmlspecialchars($invitationCode, ENT_QUOTES, 'UTF-8') ?></strong></span>
                </div>
            </div>
            <div class="invitation-links">
                <p>Use these links to share the invitation:</p>
                <p><a href="<?= htmlspecialchars($appUrl . '/auth/github?code=' . $invitationCode, ENT_QUOTES, 'UTF-8') ?>">GitHub signup link</a></p>
                <p><a href="<?= htmlspecialchars($appUrl . '/auth/google?code=' . $invitationCode, ENT_QUOTES, 'UTF-8') ?>">Google signup link</a></p>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3>Invitations</h3>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Email</th>
                        <th>Roles</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th>Expires</th>
                        <th>Used By</th>
                        <th>Used At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invitations as $invitation): ?>
                        <tr>
                            <td><?= htmlspecialchars($invitation['code'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($invitation['email'] ?? 'Any', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(implode(', ', $invitation['roles']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(ucfirst($invitation['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($invitation['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($invitation['expires_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($invitation['used_by_display'] ?? ($invitation['used_by'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($invitation['used_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    $paginationParams = array_filter([
        'filter_email' => $filter_email ?? '',
        'filter_role' => $filter_role ?? '',
        'filter_status' => $filter_status ?? '',
    ], static fn ($value) => $value !== '');
    $baseQuery = http_build_query($paginationParams);
    $queryPrefix = $baseQuery !== '' ? '/admin/invitations?' . $baseQuery . '&' : '/admin/invitations?';
    $page = isset($page) ? (int) $page : 1;
    $totalPages = isset($totalPages) ? (int) $totalPages : 1;
    ?>

    <?php if ($totalPages > 1): ?>
        <div class="pagination-controls">
            <?php if ($page > 1): ?>
                <a class="button secondary" href="<?= htmlspecialchars($queryPrefix . 'page=' . ($page - 1), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
            <?php endif; ?>
            <span>Page <?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?> of <?= htmlspecialchars($totalPages, ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($page < $totalPages): ?>
                <a class="button secondary" href="<?= htmlspecialchars($queryPrefix . 'page=' . ($page + 1), ENT_QUOTES, 'UTF-8') ?>">Next</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../layout.php';
?>