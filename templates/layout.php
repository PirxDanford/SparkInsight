<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SparkInsight - <?= htmlspecialchars($title ?? 'Home', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <header class="top-header">
        <div class="header-content">
            <div class="brand">
                <span class="brand-mark">SI</span>
                <h1>SparkInsight</h1>
            </div>
            <nav class="top-menu">
                <ul>
                    <li class="<?= $title === 'Home' ? 'active' : '' ?>"><a href="/">Home</a></li>
                    <li class="<?= $title === 'Dashboard' ? 'active' : '' ?>"><a href="/dashboard">Dashboard</a></li>
                    <?php if ($user ?? null && in_array('admin', $user['roles'] ?? [])): ?>
                        <li class="<?= in_array($title, ['User Management', 'Invitations']) ? 'active' : '' ?>"><a href="/admin/users">Users</a></li>
                        <li class="<?= $title === 'Invitations' ? 'active' : '' ?>"><a href="/admin/invitations">Invitations</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <div class="auth-section">
                <?php if ($user ?? null): ?>
                    <div class="user-info">
                        <span class="user-name"><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></span>
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
            <div class="current-location">
                <span class="location-icon">
                    <?php if ($title === 'Home'): ?>🏠<?php elseif ($title === 'Dashboard'): ?>📊<?php elseif ($title === 'User Management'): ?>👥<?php else: ?>📍<?php endif; ?>
                </span>
                <span class="location-text"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></span>
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