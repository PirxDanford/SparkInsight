<?php
$title = 'Login';
$user ??= null;
$providers = isset($providers) && is_array($providers) ? $providers : [];
require_once __DIR__ . '/partials/logo-system.php';
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
                <?php renderSparkInsightLogo('hero'); ?>
                <div class="reader-beam"></div>
            </div>
        </section>

        <section class="card login-auth-card" aria-label="OAuth sign in">
            <?php if (is_array($flash_message ?? null) && !empty($flash_message)) { ?>
            <div class="notification-box" id="flash-message">
                <div class="notification-content">
                    <span class="notification-icon">
                        <?php if (($flash_message['type'] ?? 'info') === 'error') { ?>❌<?php } elseif (($flash_message['type'] ?? 'info') === 'success') { ?>✅<?php } else { ?>ℹ️<?php } ?>
                    </span>
                    <span class="notification-text"><?php echo htmlspecialchars($flash_message['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                    <button class="notification-close" onclick="dismissNotification('flash-message')">&times;</button>
                </div>
            </div>
            <?php } ?>

            <div class="card-header">
                <h2>Sign In with OAuth</h2>
            </div>

            <div class="provider-buttons">
                <?php if (empty($providers)) { ?>
                <div class="notification-box warning">
                    <div class="notification-content">
                        <span class="notification-icon">⚠️</span>
                        <span class="notification-text">
                            No authentication providers are configured. Please contact an administrator.
                        </span>
                    </div>
                </div>
                <?php } else { ?>
                <?php foreach ($providers as $provider) { ?>
                <?php
                $providerKey = mb_strtolower((string) ($provider['key'] ?? ''));
                    $providerIconText = match ($providerKey) {
                        'github' => 'GH',
                        'google' => 'G',
                        'linkedin' => 'in',
                        default => mb_strtoupper(mb_substr($providerKey, 0, 1)),
                    };
                    $providerIconClass = in_array($providerKey, ['github', 'google', 'linkedin'], true)
                        ? 'provider-icon--' . $providerKey
                        : 'provider-icon--default';
                    ?>
                <a class="button provider-button" href="/auth/<?php echo htmlspecialchars($provider['key'], ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="provider-icon <?php echo htmlspecialchars($providerIconClass, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"><?php echo htmlspecialchars($providerIconText, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span>Continue with <?php echo htmlspecialchars($provider['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                </a>
                <?php } ?>
                <?php } ?>
            </div>

            <div class="card-footer">
                <p>Don't have an account? <a href="/signup">Sign up</a></p>
                <?php if ($showDemo ?? false) { ?>
                <p><a href="/demo">Try demo mode</a></p>
                <?php } ?>
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
