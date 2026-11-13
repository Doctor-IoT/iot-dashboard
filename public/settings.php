<?php
/**
 * settings.php — Account settings and email notification configuration.
 *                Includes language preference selector.
 */
require_once __DIR__ . '/../php/auth.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/mailer.php';
require_once __DIR__ . '/../php/i18n.php';
require_login();
i18n_init();

$success = '';
$error   = '';

// Maximum logo upload size (bytes)
define('LOGO_MAX_BYTES', 512 * 1024);
// Allowed MIME types for logo upload
$LOGO_ALLOWED = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? 'save';

    if ($action === 'upload_logo') {
        // Handle logo file upload
        $file = $_FILES['logo_file'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE || $file['tmp_name'] === '') {
            $error = __('logo_err_no_file');
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $error = __('logo_err_read');
        } elseif ($file['size'] > LOGO_MAX_BYTES) {
            $error = __('logo_err_too_large');
        } else {
            // Detect MIME from actual file content, not the browser-supplied value
            $mime = '';
            if (function_exists('mime_content_type')) {
                $mime = mime_content_type($file['tmp_name']);
            } elseif (function_exists('finfo_open')) {
                $fi   = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($fi, $file['tmp_name']);
                finfo_close($fi);
            } else {
                // Fallback: trust the browser MIME (less secure, last resort)
                $mime = $file['type'];
            }
            if (!in_array($mime, $GLOBALS['LOGO_ALLOWED'], true)) {
                $error = __('logo_err_bad_type');
            } else {
                $binary = file_get_contents($file['tmp_name']);
                if ($binary === false) {
                    $error = __('logo_err_read');
                } else {
                    DB::saveLogo($binary, $mime);
                    $success = __('logo_saved');
                }
            }
        }

    } elseif ($action === 'remove_logo') {
        DB::deleteLogo();
        $success = __('logo_removed');

    } elseif ($action === 'test_email') {
        $to = trim($_POST['test_to'] ?? DB::getSetting('alert_email_primary', ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address for the test.';
        } else {
            $ok = send_mail(
                $to,
                'Test notification - IoT Dashboard SPARC LAB',
                "This is a test message sent from IoT Dashboard SPARC LAB.\n\n" .
                "If you are receiving this email, notifications are configured correctly.\n\n" .
                "Test date/time: " . date('Y-m-d H:i:s')
            );
            $success = $ok
                ? __('msg_test_sent')
                : __('msg_test_failed');
        }

    } else {   // action === 'save'
        $data = array();
        $data['alert_email_primary']   = trim($_POST['alert_email_primary'] ?? '');
        $data['alert_email_secondary'] = trim($_POST['alert_email_secondary'] ?? '');
        $data['alert_from_name']       = trim($_POST['alert_from_name'] ?? '');
        $data['alert_from_email']      = trim($_POST['alert_from_email'] ?? '');
        $data['alert_enabled']         = isset($_POST['alert_enabled']) ? '1' : '0';
        $data['alert_offline_minutes'] = (string) max(1, (int)($_POST['alert_offline_minutes'] ?? 3));

        $errs = array();
        if ($data['alert_email_primary'] !== '' &&
            !filter_var($data['alert_email_primary'], FILTER_VALIDATE_EMAIL)) {
            $errs[] = 'Invalid primary email address.';
        }
        if ($data['alert_email_secondary'] !== '' &&
            !filter_var($data['alert_email_secondary'], FILTER_VALIDATE_EMAIL)) {
            $errs[] = 'Invalid secondary email address.';
        }
        if ($data['alert_from_email'] !== '' &&
            !filter_var($data['alert_from_email'], FILTER_VALIDATE_EMAIL)) {
            $errs[] = 'Invalid sender email address.';
        }

        if (!empty($errs)) {
            $error = implode(' ', $errs);
        } else {
            foreach ($data as $k => $v) {
                DB::setSetting($k, $v);
            }
            $success = __('msg_settings_saved');
        }
    }
}

// Load current settings
$s = DB::getAllSettings();
$defaults = array(
    'alert_email_primary'   => '',
    'alert_email_secondary' => '',
    'alert_from_name'       => 'IoT Dashboard SPARC LAB',
    'alert_from_email'      => 'noreply@sparclab.it',
    'alert_enabled'         => '1',
    'alert_offline_minutes' => '3',
);
foreach ($defaults as $k => $v) {
    if (!isset($s[$k])) $s[$k] = $v;
}

// Check whether a custom logo has been uploaded
$has_logo = DB::getLogo() !== null;
// Cache-buster for logo.php <img> tag (changes on every settings load after upload/delete)
$logo_v   = DB::getSetting('logo_data', '') !== '' ? substr(md5(DB::getSetting('logo_data', '')), 0, 8) : '0';

$recent_alerts = array();
try {
    $stmt = DB::pdo()->query(
        "SELECT al.sent_at, al.alert_type, d.name AS device_name
         FROM alert_log al
         JOIN devices d ON d.id = al.device_id
         ORDER BY al.sent_at DESC LIMIT 10"
    );
    $recent_alerts = $stmt->fetchAll();
} catch (Exception $e) {
    $recent_alerts = array();
}
?>
<!DOCTYPE html>
<html lang="<?= i18n_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= __('settings_title') ?> - IoT Dashboard</title>
<link rel="stylesheet" href="assets/style.css">
<style>
.settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    max-width: 900px;
}
@media (max-width: 700px) { .settings-grid { grid-template-columns: 1fr; } }

.settings-card {
    background: var(--bg2);
    border: 1px solid var(--bg3);
    border-radius: 10px;
    padding: 24px;
}
.settings-card h2 {
    font-size: 1rem; margin: 0 0 20px;
    color: var(--text); border-bottom: 1px solid var(--bg3); padding-bottom: 10px;
}
.field { margin-bottom: 16px; }
.field label {
    display: block; font-size: .84rem;
    color: var(--text-muted); margin-bottom: 5px;
}
.field input[type=text],
.field input[type=email],
.field input[type=number] {
    width: 100%; padding: 9px 12px;
    background: var(--bg); border: 1px solid var(--bg3);
    border-radius: 6px; color: var(--text);
    font-size: .93rem; box-sizing: border-box;
}
.field input:focus { outline: none; border-color: var(--accent); }
.field small { display: block; font-size: .78rem; color: var(--text-muted); margin-top: 4px; }

.toggle-row {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 16px;
}
.toggle-row label { font-size: .9rem; color: var(--text); cursor: pointer; }

.btn-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px; }
.btn { padding: 9px 20px; border-radius: 6px; border: none; cursor: pointer; font-size: .9rem; font-weight: 600; text-decoration: none; display: inline-block; }
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { opacity: .88; }
.btn-secondary { background: var(--bg3); color: var(--text); }
.btn-secondary:hover { opacity: .85; }

.alert-ok  { background: rgba(34,197,94,.1);  border:1px solid rgba(34,197,94,.3);  color:#22c55e; border-radius:6px; padding:10px 14px; margin-bottom:18px; font-size:.88rem; }
.alert-err { background: rgba(239,68,68,.1);  border:1px solid rgba(239,68,68,.3);  color:#fca5a5; border-radius:6px; padding:10px 14px; margin-bottom:18px; font-size:.88rem; }

.log-table { width:100%; border-collapse:collapse; font-size:.85rem; margin-top:12px; }
.log-table th { text-align:left; color:var(--text-muted); font-weight:500; padding:6px 10px; border-bottom:1px solid var(--bg3); }
.log-table td { padding:7px 10px; border-bottom:1px solid var(--bg3); }
.badge-offline { background:rgba(239,68,68,.15); color:#fca5a5; padding:2px 8px; border-radius:10px; font-size:.8rem; }
.badge-online  { background:rgba(34,197,94,.15);  color:#22c55e;  padding:2px 8px; border-radius:10px; font-size:.8rem; }

.test-row { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; margin-top:12px; }
.test-row input { flex:1; min-width:200px; padding:9px 12px; background:var(--bg); border:1px solid var(--bg3); border-radius:6px; color:var(--text); font-size:.9rem; box-sizing:border-box; }
</style>
</head>
<body>

<header>
    <h1><?= site_logo() ?><span class="h1-sep" aria-hidden="true"></span><span class="h1-text"><?= __('settings_title') ?></span></h1>
    <p class="subtitle"><?= __('settings_subtitle') ?></p>
    <nav>
        <?= i18n_switcher() ?>
        <a href="dashboard.php" class="btn-nav">&larr; Dashboard</a>
        <a href="admin.php"     class="btn-nav">&#128295; <?= __('nav_devices') ?></a>
        <a href="logout.php"    class="btn-nav">&#128275; <?= __('nav_logout') ?></a>
    </nav>
</header>

<main>

<?php if ($success): ?>
    <div class="alert-ok">&#10003; <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert-err">&#9888; <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Main settings form -->
<form method="post" id="form-settings">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <div class="settings-grid">

        <!-- Left column: notifications + language -->
        <div>
        <div class="settings-card">
            <h2>&#128231; <?= __('settings_email_heading') ?></h2>

            <div class="toggle-row">
                <input type="checkbox" id="alert_enabled" name="alert_enabled"
                    <?= $s['alert_enabled'] === '1' ? 'checked' : '' ?>>
                <label for="alert_enabled">Enable email notifications</label>
            </div>

            <div class="field">
                <label><?= __('field_email_primary') ?></label>
                <input type="email" name="alert_email_primary"
                    value="<?= htmlspecialchars($s['alert_email_primary']) ?>"
                    placeholder="admin@example.com">
                <small>Receives all alerts.</small>
            </div>

            <div class="field">
                <label><?= __('field_email_secondary') ?></label>
                <input type="email" name="alert_email_secondary"
                    value="<?= htmlspecialchars($s['alert_email_secondary']) ?>"
                    placeholder="other@example.com">
                <small>Leave blank to disable.</small>
            </div>

            <div class="field">
                <label><?= __('field_offline_minutes') ?></label>
                <input type="number" name="alert_offline_minutes" min="1" max="60"
                    value="<?= (int)$s['alert_offline_minutes'] ?>" style="max-width:100px">
                <small>Send alert if device has been offline for at least N consecutive minutes.</small>
            </div>
        </div>

        <div class="settings-card" style="margin-top:24px">
            <h2>&#9993; <?= __('settings_sender_heading') ?></h2>

            <div class="field">
                <label><?= __('field_sender_name') ?></label>
                <input type="text" name="alert_from_name"
                    value="<?= htmlspecialchars($s['alert_from_name']) ?>">
            </div>

            <div class="field">
                <label><?= __('field_sender_email') ?></label>
                <input type="email" name="alert_from_email"
                    value="<?= htmlspecialchars($s['alert_from_email']) ?>">
                <small>Must be authorised by your hosting provider to avoid spam filtering.</small>
            </div>
        </div>

        <!-- Language preference card -->
        <div class="settings-card" style="margin-top:24px">
            <h2>🌐 <?= __('lang_heading') ?></h2>
            <div class="field">
                <label><?= __('lang_label') ?></label>
                <div style="margin-top:8px"><?= i18n_switcher() ?></div>
            </div>
        </div>

        </div><!-- end left column -->

        <!-- Right column: alert log + test email + logo -->
        <div>
        <div class="settings-card">
            <h2>&#128203; <?= __('settings_log_heading') ?></h2>
            <?php if (empty($recent_alerts)): ?>
                <p style="color:var(--text-muted);font-size:.88rem">
                    <?= __('log_no_alerts') ?>
                    <?php if (empty(DB::getAllSettings())): ?>
                     &mdash; run <a href="migrate.php">migrate.php</a> to enable this feature.
                    <?php endif; ?>
                </p>
            <?php else: ?>
            <table class="log-table">
                <thead><tr>
                    <th><?= __('log_col_device') ?></th>
                    <th><?= __('log_col_type') ?></th>
                    <th><?= __('log_col_sent_at') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($recent_alerts as $a): ?>
                <tr>
                    <td><?= htmlspecialchars($a['device_name']) ?></td>
                    <td><span class="badge-<?= $a['alert_type'] ?>"><?= strtoupper($a['alert_type']) ?></span></td>
                    <td><?= htmlspecialchars($a['sent_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

                <!-- Test email card: form injected by JS after DOM ready -->
        <div class="settings-card" id="card-test-email" style="margin-top:24px">
            <h2>&#129514; <?= __('settings_test_heading') ?></h2>
            <p style="font-size:.86rem;color:var(--text-muted);margin:0 0 10px">
                Verify that PHP mail() is working correctly on this hosting account.
            </p>
            <div id="inject-test-email"></div>
        </div>

        <!-- Logo card: preview + forms injected by JS after DOM ready -->
        <div class="settings-card" id="card-logo" style="margin-top:24px">
            <h2>🖼️ <?= __('logo_heading') ?></h2>
            <div style="text-align:center;padding:10px 0 14px">
                <?php if ($has_logo): ?>
                    <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:10px"><?= __('logo_current') ?></p>
                    <img src="logo.php?v=<?= htmlspecialchars($logo_v) ?>"
                         alt="current logo"
                         style="max-height:68px;max-width:100%;border-radius:6px;border:1px solid var(--bg3);padding:4px 8px;background:var(--bg)">
                <?php else: ?>
                    <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:10px"><?= __('logo_using_default') ?></p>
                    <img src="logo.php?v=0" alt="default logo"
                         style="height:40px;border-radius:4px;opacity:.6">
                <?php endif; ?>
            </div>
            <div id="inject-logo-forms"></div>
        </div>

        </div><!-- end right column -->

    </div><!-- /settings-grid -->

    <div class="btn-row" style="margin-top:24px">
        <button type="submit" class="btn btn-primary">&#128190; <?= __('btn_save_settings') ?></button>
        <a href="dashboard.php" class="btn btn-secondary"><?= __('cancel') ?></a>
    </div>

</form>

<!--
  Extra forms live outside the main <form> to avoid illegal form nesting.
  JS moves them into their target injection points inside the cards above.
  They are initially hidden to prevent layout flash before injection.
-->

<!-- Test email form (hidden until JS moves it) -->
<div id="form-wrap-test" hidden>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test_email">
    <div class="test-row">
        <input type="email" name="test_to"
            placeholder="Test recipient"
            value="<?= htmlspecialchars($s['alert_email_primary']) ?>">
        <button type="submit" class="btn btn-secondary"><?= __('btn_send_test') ?></button>
    </div>
</form>
</div>

<!-- Logo forms (hidden until JS moves them) -->
<div id="form-wrap-logo" hidden>
<form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload_logo">
    <div class="field" style="margin:0">
        <label style="font-size:.82rem;color:var(--text-muted);margin-bottom:6px;display:block"><?= __('logo_label') ?></label>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input type="file" name="logo_file" accept="image/png,image/jpeg,image/svg+xml,image/webp"
                   style="flex:1;min-width:0;padding:7px 10px;background:var(--bg);
                          border:1px solid var(--bg3);border-radius:6px;
                          color:var(--text);font-size:.85rem;box-sizing:border-box;">
            <button type="submit" class="btn btn-primary" style="white-space:nowrap;flex-shrink:0">
                &#128228; <?= __('logo_upload_btn') ?>
            </button>
        </div>
    </div>
</form>
<?php if ($has_logo): ?>
<form method="post" style="margin-top:10px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="remove_logo">
    <button type="submit" class="btn btn-secondary"
            onclick="return confirm('<?= addslashes(__('logo_remove_btn')) ?>')"
            style="font-size:.82rem">
        &#128465; <?= __('logo_remove_btn') ?>
    </button>
</form>
<?php endif; ?>
</div>

<script>
// Move the extra forms into their injection points inside the cards.
// This runs synchronously after the DOM is parsed (script is at end of body),
// so there is no visible flash.
(function() {
    function inject(wrapId, targetId) {
        var wrap = document.getElementById(wrapId);
        var target = document.getElementById(targetId);
        if (!wrap || !target) return;
        // Move each child of wrap into target
        while (wrap.firstChild) {
            target.appendChild(wrap.firstChild);
        }
        wrap.parentNode.removeChild(wrap);
    }
    inject('form-wrap-test', 'inject-test-email');
    inject('form-wrap-logo', 'inject-logo-forms');
})();
</script>

</main>

<footer>
    <p>&copy; <?= date('Y') ?> <a href="https://www.sparclab.it" target="_blank" rel="noopener">SPARC LAB</a>
    &nbsp;&middot;&nbsp; <?= __('footer_version') ?></p>
</footer>

</body>
</html>
