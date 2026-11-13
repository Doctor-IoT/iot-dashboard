<?php
/**
 * logo.php — Serves the custom site logo, or the built-in default SVG.
 *
 * Usage: <img src="logo.php" alt="Logo">
 *
 * The image is served with a short cache (5 minutes) so the browser does not
 * fetch it on every page load. After an upload or deletion the cache is busted
 * automatically via the ?v= query string appended by the PHP templates.
 */
require_once __DIR__ . '/../php/db.php';

$logo = DB::getLogo();

if ($logo !== null) {
    // Custom logo stored in the database
    header('Content-Type: ' . $logo['mime']);
    header('Cache-Control: public, max-age=300');
    header('Content-Length: ' . strlen($logo['data']));
    echo $logo['data'];
    exit;
}

// ── Default inline SVG (the globe / network icon used in the original design) ──
header('Content-Type: image/svg+xml');
header('Cache-Control: public, max-age=3600');
echo <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36" width="36" height="36">
  <circle cx="18" cy="18" r="17" fill="none" stroke="#38bdf8" stroke-width="2"/>
  <ellipse cx="18" cy="18" rx="7" ry="17" fill="none" stroke="#38bdf8" stroke-width="1.5"/>
  <line x1="1" y1="18" x2="35" y2="18" stroke="#38bdf8" stroke-width="1.5"/>
  <line x1="4"  y1="10" x2="32" y2="10" stroke="#38bdf8" stroke-width="1.2"/>
  <line x1="4"  y1="26" x2="32" y2="26" stroke="#38bdf8" stroke-width="1.2"/>
</svg>
SVG;
