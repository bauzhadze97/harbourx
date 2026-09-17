<?php
/**
 * Bitcoin address validation.
 *
 * The rest of this codebase checked addresses with a shape regex, which accepts
 * anything the right length out of the right alphabet — including an address
 * with a mistyped character. Bitcoin addresses carry a checksum precisely so
 * that a typo can be caught before the funds are gone, and nothing was checking
 * it. This does.
 *
 * Supports:
 *   1...   P2PKH      Base58Check, version 0x00
 *   3...   P2SH       Base58Check, version 0x05
 *   bc1q   P2WPKH/SH  bech32,  witness v0   (BIP-173)
 *   bc1p   P2TR       bech32m, witness v1+  (BIP-350)
 *
 * No extensions required — the Base58 division is done on a byte array so this
 * does not need bcmath or gmp.
 */

const HX_B58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
const HX_BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
const HX_BECH32_CONST = 1;            // BIP-173, witness v0
const HX_BECH32M_CONST = 0x2bc830a3;  // BIP-350, witness v1+

/* -------------------------------------------------------------- Base58Check */

/** Decode Base58 to raw bytes, or null if a character is outside the alphabet. */
function hx_base58_decode(string $text): ?string
{
    if ($text === '') return null;

    $bytes = [0];
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
        $value = strpos(HX_B58_ALPHABET, $text[$i]);
        if ($value === false) return null;

        // bytes = bytes * 58 + value, big-endian, carried by hand.
        $carry = $value;
        for ($j = count($bytes) - 1; $j >= 0; $j--) {
            $carry += $bytes[$j] * 58;
            $bytes[$j] = $carry & 0xFF;
            $carry >>= 8;
        }
        while ($carry > 0) {
            array_unshift($bytes, $carry & 0xFF);
            $carry >>= 8;
        }
    }

    // Each leading '1' is a leading zero byte that the arithmetic above drops.
    for ($i = 0; $i < $length && $text[$i] === '1'; $i++) {
        array_unshift($bytes, 0);
    }

    // Strip any zero bytes the accumulator started with, beyond the real ones.
    $leadingOnes = 0;
    while ($leadingOnes < $length && $text[$leadingOnes] === '1') $leadingOnes++;
    while (count($bytes) > $leadingOnes && $bytes[0] === 0 && count($bytes) > 1) {
        // Only trim what is not accounted for by a leading '1'.
        $zeros = 0;
        foreach ($bytes as $b) { if ($b === 0) $zeros++; else break; }
        if ($zeros <= $leadingOnes) break;
        array_shift($bytes);
    }

    return pack('C*', ...$bytes);
}

/**
 * Verify a Base58Check payload and return [versionByte, hash160], or null.
 * The last four bytes must be the first four of sha256(sha256(rest)).
 */
function hx_base58check_decode(string $text): ?array
{
    $raw = hx_base58_decode($text);
    if ($raw === null || strlen($raw) !== 25) return null;

    $payload = substr($raw, 0, 21);
    $checksum = substr($raw, 21, 4);
    $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

    if (!hash_equals($expected, $checksum)) return null;

    return [ord($payload[0]), substr($payload, 1)];
}

/* ------------------------------------------------------- bech32 / bech32m */

/** BIP-173 checksum polynomial. */
function hx_bech32_polymod(array $values): int
{
    $generator = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
    $checksum = 1;
    foreach ($values as $value) {
        $top = $checksum >> 25;
        $checksum = (($checksum & 0x1ffffff) << 5) ^ $value;
        for ($i = 0; $i < 5; $i++) {
            if (($top >> $i) & 1) $checksum ^= $generator[$i];
        }
    }
    return $checksum;
}

/** The human-readable part, expanded as the checksum expects it. */
function hx_bech32_hrp_expand(string $hrp): array
{
    $out = [];
    $length = strlen($hrp);
    for ($i = 0; $i < $length; $i++) $out[] = ord($hrp[$i]) >> 5;
    $out[] = 0;
    for ($i = 0; $i < $length; $i++) $out[] = ord($hrp[$i]) & 31;
    return $out;
}

/**
 * Decode a bech32 string into [hrp, data, encodingConstant], or null.
 * Which constant it verified against tells the caller whether it was bech32
 * or bech32m, and therefore which witness versions are legal.
 */
function hx_bech32_decode(string $text): ?array
{
    // Mixed case is invalid outright — the checksum is defined over one case.
    if ($text !== strtolower($text) && $text !== strtoupper($text)) return null;
    $text = strtolower($text);

    $length = strlen($text);
    if ($length < 8 || $length > 90) return null;

    $split = strrpos($text, '1');
    if ($split === false || $split < 1 || $split + 7 > $length) return null;

    $hrp = substr($text, 0, $split);
    for ($i = 0; $i < strlen($hrp); $i++) {
        $code = ord($hrp[$i]);
        if ($code < 33 || $code > 126) return null;
    }

    $data = [];
    for ($i = $split + 1; $i < $length; $i++) {
        $value = strpos(HX_BECH32_CHARSET, $text[$i]);
        if ($value === false) return null;
        $data[] = $value;
    }

    $checksum = hx_bech32_polymod(array_merge(hx_bech32_hrp_expand($hrp), $data));
    if ($checksum !== HX_BECH32_CONST && $checksum !== HX_BECH32M_CONST) return null;

    return [$hrp, array_slice($data, 0, -6), $checksum];
}

/** Regroup bits, e.g. the 5-bit bech32 data into 8-bit witness program bytes. */
function hx_convert_bits(array $data, int $from, int $to, bool $pad): ?array
{
    $acc = 0;
    $bits = 0;
    $out = [];
    $maxValue = (1 << $to) - 1;

    foreach ($data as $value) {
        if ($value < 0 || ($value >> $from) !== 0) return null;
        $acc = ($acc << $from) | $value;
        $bits += $from;
        while ($bits >= $to) {
            $bits -= $to;
            $out[] = ($acc >> $bits) & $maxValue;
        }
    }

    if ($pad) {
        if ($bits > 0) $out[] = ($acc << ($to - $bits)) & $maxValue;
    } elseif ($bits >= $from || (($acc << ($to - $bits)) & $maxValue) !== 0) {
        // Leftover bits must be zero padding, and fewer than one source unit.
        return null;
    }

    return $out;
}

/* ------------------------------------------------------------------ public */

/**
 * Is this a valid Bitcoin address for the given network?
 *
 * $network is 'mainnet' or 'testnet'. Returns false for anything whose
 * checksum does not verify, which is the whole point.
 */
function hx_btc_address_valid(string $address, string $network = 'mainnet'): bool
{
    return hx_btc_address_kind($address, $network) !== null;
}

/**
 * What kind of address is this? Returns 'p2pkh', 'p2sh', 'p2wpkh', 'p2wsh',
 * 'p2tr', 'witness' (a future version), or null when it does not verify.
 */
function hx_btc_address_kind(string $address, string $network = 'mainnet'): ?string
{
    $address = trim($address);
    if ($address === '') return null;

    $hrp = $network === 'testnet' ? 'tb' : 'bc';
    $p2pkhVersion = $network === 'testnet' ? 0x6f : 0x00;
    $p2shVersion  = $network === 'testnet' ? 0xc4 : 0x05;

    // --- segwit -------------------------------------------------------------
    $lower = strtolower($address);
    if (str_starts_with($lower, $hrp . '1')) {
        $decoded = hx_bech32_decode($address);
        if ($decoded === null) return null;
        [$decodedHrp, $data, $constant] = $decoded;
        if ($decodedHrp !== $hrp || count($data) < 1) return null;

        $version = $data[0];
        if ($version > 16) return null;

        // v0 uses bech32; every later version uses bech32m. Getting this
        // backwards is exactly the mistake BIP-350 exists to prevent.
        $wanted = $version === 0 ? HX_BECH32_CONST : HX_BECH32M_CONST;
        if ($constant !== $wanted) return null;

        $program = hx_convert_bits(array_slice($data, 1), 5, 8, false);
        if ($program === null) return null;

        $length = count($program);
        if ($length < 2 || $length > 40) return null;

        if ($version === 0) {
            if ($length === 20) return 'p2wpkh';
            if ($length === 32) return 'p2wsh';
            return null;                      // v0 is only ever 20 or 32 bytes
        }
        if ($version === 1 && $length === 32) return 'p2tr';
        return 'witness';
    }

    // --- legacy -------------------------------------------------------------
    $decoded = hx_base58check_decode($address);
    if ($decoded === null) return null;
    [$version, $hash] = $decoded;
    if (strlen($hash) !== 20) return null;

    if ($version === $p2pkhVersion) return 'p2pkh';
    if ($version === $p2shVersion) return 'p2sh';
    return null;
}

/** Store bech32 in lower case; leave Base58 exactly as given (it is case-sensitive). */
function hx_btc_address_normalise(string $address): string
{
    $address = trim($address);
    $lower = strtolower($address);
    if (str_starts_with($lower, 'bc1') || str_starts_with($lower, 'tb1')) return $lower;
    return $address;
}

/** Shorten for display: bc1qw508…v8f3t4 */
function hx_btc_address_short(string $address, int $head = 8, int $tail = 6): string
{
    $address = trim($address);
    if (strlen($address) <= $head + $tail + 1) return $address;
    return substr($address, 0, $head) . '…' . substr($address, -$tail);
}
