<?php
$title = 'Login';
$user = $user ?? null;
$providers = isset($providers) && is_array($providers) ? $providers : [];
ob_start();
?>
<div class="hero-panel">
    <div class="login-shell">
        <section class="login-splash" aria-label="Intro animation">
            <div class="splash-copy">
                <p class="splash-kicker">Welcome to SparkInsight</p>
                <h2>Every great review starts with an open book.</h2>
                <p>Step into a focused reading space where feedback stays clear, calm, and actionable.</p>
            </div>

            <div class="reading-scene" aria-hidden="true">
                <div class="reading-glow"></div>
                <div class="book-shell">
                    <div class="book-spine"></div>
                    <div class="book-page page-left"></div>
                    <div class="book-page page-right"></div>
                    <div class="book-turn turn-one"></div>
                    <div class="book-turn turn-two"></div>
                </div>
                <div class="reader-beam"></div>
            </div>
        </section>

        <section class="card login-auth-card" aria-label="OAuth sign in">
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

            <div class="card-header">
                <h2>Sign In with OAuth</h2>
            </div>

            <div class="provider-buttons">
                <?php if (empty($providers)): ?>
                <div class="notification-box warning">
                    <div class="notification-content">
                        <span class="notification-icon">⚠️</span>
                        <span class="notification-text">
                            No authentication providers are configured. Please contact an administrator.
                        </span>
                    </div>
                </div>
                <?php else: ?>
                <?php foreach ($providers as $provider): ?>
                <a class="button provider-button" href="/auth/<?= htmlspecialchars($provider['key'], ENT_QUOTES, 'UTF-8') ?>">
                    <span class="provider-icon"><?= $provider['name'] === 'GitHub' ? '🐙' : '🔵' ?></span>
                    <span>Continue with <?= htmlspecialchars($provider['label'], ENT_QUOTES, 'UTF-8') ?></span>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="card-footer">
                <p>Don't have an account? <a href="/signup">Sign up</a></p>
                <?php if ($showDemo ?? false): ?>
                <p><a href="/demo">Try demo mode</a></p>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

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
