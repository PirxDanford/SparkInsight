<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SparkInsight - <?= htmlspecialchars($title ?? 'Home', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <?php
    $viewMeta = [
        'Home' => ['label' => 'Home', 'icon' => '🏠'],
        'Login' => ['label' => 'Login', 'icon' => '🔐'],
        'Sign Up' => ['label' => 'Signup', 'icon' => '📝'],
        'Author' => ['label' => 'Author', 'icon' => '✍️'],
        'Review' => ['label' => 'Review', 'icon' => '🔎'],
        'User Management' => ['label' => 'Users', 'icon' => '👥'],
        'Invitations' => ['label' => 'Invites', 'icon' => '✉️'],
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
                    <svg viewBox="0 0 24 24" role="presentation" focusable="false">
                        <path d="M3.5 5.75C3.5 4.78 4.28 4 5.25 4h5.5c1 0 1.95.38 2.66 1.06L14 5.65l.59-.59A3.76 3.76 0 0 1 17.25 4h1.5C19.72 4 20.5 4.78 20.5 5.75v12.5c0 .97-.78 1.75-1.75 1.75h-1.5c-1 0-1.95.38-2.66 1.06L14 21.65l-.59-.59A3.76 3.76 0 0 0 10.75 20h-5.5A1.75 1.75 0 0 1 3.5 18.25V5.75Zm1.5.25v12.25c0 .14.11.25.25.25h5.5c.64 0 1.27.12 1.86.34.13.05.27-.05.27-.18V7.41c0-.39-.16-.76-.44-1.03a2.27 2.27 0 0 0-1.61-.63h-5.5A.25.25 0 0 0 5 6Zm14 0a.25.25 0 0 0-.25-.25h-1.5c-.6 0-1.18.23-1.61.65-.28.27-.44.64-.44 1.03v11.25c0 .13.14.23.27.18.59-.22 1.22-.34 1.86-.34h1.5c.14 0 .25-.11.25-.25V6Z"/>
                    </svg>
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
                    <?php if (($user ?? null) && !$isPureAdmin): ?>
                        <?php if ($canAuthor): ?>
                            <li class="<?= ($dashboard_mode ?? null) === 'author' ? 'active' : '' ?>"><a href="/dashboard/author">Home</a></li>
                        <?php endif; ?>
                        <?php if ($canReview): ?>
                            <?php if ($canAuthor): ?>
                                <li class="<?= ($dashboard_mode ?? null) === 'review' ? 'active' : '' ?>"><a href="/dashboard/review">Review</a></li>
                            <?php else: ?>
                                <li class="<?= ($dashboard_mode ?? null) === 'review' ? 'active' : '' ?>"><a href="/dashboard/review">Home</a></li>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                        <li class="<?= in_array($title, ['User Management', 'Invitations']) ? 'active' : '' ?>"><a href="/admin/users">Users</a></li>
                        <li class="<?= $title === 'Invitations' ? 'active' : '' ?>"><a href="/admin/invitations">Invitations</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <div class="current-location header-section">
                <span class="location-icon">
                    <?= htmlspecialchars((string) $currentView['icon'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="location-prefix">Current View:</span>
                <span class="location-text"><?= htmlspecialchars((string) $currentView['label'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="auth-section header-section">
                <?php if ($user ?? null): ?>
                    <div class="user-info">
                        <span class="user-name" tabindex="0">
                            <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>
                            <span class="user-provider-tooltip">Signed in via <?= htmlspecialchars((string) ($user['provider'] ?? 'unknown provider'), ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <a class="button small secondary" href="/logout">
                            <span class="icon">🚪</span>
                            Logout
                        </a>
                    </div>
                <?php else: ?>
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
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="main-content">
        <?= $content ?? '' ?>
    </main>

    <footer class="footer">
        <p>&copy; <?= date('Y') ?> SparkInsight. Modern review workflows.</p>
    </footer>
</body>
</html>