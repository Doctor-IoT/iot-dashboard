<?php
/**
 * dashboard.php — Private area: all devices with full details.
 */
require_once __DIR__ . '/../php/auth.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/mailer.php';
require_once __DIR__ . '/../php/i18n.php';
require_login();
i18n_init();

$devices          = DB::getAllDevices();
$username         = current_user();
$poller_last_seen = DB::getPollerLastSeen(); // UTC string or null
check_and_send_poller_alert();              // send email if poller is stale

// ── Sorting ───────────────────────────────────────────────────────────────────
$allowed_sort = ['name' => 'name', 'ip' => 'ip_address', 'location' => 'location', 'status' => 'status'];
$sort_key     = $_GET['sort'] ?? 'name';
$sort_key     = array_key_exists($sort_key, $allowed_sort) ? $sort_key : 'name';
$sort_field   = $allowed_sort[$sort_key];
$sort_dir     = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$next_dir     = $sort_dir === 'asc' ? 'desc' : 'asc';

usort($devices, function ($a, $b) use ($sort_field, $sort_dir) {
    $va = $a[$sort_field] ?? '';
    $vb = $b[$sort_field] ?? '';
    if ($sort_field === 'ip_address') {
        $ia = ip2long($va) ?: 0;
        $ib = ip2long($vb) ?: 0;
        $cmp = $ia <=> $ib;
    } else {
        $cmp = strcasecmp((string)$va, (string)$vb);
    }
    return $sort_dir === 'asc' ? $cmp : -$cmp;
});

function sort_url(string $key, string $current_key, string $current_dir): string {
    $dir = ($key === $current_key && $current_dir === 'asc') ? 'desc' : 'asc';
    return '?sort=' . urlencode($key) . '&dir=' . $dir;
}
function sort_arrow(string $key, string $current_key, string $current_dir): string {
    if ($key !== $current_key) return '<span class="sort-arrow inactive">↕</span>';
    return '<span class="sort-arrow">' . ($current_dir === 'asc' ? '↑' : '↓') . '</span>';
}
?>
<!DOCTYPE html>
<html lang="<?= i18n_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= __('dash_title') ?></title>
<link rel="stylesheet" href="assets/style.css">
<meta http-equiv="refresh" content="60">
<style>
.devices-table th a {
    color: inherit; text-decoration: none; display: flex;
    align-items: center; gap: 5px; white-space: nowrap;
}
.devices-table th a:hover { color: var(--accent); }
.sort-arrow         { font-size: .75rem; opacity: .9; }
.sort-arrow.inactive{ opacity: .3; }
.inactive-row td    { opacity: .5; }
</style>
</head>
<body>
<header>
    <h1><?= site_logo() ?><span class="h1-sep" aria-hidden="true"></span><span class="h1-text"><?= __('dash_heading') ?></span></h1>
    <div class="header-actions">
        <span class="user-info">👤 <?= htmlspecialchars($username) ?></span>
        <?= i18n_switcher() ?>
        <a href="admin.php"    class="btn-secondary">⚙️ <?= __('nav_devices') ?></a>
        <a href="settings.php" class="btn-secondary">🔔 <?= __('nav_settings') ?></a>
        <a href="logout.php"   class="btn-logout"><?= __('nav_logout') ?></a>
    </div>
</header>

<main>
    <?php
    // ── Poller health ─────────────────────────────────────────────────────────
    $poller_stale_d   = false;
    $poller_ts_disp_d = null;
    if ($poller_last_seen !== null) {
        $poller_dt_d      = new DateTime($poller_last_seen, new DateTimeZone('UTC'));
        $poller_dt_d->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $poller_ts_disp_d = $poller_dt_d->format('Y-m-d H:i');
        $age_min_d        = (time() - $poller_dt_d->getTimestamp()) / 60;
        $poller_stale_d   = $age_min_d > STALE_MINUTES;
    }
    $total   = count($devices);
    $online  = count(array_filter($devices, fn($d) => ($d['status'] === 'online') && empty($d['is_stale'])));
    $offline = count(array_filter($devices, fn($d) => ($d['status'] === 'offline') && empty($d['is_stale'])));
    $public  = count(array_filter($devices, fn($d) => $d['is_public']));
    ?>

    <div class="stats-row">
        <div class="stat-tile">
            <span class="stat-num"><?= $total ?></span>
            <span class="stat-label"><?= __('dash_total') ?></span>
        </div>
        <div class="stat-tile online">
            <span class="stat-num"><?= $online ?></span>
            <span class="stat-label"><?= __('dash_online') ?></span>
        </div>
        <div class="stat-tile offline">
            <span class="stat-num"><?= $offline ?></span>
            <span class="stat-label"><?= __('dash_offline') ?></span>
        </div>
        <div class="stat-tile">
            <span class="stat-num"><?= $public ?></span>
            <span class="stat-label"><?= __('dash_public') ?></span>
        </div>

        <?php /* ── Poller status ── */ ?>
        <?php if ($poller_last_seen === null): ?>
        <div class="stat-tile info poller-warn-tile">
            <span class="stat-info-label"><?= __('poller_tile_label') ?></span>
            <span class="stat-info-value warn"><?= __('poller_never_short') ?></span>
        </div>
        <?php elseif ($poller_stale_d): ?>
        <div class="stat-tile info poller-warn-tile">
            <span class="stat-info-label"><?= __('poller_tile_label') ?></span>
            <span class="stat-info-value warn">⚠ <?= htmlspecialchars($poller_ts_disp_d) ?></span>
            <span class="stat-info-label" style="margin-top:4px"><?= __('poller_stale_note') ?></span>
        </div>
        <?php else: ?>
        <div class="stat-tile info poller-ok-tile">
            <span class="stat-info-label"><?= __('poller_tile_label') ?></span>
            <span class="stat-info-value ok"><?= htmlspecialchars($poller_ts_disp_d) ?></span>
        </div>
        <?php endif; ?>

        <?php /* ── Pagina aggiornata — spinta a destra ── */ ?>
        <div class="stat-tile info page-updated">
            <span class="stat-info-label"><?= __('page_updated_at') ?></span>
            <span class="stat-info-value"><?= date('Y-m-d H:i') ?></span>
        </div>
    </div>

    <?php if (empty($devices)): ?>
        <p class="empty"><?= __('dash_no_devices') ?> <a href="admin.php"><?= __('admin_add_btn') ?> →</a></p>
    <?php else: ?>
    <table class="devices-table">
        <thead>
            <tr>
                <th><a href="<?= sort_url('status', $sort_key, $sort_dir) ?>"><?= __('col_status') ?> <?= sort_arrow('status', $sort_key, $sort_dir) ?></a></th>
                <th><a href="<?= sort_url('name', $sort_key, $sort_dir) ?>"><?= __('col_name') ?> <?= sort_arrow('name', $sort_key, $sort_dir) ?></a></th>
                <th><?= __('col_description') ?></th>
                <th><a href="<?= sort_url('ip', $sort_key, $sort_dir) ?>"><?= __('col_ip') ?> <?= sort_arrow('ip', $sort_key, $sort_dir) ?></a></th>
                <th><a href="<?= sort_url('location', $sort_key, $sort_dir) ?>"><?= __('col_location') ?> <?= sort_arrow('location', $sort_key, $sort_dir) ?></a></th>
                <th><?= __('col_public') ?></th>
                <th><?= __('col_last_ping') ?></th>
                <th><?= __('col_latency') ?></th>
                <th><?= __('actions') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($devices as $d): ?>
            <?php
            $stale_d      = !empty($d['is_stale']);
            $disp_st_d    = $stale_d ? 'unknown' : ($d['status'] ?? 'unknown');
            $is_online_d  = ($d['status'] ?? '') === 'online' && !$stale_d;
            $ts_d         = $is_online_d
                ? ($d['pinged_at'] ?? '')
                : ($d['last_online_at'] ?? '');
            ?>
            <tr class="<?= !$d['active'] ? 'inactive-row' : '' ?>">
                <td><span class="badge badge-<?= htmlspecialchars($disp_st_d) ?>">
                    <?= $stale_d ? __('status_stale') : strtoupper($d['status'] ?? '—') ?>
                </span></td>
                <td><strong><?= htmlspecialchars($d['name']) ?></strong></td>
                <td><?= htmlspecialchars($d['description']) ?></td>
                <td><code><?= htmlspecialchars($d['ip_address']) ?></code></td>
                <td><?= htmlspecialchars($d['location']) ?></td>
                <td><?= $d['is_public'] ? '✅' : '🔒' ?></td>
                <td title="<?= $is_online_d ? __('last_ping') : __('last_ping_offline') ?>">
                    <?= $ts_d ? htmlspecialchars($ts_d) : '—' ?>
                    <?php if (!$is_online_d && $ts_d): ?>
                        <span style="font-size:.75rem;color:var(--offline);display:block;line-height:1.2">offline</span>
                    <?php endif; ?>
                </td>
                <td><?= $d['latency_ms'] !== null ? number_format($d['latency_ms'], 1) . ' ms' : '—' ?></td>
                <td class="actions">
                    <a href="admin.php?edit=<?= $d['id'] ?>">✏️</a>
                    <a href="device_history.php?id=<?= $d['id'] ?>">📊</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</main>

<footer>
    <p>
        <a href="index.php">← <?= __('index_title') ?></a> |
        <a href="admin.php"><?= __('nav_devices') ?></a> |
        <a href="settings.php"><?= __('nav_settings') ?></a>
        &nbsp;·&nbsp;
        © <?= date('Y') ?> <a href="https://www.sparclab.it" target="_blank" rel="noopener">SPARC LAB</a>
        &nbsp;·&nbsp; <?= __('footer_version') ?>
    </p>
</footer>
</body>
</html>
