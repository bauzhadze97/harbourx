<?php
require __DIR__ . '/../totp.php';

$fail = 0;
function check($ok, $label, $detail = '') {
    global $fail;
    if (!$ok) $fail++;
    printf("  %s %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail ? " — $detail" : '');
}

// --- base32 round-trip against RFC 4648 vectors ----------------------------
$vectors = ['' => '', 'f' => 'MY', 'fo' => 'MZXQ', 'foo' => 'MZXW6',
            'foob' => 'MZXW6YQ', 'fooba' => 'MZXW6YTB', 'foobar' => 'MZXW6YTBOI'];
foreach ($vectors as $plain => $b32) {
    check(hx_base32_encode($plain) === $b32, "base32 encode '$plain'", hx_base32_encode($plain));
}
check(hx_base32_decode('MZXW6YTBOI') === 'foobar', 'base32 decode round-trip');

// --- RFC 6238 Appendix B test vectors (SHA1, 8 digits there; we take the
//     low 6, which is what a 6-digit authenticator shows for the same secret) -
$seed = '12345678901234567890';             // the RFC's ASCII seed
$secret = hx_base32_encode($seed);
$rfc = [
    59          => '94287082',
    1111111109  => '07081804',
    1111111111  => '14050471',
    1234567890  => '89005924',
    2000000000  => '69279037',
    20000000000 => '65353130',
];
foreach ($rfc as $time => $expected8) {
    $want = substr($expected8, -HX_TOTP_DIGITS);
    $got = hx_totp_code($secret, $time);
    check($got === $want, "RFC 6238 vector t=$time", "want $want got $got");
}

// --- verification window ---------------------------------------------------
$s = hx_totp_secret();
$now = time();
check(hx_totp_verify($s, hx_totp_code($s, $now), $now) !== null, 'accepts the current code');
check(hx_totp_verify($s, hx_totp_code($s, $now - 30), $now) !== null, 'accepts one step back (clock drift)');
check(hx_totp_verify($s, hx_totp_code($s, $now + 30), $now) !== null, 'accepts one step forward');
check(hx_totp_verify($s, hx_totp_code($s, $now - 120), $now) === null, 'rejects a stale code');
check(hx_totp_verify($s, '000000', $now) === null || hx_totp_code($s, $now) === '000000', 'rejects a wrong code');
check(hx_totp_verify($s, 'abc', $now) === null, 'rejects a malformed code');
check(hx_totp_verify('', '123456', $now) === null, 'rejects an empty secret');

// counter returned must differ between steps, so replay can be blocked
$c1 = hx_totp_verify($s, hx_totp_code($s, $now), $now);
$c2 = hx_totp_verify($s, hx_totp_code($s, $now + 60), $now + 60);
check($c1 !== null && $c2 !== null && $c2 > $c1, 'counter advances with time', "$c1 -> $c2");

// --- backup codes ----------------------------------------------------------
[$plain, $hashed] = hx_backup_codes();
check(count($plain) === 10 && count($hashed) === 10, 'generates ten codes');
check(count(array_unique($plain)) === 10, 'codes are distinct');
check(!in_array($plain[0], $hashed, true), 'stored form is not the plain code');
check((bool)preg_match('/^[A-Z2-7]{4}(-[A-Z2-7]{4}){3}$/', $plain[0]),
    'code is 16 base32 characters (80 bits), grouped', $plain[0]);
check(!str_contains($plain[0] . $plain[1], '0') && !str_contains($plain[0] . $plain[1], '1'),
    'alphabet has no 0/O or 1/I to misread');
check(strlen($hashed[0]) === 64 && ctype_xdigit($hashed[0]), 'stored as a SHA-256 digest');

// Verification sits on an unauthenticated path in login.php, so it has to be
// fast enough that wrong guesses cannot be used to burn CPU.
$spin = $hashed;
$start = microtime(true);
for ($i = 0; $i < 200; $i++) hx_backup_code_consume('NOPE1-NOPE2-NOPE3-NOPE4', $spin);
$perCall = (microtime(true) - $start) / 200 * 1000;
check($perCall < 5, 'rejecting a wrong code is cheap', sprintf('%.3f ms/call', $perCall));

$store = $hashed;
check(hx_backup_code_consume($plain[3], $store) === true, 'a valid backup code is accepted');
check(count($store) === 9, 'consumed code is removed');
check(hx_backup_code_consume($plain[3], $store) === false, 'the same code cannot be used twice');
check(hx_backup_code_consume(str_replace('-', '', $plain[4]), $store) === true, 'accepts the code without its separator');
check(hx_backup_code_consume('NOPE1-NOPE2', $store) === false, 'rejects an unknown code');

// --- otpauth URI -----------------------------------------------------------
$uri = hx_totp_uri($secret, 'client@example.invalid');
check(str_starts_with($uri, 'otpauth://totp/HarbourX:'), 'URI has the issuer prefix');
check(str_contains($uri, 'secret=' . $secret), 'URI carries the secret');
check(str_contains($uri, 'digits=6') && str_contains($uri, 'period=30'), 'URI states digits and period');

echo $fail ? "\n$fail check(s) failed\n" : "\nAll TOTP checks passed\n";
exit($fail ? 1 : 0);
