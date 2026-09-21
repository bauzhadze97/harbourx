<?php
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/admin_common.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

const HX_TEST_STATE_VERSION = 1;

function hxTestStateFile(): string
{
    return __DIR__ . '/data/harbcoin.json';
}

function hxTestDefaultState(): array
{
    return [
        'version' => HX_TEST_STATE_VERSION,
        'basePrice' => 125.00,
        'overridePrice' => 0.0,
        'overrideUntil' => 0,
        'updatedAt' => gmdate('c'),
        'updatedBy' => 'system',
    ];
}

function hxTestLoadState(): array
{
    $file = hxTestStateFile();
    $state = file_exists($file) ? json_decode((string)file_get_contents($file), true) : null;
    $state = is_array($state) ? array_merge(hxTestDefaultState(), $state) : hxTestDefaultState();
    $state['basePrice'] = max(0.01, min(1000000000, (float)$state['basePrice']));
    $state['overridePrice'] = max(0, min(1000000000, (float)$state['overridePrice']));
    $state['overrideUntil'] = max(0, (int)$state['overrideUntil']);
    return $state;
}

function hxTestSaveState(array $state): bool
{
    $dir = dirname(hxTestStateFile());
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) return false;
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    return file_put_contents(hxTestStateFile(), $json . "\n", LOCK_EX) !== false;
}

function hxTestCurrentPrice(array $state): float
{
    return $state['overridePrice'] > 0 && $state['overrideUntil'] > time()
        ? (float)$state['overridePrice']
        : (float)$state['basePrice'];
}

function hxTestOverrideActive(array $state): bool
{
    return $state['overridePrice'] > 0 && $state['overrideUntil'] > time();
}

function hxTestRequireAdmin(): void
{
    if (empty($_SESSION['admin_logged_in'])) {
        header('Location: admin.php');
        exit;
    }
}

function hxTestCsrf(): string
{
    if (empty($_SESSION['hx_test_csrf'])) {
        $_SESSION['hx_test_csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['hx_test_csrf'];
}

function hxTestValidCsrf($value): bool
{
    return is_string($value) && hash_equals(hxTestCsrf(), $value);
}

function hxTestMoney(float $value): string
{
    return 'A$' . number_format($value, 2);
}
