<?php
$title = 'User Management';
$user = $user ?? null;
ob_start();
?>
<div class="page-shell">
    <div class="card">
        <div class="card-header">
            <h2>User Management</h2>
            <p>Manage user accounts, roles, and status from the admin dashboard.</p>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Roles</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($item['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($item['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(implode(', ', $item['roles'] ?? []), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($item['status'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($item['last_login'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <form method="post" action="/admin/users/<?= (int) $item['id'] ?>/status" class="inline-form">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <select name="status">
                                        <option value="active" <?= ($item['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="disabled" <?= ($item['status'] ?? '') === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                                    </select>
                                    <button type="submit" class="button secondary small">Update</button>
                                </form>
                                <form method="post" action="/admin/users/<?= (int) $item['id'] ?>/roles" class="inline-form">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="checkbox-group small">
                                        <?php foreach (['reviewer', 'author', 'admin'] as $roleOption): ?>
                                            <label>
                                                <input type="checkbox" name="roles[]" value="<?= $roleOption ?>" <?= in_array($roleOption, $item['roles'] ?? [], true) ? 'checked' : '' ?>>
                                                <?= ucfirst($roleOption) ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="submit" class="button secondary small">Save roles</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination-info">
            <p>Page <?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?> of <?= htmlspecialchars($totalPages, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($totalUsers, ENT_QUOTES, 'UTF-8') ?> total users)</p>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../layout.php';
?>