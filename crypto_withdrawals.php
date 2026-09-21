<?php
/**
 * Crypto withdrawal requests, for any asset in the table.
 *
 * Same flow as the Bitcoin endpoint — it is the same code — except that the
 * asset comes from the request. An asset the table does not list is refused
 * before anything else is looked at, so this cannot be used to invent a
 * holding by naming one.
 */

require_once __DIR__ . '/crypto_withdrawal_core.php';

$secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $secureCookie,
    'samesite' => 'Lax'
]);
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

$input = json_decode((string)file_get_contents('php://input'), true);
hx_crypto_withdrawal_handle(is_array($input) ? $input : []);
