<?php
/**
 * Front door.
 *
 * Every page in this app has a real filename — login.html, dashboard.html,
 * admin.php — so a bare request for "/" matched nothing at all: PHP's built-in
 * server answered "Not Found", and Apache served a directory listing of the
 * whole application to anyone who asked for the domain root.
 *
 * This sends people where they were already trying to go, and gives the root a
 * real response on both servers.
 */

// Only touch the session when the visitor already has one. Starting a session
// unconditionally would write a session file for every anonymous hit on "/".
$signedIn = false;
if (!empty($_COOKIE[session_name()])) {
    $secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $secureCookie,
        'samesite' => 'Lax'
    ]);
    session_start();
    $signedIn = !empty($_SESSION['client_email']);
}

header('Cache-Control: no-store');
header('Location: ' . ($signedIn ? 'dashboard.html' : 'login.html'), true, 302);
exit;
