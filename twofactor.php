<?php
/**
 * Two-factor enrolment and management for a signed-in client.
 *
 * Actions (JSON POST, all require a live client session):
 *   status   what the account currently has
 *   begin    mint a pending secret and return it plus the otpauth URI
 *   enable   confirm the pending secret with a code; returns backup codes once
 *   disable  turn it off; needs a current code or an unused backup code
 *   codes    regenerate backup codes; needs a current code
 *
 * The pending secret lives in the session, never on the client record, so an
 * enrolment that is abandoned half-way leaves nothing behind.
 */

require __DIR__ . '/totp.php';

$secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $secureCookie,
    'samesite' => 'Lax'
]);
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$email = strtolower((string)($_SESSION['client_email'] ?? ''));
if ($email === '') {
    respond(401, ['success' => false, 'message' => 'Sign in again to manage two-factor authentication.']);
}

$file = __DIR__ . '/data/users.json';
$users = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
$users = is_array($users) ? $users : [];

$index = null;
foreach ($users as $i => $user) {
    if (strtolower((string)($user['email'] ?? '')) === $email) { $index = $i; break; }
}
if ($index === null) {
    respond(404, ['success' => false, 'message' => 'Account not found.']);
}

function save_users(string $file, array $users): void
{
    $json = json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
        respond(500, ['success' => false, 'message' => 'Unable to save the change.']);
    }
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$action = strtolower(trim((string)($input['action'] ?? 'status')));
$code = preg_replace('/\s+/', '', (string)($input['code'] ?? ''));

$state = hx_totp_state($users[$index]);

/* ------------------------------------------------------------------ status */
if ($action === 'status') {
    respond(200, [
        'success' => true,
        'enabled' => $state['enabled'],
        'confirmedAt' => $state['confirmedAt'],
        'backupCodesRemaining' => count($state['backupCodes'])
    ]);
}

/* ------------------------------------------------------------------- begin */
if ($action === 'begin') {
    if ($state['enabled']) {
        respond(409, ['success' => false, 'message' => 'Two-factor authentication is already on. Turn it off first to re-enrol.']);
    }
    $secret = hx_totp_secret();
    $_SESSION['totp_pending'] = $secret;
    $_SESSION['totp_pending_at'] = time();

    respond(200, [
        'success' => true,
        'secret' => $secret,
        // Grouped for anyone typing it in by hand instead of scanning.
        'secretGrouped' => trim(chunk_split($secret, 4, ' ')),
        'uri' => hx_totp_uri($secret, $email),
        'issuer' => 'HarbourX',
        'digits' => HX_TOTP_DIGITS,
        'period' => HX_TOTP_PERIOD
    ]);
}

/* ------------------------------------------------------------------ enable */
if ($action === 'enable') {
    $pending = (string)($_SESSION['totp_pending'] ?? '');
    $startedAt = (int)($_SESSION['totp_pending_at'] ?? 0);

    if ($pending === '') {
        respond(409, ['success' => false, 'message' => 'Start the setup again — no enrolment is in progress.']);
    }
    // An enrolment left open for an hour is almost certainly abandoned.
    if ($startedAt > 0 && time() - $startedAt > 3600) {
        unset($_SESSION['totp_pending'], $_SESSION['totp_pending_at']);
        respond(409, ['success' => false, 'message' => 'The setup expired. Start it again.']);
    }

    $counter = hx_totp_verify($pending, $code);
    if ($counter === null) {
        respond(422, ['success' => false, 'message' => 'That code is not right. Check your authenticator app and try again.']);
    }

    [$plain, $hashed] = hx_backup_codes();
    $users[$index]['totp'] = [
        'secret' => $pending,
        'enabled' => true,
        'confirmedAt' => gmdate('c'),
        'lastCounter' => $counter,
        'backupCodes' => $hashed
    ];
    save_users($file, $users);
    unset($_SESSION['totp_pending'], $_SESSION['totp_pending_at']);

    respond(200, [
        'success' => true,
        'message' => 'Two-factor authentication is on.',
        // Shown once. Only the hashes are stored, so this cannot be re-issued.
        'backupCodes' => $plain
    ]);
}

/* ----------------------------------------------------------------- disable */
if ($action === 'disable') {
    if (!$state['enabled']) {
        respond(200, ['success' => true, 'message' => 'Two-factor authentication is already off.']);
    }

    $backup = $state['backupCodes'];
    $ok = hx_totp_verify($state['secret'], $code) !== null
        || hx_backup_code_consume($code, $backup);

    if (!$ok) {
        respond(422, ['success' => false, 'message' => 'Enter a current code, or one of your backup codes, to turn this off.']);
    }

    $users[$index]['totp'] = ['secret' => '', 'enabled' => false, 'confirmedAt' => '', 'lastCounter' => 0, 'backupCodes' => []];
    save_users($file, $users);

    respond(200, ['success' => true, 'message' => 'Two-factor authentication is off.']);
}

/* ------------------------------------------------------------------- codes */
if ($action === 'codes') {
    if (!$state['enabled']) {
        respond(409, ['success' => false, 'message' => 'Turn on two-factor authentication first.']);
    }
    if (hx_totp_verify($state['secret'], $code) === null) {
        respond(422, ['success' => false, 'message' => 'Enter a current code to issue new backup codes.']);
    }

    [$plain, $hashed] = hx_backup_codes();
    $users[$index]['totp']['backupCodes'] = $hashed;
    save_users($file, $users);

    respond(200, [
        'success' => true,
        'message' => 'New backup codes issued. The previous set no longer works.',
        'backupCodes' => $plain
    ]);
}

respond(400, ['success' => false, 'message' => 'Unknown action.']);
