<?php
/**
 * i18n.php — Minimal internationalisation helper
 *
 * Usage:
 *   require_once __DIR__ . '/i18n.php';
 *   i18n_init();          // call once, after session is started
 *   echo __('key');       // returns translated string
 *   echo __('key', ['name' => 'Bob']);  // simple placeholder replacement
 *
 * Supported languages: en (English), it (Italian)
 * Default language   : en
 *
 * Language is resolved in this order:
 *   1. POST ?lang=   (from the switcher form)
 *   2. Session       $_SESSION['lang']
 *   3. Cookie        iot_lang
 *   4. Browser       Accept-Language header
 *   5. Default       'en'
 */

define('I18N_SUPPORTED', ['en', 'it']);
define('I18N_DEFAULT',   'en');
define('I18N_COOKIE',    'iot_lang');

/** @var array<string,string> $GLOBALS['_i18n'] */

/**
 * Initialises the i18n system.
 * Must be called after session_start (i.e. after require auth.php).
 */
function i18n_init(): void {
    // 1. Switcher form POST
    if (isset($_POST['_lang']) && i18n_valid($_POST['_lang'])) {
        $lang = $_POST['_lang'];
        $_SESSION['lang'] = $lang;
        // Persist in cookie for 1 year (public page has no session)
        setcookie(I18N_COOKIE, $lang, [
            'expires'  => time() + 365 * 86400,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    // 2. Session
    if (isset($_SESSION['lang']) && i18n_valid($_SESSION['lang'])) {
        $lang = $_SESSION['lang'];
    }
    // 3. Cookie
    elseif (isset($_COOKIE[I18N_COOKIE]) && i18n_valid($_COOKIE[I18N_COOKIE])) {
        $lang = $_COOKIE[I18N_COOKIE];
        $_SESSION['lang'] = $lang;
    }
    // 4. Accept-Language header
    else {
        $lang = i18n_from_header();
        $_SESSION['lang'] = $lang;
    }

    // Load the language file
    $file = __DIR__ . '/lang/' . $lang . '.php';
    if (!file_exists($file)) {
        $file = __DIR__ . '/lang/' . I18N_DEFAULT . '.php';
        $lang = I18N_DEFAULT;
    }
    $GLOBALS['_i18n']       = require $file;
    $GLOBALS['_i18n_lang']  = $lang;
}

/**
 * Returns the translated string for $key.
 * Supports simple placeholder replacement: __('hello_name', ['name' => 'Bob'])
 * returns "Hello, Bob!" if the string is "Hello, {name}!".
 *
 * @param  string               $key
 * @param  array<string,string> $replace
 * @return string
 */
function __(string $key, array $replace = []): string {
    $str = $GLOBALS['_i18n'][$key] ?? $key;   // fall back to the key itself
    foreach ($replace as $k => $v) {
        $str = str_replace('{' . $k . '}', htmlspecialchars((string)$v, ENT_QUOTES), $str);
    }
    return $str;
}

/** Returns the currently active language code ('en' or 'it'). */
function i18n_lang(): string {
    return $GLOBALS['_i18n_lang'] ?? I18N_DEFAULT;
}

/** Returns true if $lang is a supported language code. */
function i18n_valid(string $lang): bool {
    return in_array($lang, I18N_SUPPORTED, true);
}

/**
 * Renders the language-switcher form (a compact inline form).
 * Can be dropped anywhere inside a <header> or <nav>.
 */
function i18n_switcher(): string {
    $current = i18n_lang();
    $labels  = ['en' => '🇺🇸 EN', 'it' => '🇮🇹 IT'];
    $out  = '<form method="post" class="lang-switcher" aria-label="Language">';
    $out .= csrf_field();
    foreach (I18N_SUPPORTED as $code) {
        $active = $code === $current ? ' active' : '';
        $out .= sprintf(
            '<button type="submit" name="_lang" value="%s" class="lang-btn%s">%s</button>',
            $code,
            $active,
            $labels[$code]
        );
    }
    $out .= '</form>';
    return $out;
}

/**
 * Returns an <img> tag for the site logo.
 *
 * Used in every page header and in login boxes.
 * The optional $class parameter lets templates apply context-specific CSS classes.
 *
 * @param  string $class  CSS class(es) to apply, default 'site-logo'.
 * @param  string $alt    Alt text for the image.
 * @return string         Safe HTML <img> tag.
 */
function site_logo(string $class = 'site-logo', string $alt = 'Logo'): string {
    // Simple cache-buster: a short hash of the stored logo data, or '0' for default.
    // We call DB::getSetting() only if DB class is available (it always is here).
    $v = '0';
    if (class_exists('DB')) {
        $data = DB::getSetting('logo_data', '');
        if ($data !== '') {
            $v = substr(md5($data), 0, 8);
        }
    }
    return sprintf(
        '<img src="logo.php?v=%s" class="%s" alt="%s" loading="eager">',
        htmlspecialchars($v, ENT_QUOTES),
        htmlspecialchars($class, ENT_QUOTES),
        htmlspecialchars($alt, ENT_QUOTES)
    );
}

/**
 * Parses the Accept-Language header and returns the best supported language.
 */
function i18n_from_header(): string {
    $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    // Parse "it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7"
    preg_match_all('/([a-z]{2})(?:-[A-Z]{2})?(?:;q=([\d.]+))?/i', $header, $m, PREG_SET_ORDER);
    $weighted = [];
    foreach ($m as $entry) {
        $code = strtolower(substr($entry[1], 0, 2));
        $q    = isset($entry[2]) && $entry[2] !== '' ? (float)$entry[2] : 1.0;
        $weighted[$code] = max($weighted[$code] ?? 0, $q);
    }
    arsort($weighted);
    foreach (array_keys($weighted) as $code) {
        if (i18n_valid($code)) return $code;
    }
    return I18N_DEFAULT;
}
