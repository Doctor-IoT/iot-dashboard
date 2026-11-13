<?php
/**
 * auth.php - Session management, authentication, CSRF protection, rate limiting
 *
 * Provides:
 *   - Secure session initialisation (httponly, samesite=Lax, strict mode)
 *   - CSRF token generation and verification (bin2hex / hash_equals)
 *   - Login rate limiting: max 5 attempts, 300s lockout, stored in session
 *   - bcrypt password verification via password_verify()
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ─── Rate limiting constants ───────────────────────────────────────────────────
define('LOGIN_MAX_ATTEMPTS', 5);    // failed attempts before lockout
define('LOGIN_LOCKOUT_SECS', 300);  // lockout duration in seconds (5 minutes)

// ─── Secure session initialisation ────────────────────────────────────────────

function session_start_secure(): void {
    if (session_status() !== PHP_SESSION_NONE) return;

    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_name(SESSION_NAME);
    session_set_cookie_params(['lifetime' => SESSION_LIFETIME, 'samesite' => 'Lax']);
    session_start();
}

// ─── CSRF protection ──────────────────────────────────────────────────────────

/**
 * Returns the CSRF token for the current session, generating one if needed.
 */
function csrf_token(): string {
    session_start_secure();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Returns a hidden <input> field containing the CSRF token.
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Verifies the CSRF token submitted in a POST request.
 * Terminates with HTTP 403 if the token is missing or invalid.
 */
function csrf_verify(): void {
    session_start_secure();
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid request (CSRF token missing or incorrect).');
    }
}

// ─── Login rate limiting (session + IP based) ─────────────────────────────────

function _rate_limit_key(): string {
    return 'login_attempts_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * Returns true if the current IP is locked out due to too many failed attempts.
 */
function login_is_locked(): bool {
    session_start_secure();
    $key       = _rate_limit_key();
    $attempts  = $_SESSION[$key]['count']     ?? 0;
    $last_time = $_SESSION[$key]['last_time'] ?? 0;

    if ($attempts >= LOGIN_MAX_ATTEMPTS) {
        if (time() - $last_time < LOGIN_LOCKOUT_SECS) {
            return true;
        }
        // Lockout expired: reset counter
        unset($_SESSION[$key]);
    }
    return false;
}

function login_record_failure(): void {
    session_start_secure();
    $key = _rate_limit_key();
    $_SESSION[$key]['count']     = ($_SESSION[$key]['count'] ?? 0) + 1;
    $_SESSION[$key]['last_time'] = time();
}

function login_reset_attempts(): void {
    session_start_secure();
    unset($_SESSION[_rate_limit_key()]);
}

/**
 * Returns the number of seconds remaining in the current lockout.
 */
function login_remaining_lockout(): int {
    session_start_secure();
    $key       = _rate_limit_key();
    $last_time = $_SESSION[$key]['last_time'] ?? 0;
    return max(0, LOGIN_LOCKOUT_SECS - (time() - $last_time));
}

// ─── Authentication ───────────────────────────────────────────────────────────

function is_logged_in(): bool {
    session_start_secure();
    return !empty($_SESSION['user_id']);
}

/**
 * Redirects to login.php if the user is not authenticated.
 */
function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Attempts to log in with the given credentials.
 * Applies a fixed 300ms delay to slow brute-force attacks even when not locked.
 * Returns true on success, false on failure.
 */
function login(string $username, string $password): bool {
    if (login_is_locked()) return false;

    // Fixed delay to slow brute-force attempts regardless of lockout state
    usleep(300_000); // 300 ms

    $user = DB::getUserByUsername($username);
    if ($user && password_verify($password, $user['password_hash'])) {
        session_start_secure();
        session_regenerate_id(true);
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        login_reset_attempts();
        return true;
    }

    login_record_failure();
    return false;
}

function logout(): void {
    session_start_secure();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function current_user(): ?string {
    return $_SESSION['username'] ?? null;
}
