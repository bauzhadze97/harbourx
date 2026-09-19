<?php
/**
 * Front door.
 *
 * Every page in this app has a real filename — home.html, login.html,
 * dashboard.html, admin.php — so a bare request for "/" matched nothing at
 * all: PHP's built-in server answered "Not Found", and Apache served a
 * directory listing of the whole application to anyone who asked for the
 * domain root.
 *
 * What the root answers with depends on who is asking:
 *
 *   signed in     302 to the dashboard, which is where they were going
 *   anonymous     home.html, served here rather than redirected to, so the
 *                 public site lives at https://harbourx.org/ and not at a
 *                 second URL a visitor has to be bounced through
 *
 * Sign-in itself stays on its own page (login.html). The root is the shop
 * window; nothing on it needs a session.
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

// The answer differs for a visitor with a session and one without, so a shared
// cache must not hand one person's answer to the next.
header('Cache-Control: no-store');
header('Vary: Cookie');

if ($signedIn) {
    header('Location: dashboard.html', true, 302);
    exit;
}

$home = __DIR__ . '/home.html';
if (!is_readable($home)) {
    // Nothing to show, but the root must still lead somewhere real.
    header('Location: login.html', true, 302);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
readfile($home);
