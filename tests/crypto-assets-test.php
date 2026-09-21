<?php
/**
 * Address validation for every supported chain.
 *
 * Each chain gets three kinds of case: addresses that are genuinely valid,
 * addresses that are one character away from valid, and addresses that belong
 * to a different chain entirely. The second kind is the one that matters —
 * anything can reject obvious rubbish, and a send goes to a typo far more
 * often than it goes to nonsense.
 */

require_once __DIR__ . '/../crypto_assets.php';

$failures = 0;
function check(bool $ok, string $label, string $detail = ''): void
{
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? " — $detail" : '') . "\n";
}

/**
 * Base58Check-encode a version byte and payload, for building test addresses.
 *
 * Little-endian digit accumulation rather than a bignum library, because this
 * host has no bcmath and the test should not need one.
 */
function b58check(int $version, string $payload): string
{
    $body = chr($version) . $payload;
    $raw = $body . substr(hash('sha256', hash('sha256', $body, true), true), 0, 4);

    $digits = [0];
    foreach (unpack('C*', $raw) as $byte) {
        $carry = $byte;
        for ($i = 0; $i < count($digits); $i++) {
            $carry += $digits[$i] << 8;
            $digits[$i] = $carry % 58;
            $carry = intdiv($carry, 58);
        }
        while ($carry > 0) {
            $digits[] = $carry % 58;
            $carry = intdiv($carry, 58);
        }
    }
    while (count($digits) > 1 && end($digits) === 0) array_pop($digits);

    $out = '';
    foreach (array_reverse($digits) as $digit) $out .= HX_B58_ALPHABET[$digit];
    for ($i = 0; $i < strlen($raw) && $raw[$i] === "\0"; $i++) $out = '1' . $out;
    return $out;
}

/** Change one character of an address to a different one from its alphabet. */
function bend(string $address, int $at): string
{
    $c = $address[$at];
    $address[$at] = $c === 'a' ? 'b' : ($c === '1' ? '2' : ($c === 'q' ? 'p' : 'a'));
    return $address;
}

$valid = [
    'BTC'  => ['bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4',
               '1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2',
               'bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj0'],
    'ETH'  => ['0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed',
               '0xd8da6bf26964af9d7eed9e03e53415d37aa96045',
               '0xDBF03B407C01E7CD3CBEA99509D93F8DDDC8C6FB'],
    'XRP'  => ['rEb8TK3gBgk5auZkwc6sHnwrGVJH8DuaLh',
               'rN7n7otQDd6FczFgLdSqtcsAUxDkw6fzRH'],
    'BNB'  => ['0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed'],
    'SOL'  => ['9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM',
               'So11111111111111111111111111111111111111112'],
    'DOGE' => ['DH5yaieqoZN36fDVciNyRueRGvGLR3mr7L',
               // A pay-to-script-hash address, built below so its checksum is
               // arithmetic rather than something recalled and hoped for.
               b58check(0x16, str_repeat("\x11", 20))],
    'ADA'  => ['addr1qx2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer3n0d3vllmyqwsx5wktcd8cc3sq835lu7drv2xwl2wywfgse35a3x'],
    'LINK' => ['0x514910771AF9Ca656af840dff83E8264EcF986CA'],
];

foreach ($valid as $symbol => $addresses) {
    foreach ($addresses as $address) {
        $kind = hx_asset_address_kind($symbol, $address);
        check($kind !== null, "$symbol accepts " . substr($address, 0, 14) . '…', (string)$kind);

        /* One character different. Base58 and bech32 both carry a checksum, so
           this must fail. Solana has none, and an EVM address written in a
           single case is not claiming one either, so neither is asked to. */
        $evmWithoutChecksum = in_array($symbol, ['ETH', 'BNB', 'LINK'], true)
            && (substr($address, 2) === strtolower(substr($address, 2))
                || substr($address, 2) === strtoupper(substr($address, 2)));
        if ($symbol !== 'SOL' && !$evmWithoutChecksum) {
            $broken = bend($address, intdiv(strlen($address), 2));
            check($broken === $address || hx_asset_address_kind($symbol, $broken) === null,
                "$symbol rejects the same address with one character changed", $broken);
        }
    }
}

/* ------------------------------------------------- EIP-55 specifically ---- */
// Flipping the case of a single letter breaks the checksum and nothing else.
check(hx_asset_address_kind('ETH', '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed') !== null,
    'ETH accepts a correctly checksummed address');
check(hx_asset_address_kind('ETH', '0x5AAeb6053F3E94C9b9A09f33669435E7Ef1BeAed') === null,
    'ETH rejects the same address with one letter re-cased');
check(hx_asset_address_kind('ETH', '0x0000000000000000000000000000000000000000') === null,
    'ETH rejects the zero address');
check(hx_asset_address_kind('ETH', '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAe') === null,
    'ETH rejects an address one character short');

/* ------------------------------------------------- crossed wires ---------- */
// The mistake people actually make: the right address, the wrong network.
$crossed = [
    ['BTC',  '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed', 'an Ethereum address'],
    ['ETH',  'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4', 'a Bitcoin address'],
    ['XRP',  '1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2',        'a Bitcoin address'],
    ['DOGE', '1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2',        'a Bitcoin address'],
    ['BTC',  'D7FkevX5cmDU4761u2car6ctA2yjBmQfbc',        'a Dogecoin address'],
    ['ADA',  'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4', 'a Bitcoin address'],
    ['SOL',  '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed', 'an Ethereum address'],
];
foreach ($crossed as [$symbol, $address, $what]) {
    check(hx_asset_address_kind($symbol, $address) === null, "$symbol rejects $what");
}

// A Cardano reward address is valid bech32 but cannot receive a payment.
check(hx_asset_address_kind('ADA', 'stake1uyehkck0lajq8gr28t9uxnuvgcqrc6ry9ylhp4duduaptwsq70yjr') === null,
    'ADA rejects a stake address');

/* ------------------------------------------------- balances --------------- */
$user = ['btc' => 1.25];
check(hx_asset_balance($user, 'BTC') === 1.25, 'BTC balance still reads from the top-level field');
check(hx_asset_balance($user, 'ETH') === 0.0, 'an asset never held reads as zero');

hx_asset_set_balance($user, 'ETH', 2.5);
hx_asset_set_balance($user, 'BTC', 0.5);
check(hx_asset_balance($user, 'ETH') === 2.5, 'a new holding is stored');
check($user['btc'] === 0.5, 'BTC is written back to the field the rest of the app reads');
check(!isset($user['holdings']['BTC']), 'BTC is not duplicated into the holdings map');

hx_asset_set_balance($user, 'XRP', -5);
check(hx_asset_balance($user, 'XRP') === 0.0, 'a negative balance is floored at zero');

check(array_keys(hx_asset_holdings($user)) === ['BTC', 'ETH'], 'only non-zero holdings are listed',
    implode(',', array_keys(hx_asset_holdings($user))));

/* ------------------------------------------------- units ------------------ */
check(hx_asset_units(1.25, 'BTC') === 125000000, 'BTC converts to satoshis');
check(hx_asset_units(1.25, 'XRP') === 1250000, 'XRP uses six decimals');
check(hx_asset_amount(125000000, 'BTC') === 1.25, 'and back again');
check(hx_asset_format(0.0000012, 'BTC') === '0.00000120', 'amounts format without exponents');

/* ------------------------------------------------- destination tag -------- */
check(hx_asset_tag_valid(''), 'an absent destination tag is fine');
check(hx_asset_tag_valid('4294967295'), 'the largest 32-bit tag is accepted');
check(!hx_asset_tag_valid('4294967296'), 'one above it is not');
check(!hx_asset_tag_valid('12a'), 'a tag with a letter in it is not');

echo $failures ? "\n  $failures asset check(s) failed\n" : "\n  All asset and address checks passed\n";
exit($failures ? 1 : 0);
