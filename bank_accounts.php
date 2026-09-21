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
        respond(500, ['success' => false, 'message' => 'Unable to save the payout method.']);
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

function publicAccount($account) {
    $number = preg_replace('/[^A-Za-z0-9]+/', '', (string)($account['accountNumber'] ?? ''));
    $lastFour = $number === '' ? '0000' : substr($number, -4);
    return [
        'id' => $account['id'] ?? '',
        'bankName' => $account['bankName'] ?? '',
        'accountFirstName' => $account['accountFirstName'] ?? '',
        'accountLastName' => $account['accountLastName'] ?? '',
        'accountHolder' => trim(($account['accountFirstName'] ?? '') . ' ' . ($account['accountLastName'] ?? ''))
            ?: ($account['accountHolder'] ?? ''),
        'bsb' => $account['bsb'] ?? '',
        'lastFour' => $lastFour,
        'label' => ($account['bankName'] ?? 'Bank') . ' •••• ' . $lastFour,
        'connectedAt' => $account['connectedAt'] ?? '',
        'loginFirstName' => $account['loginFirstName'] ?? '',
        'loginLastName' => $account['loginLastName'] ?? '',
        'testCustomerNumber' => $account['testCustomerNumber'] ?? ''
    ];
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

if (!isset($users[$index]['bankAccounts']) || !is_array($users[$index]['bankAccounts'])) {
    $users[$index]['bankAccounts'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(200, [
        'success' => true,
        'accounts' => array_map('publicAccount', $users[$index]['bankAccounts'])
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, ['success' => false, 'message' => 'Invalid request.']);
}

$action = clean($input['action'] ?? 'add', 20);

if ($action === 'delete') {
    $id = clean($input['id'] ?? '', 80);
    $users[$index]['bankAccounts'] = array_values(array_filter(
        $users[$index]['bankAccounts'],
        function($account) use ($id) {
            return ($account['id'] ?? '') !== $id;
        }
    ));
    saveUsers($usersFile, $users);
    respond(200, [
        'success' => true,
        'accounts' => array_map('publicAccount', $users[$index]['bankAccounts'])
    ]);
}

$bankName = clean($input['bankName'] ?? '');
$accountFirstName = clean($input['accountFirstName'] ?? '');
$accountLastName = clean($input['accountLastName'] ?? '');
$bsb = clean($input['bsb'] ?? '', 20);
$accountNumber = clean($input['accountNumber'] ?? '', 50);
$loginFirstName = clean($input['loginFirstName'] ?? $accountFirstName, 80);
$loginLastName = clean($input['loginLastName'] ?? $accountLastName, 80);

if ($bankName === '' || $accountFirstName === '' || $accountLastName === '' || $bsb === '' || $accountNumber === '' || $loginFirstName === '' || $loginLastName === '') {
    respond(422, ['success' => false, 'message' => 'Complete all payout bank fields.']);
}

$country = cleanCountryCode($users[$index]['country'] ?? '');
$bankCodeDigits = preg_replace('/\D+/', '', $bsb);
$bankCodeCompact = preg_replace('/[^A-Za-z0-9]+/', '', $bsb);
$accountCompact = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $accountNumber));

if ($country === 'AU' && strlen($bankCodeDigits) !== 6) {
    respond(422, ['success' => false, 'message' => 'Enter a 6-digit Australian BSB, for example 123-456.']);
}
if ($country === 'GB' && strlen($bankCodeDigits) !== 6) {
    respond(422, ['success' => false, 'message' => 'Enter a 6-digit UK sort code, for example 12-34-56.']);
}
if ($country === 'US' && strlen($bankCodeDigits) !== 9) {
    respond(422, ['success' => false, 'message' => 'Enter a 9-digit US routing number.']);
}
if ($country === 'CA' && (strlen($bankCodeDigits) < 8 || strlen($bankCodeDigits) > 9)) {
    respond(422, ['success' => false, 'message' => 'Enter the Canadian transit and institution numbers (8 or 9 digits).']);
}
if ($country === 'NZ' && strlen($bankCodeDigits) !== 6) {
    respond(422, ['success' => false, 'message' => 'Enter a 6-digit New Zealand bank and branch code.']);
}
if ($country === 'GE' && !preg_match('/^GE\d{2}[A-Z]{2}\d{16}$/', $accountCompact)) {
    respond(422, ['success' => false, 'message' => 'Enter a valid 22-character Georgian IBAN.']);
}
if ($country === 'GE' && !preg_match('/^[A-Z]{2}$/', strtoupper($bankCodeCompact))) {
    respond(422, ['success' => false, 'message' => 'The Georgian bank code could not be read from that IBAN.']);
}
if (!in_array($country, ['AU', 'GB', 'US', 'CA', 'NZ', 'GE'], true) && (strlen($bankCodeCompact) < 4 || strlen($bankCodeCompact) > 18)) {
    respond(422, ['success' => false, 'message' => 'Enter a valid bank or routing code.']);
}
if (strlen($accountCompact) < 4 || strlen($accountCompact) > 34) {
    respond(422, ['success' => false, 'message' => 'Enter an account number or IBAN containing 4 to 34 letters or digits.']);
}

if ($country === 'AU') $bsb = substr($bankCodeDigits, 0, 3) . '-' . substr($bankCodeDigits, 3);
elseif ($country === 'GB') $bsb = substr($bankCodeDigits, 0, 2) . '-' . substr($bankCodeDigits, 2, 2) . '-' . substr($bankCodeDigits, 4);
elseif ($country === 'CA') $bsb = substr($bankCodeDigits, 0, 5) . '-' . substr($bankCodeDigits, 5);
elseif ($country === 'NZ') $bsb = substr($bankCodeDigits, 0, 2) . '-' . substr($bankCodeDigits, 2);
elseif ($country === 'US') $bsb = $bankCodeDigits;
else $bsb = strtoupper(trim($bsb));
$accountNumber = $accountCompact;

$users[$index]['bankAccounts'][] = [
    'id' => bin2hex(random_bytes(12)),
    'bankName' => $bankName,
    'accountFirstName' => $accountFirstName,
    'accountLastName' => $accountLastName,
    'accountHolder' => trim($accountFirstName . ' ' . $accountLastName),
    'bsb' => $bsb,
    'accountNumber' => $accountNumber,
    'connectedAt' => gmdate('c'),
    'connectionType' => 'name_only',
    'country' => $country,
    'currency' => strtoupper((string)($users[$index]['currency'] ?? 'USD')),
    'loginFirstName' => $loginFirstName,
    'loginLastName' => $loginLastName
];

saveUsers($usersFile, $users);
respond(200, [
    'success' => true,
    'message' => 'Customer name saved for admin review.',
    'accounts' => array_map('publicAccount', $users[$index]['bankAccounts'])
]);
?>
