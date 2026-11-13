<?php
/**
 * device_history.php — Ping history for a single device (private area).
 */
require_once __DIR__ . '/../php/auth.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/i18n.php';
require_login();
i18n_init();

$id     = (int) ($_GET['id'] ?? 0);
$device = DB::getDevice($id);
if (!$device) {
    header('Location: dashboard.php');
    exit;
}
$history = DB::getPingHistory($id, 60);
?>
<!DOCTYPE html>
<html lang="<?= i18n_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= __('history_title', ['device' => $device['name']]) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header>
    <h1><?= site_logo() ?><span class="h1-sep" aria-hidden="true"></span><span class="h1-text"><?= __('history_title', ['device' => htmlspecialchars($device['name'])]) ?></span></h1>
    <div class="header-actions">
        <?= i18n_switcher() ?>
        <a href="dashboard.php" class="btn-secondary"><?= __('history_back') ?></a>
    </div>
</header>
<main>
    <p><strong>IP:</strong> <code><?= htmlspecialchars($device['ip_address']) ?></code>
       &nbsp;|&nbsp; <strong><?= __('col_location') ?>:</strong> <?= htmlspecialchars($device['location']) ?></p>

    <?php if (empty($history)): ?>
        <p class="empty"><?= __('history_empty') ?></p>
    <?php else: ?>
    <table class="devices-table">
        <thead>
            <tr>
                <th><?= __('history_col_time') ?></th>
                <th><?= __('history_col_status') ?></th>
                <th><?= __('history_col_latency') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr>
                <td><?= htmlspecialchars($h['pinged_at']) ?></td>
                <td><span class="badge badge-<?= $h['status'] ?>"><?= strtoupper($h['status']) ?></span></td>
                <td><?= $h['latency_ms'] !== null ? number_format($h['latency_ms'], 1) . ' ms' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</main>
<footer>
    <p>© <?= date('Y') ?> <a href="https://www.sparclab.it" target="_blank" rel="noopener">SPARC LAB</a>
    &nbsp;·&nbsp; <?= __('footer_version') ?></p>
</footer>
</body>
</html>
