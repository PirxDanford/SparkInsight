<?php
$title = 'Home';
$user = $user ?? null; // Make user available in template
ob_start();
?>
<div class="hero-panel">
    <div class="hero-content">
        <div class="logo-placeholder">
            <div class="logo-circle">
                <span class="logo-text">SI</span>
            </div>
            <h1 class="hero-title">SparkInsight</h1>
            <p class="hero-subtitle">Modern Review Workflows</p>
        </div>

        <div class="hero-description">
            <p>Secure OAuth authentication with local development readiness.</p>
            <p>Connect with GitHub or Google for seamless access to collaborative review tools.</p>
        </div>

        <div class="card home-cta-card">
            <div class="card-header">
                <h2><?= ($user ?? null) ? 'Continue Your Review Work' : 'Get Started' ?></h2>
                <p>
                    <?= ($user ?? null)
                        ? 'Go straight to your dashboard and switch into reviewer view as needed.'
                        : 'Sign in to access reviewer, author, and admin experiences.' ?>
                </p>
            </div>

            <div class="action-group home-cta-actions">
                <?php if ($user ?? null): ?>
                    <a class="button" href="/dashboard">Open Dashboard</a>
                <?php else: ?>
                    <a class="button" href="/login">Start with Login</a>
                    <a class="button secondary" href="/signup">Use Invitation Signup</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (is_array($flash_message ?? null) && !empty($flash_message)): ?>
<div class="notification-box" id="flash-message">
    <div class="notification-content">
        <span class="notification-icon">
            <?php if (($flash_message['type'] ?? 'info') === 'error'): ?>❌<?php elseif (($flash_message['type'] ?? 'info') === 'success'): ?>✅<?php else: ?>ℹ️<?php endif; ?>
        </span>
        <span class="notification-text"><?= htmlspecialchars($flash_message['message'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
        <button class="notification-close" onclick="dismissNotification('flash-message')">&times;</button>
    </div>
</div>
<?php endif; ?>

<script>
function dismissNotification(id) {
    const element = document.getElementById(id);
    if (element) {
        element.style.display = 'none';
    }
}
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
