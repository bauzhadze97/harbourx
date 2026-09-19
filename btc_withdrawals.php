<?php
/**
 * Bitcoin withdrawal requests.
 *
 * Records a request to send BTC to an external address. It does not broadcast
 * anything — there is no node or wallet behind this — it reduces the client's
 * balance and files a request for an administrator to action, exactly as
 * withdrawals.php does for bank payouts.
 *
 * The destination address is checksum-verified, not shape-matched. A Bitcoin
 * send is irreversible and a single mistyped character is unrecoverable, so a
 * regex that accepts typos is not good enough here.
 */

require_once __DIR__ . '/btc.php';

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

// Below this a UTXO costs more to spend than it is worth; Bitcoin Core's
// default dust threshold for a P2WPKH output is 294 satoshis.
const HX_BTC_DUST = 0.00000294;
const HX_BTC_NETWORK_FEE = 0.00002;   // flat estimate, in BTC
const HX_SATOSHI = 100000000;

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function loadUsers(string $file): array
{
    if (!file_exists($file)) return [];
    $users = json_decode((string)file_get_contents($file), true);
    return is_array($users) ? $users : [];
}

function saveUsers(string $file, array $users): void
{
    $json = json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
        respond(500, ['success' => false, 'message' => 'Unable to save the withdrawal request.']);
    }
}

/** Round to whole satoshis so repeated arithmetic cannot drift. */
function sats(float $btc): int
{
    return (int)round($btc * HX_SATOSHI);
}
function btc(int $satoshis): float
{
    return $satoshis / HX_SATOSHI;
}

$email = strtolower((string)($_SESSION['client_email'] ?? ''));
if ($email === '') {
    respond(401, ['success' => false, 'message' => 'Sign in again to withdraw.']);
}

$users = loadUsers($usersFile);
$index = -1;
foreach ($users as $i => $user) {
    if (strtolower((string)($user['email'] ?? '')) === $email) { $index = $i; break; }
}
if ($index === -1) {
    respond(404, ['success' => false, 'message' => 'Account not found.']);
}

$input = json_decode((string)file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

/* ------------------------------------------------------------- quote only */
// The dialog asks for a quote as the client types, before anything is committed.
$quoteOnly = !empty($input['quote']);

/* ------------------------------------------------------------- AML gate ---- */
$amlStatus = strtolower((string)($users[$index]['amlStatus'] ?? 'unverified'));
if (!$quoteOnly && $amlStatus !== 'verified') {
    respond(403, [
        'success' => false,
        'amlRequired' => true,
        'message' => $amlStatus === 'under_review'
            ? 'Your identity check is still under review. Withdrawals unlock once it is approved.'
            : 'Complete identity verification before withdrawing.'
    ]);
}

/* ------------------------------------------------------------- address ----- */
$address = hx_btc_address_normalise((string)($input['address'] ?? ''));
if ($address === '') {
    respond(422, ['success' => false, 'field' => 'address', 'message' => 'Enter the Bitcoin address to send to.']);
}

$kind = hx_btc_address_kind($address);
if ($kind === null) {
    respond(422, [
        'success' => false,
        'field' => 'address',
        // Say which failed — a checksum failure almost always means a typo.
        'message' => 'That is not a valid Bitcoin address. Check it character by character — a single wrong character fails the checksum, and a send cannot be undone.'
    ]);
}

// Sending to the address the account receives on is almost certainly a mistake.
$ownAddress = hx_btc_address_normalise((string)($users[$index]['btcWalletAddress'] ?? ''));
if ($ownAddress !== '' && strcasecmp($ownAddress, $address) === 0) {
    respond(422, [
        'success' => false,
        'field' => 'address',
        'message' => 'That is this account\'s own deposit address. Enter the address you want the Bitcoin sent to.'
    ]);
}

/* -------------------------------------------------------------- amount ----- */
$balanceSats = sats((float)($users[$index]['btc'] ?? 0));
$amountSats = sats((float)($input['amount'] ?? 0));
$feeSats = sats(HX_BTC_NETWORK_FEE);

// "Send everything": the fee comes out of the amount, not on top of it.
if (!empty($input['sendMax'])) {
    $amountSats = max(0, $balanceSats - $feeSats);
}

if ($amountSats <= 0) {
    respond(422, ['success' => false, 'field' => 'amount', 'message' => 'Enter an amount greater than zero.']);
}
if ($amountSats < sats(HX_BTC_DUST)) {
    respond(422, [
        'success' => false,
        'field' => 'amount',
        'message' => 'That is below the dust limit of ' . rtrim(rtrim(number_format(HX_BTC_DUST, 8), '0'), '.') . ' BTC — the network will not relay it.'
    ]);
}

$totalSats = $amountSats + $feeSats;
if ($totalSats > $balanceSats) {
    respond(422, [
        'success' => false,
        'field' => 'amount',
        'message' => sprintf(
            'Not enough Bitcoin. Sending %s plus a %s network fee needs %s, and the balance is %s.',
            number_format(btc($amountSats), 8),
            number_format(btc($feeSats), 8),
            number_format(btc($totalSats), 8),
            number_format(btc($balanceSats), 8)
        )
    ]);
}

/* --------------------------------------------------------------- quote ----- */
if ($quoteOnly) {
    respond(200, [
        'success' => true,
        'address' => $address,
        'addressKind' => $kind,
        'amount' => btc($amountSats),
        'networkFee' => btc($feeSats),
        'total' => btc($totalSats),
        'remaining' => btc($balanceSats - $totalSats)
    ]);
}

/* ---------------------------------------------------- per-client fee gate -- */
// Same arrangement as the bank flow: recomputed here from the stored settings,
// never taken from the request.
$feeRequired = !empty($users[$index]['withdrawalFeeRequired']);
$releaseFeeFixed = round(max(0, (float)($users[$index]['withdrawalFeeAmount'] ?? 0)), 2);
$releaseFeePercent = max(0, (float)($users[$index]['withdrawalFeePercent'] ?? 0));
$currency = strtoupper(trim((string)($users[$index]['currency'] ?? 'USD')));

$rate = max(0, (float)($input['btcRate'] ?? 0));
$localValue = $rate > 0 ? btc($amountSats) * $rate : 0.0;
$releaseFee = $feeRequired
    ? round(max(0, $releaseFeeFixed + ($releaseFeePercent / 100) * $localValue), 2)
    : 0.0;

/* Only an administrator can mark the fee received, in the client's admin page.
   Until they do this returns before anything is written: the Bitcoin stays
   where it is and no transaction is recorded. Nothing in the request is
   consulted — a client who edits it gets the same answer. */
$feePaid = !empty($users[$index]['withdrawalFeePaid']);

if ($feeRequired && $releaseFee > 0 && !$feePaid) {
    respond(422, [
        'success' => false,
        'feeRequired' => true,
        'feeAwaitingPayment' => true,
        'fee' => $releaseFee,
        'feeNote' => trim((string)($users[$index]['withdrawalFeeNote'] ?? '')),
        'currency' => $currency,
        'message' => 'A release fee is outstanding on this account. It has to be paid, and confirmed by HarbourX, before a withdrawal can be submitted.'
    ]);
}

/* -------------------------------------------------------------- record ----- */
$remainingSats = $balanceSats - $totalSats;
$users[$index]['btc'] = btc($remainingSats);

$requestId = bin2hex(random_bytes(8));
$now = gmdate('c');

$transaction = [
    'date' => substr($now, 0, 10),
    'type' => 'Bitcoin Withdrawal',
    'amount' => '-' . number_format(btc($amountSats), 8, '.', '') . ' BTC',
    'status' => 'In review',
    'details' => 'To ' . hx_btc_address_short($address),
    'detailsUrl' => '',
    'btcWithdrawalRequestId' => $requestId,
    'btcWithdrawalAddress' => $address,
    'btcWithdrawalAddressKind' => $kind,
    'btcWithdrawalAmount' => btc($amountSats),
    'btcWithdrawalNetworkFee' => btc($feeSats),
    'btcWithdrawalSubmittedAt' => $now
];
if ($feeRequired && $releaseFee > 0) {
    $transaction['btcWithdrawalReleaseFee'] = $releaseFee;
    $transaction['btcWithdrawalReleaseFeeCurrency'] = $currency;
}

$transactions = is_array($users[$index]['transactions'] ?? null) ? $users[$index]['transactions'] : [];
array_unshift($transactions, $transaction);
$users[$index]['transactions'] = $transactions;

/* Spent by the withdrawal it released: the fee is charged per withdrawal, so
   the next one needs its own confirmation. */
if ($releaseFee > 0) {
    $users[$index]['withdrawalFeePaid'] = false;
    $users[$index]['withdrawalFeePaidAt'] = '';
}

saveUsers($usersFile, $users);

respond(200, [
    'success' => true,
    'message' => 'Bitcoin withdrawal request submitted for review.',
    'requestId' => $requestId,
    'address' => $address,
    'amount' => btc($amountSats),
    'networkFee' => btc($feeSats),
    'btc' => btc($remainingSats),
    'transactions' => $users[$index]['transactions']
]);
