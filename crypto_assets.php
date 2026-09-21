<?php
/**
 * The assets a client can hold and send.
 *
 * One table, read by everything: the client dialog that offers a list of
 * coins, the endpoint that records a send, the admin page that sets a balance.
 * Adding a coin means adding a row here, not editing four files.
 *
 * Two things are deliberate.
 *
 * Every address is checked against the chain's own checksum, never a shape
 * regex. A send is irreversible on all of these, and "forty hex characters"
 * accepts a typo just as readily as the real thing. Where a chain has no
 * checksum to verify — Solana addresses are a raw public key — the hint says
 * so rather than implying a guarantee that is not there.
 *
 * Balances are held in the smallest unit as integers wherever arithmetic
 * happens. Floating point cannot represent a tenth exactly, and a balance that
 * drifts by a satoshi over enough operations is a bug report nobody can
 * reproduce.
 */

require_once __DIR__ . '/btc.php';
require_once __DIR__ . '/keccak.php';

/** The XRP Ledger's ordering of the Base58 characters. */
const HX_XRP_ALPHABET = 'rpshnaf39wBUDNEGHJKLM4PQRST7VWXYZ2bcdeCg65jkm8oFqi1tuvAxyz';

/**
 * The supported assets, in the order they are shown.
 *
 *   decimals    places the amount is held and displayed to
 *   networkFee  deducted from a send, in units of the asset itself
 *   minimum     smallest send the chain will carry
 *   addressing  which validator below applies
 *   tag         the chain takes a numeric destination tag alongside the address
 */
const HX_ASSETS = [
    'BTC' => [
        'name' => 'Bitcoin', 'chain' => 'Bitcoin', 'glyph' => '₿', 'swatch' => 'btc',
        'coingeckoId' => 'bitcoin', 'decimals' => 8,
        'networkFee' => 0.00002, 'minimum' => 0.00000294,
        'addressing' => 'bitcoin', 'tag' => false,
        'placeholder' => 'bc1… or 1… / 3…',
        'hint' => 'Legacy, SegWit and Taproot addresses are accepted. The checksum is verified before anything is sent.'
    ],
    'ETH' => [
        'name' => 'Ethereum', 'chain' => 'Ethereum', 'glyph' => '◆', 'swatch' => 'eth',
        'coingeckoId' => 'ethereum', 'decimals' => 8,
        'networkFee' => 0.0008, 'minimum' => 0.0005,
        'addressing' => 'evm', 'tag' => false,
        'placeholder' => '0x…',
        'hint' => 'An Ethereum address, 0x followed by 40 hex characters. If yours has capital letters its EIP-55 checksum is verified.'
    ],
    'XRP' => [
        'name' => 'XRP', 'chain' => 'XRP Ledger', 'glyph' => '✕', 'swatch' => 'xrp',
        'coingeckoId' => 'ripple', 'decimals' => 6,
        'networkFee' => 0.000012, 'minimum' => 0.000001,
        'addressing' => 'xrp', 'tag' => true,
        'placeholder' => 'r…',
        'hint' => 'A classic XRP address beginning with r. Exchanges usually also require a destination tag — a deposit without one can be lost.'
    ],
    'BNB' => [
        'name' => 'BNB', 'chain' => 'BNB Smart Chain', 'glyph' => '◈', 'swatch' => 'bnb',
        'coingeckoId' => 'binancecoin', 'decimals' => 8,
        'networkFee' => 0.0002, 'minimum' => 0.0001,
        'addressing' => 'evm', 'tag' => false,
        'placeholder' => '0x…',
        'hint' => 'A BNB Smart Chain (BEP-20) address. It has the same 0x form as Ethereum, but the two are different networks — sending to the wrong one loses the coins.'
    ],
    'SOL' => [
        'name' => 'Solana', 'chain' => 'Solana', 'glyph' => '≋', 'swatch' => 'sol',
        'coingeckoId' => 'solana', 'decimals' => 8,
        'networkFee' => 0.00001, 'minimum' => 0.000001,
        'addressing' => 'solana', 'tag' => false,
        'placeholder' => 'Base58 address',
        'hint' => 'A Solana address is a raw public key with no checksum, so only its length and alphabet can be checked. Paste it; do not type it.'
    ],
    'DOGE' => [
        'name' => 'Dogecoin', 'chain' => 'Dogecoin', 'glyph' => 'Ð', 'swatch' => 'doge',
        'coingeckoId' => 'dogecoin', 'decimals' => 8,
        'networkFee' => 1.0, 'minimum' => 1.0,
        'addressing' => 'dogecoin', 'tag' => false,
        'placeholder' => 'D…',
        'hint' => 'A Dogecoin address beginning with D, or a multisig address beginning with 9 or A. The checksum is verified.'
    ],
    'ADA' => [
        'name' => 'Cardano', 'chain' => 'Cardano', 'glyph' => '₳', 'swatch' => 'ada',
        'coingeckoId' => 'cardano', 'decimals' => 6,
        'networkFee' => 0.17, 'minimum' => 1.0,
        'addressing' => 'cardano', 'tag' => false,
        'placeholder' => 'addr1…',
        'hint' => 'A Shelley address beginning with addr1. The older Byron addresses (Ae2…, Ddz…) are not accepted here.'
    ],
    'LINK' => [
        'name' => 'Chainlink', 'chain' => 'Ethereum (ERC-20)', 'glyph' => '⬡', 'swatch' => 'link',
        'coingeckoId' => 'chainlink', 'decimals' => 8,
        'networkFee' => 0.35, 'minimum' => 0.1,
        'addressing' => 'evm', 'tag' => false,
        'placeholder' => '0x…',
        'hint' => 'An Ethereum address — LINK is an ERC-20 token, so it travels on Ethereum. Send only to a wallet that supports ERC-20 tokens.'
    ],
];

/** Every supported symbol, in display order. */
function hx_asset_symbols(): array
{
    return array_keys(HX_ASSETS);
}

/** One asset's row, with its symbol folded in, or null if unsupported. */
function hx_asset(string $symbol): ?array
{
    $symbol = strtoupper(trim($symbol));
    if (!isset(HX_ASSETS[$symbol])) return null;
    return ['symbol' => $symbol] + HX_ASSETS[$symbol];
}

/* --------------------------------------------------------------- amounts -- */

/** Convert an amount to whole smallest-units, so arithmetic cannot drift. */
function hx_asset_units(float $amount, string $symbol): int
{
    $asset = hx_asset($symbol);
    if ($asset === null) return 0;
    return (int)round($amount * (10 ** $asset['decimals']));
}

/** And back again. */
function hx_asset_amount(int $units, string $symbol): float
{
    $asset = hx_asset($symbol);
    if ($asset === null) return 0.0;
    return $units / (10 ** $asset['decimals']);
}

/** A fixed-point string, for a message someone will read. */
function hx_asset_format(float $amount, string $symbol): string
{
    $asset = hx_asset($symbol);
    if ($asset === null) return (string)$amount;
    return number_format($amount, $asset['decimals'], '.', '');
}

/* -------------------------------------------------------------- balances -- */

/**
 * What this account holds of one asset.
 *
 * Bitcoin stays in the top-level 'btc' field it has always lived in: the admin
 * page, the conversion flow and every stored record read it from there, and
 * moving it would rewrite all of them for no gain. Everything else lives under
 * 'holdings'.
 */
function hx_asset_balance(array $user, string $symbol): float
{
    $symbol = strtoupper(trim($symbol));
    if ($symbol === 'BTC') return (float)($user['btc'] ?? 0);
    $holdings = is_array($user['holdings'] ?? null) ? $user['holdings'] : [];
    return (float)($holdings[$symbol] ?? 0);
}

/** Write a balance back, rounded to the asset's own precision. */
function hx_asset_set_balance(array &$user, string $symbol, float $value): void
{
    $asset = hx_asset($symbol);
    if ($asset === null) return;

    $value = max(0, round($value, $asset['decimals']));
    if ($asset['symbol'] === 'BTC') {
        $user['btc'] = $value;
        return;
    }
    $holdings = is_array($user['holdings'] ?? null) ? $user['holdings'] : [];
    $holdings[$asset['symbol']] = $value;
    $user['holdings'] = $holdings;
}

/** Every non-zero holding, as symbol => amount, in table order. */
function hx_asset_holdings(array $user): array
{
    $out = [];
    foreach (hx_asset_symbols() as $symbol) {
        $balance = hx_asset_balance($user, $symbol);
        if ($balance > 0) $out[$symbol] = $balance;
    }
    return $out;
}

/* ------------------------------------------------------------- addresses -- */

/** Trim, and drop the case where the chain's checksum does not depend on it. */
function hx_asset_address_normalise(string $symbol, string $address): string
{
    $asset = hx_asset($symbol);
    $address = trim($address);
    if ($asset === null || $address === '') return $address;

    switch ($asset['addressing']) {
        case 'bitcoin':
            return hx_btc_address_normalise($address);
        case 'cardano':
            return strtolower($address);
        // An EVM address keeps its capitalisation: that *is* the checksum.
        // Base58 is case-sensitive throughout, so XRP, Solana and Dogecoin
        // keep theirs too.
        default:
            return $address;
    }
}

/**
 * Validate an address and describe what it is, or return null.
 *
 * The string comes back so a dialog can tell the client which kind of address
 * it recognised — a reader who expected a Taproot address and is told "Legacy"
 * has caught a paste from the wrong window.
 */
function hx_asset_address_kind(string $symbol, string $address): ?string
{
    $asset = hx_asset($symbol);
    if ($asset === null) return null;
    $address = hx_asset_address_normalise($symbol, $address);
    if ($address === '') return null;

    switch ($asset['addressing']) {
        case 'bitcoin':   return hx_btc_address_kind($address);
        case 'evm':       return hx_evm_address_kind($address, $asset['chain']);
        case 'xrp':       return hx_xrp_address_kind($address);
        case 'solana':    return hx_solana_address_kind($address);
        case 'dogecoin':  return hx_dogecoin_address_kind($address);
        case 'cardano':   return hx_cardano_address_kind($address);
    }
    return null;
}

/** bc1qw508…v8f3t4 — enough to compare against a screen, short enough to read. */
function hx_asset_address_short(string $address, int $head = 8, int $tail = 6): string
{
    return hx_btc_address_short($address, $head, $tail);
}

/**
 * An Ethereum-style address, with its EIP-55 checksum verified when it has one.
 *
 * An address written entirely in one case carries no checksum — that is how
 * they were written before EIP-55 — so there is nothing to verify and it is
 * accepted. A mixed-case address is claiming a checksum, and a claim that does
 * not hold up means a character was altered.
 */
function hx_evm_address_kind(string $address, string $chain = 'Ethereum'): ?string
{
    if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) return null;

    $body = substr($address, 2);
    if ($body === str_repeat('0', 40)) return null;   // the burn address

    $lower = strtolower($body);
    if ($body === $lower || $body === strtoupper($body)) {
        return $chain . ' address (no checksum — it is written in one case)';
    }

    /* EIP-55: hash the lower-case address and let each nibble of the digest
       decide the case of the character in the same position. Digits have no
       case, so only the letters carry the checksum. */
    $hash = hx_keccak256($lower);
    for ($i = 0; $i < 40; $i++) {
        if (!ctype_alpha($body[$i])) continue;
        if ((hexdec($hash[$i]) >= 8) !== ctype_upper($body[$i])) return null;
    }
    return $chain . ' address';
}

/** A classic XRP Ledger address: version 0x00 over a 20-byte account ID. */
function hx_xrp_address_kind(string $address): ?string
{
    if ($address === '' || $address[0] !== 'r') {
        // X-addresses fold the destination tag into the address itself and use
        // a different prefix. This portal asks for the tag separately.
        return null;
    }
    $decoded = hx_base58check_decode($address, HX_XRP_ALPHABET);
    if ($decoded === null) return null;
    [$version, $payload] = $decoded;
    if ($version !== 0x00 || strlen($payload) !== 20) return null;
    return 'XRP Ledger address';
}

/**
 * A Solana address is an Ed25519 public key in Base58 — 32 bytes, no checksum.
 *
 * There is nothing here that can catch a single altered character, and the
 * hint on the field says so. Length and alphabet are all there is to check.
 */
function hx_solana_address_kind(string $address): ?string
{
    if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address)) return null;
    $raw = hx_base58_decode($address);
    if ($raw === null || strlen($raw) !== 32) return null;
    return 'Solana address (no checksum to verify)';
}

/** Dogecoin mainnet: 0x1E pay-to-public-key-hash, 0x16 pay-to-script-hash. */
function hx_dogecoin_address_kind(string $address): ?string
{
    $decoded = hx_base58check_decode($address);
    if ($decoded === null) return null;
    [$version, $payload] = $decoded;
    if (strlen($payload) !== 20) return null;
    if ($version === 0x1E) return 'Dogecoin address';
    if ($version === 0x16) return 'Dogecoin multisig address';
    return null;
}

/**
 * A Shelley-era Cardano address.
 *
 * Plain bech32 over the raw address bytes — no witness version, unlike
 * Bitcoin's use of the same encoding. The low nibble of the header byte is the
 * network, and 1 is mainnet.
 */
function hx_cardano_address_kind(string $address): ?string
{
    $decoded = hx_bech32_decode($address, 128);
    if ($decoded === null) return null;
    [$hrp, $data, $constant] = $decoded;

    if ($constant !== HX_BECH32_CONST) return null;   // bech32, never bech32m
    if ($hrp === 'stake') return null;                // a reward address, not payable
    if ($hrp !== 'addr') return null;

    $bytes = hx_convert_bits($data, 5, 8, false);
    if ($bytes === null) return null;

    $length = count($bytes);
    if ($length !== 57 && $length !== 29) return null;
    if (($bytes[0] & 0x0F) !== 1) return null;        // testnet is 0

    return $length === 57 ? 'Cardano base address' : 'Cardano enterprise address';
}

/** An XRP destination tag is an unsigned 32-bit integer, or absent. */
function hx_asset_tag_valid(string $tag): bool
{
    if ($tag === '') return true;
    if (!preg_match('/^\d{1,10}$/', $tag)) return false;
    return (int)$tag <= 4294967295;
}

/* ---------------------------------------------------------------- client -- */

/**
 * The table as the browser needs it: everything the send dialog renders, and
 * nothing about this particular account.
 */
function hx_asset_public_table(): array
{
    $out = [];
    foreach (HX_ASSETS as $symbol => $asset) {
        $out[$symbol] = [
            'symbol' => $symbol,
            'name' => $asset['name'],
            'chain' => $asset['chain'],
            'glyph' => $asset['glyph'],
            'swatch' => $asset['swatch'],
            'coingeckoId' => $asset['coingeckoId'],
            'decimals' => $asset['decimals'],
            'networkFee' => $asset['networkFee'],
            'minimum' => $asset['minimum'],
            'tag' => $asset['tag'],
            'placeholder' => $asset['placeholder'],
            'hint' => $asset['hint'],
        ];
    }
    return $out;
}
