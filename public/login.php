<?php
/**
 * login.php — Standalone login page (fallback for direct access).
 */
require_once __DIR__ . '/../php/auth.php';
require_once __DIR__ . '/../php/i18n.php';
i18n_init();

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error  = '';
$locked = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (login_is_locked()) {
        $locked = true;
        $error  = __('login_locked');
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (login($username, $password)) {
            header('Location: dashboard.php');
            exit;
        }
        $key      = 'login_attempts_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
        $attempts = $_SESSION[$key]['count'] ?? 0;
        $left     = LOGIN_MAX_ATTEMPTS - $attempts;
        $error    = $left > 0
            ? __('login_failed') . " ({$left})"
            : __('login_locked');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= i18n_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= __('login_page_title') ?> — IoT Dashboard</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-page">
<div class="login-box">
    <div style="text-align:right;margin-bottom:16px"><?= i18n_switcher() ?></div>
    <?= site_logo('login-box-logo') ?>
    <h1>🔒 <?= __('login_page_title') ?></h1>
    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <?php if (!$locked): ?>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <label><?= __('username') ?>
            <input type="text" name="username" required autofocus>
        </label>
        <label><?= __('password') ?>
            <input type="password" name="password" required>
        </label>
        <button type="submit"><?= __('sign_in') ?></button>
    </form>
    <?php endif; ?>
    <p><a href="index.php"><?= __('login_back') ?></a></p>
</div>
</body>
</html>
