<?php
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
        respond(500, ['success' => false, 'message' => 'Unable to save AML details.']);
    }
}

function findUserIndex($users, $email) {
    foreach ($users as $index => $user) {
        if (strtolower($user['email'] ?? '') === strtolower($email)) return $index;
    }
    return -1;
}

function clean($value, $maxLength = 180) {
    $value = trim((string)$value);
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $maxLength)
        : substr($value, 0, $maxLength);
}

function amlStatus($user) {
    $status = strtolower((string)($user['amlStatus'] ?? 'unverified'));
    return in_array($status, ['verified', 'under_review', 'unverified'], true)
        ? $status
        : 'unverified';
}

function publicAml($user) {
    return [
        'status' => amlStatus($user),
        'submittedAt' => $user['amlSubmittedAt'] ?? '',
        'reviewedAt' => $user['amlReviewedAt'] ?? '',
        'reviewNote' => $user['amlReviewNote'] ?? '',
        'data' => is_array($user['aml'] ?? null) ? $user['aml'] : []
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(200, ['success' => true, 'aml' => publicAml($users[$index])]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Method not allowed.']);
}

if (amlStatus($users[$index]) === 'verified') {
    respond(409, ['success' => false, 'message' => 'This account is already AML verified.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, ['success' => false, 'message' => 'Invalid submission.']);
}

$required = [
    'legalFirstName' => 'Legal first name',
    'legalLastName' => 'Legal last name',
    'dateOfBirth' => 'Date of birth',
    'gender' => 'Gender',
    'mobileNumber' => 'Mobile number',
    'streetAddress' => 'Street address',
    'suburb' => 'Suburb or city',
    'postcode' => 'Postcode',
    'state' => 'State or territory',
    'country' => 'Country',
    'idType' => 'ID type',
    'documentNumber' => 'Document number',
    'issuingCountry' => 'Issuing country',
    'expiryDate' => 'Document expiry date',
    'taxResidency' => 'Country of tax residency',
    'sourceOfFunds' => 'Source of funds',
    'expectedVolume' => 'Expected monthly trading volume'
];

$errors = [];
foreach ($required as $key => $label) {
    if (clean($input[$key] ?? '') === '') $errors[] = $label . ' is required.';
}

$dob = DateTime::createFromFormat('Y-m-d', clean($input['dateOfBirth'] ?? ''));
$today = new DateTime('today');
if (!$dob || $dob->format('Y-m-d') !== clean($input['dateOfBirth'] ?? '') || $dob > $today) {
    $errors[] = 'Enter a valid date of birth.';
} elseif ($dob->diff($today)->y < 18) {
    $errors[] = 'You must be at least 18 years old.';
}

$expiry = DateTime::createFromFormat('Y-m-d', clean($input['expiryDate'] ?? ''));
if (!$expiry || $expiry->format('Y-m-d') !== clean($input['expiryDate'] ?? '')) {
    $errors[] = 'Enter a valid document expiry date.';
} elseif ($expiry < $today) {
    $errors[] = 'The identity document has expired.';
}

if (empty($input['sanctionsConfirmed'])) $errors[] = 'Sanctions declaration is required.';
if (empty($input['consentAccuracy'])) $errors[] = 'Accuracy declaration is required.';
if (empty($input['consentPrivacy'])) $errors[] = 'Privacy consent is required.';
if (empty($input['consentAml'])) $errors[] = 'AML/KYC review consent is required.';

if ($errors) {
    respond(422, ['success' => false, 'message' => implode(' ', $errors)]);
}

$aml = [
    'title' => clean($input['title'] ?? '', 30),
    'legalFirstName' => clean($input['legalFirstName'] ?? ''),
    'middleNames' => clean($input['middleNames'] ?? ''),
    'legalLastName' => clean($input['legalLastName'] ?? ''),
    'dateOfBirth' => clean($input['dateOfBirth'] ?? '', 10),
    'gender' => clean($input['gender'] ?? '', 40),
    'mobileNumber' => clean($input['mobileNumber'] ?? '', 50),
    'preferred2fa' => clean($input['preferred2fa'] ?? '', 40),
    'streetAddress' => clean($input['streetAddress'] ?? ''),
    'suburb' => clean($input['suburb'] ?? ''),
    'postcode' => clean($input['postcode'] ?? '', 30),
    'state' => clean($input['state'] ?? '', 80),
    'country' => clean($input['country'] ?? '', 80),
    'idType' => clean($input['idType'] ?? '', 80),
    'documentNumber' => clean($input['documentNumber'] ?? '', 100),
    'issuingCountry' => clean($input['issuingCountry'] ?? '', 80),
    'expiryDate' => clean($input['expiryDate'] ?? '', 10),
    'taxReference' => clean($input['taxReference'] ?? '', 100),
    'businessNumber' => clean($input['businessNumber'] ?? '', 100),
    'taxResidency' => clean($input['taxResidency'] ?? '', 80),
    'additionalTaxResidencies' => clean($input['additionalTaxResidencies'] ?? ''),
    'sourceOfFunds' => clean($input['sourceOfFunds'] ?? '', 100),
    'expectedVolume' => clean($input['expectedVolume'] ?? '', 100),
    'isPep' => !empty($input['isPep']),
    'sanctionsConfirmed' => true,
    'consentAccuracy' => true,
    'consentPrivacy' => true,
    'consentAml' => true
];

$users[$index]['aml'] = $aml;
$users[$index]['amlStatus'] = 'under_review';
$users[$index]['amlSubmittedAt'] = gmdate('c');
$users[$index]['amlReviewedAt'] = '';
$users[$index]['amlReviewNote'] = '';
saveUsers($usersFile, $users);

respond(200, [
    'success' => true,
    'message' => 'AML details submitted for HarbourX review.',
    'aml' => publicAml($users[$index])
]);
?>
