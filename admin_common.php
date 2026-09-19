<?php
require_once __DIR__ . '/btc.php';
require_once __DIR__ . '/locale_config.php';
require_once __DIR__ . '/payments_common.php';
require_once __DIR__ . '/followups_common.php';
require_once __DIR__ . '/admin_insights.php';

if (!defined('HX_ADMIN_TIMEZONE')) define('HX_ADMIN_TIMEZONE', 'Asia/Tbilisi');

$secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $secureCookie,
    'samesite' => 'Lax'
]);
session_start();

// CHANGE THIS PASSWORD AFTER UPLOADING
$ADMIN_PASSWORD = 'Genesis2050!';
$usersFile = __DIR__ . '/data/users.json';

function loadUsers($file) {
    if (!file_exists($file)) return [];
    $json = file_get_contents($file);
    $users = json_decode($json, true);
    return is_array($users) ? $users : [];
}

function saveUsers($file, $users) {
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
    file_put_contents($file, json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function cleanText($value) { return trim((string)$value); }
function cleanNumber($value) { return floatval(str_replace(',', '', (string)$value)); }
function cleanCurrency($value) {
    $currency = strtoupper(trim((string)$value));
    return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
}

// Normalises a Bitcoin address for storage. Bech32 addresses (bc1...) are
// case-insensitive but must not be mixed case, so they are lower-cased;
// legacy Base58Check addresses (starting 1 or 3) are case-sensitive and left as-is.
function cleanBtcAddress($value) {
    return hx_btc_address_normalise((string)$value);
}

// Verifies a Bitcoin address, checksum and all. This used to be a shape check —
// prefix and character set — which accepts an address with a mistyped character
// as readily as a correct one. Five of the six single-character typos in
// tests/btc-test.php got through it. An empty string means "no address".
function isValidBtcAddress($address) {
    return hx_btc_address_valid((string)$address);
}

function findUserIndex($users, $email) {
    $email = strtolower(trim((string)$email));
    foreach ($users as $i => $u) {
        if (strtolower($u['email'] ?? '') === $email) return $i;
    }
    return -1;
}

function amlStatus($user) {
    $status = strtolower((string)($user['amlStatus'] ?? 'unverified'));
    return in_array($status, ['verified', 'under_review', 'unverified'], true)
        ? $status
        : 'unverified';
}

function amlStatusLabel($status) {
    $status = strtolower((string)$status);
    if ($status === 'verified') return 'Verified';
    if ($status === 'under_review') return 'Under review';
    return 'Unverified';
}

function ensureTransactions(&$user) {
    if (!isset($user['transactions']) || !is_array($user['transactions'])) {
        $btc = floatval($user['btc'] ?? 0);
        $user['transactions'] = [[
            'date' => date('Y-m-d'),
            'type' => 'Received',
            'amount' => '+' . number_format($btc, 2, '.', '') . ' BTC',
            'status' => 'Completed',
            'details' => 'BTC account',
            'detailsUrl' => ''
        ]];
    }
}

function initials($name) {
    $name = trim((string)$name);
    if ($name === '') return 'U';
    $parts = preg_split('/\s+/', $name);
    $out = '';
    foreach ($parts as $p) {
        $out .= strtoupper(substr($p, 0, 1));
        if (strlen($out) >= 2) break;
    }
    return $out ?: 'U';
}

function formatMoney($value, $currency = 'USD') {
    $currency = cleanCurrency($currency);
    return currencySymbol($currency) . number_format((float)$value, 2);
}
function formatUsd($value) { return formatMoney($value, 'USD'); }
function clientMainBalance($user) { return round((float)($user['mainBalance'] ?? 0), 2); }

function publicClientUser($user) {
    unset($user['password'], $user['aml'], $user['bankAccounts'], $user['passwordSetup']);
    $status = strtolower((string)($user['amlStatus'] ?? 'unverified'));
    $user['amlStatus'] = in_array($status, ['verified', 'under_review', 'unverified'], true)
        ? $status
        : 'unverified';
    return $user;
}

/**
 * The fee settings for one client.
 *
 * `required` says a fee is owed. `paid` says an administrator has seen the
 * money arrive — it is the only thing that releases a withdrawal, and only an
 * administrator can set it. The client used to release their own withdrawal by
 * ticking a box that said they had paid, which asked the platform to take the
 * word of the one party with a reason to say it whether or not it was true.
 */
function withdrawalFeeConfig($user) {
    return [
        'required' => !empty($user['withdrawalFeeRequired']),
        'amount'   => round(max(0, (float)($user['withdrawalFeeAmount'] ?? 0)), 2),
        'percent'  => round(max(0, (float)($user['withdrawalFeePercent'] ?? 0)), 4),
        'note'     => trim((string)($user['withdrawalFeeNote'] ?? '')),
        'paid'     => !empty($user['withdrawalFeePaid']),
        'paidAt'   => trim((string)($user['withdrawalFeePaidAt'] ?? '')),
    ];
}

/**
 * Whether a withdrawal may be released right now. A fee that is required and
 * not yet marked received holds everything: the Bitcoin does not move, the
 * balance does not move, and nothing is recorded.
 */
function withdrawalFeeBlocks($user, $localAmount) {
    $config = withdrawalFeeConfig($user);
    if (!$config['required'] || $config['paid']) return false;
    return computeWithdrawalFee($user, $localAmount) > 0;
}

function computeWithdrawalFee($user, $localAmount) {
    $config = withdrawalFeeConfig($user);
    if (!$config['required']) return 0.0;
    $fee = $config['amount'] + ($config['percent'] / 100) * max(0, (float)$localAmount);
    return round(max(0, $fee), 2);
}
function statusSlug($status) {
    $status = strtolower(trim((string)$status));
    $status = preg_replace('/[^a-z0-9]+/', '-', $status);
    return trim($status, '-') ?: 'unknown';
}

function requireAdmin() {
    if (empty($_SESSION['admin_logged_in'])) {
        header('Location: admin.php');
        exit;
    }
}

function currentAdminAlertSnapshot() {
    return hxAdminAlertSnapshot(
        __DIR__ . '/data/payment_schedules.json',
        __DIR__ . '/data/client_callbacks.json',
        loadUsers(__DIR__ . '/data/users.json'),
        HX_ADMIN_TIMEZONE
    );
}

function hxMark() {
    return '<svg viewBox="0 0 32 32" fill="none" aria-hidden="true">'
        . '<path d="M7 20.5 16 11l9 9.5" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<path d="M11 25.5 16 20.5l5 5" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round" opacity=".5"/>'
        . '</svg>';
}

function pageHeader($title = 'Admin Panel') {
    $styleVersion = (string)(@filemtime(__DIR__ . '/admin-style.css') ?: 1);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<meta name="color-scheme" content="light dark">'
        . '<title>HarbourX Admin · ' . htmlspecialchars($title) . '</title>'
        . '<script src="theme.js?v=20260919-1948"></script>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200..800&display=swap">'
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200..800&display=swap" media="print" onload="this.media=&quot;all&quot;">'
        . '<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200..800&display=swap"></noscript>'
        . '<link rel="stylesheet" href="hx-motion.css?v=20260919-1948">'
        . '<link rel="stylesheet" href="admin-style.css?v=' . rawurlencode($styleVersion) . '">'
        . '<script src="hx-motion.js?v=20260919-1948" defer></script></head><body>';
}

function themeToggleIcons() {
    return '<span class="t-ico">'
        . '<svg class="t-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>'
        . '<svg class="t-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4.2"/><path d="M12 2v2.5M12 19.5V22M4.2 4.2l1.8 1.8M18 18l1.8 1.8M2 12h2.5M19.5 12H22M4.2 19.8 6 18M18 6l1.8-1.8"/></svg>'
        . '</span>';
}

function themeToggleButton() {
    return '<button type="button" class="btn btn-light theme-toggle" data-theme-toggle aria-label="Switch theme">'
        . themeToggleIcons()
        . '<span data-theme-label>Dark</span></button>';
}

function pageTop($active = 'clients') {
    $clientsClass = $active === 'clients' ? 'btn btn-blue' : 'btn btn-light';
    $paymentsClass = $active === 'payments' ? 'btn btn-blue' : 'btn btn-light';
    $callbacksClass = $active === 'callbacks' ? 'btn btn-blue' : 'btn btn-light';
    $alertSnapshot = currentAdminAlertSnapshot();
    $alertCount = (int)($alertSnapshot['count'] ?? 0);
    echo '<div class="page"><div class="topbar">'
        . '<div class="brand"><div class="badge">' . hxMark() . '</div>'
        . '<div><h1>HarbourX Admin</h1><p>Client operations console</p></div></div>'
        . '<div class="actions">'
        . themeToggleButton()
        . '<a class="' . $clientsClass . '" href="admin.php">Clients</a>'
        . '<a class="' . $paymentsClass . '" href="payments.php">Payments</a>'
        . '<a class="' . $callbacksClass . '" href="callbacks.php">Callbacks</a>'
        . '<a class="btn btn-light alert-link" href="admin.php#alerts">Alerts'
        . '<span class="alert-count" data-alert-count' . ($alertCount ? '' : ' hidden') . '>' . $alertCount . '</span></a>'
        . '<a class="btn btn-light" href="login.html" target="_blank" rel="noopener">Client app</a>'
        . '<a class="btn btn-light" href="admin.php?logout=1">Log out</a>'
        . '</div></div>';
}

function pageFooter() {
    $notificationsVersion = (string)(@filemtime(__DIR__ . '/admin-notifications.js') ?: 1);
    $opsVersion = (string)(@filemtime(__DIR__ . '/admin-ops.js') ?: 1);
    $dashboardVersion = (string)(@filemtime(__DIR__ . '/admin-dashboard.js') ?: 1);
    echo '<script src="admin-notifications.js?v=' . rawurlencode($notificationsVersion) . '"></script>'
        . '<script src="admin-ops.js?v=' . rawurlencode($opsVersion) . '"></script>'
        . '<script src="admin-dashboard.js?v=' . rawurlencode($dashboardVersion) . '"></script></div></body></html>';
}
