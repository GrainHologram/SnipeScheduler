<?php
// login.php
require_once __DIR__ . '/../src/bootstrap.php';
session_start();
require_once SRC_PATH . '/layout.php';

$config = [];
try {
    $config = load_config();
} catch (Throwable $e) {
    $config = [];
}

$authCfg   = $config['auth'] ?? [];
$googleCfg = $config['google_oauth'] ?? [];
$msCfg     = $config['microsoft_oauth'] ?? [];
$ldapEnabled   = array_key_exists('ldap_enabled', $authCfg) ? !empty($authCfg['ldap_enabled']) : true;
$googleEnabled = !empty($authCfg['google_oauth_enabled']);
$msEnabled     = !empty($authCfg['microsoft_oauth_enabled']);
$showGoogle    = $googleEnabled && !empty($googleCfg['client_id']);
$showMicrosoft = $msEnabled && !empty($msCfg['client_id']);
$showLdap      = $ldapEnabled;

// Show any previous error
$loginError = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

// Already logged in? Go to dashboard
if (!empty($_SESSION['user']['email'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in – Wrap It</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= layout_stylesheet_url() ?>">
    <?= layout_theme_styles($config) ?>
    <style>
        html, body { height: 100%; }

        body.login-page {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100%;
            padding: 2rem 1rem;
            background: var(--bg);
        }

        .login-shell {
            width: 100%;
            max-width: 420px;
        }

        .login-brand {
            text-align: center;
            margin-bottom: 2.25rem;
        }

        .login-logo {
            max-height: 64px;
            width: auto;
            margin-bottom: 1rem;
        }

        .login-wordmark {
            display: block;
            font-size: 2rem;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -0.03em;
            line-height: 1;
            text-decoration: none;
        }

        .login-wordmark span {
            color: var(--primary-strong);
        }

        .login-tagline {
            margin-top: 0.35rem;
            font-size: 0.85rem;
            color: var(--muted);
            letter-spacing: 0.01em;
        }

        .login-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-top: 2px solid var(--primary);
            border-radius: 0.875rem;
            padding: 2rem 2rem 1.75rem;
        }

        .login-card-heading {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text);
            margin: 0 0 0.3rem;
        }

        .login-card-sub {
            font-size: 0.85rem;
            color: var(--muted);
            margin: 0 0 1.5rem;
        }

        .login-divider {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 1.25rem 0;
            color: var(--muted);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .login-divider::before,
        .login-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .btn-oauth {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 0.55rem;
            font-size: 0.9rem;
            font-weight: 500;
            padding: 0.55rem 1rem;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        .btn-oauth:hover {
            background: var(--surface-card);
            border-color: rgba(var(--text-rgb), 0.25);
            color: var(--text);
        }

        .btn-oauth:visited {
            color: var(--text);
        }

        .login-footer {
            margin-top: 1.75rem;
            text-align: center;
            font-size: 0.78rem;
            color: var(--muted);
        }
    </style>
</head>
<body class="login-page">

<div class="login-shell">

    <div class="login-brand">
        <?php
        $logoUrl = trim($config['app']['logo_url'] ?? '');
        if ($logoUrl !== ''): ?>
            <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES) ?>" alt="Wrap It" class="login-logo">
        <?php endif; ?>
        <a href="login.php" class="login-wordmark">Wrap<span> It</span></a>
        <div class="login-tagline">Equipment Booking</div>
    </div>

    <div class="login-card">
        <h1 class="login-card-heading">Sign in</h1>
        <p class="login-card-sub">Choose an available sign-in option below.</p>

        <?php if ($loginError): ?>
            <div class="alert alert-danger mb-3" style="font-size:0.88rem;">
                <?= nl2br(htmlspecialchars($loginError)) ?>
            </div>
        <?php endif; ?>

        <?php if (!$showGoogle && !$showMicrosoft && !$showLdap): ?>
            <div class="alert alert-warning" style="font-size:0.88rem;">
                No authentication methods are enabled. Please contact an administrator.
            </div>
        <?php endif; ?>

        <?php if ($showLdap): ?>
            <form method="post" action="login_process.php">
                <input type="hidden" name="provider" value="ldap">
                <div class="mb-3">
                    <label for="email" class="form-label">Email address</label>
                    <input type="email"
                           class="form-control"
                           id="email"
                           name="email"
                           autocomplete="email"
                           required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password"
                           class="form-control"
                           id="password"
                           name="password"
                           autocomplete="current-password"
                           required>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    Sign in
                </button>
            </form>

            <?php if ($showGoogle || $showMicrosoft): ?>
                <div class="login-divider">or</div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($showGoogle): ?>
            <a href="login_process.php?provider=google"
               class="btn btn-oauth w-100 d-flex align-items-center justify-content-center gap-2 <?= $showLdap ? 'mb-2' : 'mb-2 mt-0' ?>">
                <svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 533.5 544.3">
                    <path fill="#EA4335" d="M533.5 278.4c0-18.6-1.5-37.3-4.8-55.5H272v105h147.6c-6.3 34-25 62.8-53.3 82.1l86.2 67.2c50.6-46.6 80-115.3 80-198.8z"/>
                    <path fill="#34A853" d="M272 544.3c71.8 0 132-23.5 176-64.1l-86.2-67.2c-24 16.4-54.8 26-89.8 26-69 0-127.5-46.5-148.4-108.9l-90 69.4C72.8 483.3 163.1 544.3 272 544.3z"/>
                    <path fill="#4A90E2" d="M123.6 330.1c-10.8-32.5-10.8-67.7 0-100.2l-90-69.4c-39.2 78.4-39.2 170.6 0 249.1l90-69.5z"/>
                    <path fill="#FBBC05" d="M272 106.1c37.8-.6 74.2 13 102 38.2l76.1-76.1C403.9 24.8 339.7.5 272 1 163.1 1 72.8 62 33.6 160.5l90 69.4C144.6 152.6 203 106.1 272 106.1z"/>
                </svg>
                <span>Sign in with Google</span>
            </a>
        <?php endif; ?>

        <?php if ($showMicrosoft): ?>
            <a href="login_process.php?provider=microsoft"
               class="btn btn-oauth w-100 d-flex align-items-center justify-content-center gap-2">
                <svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 23 23">
                    <rect width="10.5" height="10.5" x="0.5" y="0.5" fill="#F35325"/>
                    <rect width="10.5" height="10.5" x="12" y="0.5" fill="#81BC06"/>
                    <rect width="10.5" height="10.5" x="0.5" y="12" fill="#05A6F0"/>
                    <rect width="10.5" height="10.5" x="12" y="12" fill="#FFBA08"/>
                </svg>
                <span>Sign in with Microsoft</span>
            </a>
        <?php endif; ?>
    </div>

    <div class="login-footer">
        Wrap It &mdash; Equipment Booking System
    </div>

</div>

</body>
</html>
