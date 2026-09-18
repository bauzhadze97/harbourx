<?php
require_once __DIR__ . '/admin_common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Admin authentication required.']);
    exit;
}

$snapshot = currentAdminAlertSnapshot();
echo json_encode([
    'count' => (int)($snapshot['count'] ?? 0),
    'body' => (string)($snapshot['body'] ?? ''),
    'signature' => (string)($snapshot['signature'] ?? ''),
    'paymentStats' => $snapshot['paymentStats'] ?? [],
    'callbackStats' => $snapshot['callbackStats'] ?? []
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

