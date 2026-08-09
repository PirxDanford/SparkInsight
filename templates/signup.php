<?php
$title = 'Sign Up';
$user ??= null;
require_once __DIR__ . '/partials/logo-system.php';
ob_start();
?>
<div class="hero-panel">
    <div class="hero-content">
        <div class="signup-brand">
            <?php renderSparkInsightLogo('brand', false); ?>
            <h1 class="hero-title">Sign Up</h1>
            <p class="hero-subtitle">Join SparkInsight</p>
        </div>

        <div class="hero-description">
            <p>Create your account to start collaborating on reviews.</p>
            <p>You'll need an invitation code to sign up.</p>
        </div>
    </div>
</div>

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

<div class="card">
    <div class="card-header">
        <h2>Sign Up with OAuth</h2>
    </div>

    <?php if (!empty($invitationCode ?? null)) { ?>
    <div class="notification-box info">
        <div class="notification-content">
            <span class="notification-icon">🎫</span>
            <span class="notification-text">
                Invitation code: <strong><?php echo htmlspecialchars($invitationCode, ENT_QUOTES, 'UTF-8'); ?></strong>
            </span>
        </div>
    </div>
    <?php } else { ?>
    <form method="get" action="/signup" class="form-group">
        <label for="invitation-code">Invitation Code</label>
        <input type="text" id="invitation-code" name="code" placeholder="Enter your invitation code" required>
        <button type="submit" class="button">Continue</button>
        <p class="form-help">You need a valid invitation code to create an account.</p>
    </form>
    <?php } ?>

    <?php if (!empty($invitationCode ?? null)) { ?>
    <div class="provider-buttons">
        <?php foreach ($providers ?? [] as $provider) { ?>
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
        <a class="button provider-button" href="/auth/<?php echo htmlspecialchars($provider['key'], ENT_QUOTES, 'UTF-8'); ?>?code=<?php echo htmlspecialchars($invitationCode, ENT_QUOTES, 'UTF-8'); ?>">
            <span class="provider-icon <?php echo htmlspecialchars($providerIconClass, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"><?php echo htmlspecialchars($providerIconText, ENT_QUOTES, 'UTF-8'); ?></span>
            <span>Continue with <?php echo htmlspecialchars($provider['label'], ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
        <?php } ?>
    </div>
    <?php } ?>

    <div class="card-footer">
        <p>Already have an account? <a href="/login">Log in</a></p>
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
