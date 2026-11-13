<?php
/**
 * api.php - Internal JSON API consumed by the Python polling script
 *
 * Authentication: HTTP header  X-Api-Key: <DB_API_KEY>
 * The key is never accepted in the URL/query string to avoid it appearing
 * in server access logs.
 *
 * Endpoints:
 *   GET  ?action=devices_list        -> list of active devices to be pinged
 *   POST ?action=ping_result         -> save a single ping result
 *   POST ?action=ping_results_bulk   -> save an array of ping results
 *
 * After saving each ping result the mailer is invoked to check whether an
 * alert email needs to be sent (offline threshold or recovery).
 */
require_once __DIR__ . '/../php/config.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/mailer.php';

// ─── No caching ───────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// ─── Optional IP whitelist ────────────────────────────────────────────────────
// Uncomment and adapt if the poller always runs on a known host/subnet:
// $allowed_ips = ['127.0.0.1', '::1', '192.168.1.0/24'];
// if (!ip_in_list($_SERVER['REMOTE_ADDR'], $allowed_ips)) { respond(403, 'Forbidden'); }

// ─── API key authentication ───────────────────────────────────────────────────
$provided_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!hash_equals(DB_API_KEY, $provided_key)) {
    respond(403, ['error' => 'Unauthorized']);
}

// ─── Routing ──────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {

    // ─── GET: list active devices ─────────────────────────────────────────────
    case 'devices_list':
        if ($method !== 'GET') respond(405, ['error' => 'Method not allowed']);
        $st = DB::pdo()->query(
            "SELECT id, name, ip_address FROM devices WHERE active=1"
        );
        respond(200, ['devices' => $st->fetchAll()]);
        break;

    // ─── POST: single ping result ─────────────────────────────────────────────
    case 'ping_result':
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) respond(400, ['error' => 'Invalid JSON']);
        save_ping_result($body);
        respond(200, ['ok' => true]);
        break;

    // ─── POST: bulk ping results ──────────────────────────────────────────────
    case 'ping_results_bulk':
        if ($method !== 'POST') respond(405, ['error' => 'Method not allowed']);
        $results = json_decode(file_get_contents('php://input'), true);
        if (!is_array($results)) respond(400, ['error' => 'Expected JSON array']);
        $saved = 0;
        foreach ($results as $r) {
            try { save_ping_result($r); $saved++; } catch (Throwable $e) { /* skip malformed row */ }
        }
        // Record the timestamp of the last successful poller contact
        if ($saved > 0) {
            DB::setSetting('poller_last_seen', gmdate('Y-m-d H:i:s'));
        }
        respond(200, ['ok' => true, 'saved' => $saved]);
        break;

    default:
        respond(400, ['error' => 'Unknown action']);
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Validates and persists one ping result, then triggers alert evaluation.
 */
function save_ping_result(array $r): void {
    $device_id = filter_var($r['device_id'] ?? 0, FILTER_VALIDATE_INT);
    $status    = $r['status'] ?? '';
    $latency   = isset($r['latency_ms']) ? (float) $r['latency_ms'] : null;

    if (!$device_id || !in_array($status, ['online', 'offline', 'unknown'], true)) {
        respond(400, ['error' => 'Invalid payload: device_id or status']);
    }
    DB::insertPingStatus((int)$device_id, $status, $latency);

    // Evaluate and send alert emails for definitive online/offline states
    if (in_array($status, ['online', 'offline'], true)) {
        try {
            $device = DB::getDevice((int)$device_id);
            if ($device) {
                check_and_send_alert((int)$device_id, $status, $device['name']);
            }
        } catch (Throwable $e) { /* never let email errors break the API response */ }
    }
}

/**
 * Outputs a JSON response with the given HTTP status code and exits.
 */
function respond(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ─── Optional helper: CIDR IP whitelist ───────────────────────────────────────
function ip_in_list(string $ip, array $list): bool {
    foreach ($list as $entry) {
        if (str_contains($entry, '/')) {
            [$net, $bits] = explode('/', $entry);
            $mask = -1 << (32 - (int)$bits);
            if ((ip2long($ip) & $mask) === (ip2long($net) & $mask)) return true;
        } elseif ($ip === $entry) {
            return true;
        }
    }
    return false;
}
