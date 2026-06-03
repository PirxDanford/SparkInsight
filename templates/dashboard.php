<?php
$title = 'Dashboard';
ob_start();
?>
<div class="hero-panel">
    <div class="welcome-section">
        <h2>Welcome back, <?= htmlspecialchars($user['name'] ?? 'Reviewer', ENT_QUOTES, 'UTF-8') ?>!</h2>
        <p>You are signed in through <?= htmlspecialchars($user['provider'] ?? 'unknown provider', ENT_QUOTES, 'UTF-8') ?>.</p>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Your Session Details</h2>
        </div>

        <dl class="details-list">
            <dt>Name</dt>
            <dd><?= htmlspecialchars($user['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></dd>
            <dt>Email</dt>
            <dd><?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></dd>
            <dt>Provider</dt>
            <dd><?= htmlspecialchars($user['provider'] ?? '', ENT_QUOTES, 'UTF-8') ?></dd>
        </dl>

        <div class="action-group">
            <a class="button secondary" href="/logout">
                <span class="icon">🚪</span>
                Sign out
            </a>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
