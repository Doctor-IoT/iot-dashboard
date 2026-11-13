<?php
/**
 * index.php — Public status page with inline login modal.
 *             Language is detected automatically (cookie / Accept-Language).
 */
require_once __DIR__ . '/../php/auth.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/mailer.php';
require_once __DIR__ . '/../php/i18n.php';
i18n_init();

// Redirect authenticated users to the private dashboard
if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

try {
    $devices = DB::getPublicDevices();
} catch (Exception $e) {
    $devices = [];
}
$poller_last_seen = DB::getPollerLastSeen(); // UTC string or null
check_and_send_poller_alert();              // send email if poller is stale
$login_error  = '';
$login_locked = false;
$show_login   = isset($_GET['login']) || $_SERVER['REQUEST_METHOD'] === 'POST';

// Handle login POST (the language POST is already handled inside i18n_init)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_login'])) {
    csrf_verify();

    if (login_is_locked()) {
        $login_locked = true;
        $login_error  = __('login_locked');
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
        $login_error = $left > 0
            ? __('login_error') . " ({$left})"
            : __('login_locked');
    }
    $show_login = true;
}
$lang = i18n_lang();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= __('index_title') ?></title>
<link rel="stylesheet" href="assets/style.css">
<meta http-equiv="refresh" content="60">
<style>
/* ── Sort toolbar ──────────────────────────────────────────── */
.sort-toolbar {
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 16px; flex-wrap: wrap;
}
.sort-toolbar span { font-size: .82rem; color: var(--text-muted); }
.sort-btn {
    padding: 5px 12px; border-radius: 20px; border: 1px solid var(--bg3);
    background: var(--bg2); color: var(--text-muted);
    font-size: .82rem; cursor: pointer; display: flex; align-items: center; gap: 4px;
}
.sort-btn:hover  { border-color: var(--accent); color: var(--accent); }
.sort-btn.active { background: var(--bg3); color: var(--text); border-color: var(--accent); }
.sort-btn .arrow { font-size: .7rem; }

/* ── Login modal overlay ───────────────────────────────────── */
.login-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 100;
    align-items: center;
    justify-content: center;
}
.login-overlay.open { display: flex; }

.login-modal {
    background: var(--bg2);
    border: 1px solid var(--bg3);
    border-radius: 12px;
    padding: 32px 36px;
    width: 100%;
    max-width: 360px;
    box-shadow: 0 20px 60px rgba(0,0,0,.5);
    position: relative;
}
.login-modal h2 { margin: 0 0 20px; font-size: 1.1rem; }
.login-modal label { display: block; margin-bottom: 14px; font-size: .88rem; color: var(--text-muted); }
.login-modal input {
    display: block; width: 100%; margin-top: 4px;
    padding: 9px 12px; border-radius: 6px;
    background: var(--bg); border: 1px solid var(--bg3);
    color: var(--text); font-size: .95rem;
    box-sizing: border-box;
}
.login-modal button[type=submit] {
    width: 100%; padding: 10px; margin-top: 6px;
    background: var(--accent); color: #fff;
    border: none; border-radius: 6px; cursor: pointer;
    font-size: .95rem; font-weight: 600;
}
.login-modal button[type=submit]:hover { opacity: .9; }
.modal-close {
    position: absolute; top: 12px; right: 16px;
    background: none; border: none;
    color: var(--text-muted); font-size: 1.3rem;
    cursor: pointer; line-height: 1;
}
.modal-close:hover { color: var(--text); }
.login-modal .error {
    background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.3);
    color: #fca5a5; border-radius: 6px;
    padding: 8px 12px; margin-bottom: 14px; font-size: .86rem;
}
</style>
</head>
<body>

<header>
    <h1><?= site_logo() ?><span class="h1-sep" aria-hidden="true"></span><span class="h1-text"><?= __('index_title') ?></span></h1>
    <p class="subtitle"><?= __('index_subtitle') ?></p>
    <div class="header-actions">
        <?= i18n_switcher() ?>
        <button class="btn-login" onclick="openLogin()">🔒 <?= __('private_area') ?></button>
    </div>
</header>

<main>
    <?php
    // ── Poller health ─────────────────────────────────────────────────────────
    $poller_stale   = false;
    $poller_ts_disp = null;
    if ($poller_last_seen !== null) {
        $poller_dt      = new DateTime($poller_last_seen, new DateTimeZone('UTC'));
        $poller_dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $poller_ts_disp = $poller_dt->format('Y-m-d H:i');
        $age_minutes    = (time() - $poller_dt->getTimestamp()) / 60;
        $poller_stale   = $age_minutes > STALE_MINUTES;
    }
    ?>

    <?php if (!empty($devices)): ?>
    <div class="stats-row" style="margin-bottom:24px">
        <?php /* ── Poller status ── */ ?>
        <?php if ($poller_last_seen === null): ?>
        <div class="stat-tile info poller-warn-tile">
            <span class="stat-info-label"><?= __('poller_tile_label') ?></span>
            <span class="stat-info-value warn"><?= __('poller_never_short') ?></span>
        </div>
        <?php elseif ($poller_stale): ?>
        <div class="stat-tile info poller-warn-tile">
            <span class="stat-info-label"><?= __('poller_tile_label') ?></span>
            <span class="stat-info-value warn">⚠ <?= htmlspecialchars($poller_ts_disp) ?></span>
            <span class="stat-info-label" style="margin-top:4px"><?= __('poller_stale_note') ?></span>
        </div>
        <?php endif; ?>

        <?php /* ── Pagina aggiornata — spinta a destra ── */ ?>
        <div class="stat-tile info page-updated">
            <span class="stat-info-label"><?= __('page_updated_at') ?></span>
            <span class="stat-info-value"><?= date('Y-m-d H:i') ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($devices)): ?>
        <p class="empty"><?= __('dash_no_devices') ?></p>
    <?php else: ?>

    <div class="sort-toolbar">
        <span><?= __('sort_by') ?></span>
        <button class="sort-btn active" data-sort="name"     onclick="sortCards(this)"><?= __('sort_name') ?> <span class="arrow">↑</span></button>
        <button class="sort-btn"        data-sort="status"   onclick="sortCards(this)"><?= __('sort_status') ?> <span class="arrow">↕</span></button>
        <button class="sort-btn"        data-sort="location" onclick="sortCards(this)"><?= __('sort_location') ?> <span class="arrow">↕</span></button>
    </div>

    <div class="device-grid" id="deviceGrid">
        <?php foreach ($devices as $d): ?>
        <?php
        $stale      = !empty($d['is_stale']);
        // If stale, override status for display purposes only
        $disp_status = $stale ? 'unknown' : ($d['status'] ?? 'offline');
        $is_online   = ($d['status'] ?? '') === 'online' && !$stale;
        $show_ts     = $is_online
            ? ($d['pinged_at'] ?? '')
            : ($d['last_online_at'] ?? '');
        $ping_label  = $is_online ? __('last_ping') : __('last_ping_offline');
        ?>
        <div class="device-card <?= htmlspecialchars($disp_status) ?>"
             data-name="<?= htmlspecialchars($d['name']) ?>"
             data-status="<?= htmlspecialchars($disp_status) ?>"
             data-location="<?= htmlspecialchars($d['location'] ?? '') ?>">
            <div class="status-dot"></div>
            <div class="device-info">
                <h2><?= htmlspecialchars($d['name']) ?></h2>
                <?php if (!empty($d['location'])): ?>
                <p class="description" style="font-size:.8rem;color:var(--text-muted);margin-bottom:4px">📍 <?= htmlspecialchars($d['location']) ?></p>
                <?php endif; ?>
                <p class="description"><?= htmlspecialchars($d['description']) ?></p>
                <span class="badge badge-<?= htmlspecialchars($disp_status) ?>">
                    <?= $stale ? __('status_stale') : strtoupper($d['status'] ?? 'OFFLINE') ?>
                </span>
                <?php if (!empty($show_ts)): ?>
                <small class="last-ping">
                    <?= $ping_label ?> <?= htmlspecialchars($show_ts) ?>
                </small>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<footer>
    <p>© <?= date('Y') ?> <a href="https://www.sparclab.it" target="_blank" rel="noopener">SPARC LAB</a> &nbsp;·&nbsp; <?= __('footer_version') ?></p>
</footer>

<!-- ── Login modal ─────────────────────────────────────────── -->
<div class="login-overlay" id="loginOverlay" onclick="closeOnBg(event)">
    <div class="login-modal">
        <button class="modal-close" onclick="closeLogin()" title="Close">✕</button>
        <?= site_logo('login-modal-logo') ?>
        <h2>🔒 <?= __('login_title') ?></h2>

        <?php if ($login_error): ?>
            <p class="error"><?= htmlspecialchars($login_error) ?></p>
        <?php endif; ?>

        <?php if (!$login_locked): ?>
        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="_login" value="1">
            <label><?= __('username') ?>
                <input type="text" name="username" required autofocus>
            </label>
            <label><?= __('password') ?>
                <input type="password" name="password" required>
            </label>
            <button type="submit"><?= __('sign_in') ?></button>
        </form>
        <?php else: ?>
            <p style="color:var(--muted);font-size:.88rem;"><?= __('login_locked') ?></p>
        <?php endif; ?>
    </div>
</div>

<script>
/* ── Login modal ── */
const overlay = document.getElementById('loginOverlay');
function openLogin()  { overlay.classList.add('open'); }
function closeLogin() { overlay.classList.remove('open'); }
function closeOnBg(e) { if (e.target === overlay) closeLogin(); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLogin(); });
<?php if ($show_login): ?>
document.addEventListener('DOMContentLoaded', openLogin);
<?php endif; ?>

/* ── Card sorting ── */
let _sortKey = 'name', _sortDir = 'asc';

function sortCards(btn) {
    const key = btn.dataset.sort;
    if (key === _sortKey) {
        _sortDir = _sortDir === 'asc' ? 'desc' : 'asc';
    } else {
        _sortKey = key; _sortDir = 'asc';
    }
    document.querySelectorAll('.sort-btn').forEach(b => {
        b.classList.remove('active');
        b.querySelector('.arrow').textContent = '↕';
    });
    btn.classList.add('active');
    btn.querySelector('.arrow').textContent = _sortDir === 'asc' ? '↑' : '↓';

    const grid  = document.getElementById('deviceGrid');
    const cards = Array.from(grid.querySelectorAll('.device-card'));
    const statusWeight = s => s === 'online' ? 0 : s === 'offline' ? 1 : 2;

    cards.sort((a, b) => {
        let va = a.dataset[_sortKey] || '';
        let vb = b.dataset[_sortKey] || '';
        let cmp;
        if (_sortKey === 'status') {
            cmp = statusWeight(va) - statusWeight(vb) || va.localeCompare(vb);
        } else {
            cmp = va.localeCompare(vb, '<?= $lang ?>', { sensitivity: 'base' });
        }
        return _sortDir === 'asc' ? cmp : -cmp;
    });
    cards.forEach(c => grid.appendChild(c));
}
</script>

</body>
</html>
