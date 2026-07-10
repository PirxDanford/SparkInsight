<?php
$title = 'Admin Settings';
$user = $user ?? null;
$settings = $settings ?? [];
ob_start();
?>
<div class="page-shell">
    <div class="card">
        <div class="card-header">
            <h2>Admin Settings</h2>
            <p>Configure defaults used by invitation generation and automation snippets.</p>
        </div>

        <?php if (!empty($flash_message ?? null)): ?>
            <div class="notification-box <?= htmlspecialchars($flash_message['type'] ?? 'info', ENT_QUOTES, 'UTF-8') ?>">
                <div class="notification-content">
                    <span class="notification-icon"><?= ($flash_message['type'] ?? 'info') === 'success' ? '✅' : 'ℹ️' ?></span>
                    <span class="notification-text"><?= htmlspecialchars($flash_message['message'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" action="/admin/settings" class="form-card">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-row">
                <label for="invitation-default-hours">Default invitation validity (hours)</label>
                <input
                    id="invitation-default-hours"
                    name="invitation_default_hours"
                    type="number"
                    min="1"
                    max="720"
                    value="<?= htmlspecialchars((string) ($settings['invitation_default_hours'] ?? 168), ENT_QUOTES, 'UTF-8') ?>"
                >
                <small>Recommended default is 168 hours (7 days).</small>
            </div>

            <div class="form-row">
                <label>Default invitation roles</label>
                <div class="checkbox-group">
                    <?php foreach (['reviewer', 'author', 'admin'] as $roleOption): ?>
                        <label>
                            <input
                                type="checkbox"
                                name="invitation_default_roles[]"
                                value="<?= $roleOption ?>"
                                <?= in_array($roleOption, (array) ($settings['invitation_default_roles'] ?? ['reviewer']), true) ? 'checked' : '' ?>
                            >
                            <?= ucfirst($roleOption) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-row">
                <label for="import-cron-schedule">Daily import cron schedule</label>
                <input
                    id="import-cron-schedule"
                    name="import_cron_schedule"
                    type="text"
                    value="<?= htmlspecialchars((string) ($settings['import_cron_schedule'] ?? '0 2 * * *'), ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>

            <div class="form-row">
                <label for="invitation-cleanup-cron-schedule">Expired invitation cleanup cron schedule</label>
                <input
                    id="invitation-cleanup-cron-schedule"
                    name="invitation_cleanup_cron_schedule"
                    type="text"
                    value="<?= htmlspecialchars((string) ($settings['invitation_cleanup_cron_schedule'] ?? '30 2 * * *'), ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>

            <button type="submit" class="button">Save Settings</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Cronjob Snippets</h3>
            <p>Use these examples for Linux cron (adjust paths and parameters for your environment).</p>
        </div>

        <div class="form-card">
            <div class="form-row">
                <label for="cron-import">Daily Scrivener import</label>
                <textarea id="cron-import" rows="3" readonly><?= htmlspecialchars((string) (($cron_snippets ?? [])['import'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div class="form-row">
                <label for="cron-cleanup">Expired invitation cleanup</label>
                <textarea id="cron-cleanup" rows="3" readonly><?= htmlspecialchars((string) (($cron_snippets ?? [])['invitation_cleanup'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../layout.php';
