<?php
/**
 * Client sign-in.
 *
 * Two shapes of request land here:
 *
 *   {email, password}      first step. Returns the user when the account has no
 *                          second factor, or {requires2fa:true} when it does.
 *   {code} or {backupCode} second step, against the challenge held in the
 *                          session from the first.
 *
 * An account without TOTP enabled behaves exactly as it always did, so this
 * change is invisible to clients who have not enrolled.
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

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

$file = __DIR__ . '/data/users.json';
$users = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
$users = is_array($users) ? $users : [];

function save_users(string $file, array $users): bool
{
    $json = json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return $json !== false && file_put_contents($file, $json, LOCK_EX) !== false;
}

/** The client payload the dashboard expects — never the secrets. */
function public_user(array $user): array
{
    unset($user['password'], $user['aml'], $user['bankAccounts'], $user['totp']);
    $status = strtolower((string)($user['amlStatus'] ?? 'unverified'));
    $user['amlStatus'] = in_array($status, ['verified', 'under_review', 'unverified'], true) ? $status : 'unverified';
    $user['twoFactorEnabled'] = !empty($user['twoFactorEnabled']);
    return $user;
}

function finish_login(array $users, int $index, string $file): void
{
    session_regenerate_id(true);
    $_SESSION['client_email'] = strtolower((string)$users[$index]['email']);
    unset($_SESSION['pending_2fa_email'], $_SESSION['pending_2fa_at']);

    $user = $users[$index];
    $user['twoFactorEnabled'] = hx_totp_state($user)['enabled'];
    echo json_encode(['success' => true, 'user' => public_user($user)]);
    exit;
}

function find_index(array $users, string $email): ?int
{
    foreach ($users as $i => $user) {
        if (strtolower((string)($user['email'] ?? '')) === $email) return $i;
    }
    return null;
}

/* ------------------------------------------------- step two: the 2FA code */
$code = preg_replace('/\s+/', '', (string)($input['code'] ?? ''));
$backupCode = trim((string)($input['backupCode'] ?? ''));

if ($code !== '' || $backupCode !== '') {
    $pendingEmail = strtolower((string)($_SESSION['pending_2fa_email'] ?? ''));
    $pendingAt = (int)($_SESSION['pending_2fa_at'] ?? 0);

    if ($pendingEmail === '') {
        http_response_code(440);
        echo json_encode(['success' => false, 'message' => 'Your sign-in expired. Enter your email and password again.']);
        exit;
    }
    // Five minutes to enter a code is generous; after that, start over.
    if ($pendingAt > 0 && time() - $pendingAt > 300) {
        unset($_SESSION['pending_2fa_email'], $_SESSION['pending_2fa_at']);
        http_response_code(440);
        echo json_encode(['success' => false, 'message' => 'Your sign-in expired. Enter your email and password again.']);
        exit;
    }

    $index = find_index($users, $pendingEmail);
    if ($index === null) {
        unset($_SESSION['pending_2fa_email'], $_SESSION['pending_2fa_at']);
        echo json_encode(['success' => false, 'message' => 'Account not found.']);
        exit;
    }

    $state = hx_totp_state($users[$index]);

    if ($backupCode !== '') {
        $remaining = $state['backupCodes'];
        if (!hx_backup_code_consume($backupCode, $remaining)) {
            echo json_encode(['success' => false, 'message' => 'That backup code is not valid.']);
            exit;
        }
        $users[$index]['totp']['backupCodes'] = $remaining;   // single use
        save_users($file, $users);
        finish_login($users, $index, $file);
    }

    $counter = hx_totp_verify($state['secret'], $code);
    if ($counter === null) {
        echo json_encode(['success' => false, 'message' => 'That code is not right. Check your authenticator app.']);
        exit;
    }
    // A code is good for one sign-in: refuse a counter already spent, so a code
    // captured inside its 30-second life cannot be replayed.
    if ($counter <= $state['lastCounter']) {
        echo json_encode(['success' => false, 'message' => 'That code has already been used. Wait for the next one.']);
        exit;
    }

    $users[$index]['totp']['lastCounter'] = $counter;
    save_users($file, $users);
    finish_login($users, $index, $file);
}

/* -------------------------------------------- step one: email and password */
$email = strtolower(trim((string)($input['email'] ?? '')));
$password = (string)($input['password'] ?? '');

unset($_SESSION['client_email'], $_SESSION['pending_2fa_email'], $_SESSION['pending_2fa_at']);

$index = find_index($users, $email);
if ($index !== null && (string)($users[$index]['password'] ?? '') === $password) {
    $state = hx_totp_state($users[$index]);

    if ($state['enabled'] && $state['secret'] !== '') {
        // Hold the account against this session only. No user data goes out
        // until the second factor has been answered.
        $_SESSION['pending_2fa_email'] = $email;
        $_SESSION['pending_2fa_at'] = time();
        echo json_encode([
            'success' => false,
            'requires2fa' => true,
            'backupCodesRemaining' => count($state['backupCodes']),
            'message' => 'Enter the 6-digit code from your authenticator app.'
        ]);
        exit;
    }

    finish_login($users, $index, $file);
}

echo json_encode(['success' => false, 'message' => 'Wrong email or password']);
