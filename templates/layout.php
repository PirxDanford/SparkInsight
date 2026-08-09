<?php require_once __DIR__ . '/partials/logo-system.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SparkInsight - <?php echo htmlspecialchars($title ?? 'Home', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <?php
    $user = isset($user) && is_array($user) ? $user : [];
$viewMeta = [
    'Home' => ['label' => 'Home', 'icon' => '🏠'],
    'Login' => ['label' => 'Login', 'icon' => '🔐'],
    'Sign Up' => ['label' => 'Signup', 'icon' => '📝'],
    'Author' => ['label' => 'Author', 'icon' => '✍️'],
    'Review' => ['label' => 'Review', 'icon' => '🔎'],
    'Admin' => ['label' => 'Admin', 'icon' => '🛠️'],
];
$currentView = $viewMeta[$title ?? 'Home'] ?? [
    'label' => (string) ($title ?? 'Home'),
    'icon' => '📍',
];
?>
    <header class="top-header">
        <div class="header-content">
            <div class="brand header-section">
                <span class="brand-mark" aria-hidden="true">
                    <?php renderSparkInsightLogo('icon'); ?>
                </span>
                <h1>SparkInsight</h1>
            </div>
            <nav class="top-menu header-section">
                <?php $roles = is_array($user ?? null) ? (array) ($user['roles'] ?? []) : []; ?>
                <?php $isAdmin = in_array('admin', $roles, true); ?>
                <?php $canAuthor = in_array('author', $roles, true); ?>
                <?php $canReview = in_array('reviewer', $roles, true) || $canAuthor; ?>
                <?php $isPureAdmin = $isAdmin && !$canAuthor && !in_array('reviewer', $roles, true); ?>
                <ul>
                    <?php if (($user ?? null) && !$isPureAdmin) { ?>
                        <?php if ($canAuthor) { ?>
                            <li class="<?php echo ($dashboard_mode ?? null) === 'author' ? 'active' : ''; ?>"><a href="/dashboard/author">Home</a></li>
                        <?php } ?>
                        <?php if ($canReview) { ?>
                            <?php if ($canAuthor) { ?>
                                <li class="<?php echo ($dashboard_mode ?? null) === 'review' ? 'active' : ''; ?>"><a href="/dashboard/review">Review</a></li>
                            <?php } else { ?>
                                <li class="<?php echo ($dashboard_mode ?? null) === 'review' ? 'active' : ''; ?>"><a href="/dashboard/review">Home</a></li>
                            <?php } ?>
                        <?php } ?>
                    <?php } ?>
                    <?php if ($isAdmin) { ?>
                        <li class="<?php echo in_array($title, ['Admin', 'User Management', 'Invitations', 'Admin Settings'], true) ? 'active' : ''; ?>"><a href="/dashboard/admin">Admin</a></li>
                    <?php } ?>
                </ul>
            </nav>
            <div class="current-location header-section">
                <span class="location-icon">
                    <?php echo htmlspecialchars((string) $currentView['icon'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <span class="location-prefix">Current View:</span>
                <span class="location-text"><?php echo htmlspecialchars((string) $currentView['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="auth-section header-section">
                <?php if ($user ?? null) { ?>
                    <div class="user-info">
                        <span class="user-name" tabindex="0">
                            <?php echo htmlspecialchars((string) ($user['display_name'] ?? $user['name']), ENT_QUOTES, 'UTF-8'); ?>
                            <span class="user-provider-tooltip">Signed in via <?php echo htmlspecialchars((string) ($user['provider'] ?? 'unknown provider'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </span>
                        <a class="button small secondary" href="/logout">
                            <span class="icon">🚪</span>
                            Logout
                        </a>
                    </div>
                <?php } else { ?>
                    <div class="auth-buttons">
                        <a class="button small" href="/login">
                            <span class="provider-icon">🔐</span>
                            Login
                        </a>
                        <span class="auth-separator">or</span>
                        <a class="button small secondary" href="/signup">
                            Sign Up
                        </a>
                    </div>
                <?php } ?>
            </div>
        </div>
    </header>

    <main class="main-content">
        <?php echo $content ?? ''; ?>
    </main>

    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> SparkInsight. Modern review workflows.</p>
    </footer>
</body>
</html>