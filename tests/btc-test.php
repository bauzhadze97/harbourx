<?php
/**
 * Bitcoin address validation, against the vectors from BIP-173 and BIP-350.
 *
 * The invalid cases matter more than the valid ones: the whole reason to verify
 * a checksum is to reject an address a shape regex would have waved through.
 */

require __DIR__ . '/../btc.php';

$fail = 0;
function check($ok, $label, $detail = '') {
    global $fail;
    if (!$ok) $fail++;
    printf("  %s %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? " — $detail" : '');
}

/* ----------------------------------------------------------- valid, real -- */
$valid = [
    // Satoshi's address, the genesis block coinbase output.
    '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa' => 'p2pkh',
    '3J98t1WpEZ73CNmQviecrnyiWrnqRhWNLy' => 'p2sh',
    // BIP-173 worked examples.
    'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4' => 'p2wpkh',
    'bc1qrp33g0q5c5txsp9arysrx4k6zdkfs4nce4xj0gdcccefvpysxf3qccfmv3' => 'p2wsh',
    // BIP-350 taproot.
    'bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj0' => 'p2tr',
];
foreach ($valid as $address => $kind) {
    $got = hx_btc_address_kind($address);
    check($got === $kind, 'accepts ' . substr($address, 0, 24) . '…', "want $kind got " . var_export($got, true));
}

/* ------------------------------------------------- invalid: the whole point */

// One character changed in an otherwise well-formed address. A shape regex
// takes every one of these; a checksum takes none.
$typos = [
    '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNb',                          // last char
    '1B1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',                          // first char
    '3J98t1WpEZ73CNmQviecrnyiWrnqRhWNLx',
    'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t5',
    'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kw8f3t4',                  // middle
    'bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj1',
];
$shapeWouldPass = 0;
foreach ($typos as $address) {
    check(hx_btc_address_kind($address) === null, 'rejects a one-character typo', substr($address, -12));
    // How many of these the old shape regex waved through. Not all of them —
    // a typo landing outside bech32's alphabet is caught by shape alone — but
    // any at all is the reason checksum validation is here.
    if (preg_match('/^[13][a-km-zA-HJ-NP-Z1-9]{25,34}$/', $address)
        || preg_match('/^bc1[ac-hj-np-z02-9]{11,87}$/', strtolower($address))) {
        $shapeWouldPass++;
    }
}
check($shapeWouldPass > 0,
    'the old shape regex would have accepted some of those typos',
    "$shapeWouldPass of " . count($typos));

// BIP-173 / BIP-350 invalid vectors.
$invalid = [
    'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4x' => 'bad checksum (extra char)',
    'BC1QW508D6QEJXTDG4Y5R3ZARVARY0C5XW7KV8F3T4X' => 'bad checksum, upper case',
    'bc1Qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4'  => 'mixed case',
    'bc1rw5uspcuh'                                => 'witness program too short',
    'bc1zw508d6qejxtdg4y5r3zarvary0c5xw7kw508d6qejxtdg4y5r3zarvary0c5xw7kw5rljs90' => 'program too long',
    'bc1gmk9yu'                                   => 'empty data section',
    'tb1qrp33g0q5c5txsp9arysrx4k6zdkfs4nce4xj0gdcccefvpysxf3q0sL5k7' => 'testnet address on mainnet',
    'bc1p38j9r5y49hruaue7wxjce0updqjuyyx0kh56v8s25huc6995vvpql3jow4' => 'witness v1 with bech32, not bech32m',
    'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t44'  => 'v0 program of the wrong length',
    ''                                            => 'empty string',
    'not-an-address'                              => 'nonsense',
    '1A1zP1eP5QGefi2DMPTfTL5SLmv7Divf'            => 'base58 of the wrong length',
    '1O0lIA1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa'      => 'base58 with characters outside the alphabet',
    'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4 ' => 'trailing space is trimmed then valid',
];
foreach ($invalid as $address => $why) {
    $got = hx_btc_address_kind($address);
    // The trailing-space case is deliberately the one that should still pass.
    $shouldReject = $why !== 'trailing space is trimmed then valid';
    check($shouldReject ? $got === null : $got === 'p2wpkh', "rejects: $why", var_export($got, true));
}

// The all-zeros hash160 address. Unspendable, but its checksum is valid and it
// is a real address — worth pinning so nobody "fixes" the decoder into
// rejecting it.
check(hx_btc_address_kind('1111111111111111111114oLvT2') === 'p2pkh',
    'the burn address is valid (checksum verifies)');

/* ------------------------------------------------------------- normalising */
check(hx_btc_address_normalise('BC1QW508D6QEJXTDG4Y5R3ZARVARY0C5XW7KV8F3T4')
    === 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4', 'bech32 normalises to lower case');
check(hx_btc_address_normalise('1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa')
    === '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa', 'base58 case is left alone (it is significant)');
check(hx_btc_address_valid(strtoupper('bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4')),
    'all-upper bech32 is valid (only mixed case is not)');

/* --------------------------------------------------------------- shortening */
check(hx_btc_address_short('bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4') === 'bc1qw508…v8f3t4',
    'shortens for display', hx_btc_address_short('bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4'));

/* ---------------------------------------------------------------- testnet -- */
check(hx_btc_address_kind('tb1qrp33g0q5c5txsp9arysrx4k6zdkfs4nce4xj0gdcccefvpysxf3q0sl5k7', 'testnet') === 'p2wsh',
    'testnet address accepted on testnet');
check(hx_btc_address_kind('bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4', 'testnet') === null,
    'mainnet address rejected on testnet');

echo $fail ? "\n$fail check(s) failed\n" : "\nAll Bitcoin address checks passed\n";
exit($fail ? 1 : 0);
