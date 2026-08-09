<?php
$title = 'Admin';
$user ??= null;
$adminSection = $admin_section ?? 'users';
$users ??= [];
$usersPage = (int) ($users_page ?? 1);
$usersTotalPages = (float) ($users_total_pages ?? 1);
$usersTotal = (int) ($users_total ?? 0);
$usersFilters = $users_filters ?? ['status' => '', 'role' => '', 'search' => ''];
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
<?php $releasePackageResult = isset($release_package_result) && is_array($release_package_result) ? $release_package_result : []; ?>
<?php $releaseDeployResult = isset($release_deploy_result) && is_array($release_deploy_result) ? $release_deploy_result : []; ?>

<div class="page-shell admin-console">
    <details id="admin-users" class="admin-accordion card" open>
        <summary class="admin-accordion-summary">
            <span>
                <h2>User Management</h2>
                <p>Compact filters and single-line user actions.</p>
            </span>
            <span class="admin-accordion-toggle" aria-hidden="true">Toggle</span>
        </summary>

        <?php if (!empty($users_flash_message ?? null)) { ?>
            <div class="notification-box <?php echo htmlspecialchars((string) ($users_flash_message['type'] ?? 'info'), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="notification-content">
                    <span class="notification-text"><?php echo htmlspecialchars((string) ($users_flash_message['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
        <?php } ?>

        <form method="get" action="/dashboard/admin" class="form-card admin-filter-card admin-inline-filter">
            <input type="hidden" name="section" value="users">
            <div class="admin-inline-fields">
                <label class="sr-only" for="users-search">Search user</label>
                <input id="users-search" name="search" type="text" placeholder="Search name or email" value="<?php echo htmlspecialchars((string) ($usersFilters['search'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label class="sr-only" for="users-status">Status</label>
                <select id="users-status" name="status">
                    <option value="">Status: Any</option>
                    <option value="active" <?php echo (($usersFilters['status'] ?? '') === 'active') ? 'selected' : ''; ?>>Status: Active</option>
                    <option value="disabled" <?php echo (($usersFilters['status'] ?? '') === 'disabled') ? 'selected' : ''; ?>>Status: Disabled</option>
                </select>

                <label class="sr-only" for="users-role">Role</label>
                <select id="users-role" name="role">
                    <option value="">Role: Any</option>
                    <?php foreach (['reviewer', 'author', 'admin'] as $roleOption) { ?>
                        <option value="<?php echo $roleOption; ?>" <?php echo (($usersFilters['role'] ?? '') === $roleOption) ? 'selected' : ''; ?>>Role: <?php echo ucfirst($roleOption); ?></option>
                    <?php } ?>
                </select>

                <button type="submit" class="button">Apply User Filter</button>
                <a class="button secondary" href="/dashboard/admin?section=users">Reset</a>
            </div>
        </form>

        <div class="table-responsive admin-table-wrap">
            <table class="data-table admin-data-table admin-user-table">
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
                            <td class="nowrap"><?php echo htmlspecialchars((string) $item['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($item['display_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="admin-email-cell"><?php echo htmlspecialchars((string) ($item['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($formatRoles($item['roles'] ?? []), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="nowrap"><?php echo htmlspecialchars((string) ($item['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="nowrap"><?php echo htmlspecialchars((string) ($item['last_login'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="admin-actions-cell">
                                <div class="admin-user-action-row">
                                    <form method="post" action="/dashboard/admin/users/<?php echo (int) $item['id']; ?>/status" class="inline-form admin-inline-form-row">
                                        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <label class="sr-only" for="user-status-<?php echo (int) $item['id']; ?>">Status</label>
                                        <select id="user-status-<?php echo (int) $item['id']; ?>" name="status">
                                            <option value="active" <?php echo ($item['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="disabled" <?php echo ($item['status'] ?? '') === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                                        </select>
                                        <button type="submit" class="button secondary small">Save</button>
                                    </form>

                                    <form method="post" action="/dashboard/admin/users/<?php echo (int) $item['id']; ?>/roles" class="inline-form admin-inline-form-row">
                                        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="checkbox-group small admin-role-checks admin-role-checks-tight">
                                            <?php foreach (['reviewer', 'author', 'admin'] as $roleOption) { ?>
                                                <label>
                                                    <input type="checkbox" name="roles[]" value="<?php echo $roleOption; ?>" <?php echo in_array($roleOption, $itemRoles, true) ? 'checked' : ''; ?>>
                                                    <?php echo ucfirst($roleOption); ?>
                                                </label>
                                            <?php } ?>
                                        </div>
                                        <button type="submit" class="button secondary small">Roles</button>
                                    </form>

                                    <form method="post" action="/dashboard/admin/users/<?php echo (int) $item['id']; ?>/display-name" class="inline-form admin-inline-form-row">
                                        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input
                                            type="text"
                                            name="display_name"
                                            value="<?php echo htmlspecialchars((string) ($item['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            placeholder="Display name"
                                            maxlength="255"
                                        >
                                        <button type="submit" class="button secondary small">Name</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="pagination-info">
            <p>Page <?php echo htmlspecialchars((string) $usersPage, ENT_QUOTES, 'UTF-8'); ?> of <?php echo htmlspecialchars((string) $usersTotalPages, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string) $usersTotal, ENT_QUOTES, 'UTF-8'); ?> total users)</p>
        </div>
    </details>

    <details id="admin-invitations" class="admin-accordion card" open>
        <summary class="admin-accordion-summary">
            <span>
                <h2>Invitation Management</h2>
                <p>Single-line create and filter controls.</p>
            </span>
            <span class="admin-accordion-toggle" aria-hidden="true">Toggle</span>
        </summary>

        <?php if (!empty($invitation_flash_message ?? null)) { ?>
            <div class="notification-box <?php echo htmlspecialchars((string) ($invitation_flash_message['type'] ?? 'info'), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="notification-content">
                    <span class="notification-text"><?php echo htmlspecialchars((string) ($invitation_flash_message['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
        <?php } ?>

        <form method="post" action="/dashboard/admin/invitations" class="form-card admin-hero-form admin-inline-filter">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="admin-inline-fields">
                <label class="sr-only" for="invitation-email">Email (optional)</label>
                <input id="invitation-email" name="email" type="email" placeholder="Email restriction (optional)" value="<?php echo htmlspecialchars((string) ($invitation_email ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label class="sr-only" for="expires-hours">Expires in hours</label>
                <input id="expires-hours" name="hours" type="number" min="1" value="<?php echo htmlspecialchars((string) ($invitation_hours ?? 168), ENT_QUOTES, 'UTF-8'); ?>">

                <div class="checkbox-group admin-role-checks admin-role-checks-tight">
                    <?php foreach (['reviewer', 'author', 'admin'] as $roleOption) { ?>
                        <label>
                            <input type="checkbox" name="roles[]" value="<?php echo $roleOption; ?>" <?php echo in_array($roleOption, $invitation_roles ?? ['reviewer'], true) ? 'checked' : ''; ?>>
                            <?php echo ucfirst($roleOption); ?>
                        </label>
                    <?php } ?>
                </div>

                <button type="submit" class="button">Create Invitation</button>
            </div>
        </form>

        <form method="get" action="/dashboard/admin" class="form-card admin-filter-card admin-filter-card-invites admin-inline-filter">
            <input type="hidden" name="section" value="invitations">
            <div class="admin-inline-fields">
                <label class="sr-only" for="filter-email">Email filter</label>
                <input id="filter-email" name="filter_email" type="email" placeholder="Filter email" value="<?php echo htmlspecialchars((string) ($invitationFilters['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label class="sr-only" for="filter-role">Role filter</label>
                <select id="filter-role" name="filter_role">
                    <option value="">Role: Any</option>
                    <?php foreach (['reviewer', 'author', 'admin'] as $roleOption) { ?>
                        <option value="<?php echo $roleOption; ?>" <?php echo (($invitationFilters['role'] ?? '') === $roleOption) ? 'selected' : ''; ?>>Role: <?php echo ucfirst($roleOption); ?></option>
                    <?php } ?>
                </select>

                <label class="sr-only" for="filter-status">Status filter</label>
                <select id="filter-status" name="filter_status">
                    <option value="">Status: Any</option>
                    <?php foreach (['pending', 'used', 'expired'] as $statusOption) { ?>
                        <option value="<?php echo $statusOption; ?>" <?php echo (($invitationFilters['status'] ?? '') === $statusOption) ? 'selected' : ''; ?>>Status: <?php echo ucfirst($statusOption); ?></option>
                    <?php } ?>
                </select>

                <button type="submit" class="button secondary">Apply Filter</button>
                <a class="button secondary" href="/dashboard/admin?section=invitations">Reset</a>
            </div>
        </form>

        <?php if (!empty($invitation_code)) { ?>
            <div class="notification-box success">
                <div class="notification-content">
                    <span class="notification-text">Invitation created: <strong><?php echo htmlspecialchars((string) $invitation_code, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                </div>
            </div>
            <div class="invitation-links">
                <p>Use these links to share the invitation:</p>
                <p><a href="<?php echo htmlspecialchars((string) (($appUrl ?? '') . '/auth/github?code=' . $invitation_code), ENT_QUOTES, 'UTF-8'); ?>">GitHub signup link</a></p>
                <p><a href="<?php echo htmlspecialchars((string) (($appUrl ?? '') . '/auth/google?code=' . $invitation_code), ENT_QUOTES, 'UTF-8'); ?>">Google signup link</a></p>
            </div>
        <?php } ?>

        <div class="table-responsive admin-table-wrap">
            <table class="data-table admin-data-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Email</th>
                        <th>Roles</th>
                        <th>Status</th>
                        <th>Created</th>
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
                            <td class="nowrap"><?php echo htmlspecialchars((string) $invitation['code'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($invitation['email'] ?? 'Any'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($formatRoles($invitationRoles), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="nowrap"><?php echo htmlspecialchars((string) ucfirst($invitation['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="nowrap"><?php echo htmlspecialchars((string) ($invitation['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="nowrap"><?php echo htmlspecialchars((string) ($invitation['expires_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($invitation['used_by_display'] ?? ($invitation['used_by'] ?? '-')), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="nowrap"><?php echo htmlspecialchars((string) ($invitation['used_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="nowrap">
                                <?php $isUsedInvitation = (($invitation['status'] ?? 'pending') === 'used'); ?>
                                <form method="post" action="/dashboard/admin/invitations/<?php echo htmlspecialchars((string) $invitation['code'], ENT_QUOTES, 'UTF-8'); ?>/delete" class="inline-form admin-inline-form-row">
                                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="button secondary small" <?php echo $isUsedInvitation ? 'disabled aria-disabled="true" title="Used invitations cannot be deleted"' : ''; ?>>Delete</button>
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
    </details>

    <details id="admin-settings" class="admin-accordion card" open>
        <summary class="admin-accordion-summary">
            <span>
                <h2>Admin Settings</h2>
                <p>Invitation defaults only.</p>
            </span>
            <span class="admin-accordion-toggle" aria-hidden="true">Toggle</span>
        </summary>

        <?php if (!empty($settings_flash_message ?? null)) { ?>
            <div class="notification-box <?php echo htmlspecialchars((string) ($settings_flash_message['type'] ?? 'info'), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="notification-content">
                    <span class="notification-text"><?php echo htmlspecialchars((string) ($settings_flash_message['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
        <?php } ?>

        <form method="post" action="/dashboard/admin/settings" class="form-card">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="admin-grid admin-grid-two">
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
                    <small>Recommended: 168 hours (7 days)</small>
                </div>

                <div class="form-row">
                    <label>Default invitation roles</label>
                    <div class="checkbox-group admin-role-checks">
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
            </div>

            <div class="form-row form-actions admin-action-row">
                <button type="submit" class="button">Save Settings</button>
            </div>
        </form>
    </details>

    <details id="admin-actions" class="admin-accordion card" open>
        <summary class="admin-accordion-summary">
            <span>
                <h2>Admin Actions</h2>
                <p>Operational tools, release intake, and diagnostics.</p>
            </span>
            <span class="admin-accordion-toggle" aria-hidden="true">Toggle</span>
        </summary>

        <?php if (!empty($actions_flash_message ?? null)) { ?>
            <div class="notification-box <?php echo htmlspecialchars((string) ($actions_flash_message['type'] ?? 'info'), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="notification-content">
                    <span class="notification-text"><?php echo htmlspecialchars((string) ($actions_flash_message['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
        <?php } ?>

        <?php if (!empty($maintenance_action_result ?? null)) { ?>
                <div class="form-row admin-output-row">
                <label for="maintenance-action-output">Last action output</label>
                <small>
                    <?php echo htmlspecialchars((string) ($maintenance_action_result['label'] ?? 'Action'), ENT_QUOTES, 'UTF-8'); ?>
                    (exit code: <?php echo htmlspecialchars((string) ($maintenance_action_result['exit_code'] ?? 1), ENT_QUOTES, 'UTF-8'); ?>)
                </small>
                    <textarea id="maintenance-action-output" class="admin-output-box" rows="9" readonly><?php echo htmlspecialchars((string) ($maintenance_action_result['output'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
        <?php } ?>

        <div class="form-card admin-package-card">
            <div class="form-row">
                <h3>Release Package Intake</h3>
                <p>Upload a signed release ZIP. SparkInsight verifies and deploys it automatically.</p>
            </div>

            <form method="post" action="/dashboard/admin/packages/export-live-manifest" class="form-row admin-upload-row">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="button secondary">Download Current Live Manifest</button>
                <small>Use this JSON as <code>--base-package</code> for patch builds when you want to rebase on what is currently live.</small>
            </form>

            <form id="admin-package-upload-form" method="post" action="/dashboard/admin/packages" enctype="multipart/form-data" class="form-row admin-upload-row">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input id="admin-package-upload-file" type="file" name="release_package" accept=".zip,application/zip" required>
                <button id="admin-package-upload-submit" type="submit" class="button">Upload Package</button>
                <div id="admin-package-upload-progress" class="progress" aria-hidden="true" style="display:none;height:10px;background:#e9eef7;border-radius:999px;overflow:hidden;width:100%;max-width:460px;">
                    <span id="admin-package-upload-progress-bar" style="display:block;height:100%;width:0;background:#2b6cb0;transition:width .2s ease;"></span>
                </div>
                <div id="admin-package-upload-status" class="status" aria-live="polite" style="display:none;color:#334155;font-size:.95rem;"></div>
            </form>

            <?php if (!empty($releasePackageResult)) { ?>
                <div class="form-row admin-output-row">
                    <label for="release-package-output">Last package result</label>
                    <small>
                        <?php if (!empty($releasePackageResult['error'] ?? null)) { ?>
                            Upload error: <?php echo htmlspecialchars((string) ($releasePackageResult['error'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        <?php } else { ?>
                            Package <?php echo htmlspecialchars((string) ($releasePackageResult['package_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            for release <?php echo htmlspecialchars((string) ($releasePackageResult['release_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            stored as <?php echo htmlspecialchars((string) ($releasePackageResult['operation_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        <?php } ?>
                    </small>
                    <textarea id="release-package-output" class="admin-output-box" rows="8" readonly><?php echo htmlspecialchars(json_encode($releasePackageResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
            <?php } ?>

            <?php if (!empty($releaseDeployResult)) { ?>
                <div class="form-row admin-output-row">
                    <label for="release-deploy-output">Last deployment result</label>
                    <small>
                        Operation <?php echo htmlspecialchars((string) ($releaseDeployResult['operation_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        (exit code: <?php echo htmlspecialchars((string) ($releaseDeployResult['exit_code'] ?? 1), ENT_QUOTES, 'UTF-8'); ?>)
                    </small>
                    <textarea id="release-deploy-output" class="admin-output-box" rows="10" readonly><?php echo htmlspecialchars((string) ($releaseDeployResult['output'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
            <?php } ?>
        </div>

        <div class="form-card admin-actions-card">
            <div class="form-row">
                <h3>Maintenance Actions</h3>
                <p>Run one-shot maintenance operations.</p>
            </div>

            <form method="post" action="/dashboard/admin/maintenance" class="admin-inline-filter">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="maintenance_action" value="db_migrate_status">
                <div class="admin-inline-fields">
                    <span class="admin-action-label">Migration status</span>
                    <button type="submit" class="button secondary">Run</button>
                </div>
            </form>

            <form method="post" action="/dashboard/admin/maintenance" class="admin-inline-filter">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="maintenance_action" value="db_migrate">
                <div class="admin-inline-fields">
                    <span class="admin-action-label">Apply migrations</span>
                    <button type="submit" class="button secondary">Run</button>
                </div>
            </form>

            <form method="post" action="/dashboard/admin/maintenance" class="admin-inline-filter">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="maintenance_action" value="check_environment">
                <div class="admin-inline-fields">
                    <span class="admin-action-label">Environment check</span>
                    <button type="submit" class="button secondary">Run</button>
                </div>
            </form>

            <form method="post" action="/dashboard/admin/maintenance" class="admin-inline-filter">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="maintenance_action" value="invite_purge_expired">
                <div class="admin-inline-fields">
                    <span class="admin-action-label">Purge expired invitations</span>
                    <button type="submit" class="button secondary">Run</button>
                </div>
            </form>

            <form method="post" action="/dashboard/admin/maintenance" class="admin-inline-filter">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="maintenance_action" value="content_list_imports">
                <div class="admin-inline-fields">
                    <span class="admin-action-label">List import batches</span>
                    <button type="submit" class="button secondary">Run</button>
                </div>
            </form>
        </div>
    </details>
</div>
<script>
(function () {
    var form = document.getElementById('admin-package-upload-form');
    if (!form) {
        return;
    }

    var fileInput = document.getElementById('admin-package-upload-file');
    var submitButton = document.getElementById('admin-package-upload-submit');
    var progress = document.getElementById('admin-package-upload-progress');
    var progressBar = document.getElementById('admin-package-upload-progress-bar');
    var status = document.getElementById('admin-package-upload-status');
    var completed = false;

    function setStatus(text) {
        if (!status) {
            return;
        }

        status.style.display = 'block';
        status.textContent = text;
    }

    function setProgress(percent) {
        if (!progress || !progressBar) {
            return;
        }

        progress.style.display = 'block';
        progressBar.style.width = String(percent) + '%';
    }

    form.addEventListener('submit', function (event) {
        if (completed) {
            return;
        }

        event.preventDefault();

        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            setStatus('Select a release package ZIP first.');
            return;
        }

        if (submitButton) {
            submitButton.disabled = true;
        }

        setStatus('Uploading package...');
        setProgress(1);

        var formData = new FormData(form);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', form.action, true);

        xhr.upload.addEventListener('progress', function (uploadEvent) {
            if (!uploadEvent.lengthComputable) {
                return;
            }

            var percent = Math.max(1, Math.min(99, Math.round((uploadEvent.loaded / uploadEvent.total) * 100)));
            setProgress(percent);
            setStatus('Uploading package... ' + String(percent) + '%');
        });

        xhr.addEventListener('load', function () {
            setProgress(100);
            setStatus('Upload complete. Verifying and deploying package...');
            document.open();
            document.write(xhr.responseText);
            document.close();
            completed = true;
        });

        xhr.addEventListener('error', function () {
            if (submitButton) {
                submitButton.disabled = false;
            }
            setStatus('Upload failed. Check connection and try again.');
        });

        xhr.send(formData);
    });
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../layout.php';
