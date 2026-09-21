<?php
/**
 * Keccak-256, as Ethereum uses it.
 *
 * PHP ships SHA3-256, which is *not* the same function: NIST changed the
 * padding byte from 0x01 to 0x06 when Keccak became SHA-3, and Ethereum
 * predates that change. hash('sha3-256', …) therefore gives a different digest
 * and cannot be used to check an address.
 *
 * This exists for one reason: an Ethereum address carries its checksum in the
 * capitalisation of its hex digits (EIP-55), and verifying that needs
 * Keccak-256. Without it the only check available is "forty hex characters",
 * which accepts a typo — and a send to a mistyped address is as unrecoverable
 * on Ethereum as it is on Bitcoin.
 *
 * Each 64-bit lane is held as a pair of 32-bit halves, [low, high]. PHP has no
 * unsigned 64-bit integer and no logical right shift for one, so working in
 * halves is both simpler to follow and correct on any build.
 *
 * Checked against the published vectors in tests/keccak-test.php.
 */

/** Rotation offsets, indexed by lane (x + 5y). */
const HX_KECCAK_ROT = [
     0,  1, 62, 28, 27,
    36, 44,  6, 55, 20,
     3, 10, 43, 25, 39,
    41, 45, 15, 21,  8,
    18,  2, 61, 56, 14
];

/** Round constants, split into [low, high] halves. */
const HX_KECCAK_RC = [
    [0x00000001, 0x00000000], [0x00008082, 0x00000000], [0x0000808A, 0x80000000],
    [0x80008000, 0x80000000], [0x0000808B, 0x00000000], [0x80000001, 0x00000000],
    [0x80008081, 0x80000000], [0x00008009, 0x80000000], [0x0000008A, 0x00000000],
    [0x00000088, 0x00000000], [0x80008009, 0x00000000], [0x8000000A, 0x00000000],
    [0x8000808B, 0x00000000], [0x0000008B, 0x80000000], [0x00008089, 0x80000000],
    [0x00008003, 0x80000000], [0x00008002, 0x80000000], [0x00000080, 0x80000000],
    [0x0000800A, 0x00000000], [0x8000000A, 0x80000000], [0x80008081, 0x80000000],
    [0x00008080, 0x80000000], [0x80000001, 0x00000000], [0x80008008, 0x80000000]
];

/** Rotate one lane left by $n bits. */
function hx_keccak_rotl(array $lane, int $n): array
{
    [$lo, $hi] = $lane;
    $n &= 63;
    if ($n === 0)  return [$lo, $hi];
    if ($n === 32) return [$hi, $lo];

    if ($n < 32) {
        $shift = 32 - $n;
        return [
            (($lo << $n) | ($hi >> $shift)) & 0xFFFFFFFF,
            (($hi << $n) | ($lo >> $shift)) & 0xFFFFFFFF
        ];
    }
    $m = $n - 32;
    $shift = 32 - $m;
    return [
        (($hi << $m) | ($lo >> $shift)) & 0xFFFFFFFF,
        (($lo << $m) | ($hi >> $shift)) & 0xFFFFFFFF
    ];
}

/** The Keccak-f[1600] permutation, applied to the 25-lane state in place. */
function hx_keccak_f(array &$a): void
{
    for ($round = 0; $round < 24; $round++) {
        // theta
        $c = [];
        for ($x = 0; $x < 5; $x++) {
            $lo = $a[$x][0] ^ $a[$x + 5][0] ^ $a[$x + 10][0] ^ $a[$x + 15][0] ^ $a[$x + 20][0];
            $hi = $a[$x][1] ^ $a[$x + 5][1] ^ $a[$x + 10][1] ^ $a[$x + 15][1] ^ $a[$x + 20][1];
            $c[$x] = [$lo, $hi];
        }
        for ($x = 0; $x < 5; $x++) {
            $rotated = hx_keccak_rotl($c[($x + 1) % 5], 1);
            $dLo = $c[($x + 4) % 5][0] ^ $rotated[0];
            $dHi = $c[($x + 4) % 5][1] ^ $rotated[1];
            for ($y = 0; $y < 25; $y += 5) {
                $a[$x + $y][0] ^= $dLo;
                $a[$x + $y][1] ^= $dHi;
            }
        }

        // rho and pi
        $b = array_fill(0, 25, [0, 0]);
        for ($x = 0; $x < 5; $x++) {
            for ($y = 0; $y < 5; $y++) {
                $from = $x + 5 * $y;
                $b[$y + 5 * ((2 * $x + 3 * $y) % 5)] = hx_keccak_rotl($a[$from], HX_KECCAK_ROT[$from]);
            }
        }

        // chi
        for ($y = 0; $y < 25; $y += 5) {
            for ($x = 0; $x < 5; $x++) {
                $next = $b[($x + 1) % 5 + $y];
                $after = $b[($x + 2) % 5 + $y];
                $a[$x + $y] = [
                    $b[$x + $y][0] ^ ((~$next[0] & 0xFFFFFFFF) & $after[0]),
                    $b[$x + $y][1] ^ ((~$next[1] & 0xFFFFFFFF) & $after[1])
                ];
            }
        }

        // iota
        $a[0][0] ^= HX_KECCAK_RC[$round][0];
        $a[0][1] ^= HX_KECCAK_RC[$round][1];
    }
}

/**
 * Keccak-256 of a byte string, returned as 64 lower-case hex characters.
 *
 * Rate 1088 bits (136 bytes), capacity 512, and the original Keccak padding:
 * a 0x01 byte after the message, zeroes to the block boundary, and 0x80 set in
 * the final byte.
 */
function hx_keccak256(string $message): string
{
    $rate = 136;
    $state = array_fill(0, 25, [0, 0]);

    $padded = $message . "\x01" . str_repeat("\0", ($rate - (strlen($message) + 1) % $rate) % $rate);
    $padded[strlen($padded) - 1] = chr(ord($padded[strlen($padded) - 1]) | 0x80);

    for ($offset = 0; $offset < strlen($padded); $offset += $rate) {
        $block = substr($padded, $offset, $rate);
        for ($lane = 0; $lane < $rate / 8; $lane++) {
            // Each lane is eight little-endian bytes: low half first.
            $parts = unpack('V2', substr($block, $lane * 8, 8));
            $state[$lane][0] ^= $parts[1];
            $state[$lane][1] ^= $parts[2];
        }
        hx_keccak_f($state);
    }

    $digest = '';
    for ($lane = 0; $lane < 4; $lane++) {
        $digest .= pack('V2', $state[$lane][0], $state[$lane][1]);
    }
    return bin2hex($digest);
}
