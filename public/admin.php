<?php
/**
 * admin.php — Device CRUD management (authenticated users only).
 *             All mutating forms are protected by a CSRF token.
 */
require_once __DIR__ . '/../php/auth.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/i18n.php';
require_login();
i18n_init();

$message = '';
$error   = '';
$editing = null;

// ─── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? '';

    $data = [
        'name'        => trim($_POST['name']        ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'ip_address'  => trim($_POST['ip_address']  ?? ''),
        'location'    => trim($_POST['location']    ?? ''),
        'is_public'   => isset($_POST['is_public'])  ? 1 : 0,
        'active'      => isset($_POST['active'])     ? 1 : 0,
    ];

    $ip_ok = filter_var($data['ip_address'], FILTER_VALIDATE_IP)
          || filter_var($data['ip_address'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);

    if (!$data['name'] && $action !== 'delete') {
        $error = __('err_name_required');
    } elseif (!$data['ip_address'] && $action !== 'delete') {
        $error = __('err_ip_required');
    } else {
        try {
            if ($action === 'insert') {
                DB::insertDevice($data);
                $message = __('msg_added');
            } elseif ($action === 'update') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id < 1) { $error = __('err_not_found'); }
                else { DB::updateDevice($id, $data); $message = __('msg_updated'); }
            } elseif ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id < 1) { $error = __('err_not_found'); }
                else { DB::deleteDevice($id); $message = __('msg_deleted'); }
            }
        } catch (Exception $e) {
            $error = __('error') . ': ' . $e->getMessage();
        }
    }
}

// Edit mode
if (isset($_GET['edit'])) {
    $editing = DB::getDevice((int) $_GET['edit']);
}

// ── Sorting ───────────────────────────────────────────────────────────────────
$allowed_sort = ['name' => 'name', 'ip' => 'ip_address', 'location' => 'location', 'status' => 'status'];
$sort_key     = $_GET['sort'] ?? 'name';
$sort_key     = array_key_exists($sort_key, $allowed_sort) ? $sort_key : 'name';
$sort_field   = $allowed_sort[$sort_key];
$sort_dir     = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

$devices = DB::getAllDevices();
usort($devices, function ($a, $b) use ($sort_field, $sort_dir) {
    $va = $a[$sort_field] ?? '';
    $vb = $b[$sort_field] ?? '';
    if ($sort_field === 'ip_address') {
        $cmp = (ip2long($va) ?: 0) <=> (ip2long($vb) ?: 0);
    } else {
        $cmp = strcasecmp((string)$va, (string)$vb);
    }
    return $sort_dir === 'asc' ? $cmp : -$cmp;
});

function admin_sort_url(string $key, string $current_key, string $current_dir, ?int $edit_id = null): string {
    $dir = ($key === $current_key && $current_dir === 'asc') ? 'desc' : 'asc';
    $url = '?sort=' . urlencode($key) . '&dir=' . $dir;
    if ($edit_id) $url .= '&edit=' . $edit_id;
    return $url;
}
function admin_sort_arrow(string $key, string $current_key, string $current_dir): string {
    if ($key !== $current_key) return '<span class="sort-arrow inactive">↕</span>';
    return '<span class="sort-arrow">' . ($current_dir === 'asc' ? '↑' : '↓') . '</span>';
}
?>
<!DOCTYPE html>
<html lang="<?= i18n_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= __('admin_title') ?> — IoT Dashboard</title>
<link rel="stylesheet" href="assets/style.css">
<style>
.devices-table th a { color: inherit; text-decoration: none; display: flex; align-items: center; gap: 5px; white-space: nowrap; }
.devices-table th a:hover { color: var(--accent); }
.sort-arrow          { font-size: .75rem; opacity: .9; }
.sort-arrow.inactive { opacity: .3; }
</style>
</head>
<body>
<header>
    <h1><?= site_logo() ?><span class="h1-sep" aria-hidden="true"></span><span class="h1-text"><?= __('admin_title') ?></span></h1>
    <div class="header-actions">
        <?= i18n_switcher() ?>
        <a href="dashboard.php" class="btn-secondary">← Dashboard</a>
        <a href="settings.php"  class="btn-secondary">🔔 <?= __('nav_settings') ?></a>
        <a href="logout.php"    class="btn-logout"><?= __('nav_logout') ?></a>
    </div>
</header>

<main>

<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- ─── Add / edit form ─── -->
<section class="form-section">
    <h2><?= $editing ? '✏️ ' . __('edit') . ' ' . htmlspecialchars($editing['name']) : '➕ ' . __('admin_add_btn') ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'insert' ?>">
        <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
        <?php endif; ?>

        <div class="form-grid">
            <label><?= __('form_name') ?> *
                <input type="text" name="name"
                       value="<?= htmlspecialchars($editing['name'] ?? '') ?>" required maxlength="100">
            </label>
            <label><?= __('form_ip') ?> *
                <input type="text" name="ip_address"
                       value="<?= htmlspecialchars($editing['ip_address'] ?? '') ?>"
                       placeholder="192.168.1.x or host.example.com" required maxlength="253">
            </label>
            <label><?= __('form_description') ?>
                <input type="text" name="description"
                       value="<?= htmlspecialchars($editing['description'] ?? '') ?>" maxlength="255">
            </label>
            <label><?= __('form_location') ?>
                <input type="text" name="location"
                       value="<?= htmlspecialchars($editing['location'] ?? '') ?>" maxlength="100">
            </label>
            <label class="checkbox-label">
                <input type="checkbox" name="is_public"
                       <?= ($editing['is_public'] ?? 0) ? 'checked' : '' ?>>
                <?= __('form_public') ?>
            </label>
            <label class="checkbox-label">
                <input type="checkbox" name="active"
                       <?= ($editing['active'] ?? 1) ? 'checked' : '' ?>>
                <?= __('form_active') ?>
            </label>
        </div>

        <div class="form-actions">
            <button type="submit"><?= $editing ? __('form_save') : __('form_add') ?></button>
            <?php if ($editing): ?>
            <a href="admin.php" class="btn-secondary"><?= __('cancel') ?></a>
            <?php endif; ?>
        </div>
    </form>
</section>

<!-- ─── Device list ─── -->
<section>
    <h2>📋 <?= htmlspecialchars(sprintf('%s (%d)', __('nav_devices'), count($devices))) ?></h2>
    <?php $edit_id = isset($editing['id']) ? (int)$editing['id'] : null; ?>
    <?php if (empty($devices)): ?>
        <p class="empty"><?= __('dash_no_devices') ?></p>
    <?php else: ?>
    <table class="devices-table">
        <thead>
            <tr>
                <th>ID</th>
                <th><a href="<?= admin_sort_url('name', $sort_key, $sort_dir, $edit_id) ?>"><?= __('col_name') ?> <?= admin_sort_arrow('name', $sort_key, $sort_dir) ?></a></th>
                <th><a href="<?= admin_sort_url('ip', $sort_key, $sort_dir, $edit_id) ?>"><?= __('col_ip') ?> <?= admin_sort_arrow('ip', $sort_key, $sort_dir) ?></a></th>
                <th><a href="<?= admin_sort_url('location', $sort_key, $sort_dir, $edit_id) ?>"><?= __('col_location') ?> <?= admin_sort_arrow('location', $sort_key, $sort_dir) ?></a></th>
                <th><?= __('col_public') ?></th>
                <th><?= __('form_active') ?></th>
                <th><a href="<?= admin_sort_url('status', $sort_key, $sort_dir, $edit_id) ?>"><?= __('col_status') ?> <?= admin_sort_arrow('status', $sort_key, $sort_dir) ?></a></th>
                <th><?= __('actions') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($devices as $d): ?>
            <tr>
                <td><?= (int)$d['id'] ?></td>
                <td><?= htmlspecialchars($d['name']) ?></td>
                <td><code><?= htmlspecialchars($d['ip_address']) ?></code></td>
                <td><?= htmlspecialchars($d['location']) ?></td>
                <td><?= $d['is_public'] ? '✅' : '🔒' ?></td>
                <td><?= $d['active']    ? '✅' : '⏸️' ?></td>
                <td><span class="badge badge-<?= htmlspecialchars($d['status']) ?>">
                    <?= strtoupper(htmlspecialchars($d['status'])) ?>
                </span></td>
                <td class="actions">
                    <a href="admin.php?edit=<?= (int)$d['id'] ?>">✏️ <?= __('edit') ?></a>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('<?= htmlspecialchars(__('confirm_delete')) ?>')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id"     value="<?= (int)$d['id'] ?>">
                        <button type="submit" class="btn-danger-sm">🗑️ <?= __('delete') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<!-- ─── Polling script info ─── -->
<section class="polling-section">
    <h2>🔄 <?= __('poll_heading') ?></h2>
    <p><?= __('poll_intro') ?></p>
    <code class="block"><?= htmlspecialchars(POLL_API_URL) ?>?action=devices_list</code>
    <p><?= __('poll_manual') ?></p>
    <pre>cd /opt/iot-poller
export IOT_API_URL="<?= htmlspecialchars(POLL_API_URL) ?>"
export IOT_API_KEY="your_secret_key"
python3 poller.py</pre>
    <p><?= __('poll_cron') ?></p>
    <pre>* * * * * IOT_API_URL=... IOT_API_KEY=... /usr/bin/python3 /opt/iot-poller/poller.py >> /var/log/iot-poller.log 2>&amp;1</pre>
</section>

</main>
<footer>
    <p>
        <a href="dashboard.php">← Dashboard</a> | <a href="settings.php"><?= __('nav_settings') ?></a>
        &nbsp;·&nbsp;
        © <?= date('Y') ?> <a href="https://www.sparclab.it" target="_blank" rel="noopener">SPARC LAB</a>
        &nbsp;·&nbsp; <?= __('footer_version') ?>
    </p>
</footer>
</body>
</html>
