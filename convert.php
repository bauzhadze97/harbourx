<?php
require_once __DIR__ . '/locale_config.php';

$secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $secureCookie,
    'samesite' => 'Lax'
]);
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

$usersFile = __DIR__ . '/data/users.json';

function respond($statusCode, $payload) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function loadUsers($file) {
    if (!file_exists($file)) return [];
    $users = json_decode(file_get_contents($file), true);
    return is_array($users) ? $users : [];
}

function saveUsers($file, $users) {
    $json = json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
        respond(500, ['success' => false, 'message' => 'Unable to save the conversion.']);
    }
}

function findUserIndex($users, $email) {
    foreach ($users as $index => $user) {
        if (strtolower($user['email'] ?? '') === strtolower($email)) return $index;
    }
    return -1;
}

function cleanNumber($value) {
    return floatval(preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string)$value)));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$email = strtolower((string)($_SESSION['client_email'] ?? ''));
if ($email === '') {
    respond(401, ['success' => false, 'message' => 'Please log in again.']);
}

$users = loadUsers($usersFile);
$index = findUserIndex($users, $email);
if ($index === -1) {
    respond(404, ['success' => false, 'message' => 'Client account not found.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, ['success' => false, 'message' => 'Invalid request.']);
}

$btcAmount = round(cleanNumber($input['btcAmount'] ?? 0), 8);
$rate = cleanNumber($input['rate'] ?? $input['btcLocalRate'] ?? 0);

$currency = strtoupper(trim((string)($users[$index]['currency'] ?? 'USD')));
if (!preg_match('/^[A-Z]{3}$/', $currency)) $currency = 'USD';

if ($btcAmount <= 0) {
    respond(422, ['success' => false, 'message' => 'Enter a BTC amount greater than zero.']);
}
if ($rate <= 0) {
    respond(422, ['success' => false, 'message' => 'A BTC to ' . $currency . ' rate is not available yet. Refresh and try again.']);
}

$availableBtc = round((float)($users[$index]['btc'] ?? 0), 8);
if ($btcAmount > $availableBtc + 0.00000001) {
    respond(422, ['success' => false, 'message' => 'Amount exceeds your available BTC balance.']);
}

$localAmount = round($btcAmount * $rate, 2);
$newBtc = round($availableBtc - $btcAmount, 8);
$currentBalance = round((float)($users[$index]['mainBalance'] ?? 0), 2);
$newBalance = round($currentBalance + $localAmount, 2);

if (!isset($users[$index]['transactions']) || !is_array($users[$index]['transactions'])) {
    $users[$index]['transactions'] = [];
}

$symbol = currencySymbol($currency);
$conversionId = bin2hex(random_bytes(8));

$conversionTransaction = [
    'date' => date('Y-m-d'),
    'type' => 'BTC to ' . $currency . ' Conversion',
    'amount' => '-' . number_format($btcAmount, 8, '.', '') . ' BTC -> ' . $symbol . number_format($localAmount, 2, '.', ','),
    'status' => 'Completed',
    'details' => 'Converted to main balance at ' . $symbol . number_format($rate, 2, '.', ',') . ' / BTC',
    'detailsUrl' => '',
    'conversionId' => $conversionId
];

array_unshift($users[$index]['transactions'], $conversionTransaction);
$users[$index]['btc'] = $newBtc;
$users[$index]['mainBalance'] = $newBalance;

saveUsers($usersFile, $users);

respond(200, [
    'success' => true,
    'message' => 'Converted ' . number_format($btcAmount, 8, '.', '') . ' BTC to ' . $symbol . number_format($localAmount, 2, '.', ',') . '.',
    'conversionId' => $conversionId,
    'currency' => $currency,
    'btc' => $newBtc,
    'mainBalance' => $newBalance,
    'localAmount' => $localAmount,
    'rate' => $rate,
    'transactions' => $users[$index]['transactions']
]);
?>
