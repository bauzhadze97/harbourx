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
        respond(500, ['success' => false, 'message' => 'Unable to save the withdrawal request.']);
    }
}

function findUserIndex($users, $email) {
    foreach ($users as $index => $user) {
        if (strtolower($user['email'] ?? '') === strtolower($email)) return $index;
    }
    return -1;
}

function clean($value, $maxLength = 120) {
    $value = trim((string)$value);
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $maxLength)
        : substr($value, 0, $maxLength);
}

function cleanNumber($value) {
    return floatval(preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string)$value)));
}

function normaliseName($value) {
    $value = strtolower(trim((string)$value));
    return preg_replace('/\s+/', ' ', $value);
}

function findBankAccount($accounts, $id) {
    foreach ($accounts as $account) {
        if (($account['id'] ?? '') === $id) return $account;
    }
    return null;
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

$status = strtolower((string)($users[$index]['amlStatus'] ?? 'unverified'));
if ($status !== 'verified') {
    respond(403, ['success' => false, 'message' => 'AML verification is required before withdrawing to a bank.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, ['success' => false, 'message' => 'Invalid request.']);
}

$source = strtolower(clean($input['source'] ?? 'btc', 12));
if ($source !== 'balance') $source = 'btc';
$fromBalance = ($source === 'balance');

$bankAccountId = clean($input['bankAccountId'] ?? '', 80);
$btcAmount = cleanNumber($input['btcAmount'] ?? 0);
$localAmount = round(cleanNumber($input['localAmount'] ?? $input['audAmount'] ?? 0), 2);
$btcLocalRate = cleanNumber($input['btcLocalRate'] ?? $input['btcAudRate'] ?? 0);
$feeAcknowledged = !empty($input['feeAcknowledged']);
$currency = strtoupper(trim((string)($users[$index]['currency'] ?? 'USD')));
if (!preg_match('/^[A-Z]{3}$/', $currency)) $currency = 'USD';

$availableBtc = round((float)($users[$index]['btc'] ?? 0), 8);
$availableBalance = round((float)($users[$index]['mainBalance'] ?? 0), 2);

if ($fromBalance) {
    if ($localAmount <= 0) {
        respond(422, ['success' => false, 'message' => 'Enter a valid withdrawal amount.']);
    }
    if ($localAmount > $availableBalance + 0.005) {
        respond(422, ['success' => false, 'message' => 'Amount exceeds your available main balance.']);
    }
    $btcAmount = 0;
    $btcLocalRate = 0;
} else {
    if ($btcAmount <= 0 || $localAmount <= 0 || $btcLocalRate <= 0) {
        respond(422, ['success' => false, 'message' => 'Enter a valid withdrawal amount.']);
    }
    if ($btcAmount > $availableBtc + 0.00000001) {
        respond(422, ['success' => false, 'message' => 'Amount exceeds the available BTC balance.']);
    }
}

$bankAccounts = is_array($users[$index]['bankAccounts'] ?? null) ? $users[$index]['bankAccounts'] : [];
$bank = findBankAccount($bankAccounts, $bankAccountId);
if (!$bank) {
    respond(422, ['success' => false, 'message' => 'Select a connected payout bank account.']);
}

// Per-client withdrawal fee. Computed here from the stored client settings, never trusted from the request.
$feeRequired = !empty($users[$index]['withdrawalFeeRequired']);
$feeFixed = round(max(0, (float)($users[$index]['withdrawalFeeAmount'] ?? 0)), 2);
$feePercent = max(0, (float)($users[$index]['withdrawalFeePercent'] ?? 0));
$feeAmount = $feeRequired ? round(max(0, $feeFixed + ($feePercent / 100) * $localAmount), 2) : 0.0;
if ($feeRequired && $feeAmount > 0 && !$feeAcknowledged) {
    respond(422, [
        'success' => false,
        'message' => 'The withdrawal fee must be paid before this request can be submitted.',
        'feeRequired' => true,
        'fee' => $feeAmount
    ]);
}

if (!isset($users[$index]['transactions']) || !is_array($users[$index]['transactions'])) {
    $users[$index]['transactions'] = [];
}

$accountNumber = preg_replace('/[^A-Za-z0-9]+/', '', (string)($bank['accountNumber'] ?? ''));
$lastFour = $accountNumber !== '' ? substr($accountNumber, -4) : '0000';
$today = date('Y-m-d');
$requestId = bin2hex(random_bytes(8));
$submittedAt = gmdate('c');
$bankDetails = trim(($bank['bankName'] ?? 'Bank') . ' - ' . ($bank['accountFirstName'] ?? '') . ' ' . ($bank['accountLastName'] ?? ''));
$bankDetails .= ' - Bank code ' . ($bank['bsb'] ?? '') . ' - Acct **** ' . $lastFour;
$symbol = currencySymbol($currency);

$withdrawalTransaction = [
    'date' => $today,
    'type' => 'Bank Withdrawal',
    'amount' => '-' . $symbol . number_format($localAmount, 2, '.', ','),
    'status' => 'In review',
    'details' => $bankDetails,
    'detailsUrl' => '',
    'withdrawalRequestId' => $requestId,
    'withdrawalSubmittedAt' => $submittedAt,
    'bankAccountId' => $bankAccountId
];

$feeNote = trim((string)($users[$index]['withdrawalFeeNote'] ?? ''));
$feeTransaction = [
    'date' => $today,
    'type' => 'Withdrawal Fee',
    'amount' => '-' . $symbol . number_format($feeAmount, 2, '.', ','),
    'status' => 'Pending',
    'details' => $feeNote !== '' ? $feeNote : 'Withdrawal release fee — awaiting confirmation',
    'detailsUrl' => '',
    'withdrawalRequestId' => $requestId
];

if ($fromBalance) {
    $withdrawalTransaction['details'] = 'From ' . $currency . ' main balance · ' . $bankDetails;
} else {
    $withdrawalTransaction['details'] = $bankDetails;
}

array_unshift($users[$index]['transactions'], $withdrawalTransaction);
if ($feeAmount > 0) {
    array_unshift($users[$index]['transactions'], $feeTransaction);
}

if ($fromBalance) {
    $newBalance = round(max(0, $availableBalance - $localAmount), 2);
    $users[$index]['mainBalance'] = $newBalance;
    $newBtc = $availableBtc;
} else {
    $swapTransaction = [
        'date' => $today,
        'type' => 'BTC to ' . $currency . ' Swap',
        'amount' => '-' . number_format($btcAmount, 8, '.', '') . ' BTC -> ' . $symbol . number_format($localAmount, 2, '.', ','),
        'status' => 'Completed',
        'details' => 'Rate ' . $symbol . number_format($btcLocalRate, 2, '.', ',') . ' / BTC',
        'detailsUrl' => '',
        'withdrawalRequestId' => $requestId
    ];
    array_unshift($users[$index]['transactions'], $swapTransaction);
    // The swapped Bitcoin leaves the account once the withdrawal is submitted.
    $newBtc = round(max(0, $availableBtc - $btcAmount), 8);
    $users[$index]['btc'] = $newBtc;
    $newBalance = $availableBalance;
}

saveUsers($usersFile, $users);

// index of the "Bank Withdrawal" row after the unshifts above
$withdrawalIndex = ($fromBalance ? 0 : 1) + ($feeAmount > 0 ? 1 : 0);

respond(200, [
    'success' => true,
    'message' => $feeAmount > 0
        ? 'Withdrawal request recorded. The ' . $symbol . number_format($feeAmount, 2, '.', ',') . ' fee is marked pending until confirmed.'
        : 'Withdrawal request recorded.',
    'withdrawalRequestId' => $requestId,
    'withdrawalIndex' => $withdrawalIndex,
    'source' => $source,
    'currency' => $currency,
    'fee' => $feeAmount,
    'btc' => $newBtc,
    'mainBalance' => $newBalance,
    'transactions' => $users[$index]['transactions']
]);
?>
