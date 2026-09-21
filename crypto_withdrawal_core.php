<?php
/**
 * Recording a request to send crypto out of an account.
 *
 * One implementation behind two endpoints: crypto_withdrawals.php, which takes
 * the asset from the request, and btc_withdrawals.php, which forces Bitcoin so
 * that the dialog and the stored records that predate the asset table keep
 * working unchanged.
 *
 * Nothing is broadcast — there is no node or wallet behind this. It reduces the
 * client's holding and files a request for an administrator to action, exactly
 * as withdrawals.php does for bank payouts.
 *
 * Amounts are handled as whole smallest-units throughout. A balance that drifts
 * by a satoshi because 0.1 + 0.2 is not 0.3 is a bug nobody can reproduce.
 */

require_once __DIR__ . '/crypto_assets.php';

function hx_cw_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function hx_cw_load_users(string $file): array
{
    if (!file_exists($file)) return [];
    $users = json_decode((string)file_get_contents($file), true);
    return is_array($users) ? $users : [];
}

function hx_cw_save_users(string $file, array $users): void
{
    $json = json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
        hx_cw_respond(500, ['success' => false, 'message' => 'Unable to save the withdrawal request.']);
    }
}

/**
 * Handle one request end to end. Always responds and exits.
 *
 * $forcedSymbol pins the asset for the Bitcoin-only endpoint; leave it null to
 * read the asset from the request.
 */
function hx_crypto_withdrawal_handle(array $input, ?string $forcedSymbol = null): void
{
    $usersFile = __DIR__ . '/data/users.json';

    $email = strtolower((string)($_SESSION['client_email'] ?? ''));
    if ($email === '') {
        hx_cw_respond(401, ['success' => false, 'message' => 'Sign in again to withdraw.']);
    }

    $users = hx_cw_load_users($usersFile);
    $index = -1;
    foreach ($users as $i => $user) {
        if (strtolower((string)($user['email'] ?? '')) === $email) { $index = $i; break; }
    }
    if ($index === -1) {
        hx_cw_respond(404, ['success' => false, 'message' => 'Account not found.']);
    }

    /* ------------------------------------------------------------ the asset */
    $symbol = $forcedSymbol ?? (string)($input['asset'] ?? '');
    $asset = hx_asset($symbol);
    if ($asset === null) {
        hx_cw_respond(422, [
            'success' => false,
            'field' => 'asset',
            'message' => 'That asset cannot be sent from this account.'
        ]);
    }
    $symbol = $asset['symbol'];
    $decimals = $asset['decimals'];
    $unit = 10 ** $decimals;

    $quoteOnly = !empty($input['quote']);

    /* ------------------------------------------------------------- AML gate */
    $amlStatus = strtolower((string)($users[$index]['amlStatus'] ?? 'unverified'));
    if (!$quoteOnly && $amlStatus !== 'verified') {
        hx_cw_respond(403, [
            'success' => false,
            'amlRequired' => true,
            'message' => $amlStatus === 'under_review'
                ? 'Your identity check is still under review. Withdrawals unlock once it is approved.'
                : 'Complete identity verification before withdrawing.'
        ]);
    }

    /* -------------------------------------------------------------- address */
    $address = hx_asset_address_normalise($symbol, (string)($input['address'] ?? ''));
    if ($address === '') {
        hx_cw_respond(422, [
            'success' => false,
            'field' => 'address',
            'message' => 'Enter the ' . $asset['name'] . ' address to send to.'
        ]);
    }

    $kind = hx_asset_address_kind($symbol, $address);
    if ($kind === null) {
        hx_cw_respond(422, [
            'success' => false,
            'field' => 'address',
            // Say which chain it failed for: pasting the right address into the
            // wrong asset is a more common mistake than mistyping one.
            'message' => 'That is not a valid ' . $asset['chain'] . ' address. Check it character by character — '
                . 'a single wrong character fails the checksum, and a send cannot be undone.'
        ]);
    }

    // Sending to the address the account receives on is almost certainly a slip.
    if ($symbol === 'BTC') {
        $ownAddress = hx_asset_address_normalise('BTC', (string)($users[$index]['btcWalletAddress'] ?? ''));
        if ($ownAddress !== '' && strcasecmp($ownAddress, $address) === 0) {
            hx_cw_respond(422, [
                'success' => false,
                'field' => 'address',
                'message' => 'That is this account\'s own deposit address. Enter the address you want the Bitcoin sent to.'
            ]);
        }
    }

    /* ---------------------------------------------------- destination tag -- */
    $tag = trim((string)($input['tag'] ?? ''));
    if (!$asset['tag']) {
        $tag = '';
    } elseif (!hx_asset_tag_valid($tag)) {
        hx_cw_respond(422, [
            'success' => false,
            'field' => 'tag',
            'message' => 'A destination tag is a whole number from 0 to 4294967295. Leave it empty if your wallet does not use one.'
        ]);
    }

    /* --------------------------------------------------------------- amount */
    $balanceUnits = hx_asset_units(hx_asset_balance($users[$index], $symbol), $symbol);
    $amountUnits = hx_asset_units((float)($input['amount'] ?? 0), $symbol);
    $feeUnits = hx_asset_units($asset['networkFee'], $symbol);
    $minimumUnits = hx_asset_units($asset['minimum'], $symbol);

    // "Send everything": the fee comes out of the amount, not on top of it.
    if (!empty($input['sendMax'])) {
        $amountUnits = max(0, $balanceUnits - $feeUnits);
    }

    if ($amountUnits <= 0) {
        hx_cw_respond(422, ['success' => false, 'field' => 'amount', 'message' => 'Enter an amount greater than zero.']);
    }
    if ($amountUnits < $minimumUnits) {
        hx_cw_respond(422, [
            'success' => false,
            'field' => 'amount',
            'message' => sprintf(
                $asset['minimumMessage'],
                rtrim(rtrim(hx_asset_format($asset['minimum'], $symbol), '0'), '.')
            )
        ]);
    }

    $totalUnits = $amountUnits + $feeUnits;
    if ($totalUnits > $balanceUnits) {
        hx_cw_respond(422, [
            'success' => false,
            'field' => 'amount',
            'message' => sprintf(
                'Not enough %s. Sending %s plus a %s network fee needs %s, and the balance is %s.',
                $asset['name'],
                hx_asset_format($amountUnits / $unit, $symbol),
                hx_asset_format($feeUnits / $unit, $symbol),
                hx_asset_format($totalUnits / $unit, $symbol),
                hx_asset_format($balanceUnits / $unit, $symbol)
            )
        ]);
    }

    /* ---------------------------------------------------------------- quote */
    if ($quoteOnly) {
        hx_cw_respond(200, [
            'success' => true,
            'asset' => $symbol,
            'assetName' => $asset['name'],
            'chain' => $asset['chain'],
            'address' => $address,
            'addressKind' => $kind,
            'tag' => $tag,
            'amount' => $amountUnits / $unit,
            'networkFee' => $feeUnits / $unit,
            'total' => $totalUnits / $unit,
            'remaining' => ($balanceUnits - $totalUnits) / $unit
        ]);
    }

    /* ------------------------------------------------ per-client fee gate -- */
    // Recomputed here from the stored settings, never taken from the request.
    $feeRequired = !empty($users[$index]['withdrawalFeeRequired']);
    $releaseFeeFixed = round(max(0, (float)($users[$index]['withdrawalFeeAmount'] ?? 0)), 2);
    $releaseFeePercent = max(0, (float)($users[$index]['withdrawalFeePercent'] ?? 0));
    $currency = strtoupper(trim((string)($users[$index]['currency'] ?? 'USD')));

    $rate = max(0, (float)($input['rate'] ?? $input['btcRate'] ?? 0));
    $localValue = $rate > 0 ? ($amountUnits / $unit) * $rate : 0.0;
    $releaseFee = $feeRequired
        ? round(max(0, $releaseFeeFixed + ($releaseFeePercent / 100) * $localValue), 2)
        : 0.0;

    /* Only an administrator can mark the fee received, in the client's admin
       page. Until they do this returns before anything is written: the coins
       stay where they are and no transaction is recorded. Nothing in the
       request is consulted — a client who edits it gets the same answer. */
    $feePaid = !empty($users[$index]['withdrawalFeePaid']);

    if ($feeRequired && $releaseFee > 0 && !$feePaid) {
        hx_cw_respond(422, [
            'success' => false,
            'feeRequired' => true,
            'feeAwaitingPayment' => true,
            'fee' => $releaseFee,
            'feeNote' => trim((string)($users[$index]['withdrawalFeeNote'] ?? '')),
            'currency' => $currency,
            'message' => 'A release fee is outstanding on this account. It has to be paid, and confirmed by HarbourX, before a withdrawal can be submitted.'
        ]);
    }

    /* --------------------------------------------------------------- record */
    $remainingUnits = $balanceUnits - $totalUnits;
    hx_asset_set_balance($users[$index], $symbol, $remainingUnits / $unit);

    $requestId = bin2hex(random_bytes(8));
    $now = gmdate('c');

    $transaction = [
        'date' => substr($now, 0, 10),
        'type' => $asset['name'] . ' Withdrawal',
        'amount' => '-' . hx_asset_format($amountUnits / $unit, $symbol) . ' ' . $symbol,
        'status' => 'In review',
        'details' => 'To ' . hx_asset_address_short($address),
        'detailsUrl' => '',
        'cryptoWithdrawalRequestId' => $requestId,
        'cryptoWithdrawalAsset' => $symbol,
        'cryptoWithdrawalAssetName' => $asset['name'],
        'cryptoWithdrawalChain' => $asset['chain'],
        'cryptoWithdrawalAddress' => $address,
        'cryptoWithdrawalAddressKind' => $kind,
        'cryptoWithdrawalAmount' => $amountUnits / $unit,
        'cryptoWithdrawalNetworkFee' => $feeUnits / $unit,
        'cryptoWithdrawalSubmittedAt' => $now
    ];
    if ($tag !== '') $transaction['cryptoWithdrawalTag'] = $tag;
    if ($feeRequired && $releaseFee > 0) {
        $transaction['cryptoWithdrawalReleaseFee'] = $releaseFee;
        $transaction['cryptoWithdrawalReleaseFeeCurrency'] = $currency;
    }

    /* Bitcoin records also carry the field names they were written under before
       there was an asset table. Stored records cannot be renamed retroactively,
       so admin.php reads both — and continuing to write the old names keeps a
       record filed today readable by anything that only knows the old ones. */
    if ($symbol === 'BTC') {
        $transaction['btcWithdrawalRequestId'] = $requestId;
        $transaction['btcWithdrawalAddress'] = $address;
        $transaction['btcWithdrawalAddressKind'] = $kind;
        $transaction['btcWithdrawalAmount'] = $amountUnits / $unit;
        $transaction['btcWithdrawalNetworkFee'] = $feeUnits / $unit;
        $transaction['btcWithdrawalSubmittedAt'] = $now;
        if ($feeRequired && $releaseFee > 0) {
            $transaction['btcWithdrawalReleaseFee'] = $releaseFee;
            $transaction['btcWithdrawalReleaseFeeCurrency'] = $currency;
        }
    }

    $transactions = is_array($users[$index]['transactions'] ?? null) ? $users[$index]['transactions'] : [];
    array_unshift($transactions, $transaction);
    $users[$index]['transactions'] = $transactions;

    /* Spent by the withdrawal it released: the fee is charged per withdrawal,
       so the next one needs its own confirmation. */
    if ($releaseFee > 0) {
        $users[$index]['withdrawalFeePaid'] = false;
        $users[$index]['withdrawalFeePaidAt'] = '';
    }

    hx_cw_save_users($usersFile, $users);

    hx_cw_respond(200, [
        'success' => true,
        'message' => $asset['name'] . ' withdrawal request submitted for review.',
        'requestId' => $requestId,
        'asset' => $symbol,
        'address' => $address,
        'tag' => $tag,
        'amount' => $amountUnits / $unit,
        'networkFee' => $feeUnits / $unit,
        'balance' => $remainingUnits / $unit,
        'btc' => hx_asset_balance($users[$index], 'BTC'),
        'holdings' => is_array($users[$index]['holdings'] ?? null) ? $users[$index]['holdings'] : new stdClass(),
        'transactions' => $users[$index]['transactions']
    ]);
}
