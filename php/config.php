<?php
/**
 * config.php - Central configuration for IoT Dashboard
 *
 * Directory layout on shared hosting:
 *   iot-dashboard/php/config.php   <- this file
 *   iot-dashboard/db/iot.sqlite    <- SQLite database (blocked by .htaccess)
 *   iot-dashboard/public/          <- web root (only folder served over HTTP)
 *
 * Both php/ and db/ are protected by .htaccess rules but are NOT outside the
 * web root. The root iot-dashboard/.htaccess must be in place as well.
 */

// ─── Database path ────────────────────────────────────────────────────────────
// __DIR__ resolves to .../iot-dashboard/php/
// The SQLite file sits one level up, inside db/
define('DB_PATH', dirname(__DIR__) . '/db/iot.sqlite');

// ─── API key (shared with the Python polling script) ─────────────────────────
// Generate a secure key with:  php -r "echo bin2hex(random_bytes(32));"
// or:                          openssl rand -hex 32
// Must match IOT_API_KEY in the poller environment / poller.py default.
define('DB_API_KEY', 'CHANGE_THIS_SECRET_KEY');

// ─── API endpoint (used by the Python poller to know where to POST results) ───
define('POLL_API_URL', 'https://www.yoursite.com/iot-dashboard/public/api.php');

// ─── Public site URL (used in alert emails) ───────────────────────────────────
define('SITE_PUBLIC_URL', 'https://www.yoursite.com/iot-dashboard/public/');

// ─── Poller staleness threshold ───────────────────────────────────────────────
// If no ping data has been received for more than this many minutes, devices
// are displayed as "unknown" (stale) rather than showing their last known state.
// Set to match your poller interval + a small margin (default: 5 minutes).
define('STALE_MINUTES', 5);

// ─── Session settings ─────────────────────────────────────────────────────────
define('SESSION_NAME',     'iot_dashboard');
define('SESSION_LIFETIME', 3600);   // session lifetime in seconds (1 hour)

// ─── Timezone ─────────────────────────────────────────────────────────────────
date_default_timezone_set('Europe/Rome');
