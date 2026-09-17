<?php
/**
 * Monthly statements.
 *
 *   ?action=list           the signed-in client's statement periods, as JSON
 *   ?action=view&period=…  one period in full, as JSON
 *   ?action=pdf&period=…   the same period as a PDF download
 *
 * Statements are derived from the transactions already on the client record —
 * nothing new is stored. Amounts on those records are free text written by
 * whichever flow created them, so parseAmount below reads what it can and says
 * so when it cannot, rather than inventing a figure for a financial document.
 */

require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/locale_config.php';

$secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $secureCookie,
    'samesite' => 'Lax'
]);
session_start();

$usersFile = __DIR__ . '/data/users.json';

function respond(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}

/**
 * Read a transaction's amount string.
 *
 * The shapes in the wild:
 *   "+2.56 BTC"                        received
 *   "+ 0.0361 BTC"                     received, space after the sign
 *   "+0.0029"                          no unit at all
 *   "-A$1,000.00"                      fiat out
 *   "-2.56000000 BTC -> A$286,617.60"  a conversion: BTC out, fiat in
 *
 * Returns btc and fiat deltas, either of which may be null when this string
 * does not carry that side. `parsed` is false when nothing could be read.
 */
function parseAmount(string $raw): array
{
    $out = ['btc' => null, 'fiat' => null, 'currency' => null, 'parsed' => false];
    $text = trim($raw);
    if ($text === '') return $out;

    // A conversion names both sides: what left, and what arrived.
    if (str_contains($text, '->')) {
        [$left, $right] = array_map('trim', explode('->', $text, 2));
        $from = parseComponent($left);
        $to = parseComponent($right);

        if ($from['kind'] === 'btc') $out['btc'] = -abs($from['value']);
        elseif ($from['kind'] === 'fiat') { $out['fiat'] = -abs($from['value']); $out['currency'] = $from['currency']; }

        if ($to['kind'] === 'btc') $out['btc'] = ($out['btc'] ?? 0) + abs($to['value']);
        elseif ($to['kind'] === 'fiat') { $out['fiat'] = abs($to['value']); $out['currency'] = $to['currency']; }

        $out['parsed'] = $from['kind'] !== null || $to['kind'] !== null;
        return $out;
    }

    $one = parseComponent($text);
    if ($one['kind'] === 'btc') { $out['btc'] = $one['value']; $out['parsed'] = true; }
    elseif ($one['kind'] === 'fiat') { $out['fiat'] = $one['value']; $out['currency'] = $one['currency']; $out['parsed'] = true; }
    return $out;
}

/** One side of an amount: its sign, its number, and whether it is BTC or fiat. */
function parseComponent(string $text): array
{
    $text = trim($text);
    $result = ['kind' => null, 'value' => 0.0, 'currency' => null];
    if ($text === '') return $result;

    // The sign may be separated from the number by a space ("+ 0.0361 BTC").
    $sign = 1.0;
    if (preg_match('/^\s*([+-])\s*/', $text, $m)) {
        $sign = $m[1] === '-' ? -1.0 : 1.0;
        $text = substr($text, strlen($m[0]));
    }

    if (!preg_match('/([0-9][0-9,]*(?:\.[0-9]+)?)/', $text, $num)) return $result;
    $value = (float)str_replace(',', '', $num[1]) * $sign;

    if (preg_match('/\bBTC\b/i', $text)) {
        return ['kind' => 'btc', 'value' => $value, 'currency' => null];
    }

    // A currency symbol or a three-letter code makes it fiat.
    if (preg_match('/(A\$|NZ\$|C\$|US\$|\$|€|£|¥|₹)/u', $text, $sym)) {
        return ['kind' => 'fiat', 'value' => $value, 'currency' => $sym[1]];
    }
    if (preg_match('/\b([A-Z]{3})\b/', $text, $code) && $code[1] !== 'BTC') {
        return ['kind' => 'fiat', 'value' => $value, 'currency' => $code[1]];
    }

    // No unit. Every bare figure in this data is a BTC amount, and the
    // alternative is discarding it, so treat it as BTC and let the statement
    // show the original string beside it.
    return ['kind' => 'btc', 'value' => $value, 'currency' => null];
}

/** Group a client's transactions into months, newest period first. */
function buildStatements(array $transactions): array
{
    $periods = [];
    foreach ($transactions as $tx) {
        $date = trim((string)($tx['date'] ?? ''));
        if (!preg_match('/^(\d{4})-(\d{2})/', $date, $m)) continue;
        $key = $m[1] . '-' . $m[2];

        if (!isset($periods[$key])) {
            $periods[$key] = [
                'period' => $key,
                'label' => date('F Y', mktime(0, 0, 0, (int)$m[2], 1, (int)$m[1])),
                'reference' => 'HX-STMT-' . $key,
                'transactions' => [],
                'btcIn' => 0.0, 'btcOut' => 0.0,
                'fiatIn' => 0.0, 'fiatOut' => 0.0,
                'currency' => null,
                'unparsed' => 0,
            ];
        }

        $parsed = parseAmount((string)($tx['amount'] ?? ''));
        if (!$parsed['parsed']) $periods[$key]['unparsed']++;
        if ($parsed['btc'] !== null) {
            if ($parsed['btc'] >= 0) $periods[$key]['btcIn'] += $parsed['btc'];
            else $periods[$key]['btcOut'] += abs($parsed['btc']);
        }
        if ($parsed['fiat'] !== null) {
            if ($parsed['fiat'] >= 0) $periods[$key]['fiatIn'] += $parsed['fiat'];
            else $periods[$key]['fiatOut'] += abs($parsed['fiat']);
            if ($parsed['currency']) $periods[$key]['currency'] = $parsed['currency'];
        }

        $periods[$key]['transactions'][] = [
            'date' => $date,
            'type' => (string)($tx['type'] ?? ''),
            'amount' => (string)($tx['amount'] ?? ''),
            'status' => (string)($tx['status'] ?? ''),
            'details' => (string)($tx['details'] ?? ''),
        ];
    }

    foreach ($periods as &$period) {
        usort($period['transactions'], static fn($a, $b) => strcmp($b['date'], $a['date']));
        $period['count'] = count($period['transactions']);
    }
    unset($period);

    krsort($periods);
    return array_values($periods);
}

/* ------------------------------------------------------------------- auth */

$email = strtolower((string)($_SESSION['client_email'] ?? ''));
if ($email === '') {
    respond(401, ['success' => false, 'message' => 'Sign in again to see your statements.']);
}

$users = file_exists($usersFile) ? json_decode((string)file_get_contents($usersFile), true) : [];
$users = is_array($users) ? $users : [];

$me = null;
foreach ($users as $user) {
    if (strtolower((string)($user['email'] ?? '')) === $email) { $me = $user; break; }
}
if ($me === null) {
    respond(404, ['success' => false, 'message' => 'Account not found.']);
}

$transactions = is_array($me['transactions'] ?? null) ? $me['transactions'] : [];
$statements = buildStatements($transactions);

$action = strtolower(trim((string)($_GET['action'] ?? 'list')));
$wanted = trim((string)($_GET['period'] ?? ''));

/* ------------------------------------------------------------------- list */
if ($action === 'list') {
    $summary = array_map(static function ($s) {
        unset($s['transactions']);
        return $s;
    }, $statements);
    respond(200, [
        'success' => true,
        'account' => [
            'name' => (string)($me['name'] ?? ''),
            'email' => (string)($me['email'] ?? ''),
            'currency' => strtoupper((string)($me['currency'] ?? 'USD')),
        ],
        'statements' => $summary,
    ]);
}

$period = null;
foreach ($statements as $s) {
    if ($s['period'] === $wanted) { $period = $s; break; }
}
if ($period === null) {
    respond(404, ['success' => false, 'message' => 'No statement for that period.']);
}

/* ------------------------------------------------------------------- view */
if ($action === 'view') {
    respond(200, ['success' => true, 'statement' => $period]);
}

/* -------------------------------------------------------------------- pdf */
if ($action !== 'pdf') {
    respond(400, ['success' => false, 'message' => 'Unknown action.']);
}

$currencyCode = strtoupper((string)($me['currency'] ?? 'USD'));
$symbol = function_exists('currencySymbolFor') ? currencySymbolFor($currencyCode) : '';
$pdf = new HxPdf(['title' => 'HarbourX statement — ' . $period['label']]);

$margin = 40.0;
$width = HxPdf::WIDTH - $margin * 2;
$ink = [0.05, 0.12, 0.19];
$muted = [0.42, 0.48, 0.54];

/** Page furniture, repeated on every page the rows spill onto. */
$header = function (HxPdf $pdf, array $period, array $me, string $currencyCode) use ($margin, $width, $ink, $muted) {
    $pdf->box($margin, 36, $width, 62, ['fill' => [0.04, 0.10, 0.16]]);
    $pdf->text('HarbourX', $margin + 16, 62, ['size' => 18, 'font' => 'bold', 'colour' => [1, 1, 1]]);
    $pdf->text('Account statement', $margin + 16, 82, ['size' => 9.5, 'colour' => [0.62, 0.78, 0.85]]);
    $pdf->text($period['label'], $margin, 62, ['size' => 13, 'font' => 'bold', 'colour' => [1, 1, 1], 'align' => 'right', 'width' => $width - 16]);
    $pdf->text($period['reference'], $margin, 82, ['size' => 9, 'colour' => [0.62, 0.78, 0.85], 'align' => 'right', 'width' => $width - 16]);

    $y = 126;
    $pdf->text('Account holder', $margin, $y, ['size' => 8, 'colour' => $muted]);
    $pdf->text((string)($me['name'] ?? ''), $margin, $y + 14, ['size' => 11, 'font' => 'bold', 'colour' => $ink]);
    $pdf->text('Email', $margin + 200, $y, ['size' => 8, 'colour' => $muted]);
    $pdf->text((string)($me['email'] ?? ''), $margin + 200, $y + 14, ['size' => 11, 'colour' => $ink]);
    $pdf->text('Currency', $margin + 420, $y, ['size' => 8, 'colour' => $muted]);
    $pdf->text($currencyCode, $margin + 420, $y + 14, ['size' => 11, 'font' => 'bold', 'colour' => $ink]);

    return $y + 34;
};

$columnHeader = function (HxPdf $pdf, float $y) use ($margin, $width, $muted) {
    $pdf->box($margin, $y, $width, 20, ['fill' => [0.93, 0.95, 0.96]]);
    $pdf->text('Date', $margin + 8, $y + 14, ['size' => 8, 'font' => 'bold', 'colour' => $muted]);
    $pdf->text('Description', $margin + 78, $y + 14, ['size' => 8, 'font' => 'bold', 'colour' => $muted]);
    $pdf->text('Status', $margin + 288, $y + 14, ['size' => 8, 'font' => 'bold', 'colour' => $muted]);
    $pdf->text('Amount', $margin, $y + 14, ['size' => 8, 'font' => 'bold', 'colour' => $muted, 'align' => 'right', 'width' => $width - 8]);
    return $y + 30;
};

$y = $header($pdf, $period, $me, $currencyCode);

// --- summary -----------------------------------------------------------------
$pdf->text('Summary', $margin, $y + 6, ['size' => 11, 'font' => 'bold', 'colour' => $ink]);
$y += 18;
$pdf->box($margin, $y, $width, 56, ['fill' => [0.96, 0.97, 0.98]]);

$cells = [
    ['Transactions', (string)$period['count']],
    ['Bitcoin in', rtrim(rtrim(number_format($period['btcIn'], 8), '0'), '.') . ' BTC'],
    ['Bitcoin out', rtrim(rtrim(number_format($period['btcOut'], 8), '0'), '.') . ' BTC'],
    ['Cash in', $symbol . number_format($period['fiatIn'], 2)],
    ['Cash out', $symbol . number_format($period['fiatOut'], 2)],
];
$cellWidth = $width / count($cells);
foreach ($cells as $i => [$label, $value]) {
    $cx = $margin + $i * $cellWidth + 10;
    $pdf->text($label, $cx, $y + 20, ['size' => 8, 'colour' => $muted]);
    $pdf->text($value, $cx, $y + 40, ['size' => 10.5, 'font' => 'bold', 'colour' => $ink]);
}
$y += 76;

// --- transactions ------------------------------------------------------------
$pdf->text('Transactions', $margin, $y, ['size' => 11, 'font' => 'bold', 'colour' => $ink]);
$y += 10;
$y = $columnHeader($pdf, $y);

$bottom = HxPdf::HEIGHT - 70;
foreach ($period['transactions'] as $i => $tx) {
    if ($y > $bottom) {
        $pdf->newPage();
        $y = $header($pdf, $period, $me, $currencyCode) + 6;
        $y = $columnHeader($pdf, $y);
    }
    if ($i % 2 === 1) {
        $pdf->box($margin, $y - 11, $width, 19, ['fill' => [0.975, 0.98, 0.985]]);
    }

    $pdf->text($tx['date'], $margin + 8, $y, ['size' => 8.5, 'colour' => $ink]);

    $description = $tx['type'];
    if ($tx['details'] !== '') $description .= ' — ' . $tx['details'];
    if ($pdf->textWidth($description, 8.5) > 200) {
        while ($pdf->textWidth($description . '…', 8.5) > 200 && strlen($description) > 4) {
            $description = substr($description, 0, -1);
        }
        $description .= '…';
    }
    $pdf->text($description, $margin + 78, $y, ['size' => 8.5, 'colour' => $ink]);
    $pdf->text($tx['status'], $margin + 288, $y, ['size' => 8.5, 'colour' => $muted]);
    $pdf->text($tx['amount'], $margin, $y, ['size' => 8.5, 'font' => 'mono', 'colour' => $ink, 'align' => 'right', 'width' => $width - 8]);

    $y += 19;
}

if ($period['unparsed'] > 0) {
    $y += 10;
    $y = $pdf->paragraph(
        sprintf(
            '%d transaction(s) in this period record their amount in a form this summary could not total. They are listed above exactly as recorded, and are not counted in the figures.',
            $period['unparsed']
        ),
        $margin, $y, $width, ['size' => 8, 'colour' => $muted]
    );
}

// --- footer ------------------------------------------------------------------
$footY = HxPdf::HEIGHT - 52;
$pdf->rule($margin, $footY, $width);
$pdf->text('Generated ' . gmdate('j F Y H:i') . ' UTC · HarbourX · harbourx.org', $margin, $footY + 14, ['size' => 8, 'colour' => $muted]);
$pdf->text('Demo account — sample data only.', $margin, $footY + 14, ['size' => 8, 'colour' => $muted, 'align' => 'right', 'width' => $width]);

$bytes = $pdf->render();
$filename = 'HarbourX-statement-' . $period['period'] . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: no-store');
echo $bytes;
