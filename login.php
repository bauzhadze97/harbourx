<?php
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
$email = strtolower(trim($input['email'] ?? ''));
$password = $input['password'] ?? '';

$file = __DIR__ . '/data/users.json';
$users = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
$users = is_array($users) ? $users : [];
unset($_SESSION['client_email']);

foreach ($users as $user) {
    if (strtolower($user['email'] ?? '') === $email && ($user['password'] ?? '') === $password) {
        session_regenerate_id(true);
        $_SESSION['client_email'] = strtolower($user['email']);
        unset($user['password']);
        unset($user['aml']);
        unset($user['bankAccounts']);
        $status = strtolower((string)($user['amlStatus'] ?? 'unverified'));
        $user['amlStatus'] = in_array($status, ['verified', 'under_review', 'unverified'], true)
            ? $status
            : 'unverified';
        echo json_encode([
            'success' => true,
            'user' => $user
        ]);
        exit;
    }
}

echo json_encode([
    'success' => false,
    'message' => 'Wrong email or password'
]);
?>
