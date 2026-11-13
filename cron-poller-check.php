<?php
/**
 * cron-poller-check.php — Cron script: check poller health and send alert emails.
 *
 * Run this script every minute (or every 5 minutes) via your hosting cron panel.
 * It checks whether the poller has been silent for longer than STALE_MINUTES and
 * sends an alert email to the configured recipients. It also sends a recovery
 * email when the poller comes back online after a stale period.
 *
 * This script is SAFE to run from outside the web root (command line / cron).
 * It does NOT need to be accessible over HTTP — place it outside public/ or
 * protect it with .htaccess.  However it CAN run via HTTP if needed (e.g. if
 * your hosting panel only supports URLs in cron jobs):
 *
 *   Command-line cron (recommended):
 *     * * * * * /usr/bin/php /path/to/iot-dashboard/cron-poller-check.php >> /var/log/iot-cron.log 2>&1
 *
 *   Hosting panel (URL-based cron):
 *     https://www.yoursite.com/iot-dashboard/public/cron-poller-check.php
 *     (see CRON_SECRET below — required when running over HTTP)
 *
 * ─── CONFIGURATION ───────────────────────────────────────────────────────────
 * All settings are inherited from php/config.php (STALE_MINUTES, DB_PATH, etc.).
 * No changes needed here unless you use the HTTP mode.
 *
 * CRON_SECRET: when running over HTTP set a secret token below, then append
 *   ?token=YOUR_SECRET to the cron URL to prevent unauthorised triggering.
 *   Leave as '' to disable the check (CLI-only usage).
 */

define('CRON_SECRET', '');   // ← set a secret string if using HTTP cron, e.g. 'my-secret-token-123'

// ─── Bootstrap ───────────────────────────────────────────────────────────────

// Resolve the script's own directory regardless of how it was invoked
$root = __DIR__;

// When running over HTTP, enforce the secret token
if (PHP_SAPI !== 'cli') {
    $token = $_GET['token'] ?? '';
    if (CRON_SECRET !== '' && $token !== CRON_SECRET) {
        http_response_code(403);
        exit("Forbidden\n");
    }
    // Plain-text output for HTTP cron logs
    header('Content-Type: text/plain; charset=utf-8');
}

require_once $root . '/php/config.php';
require_once $root . '/php/db.php';
require_once $root . '/php/mailer.php';

// ─── Run the check ───────────────────────────────────────────────────────────

$ts = date('Y-m-d H:i:s');
echo "[{$ts}] cron-poller-check: running\n";

check_and_send_poller_alert();

echo "[{$ts}] cron-poller-check: done\n";
