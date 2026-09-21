<?php
/**
 * Keccak-256 against the published vectors.
 *
 * The last two matter most: they are the difference between Keccak-256 and
 * SHA3-256, which differ only in one padding byte and would otherwise be easy
 * to confuse. An Ethereum address checksum verified with the wrong one of the
 * two rejects every valid address.
 */

require_once __DIR__ . '/../keccak.php';

$failures = 0;
function check(bool $ok, string $label, string $detail = ''): void
{
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? " — $detail" : '') . "\n";
}

$vectors = [
    ['', 'c5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470'],
    ['abc', '4e03657aea45a94fc7d47ba826c8d667c0d1e6e33a64a036ec44f58fa12d6c45'],
    ['The quick brown fox jumps over the lazy dog',
     '4d741b6f1eb29cb2a9b9911c82f56fa8d73b04959d3d9d222895df6c0b28aa15'],
    /* The rate is 136 bytes. One under, exactly on it (which forces a whole
       extra block of padding) and one over are where an absorb loop goes
       wrong. Unlike the three above, these digests are not published
       anywhere — they are pinned from this implementation, which the
       published vectors and the EIP-55 addresses below vouch for. They guard
       against a later change to the block handling, not against it being
       wrong today. */
    [str_repeat('a', 135), '34367dc248bbd832f4e3e69dfaac2f92638bd0bbd18f2912ba4ef454919cf446'],
    [str_repeat('a', 136), 'a6c4d403279fe3e0af03729caada8374b5ca54d8065329a3ebcaeb4b60aa386e'],
    [str_repeat('a', 137), 'd869f639c7046b4929fc92a4d988a8b22c55fbadb802c0c66ebcd484f1915f39'],
];

foreach ($vectors as $i => [$input, $expected]) {
    $actual = hx_keccak256($input);
    $label = $input === '' ? 'empty string' : (strlen($input) > 20 ? strlen($input) . ' bytes' : "\"$input\"");
    check($actual === $expected, "keccak256 of $label", $actual);
}

/* EIP-55 end to end, against the four addresses in the proposal itself. This
   is the check that matters in practice: it runs keccak over a 40-character
   input and compares every nibble of the answer, so a rotation constant or a
   padding byte that is wrong anywhere shows up here as a mis-capitalised
   address rather than as a digest nobody reads. */
function eip55(string $hex40): string
{
    $lower = strtolower($hex40);
    $hash = hx_keccak256($lower);
    $out = '';
    for ($i = 0; $i < 40; $i++) {
        $out .= hexdec($hash[$i]) >= 8 ? strtoupper($lower[$i]) : $lower[$i];
    }
    return '0x' . $out;
}

foreach ([
    '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed',
    '0xfB6916095ca1df60bB79Ce92cE3Ea74c37c5d359',
    '0xdbF03B407c01E7cD3CBea99509d93f8DDDC8C6FB',
    '0xD1220A0cf47c7B9Be7A2E6BA89F429762e7b9aDb',
] as $address) {
    $actual = eip55(substr($address, 2));
    check($actual === $address, "EIP-55 capitalisation of {$address}", $actual);
}

// SHA3-256 must not be mistaken for it: same input, different answer.
check(hx_keccak256('') !== hash('sha3-256', ''), 'keccak256 is not sha3-256');

echo $failures ? "\n  $failures keccak check(s) failed\n" : "\n  All keccak vectors matched\n";
exit($failures ? 1 : 0);
