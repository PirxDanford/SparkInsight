<?php
$title = 'Admin';
$user ??= null;
$adminSection = $admin_section ?? 'users';
$users ??= [];
$usersPage = (int) ($users_page ?? 1);
$usersTotalPages = (float) ($users_total_pages ?? 1);
$usersTotal = (int) ($users_total ?? 0);
$invitations ??= [];
$invitationFilters = $invitation_filters ?? ['email' => '', 'role' => '', 'status' => ''];
$invitationPage = (int) ($invitations_page ?? 1);
$invitationTotalPages = (int) ($invitations_total_pages ?? 1);
$formatRoles = static function (mixed $rawRoles): string {
    if (is_array($rawRoles)) {
        return implode(', ', $rawRoles);
    }

    if (is_string($rawRoles) && $rawRoles !== '') {
        $decoded = json_decode($rawRoles, true);
        if (is_array($decoded)) {
            return implode(', ', $decoded);
        }

        return $rawRoles;
    }

    return '';
};
ob_start();
?>
<div class="page-shell">
    <div class="card">
        <div class="card-header">
            <h2>Admin Dashboard</h2>
            <p>Manage users, invitations, and platform settings in one place.</p>
        </div>
        <div class="action-group" style="margin-top: 12px;">
            <div>
                <a class="button small secondary" href="/dashboard/admin?section=users">Users</a>
                <a class="button small secondary" href="/dashboard/admin?section=invitations">Invitations</a>
                <a class="button small secondary" href="/dashboard/admin?section=settings">Settings</a>
            </div>
        </div>
    </div>

    <div id="admin-users" class="card">
        <div class="card-header">
            <h2>User Management</h2>
            <p>Manage user accounts, roles, and status from the admin dashboard.</p>
        </div>

        <?php if (!empty($users_flash_message ?? null)) { ?>
            <div class="notification-box <?php echo htmlspecialchars((string) ($users_flash_message['type'] ?? 'info'), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="notification-content">
                    <span class="notification-icon"><?php echo (($users_flash_message['type'] ?? 'info') === 'success') ? '✅' : 'ℹ️'; ?></span>
                    <span class="notification-text"><?php echo htmlspecialchars((string) ($users_flash_message['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
        <?php } ?>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Account Name</th>
                        <th>Display Name</th>
                        <th>Email</th>
                        <th>Roles</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $item) { ?>
                        <?php $itemRoles = is_array($item['roles'] ?? null) ? $item['roles'] : []; ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) $item['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($item['display_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($item['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($formatRoles($item['roles'] ?? []), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($item['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($item['last_login'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <form method="post" action="/dashboard/admin/users/<?php echo (int) $item['id']; ?>/status" class="inline-form">
                                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <select name="status">
                                        <option value="active" <?php echo ($item['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="disabled" <?php echo ($item['status'] ?? '') === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                                    </select>
                                    <button type="submit" class="button secondary small">Update</button>
                                </form>
                                <form method="post" action="/dashboard/admin/users/<?php echo (int) $item['id']; ?>/roles" class="inline-form">
                                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="checkbox-group small">
                                        <?php foreach (['reviewer', 'author', 'admin'] as $roleOption) { ?>
                                            <label>
                                                <input type="checkbox" name="roles[]" value="<?php echo $roleOption; ?>" <?php echo in_array($roleOption, $itemRoles, true) ? 'checked' : ''; ?>>
                                                <?php echo ucfirst($roleOption); ?>
                                            </label>
                                        <?php } ?>
                                    </div>
                                    <button type="submit" class="button secondary small">Save roles</button>
                                </form>
                                <form method="post" action="/dashboard/admin/users/<?php echo (int) $item['id']; ?>/display-name" class="inline-form">
                                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input
                                        type="text"
                                        name="display_name"
                                        value="<?php echo htmlspecialchars((string) ($item['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        placeholder="Custom display name"
                                        maxlength="255"
                                    >
                                    <button type="submit" class="button secondary small">Save name</button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="pagination-info">
            <p>Page <?php echo htmlspecialchars((string) $usersPage, ENT_QUOTES, 'UTF-8'); ?> of <?php echo htmlspecialchars((string) $usersTotalPages, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string) $usersTotal, ENT_QUOTES, 'UTF-8'); ?> total users)</p>
        </div>
    </div>

    <div id="admin-invitations" class="card">
        <div class="card-header">
            <h2>Invitation Management</h2>
            <p>Generate and review invitation codes for new users.</p>
            <p class="meta">Total invitations: <?php echo htmlspecialchars((string) ((int) ($total_invitations ?? 0)), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>

        <?php if (!empty($invitation_flash_message ?? null)) { ?>
            <div class="notification-box <?php echo htmlspecialchars((string) ($invitation_flash_message['type'] ?? 'info'), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="notification-content">
                    <span class="notification-icon"><?php echo (($invitation_flash_message['type'] ?? 'info') === 'success') ? '✅' : 'ℹ️'; ?></span>
                    <span class="notification-text"><?php echo htmlspecialchars((string) ($invitation_flash_message['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
        <?php } ?>

        <form method="get" action="/dashboard/admin" class="form-card">
            <input type="hidden" name="section" value="invitations">
            <div class="form-row">
                <label for="filter-email">Filter by email</label>
                <input id="filter-email" name="filter_email" type="email" placeholder="Filter invitations by email" value="<?php echo htmlspecialchars((string) ($invitationFilters['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="filter-role">Filter by role</label>
                <select id="filter-role" name="filter_role">
                    <option value="">Any role</option>
                    <?php foreach (['reviewer', 'author', 'admin'] as $roleOption) { ?>
                        <option value="<?php echo $roleOption; ?>" <?php echo (($invitationFilters['role'] ?? '') === $roleOption) ? 'selected' : ''; ?>><?php echo ucfirst($roleOption); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-row">
                <label for="filter-status">Filter by status</label>
                <select id="filter-status" name="filter_status">
                    <option value="">Any status</option>
                    <?php foreach (['pending', 'used', 'expired'] as $statusOption) { ?>
                        <option value="<?php echo $statusOption; ?>" <?php echo (($invitationFilters['status'] ?? '') === $statusOption) ? 'selected' : ''; ?>><?php echo ucfirst($statusOption); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-row form-actions">
                <button type="submit" class="button">Apply Filters</button>
                <a class="button secondary" href="/dashboard/admin?section=invitations">Reset</a>
            </div>
        </form>

        <form method="post" action="/dashboard/admin/invitations" class="form-card">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-row">
                <label for="invitation-email">Email (optional)</label>
                <input id="invitation-email" name="email" type="email" placeholder="Restrict invitation to a specific email" value="<?php echo htmlspecialchars((string) ($invitation_email ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="form-row">
                <label>Roles</label>
                <div class="checkbox-group">
                    <?php foreach (['reviewer', 'author', 'admin'] as $roleOption) { ?>
                        <label>
                            <input type="checkbox" name="roles[]" value="<?php echo $roleOption; ?>" <?php echo in_array($roleOption, $invitation_roles ?? ['reviewer'], true) ? 'checked' : ''; ?>>
                            <?php echo ucfirst($roleOption); ?>
                        </label>
                    <?php } ?>
                </div>
            </div>

            <div class="form-row">
                <label for="expires-hours">Expires in (hours)</label>
                <input id="expires-hours" name="hours" type="number" min="1" value="<?php echo htmlspecialchars((string) ($invitation_hours ?? 168), ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <button type="submit" class="button">Create Invitation</button>
        </form>

        <?php if (!empty($invitation_code)) { ?>
            <div class="notification-box success">
                <div class="notification-content">
                    <span class="notification-icon">🎉</span>
                    <span class="notification-text">Invitation created: <strong><?php echo htmlspecialchars((string) $invitation_code, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                </div>
            </div>
            <div class="invitation-links">
                <p>Use these links to share the invitation:</p>
                <p><a href="<?php echo htmlspecialchars((string) (($appUrl ?? '') . '/auth/github?code=' . $invitation_code), ENT_QUOTES, 'UTF-8'); ?>">GitHub signup link</a></p>
                <p><a href="<?php echo htmlspecialchars((string) (($appUrl ?? '') . '/auth/google?code=' . $invitation_code), ENT_QUOTES, 'UTF-8'); ?>">Google signup link</a></p>
            </div>
        <?php } ?>

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
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invitations as $invitation) { ?>
                        <?php $invitationRoles = $invitation['roles'] ?? []; ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) $invitation['code'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($invitation['email'] ?? 'Any'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($formatRoles($invitationRoles), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ucfirst($invitation['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($invitation['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($invitation['expires_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($invitation['used_by_display'] ?? ($invitation['used_by'] ?? '—')), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($invitation['used_at'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <form method="post" action="/dashboard/admin/invitations/<?php echo htmlspecialchars((string) $invitation['code'], ENT_QUOTES, 'UTF-8'); ?>/delete" class="inline-form">
                                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="button secondary small">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <?php
        $paginationParams = array_filter([
            'section' => 'invitations',
            'filter_email' => $invitationFilters['email'] ?? '',
            'filter_role' => $invitationFilters['role'] ?? '',
            'filter_status' => $invitationFilters['status'] ?? '',
        ], static fn ($value) => $value !== '');
$baseQuery = http_build_query($paginationParams);
$queryPrefix = $baseQuery !== '' ? '/dashboard/admin?' . $baseQuery . '&' : '/dashboard/admin?';
?>

        <?php if ($invitationTotalPages > 1) { ?>
            <div class="pagination-controls">
                <?php if ($invitationPage > 1) { ?>
                    <a class="button secondary" href="<?php echo htmlspecialchars($queryPrefix . 'invitations_page=' . ($invitationPage - 1), ENT_QUOTES, 'UTF-8'); ?>">Previous</a>
                <?php } ?>
                <span>Page <?php echo htmlspecialchars((string) $invitationPage, ENT_QUOTES, 'UTF-8'); ?> of <?php echo htmlspecialchars((string) $invitationTotalPages, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($invitationPage < $invitationTotalPages) { ?>
                    <a class="button secondary" href="<?php echo htmlspecialchars($queryPrefix . 'invitations_page=' . ($invitationPage + 1), ENT_QUOTES, 'UTF-8'); ?>">Next</a>
                <?php } ?>
            </div>
        <?php } ?>
    </div>

    <div id="admin-settings" class="card">
        <div class="card-header">
            <h2>Admin Settings</h2>
            <p>Configure defaults used by invitation generation and automation snippets.</p>
        </div>

        <?php if (!empty($settings_flash_message ?? null)) { ?>
            <div class="notification-box <?php echo htmlspecialchars((string) ($settings_flash_message['type'] ?? 'info'), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="notification-content">
                    <span class="notification-icon"><?php echo (($settings_flash_message['type'] ?? 'info') === 'success') ? '✅' : 'ℹ️'; ?></span>
                    <span class="notification-text"><?php echo htmlspecialchars((string) ($settings_flash_message['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
        <?php } ?>

        <form method="post" action="/dashboard/admin/settings" class="form-card">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-row">
                <label for="invitation-default-hours">Default invitation validity (hours)</label>
                <input
                    id="invitation-default-hours"
                    name="invitation_default_hours"
                    type="number"
                    min="1"
                    max="720"
                    value="<?php echo htmlspecialchars((string) ($settings['invitation_default_hours'] ?? 168), ENT_QUOTES, 'UTF-8'); ?>"
                >
                <small>Recommended default is 168 hours (7 days).</small>
            </div>

            <div class="form-row">
                <label>Default invitation roles</label>
                <div class="checkbox-group">
                    <?php foreach (['reviewer', 'author', 'admin'] as $roleOption) { ?>
                        <label>
                            <input
                                type="checkbox"
                                name="invitation_default_roles[]"
                                value="<?php echo $roleOption; ?>"
                                <?php echo in_array($roleOption, (array) ($settings['invitation_default_roles'] ?? ['reviewer']), true) ? 'checked' : ''; ?>
                            >
                            <?php echo ucfirst($roleOption); ?>
                        </label>
                    <?php } ?>
                </div>
            </div>

            <div class="form-row">
                <label for="import-cron-schedule">Daily import cron schedule</label>
                <input
                    id="import-cron-schedule"
                    name="import_cron_schedule"
                    type="text"
                    value="<?php echo htmlspecialchars((string) ($settings['import_cron_schedule'] ?? '0 2 * * *'), ENT_QUOTES, 'UTF-8'); ?>"
                >
            </div>

            <div class="form-row">
                <label for="invitation-cleanup-cron-schedule">Expired invitation cleanup cron schedule</label>
                <input
                    id="invitation-cleanup-cron-schedule"
                    name="invitation_cleanup_cron_schedule"
                    type="text"
                    value="<?php echo htmlspecialchars((string) ($settings['invitation_cleanup_cron_schedule'] ?? '30 2 * * *'), ENT_QUOTES, 'UTF-8'); ?>"
                >
            </div>

            <button type="submit" class="button">Save Settings</button>
        </form>

        <div class="form-card">
            <div class="form-row">
                <label for="cron-import">Daily Scrivener import</label>
                <textarea id="cron-import" rows="3" readonly><?php echo htmlspecialchars((string) (($cron_snippets ?? [])['import'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div class="form-row">
                <label for="cron-cleanup">Expired invitation cleanup</label>
                <textarea id="cron-cleanup" rows="3" readonly><?php echo htmlspecialchars((string) (($cron_snippets ?? [])['invitation_cleanup'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
        </div>

        <?php $maintenanceCommands = isset($maintenance_once_cron_examples) && is_array($maintenance_once_cron_examples) ? $maintenance_once_cron_examples : []; ?>
        <?php if (!empty($maintenanceCommands)) { ?>
            <div class="form-card">
                <div class="form-row">
                    <h3>One-time Cronjob Examples</h3>
                    <p>Use these examples in hosting cron panels for one-time maintenance runs, then remove the entries.</p>
                </div>

                <?php foreach ($maintenanceCommands as $index => $command) { ?>
                    <div class="form-row">
                        <label for="maintenance-command-<?php echo (int) $index; ?>"><?php echo htmlspecialchars((string) ($command['label'] ?? 'Maintenance command'), ENT_QUOTES, 'UTF-8'); ?></label>
                        <small><?php echo htmlspecialchars((string) ($command['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small>
                        <textarea id="maintenance-command-<?php echo (int) $index; ?>" rows="2" readonly><?php echo htmlspecialchars((string) ($command['command'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../layout.php';
