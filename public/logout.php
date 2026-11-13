<?php
/**
 * logout.php — Destroys the session and redirects to the public page
 */
require_once __DIR__ . '/../php/auth.php';
logout();

// Absolute redirect to avoid path issues on shared hosting
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script = $_SERVER['SCRIPT_NAME'];                       // /iot-dashboard/public/logout.php
$base   = rtrim(dirname($script), '/');                  // /iot-dashboard/public
$proto  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
header("Location: $proto://$host$base/index.php", true, 302);
exit;
