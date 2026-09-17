<?php
/**
 * Time-based one-time passwords (RFC 6238) and single-use backup codes.
 *
 * No dependencies: TOTP is HMAC-SHA1 over a counter, and PHP has both hash_hmac
 * and random_bytes in core. Codes are the standard 6 digits on a 30-second step,
 * so any authenticator app works — Google Authenticator, Authy, 1Password, Aegis.
 *
 * Secrets are stored base32-encoded on the client record. They are shared
 * secrets by nature: anyone who can read data/users.json can mint codes, which
 * is one more reason that file belongs nowhere near version control.
 */

const HX_TOTP_PERIOD = 30;   // seconds per code
const HX_TOTP_DIGITS = 6;
const HX_TOTP_WINDOW = 1;    // accept one step either side, for clock drift
const HX_BACKUP_CODE_COUNT = 10;

const HX_BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** Encode raw bytes as RFC 4648 base32, without padding. */
function hx_base32_encode(string $bytes): string
{
    if ($bytes === '') return '';
    $bits = '';
    foreach (str_split($bytes) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= HX_BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

/** Decode base32 back to raw bytes. Returns '' for anything malformed. */
function hx_base32_decode(string $text): string
{
    $text = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $text));
    if ($text === '') return '';

    $bits = '';
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
        $index = strpos(HX_BASE32_ALPHABET, $text[$i]);
        if ($index === false) return '';
        $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
    }

    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
    }
    return $out;
}

/** A fresh 160-bit secret, base32-encoded — the size RFC 4226 recommends. */
function hx_totp_secret(): string
{
    return hx_base32_encode(random_bytes(20));
}

/** The counter value for a moment in time. */
function hx_totp_counter(?int $timestamp = null): int
{
    return intdiv($timestamp ?? time(), HX_TOTP_PERIOD);
}

/** The code for a given counter, zero-padded to HX_TOTP_DIGITS. */
function hx_totp_code_at(string $secret, int $counter): string
{
    $key = hx_base32_decode($secret);
    if ($key === '') return '';

    // 8-byte big-endian counter. pack('J') needs 64-bit, which every supported
    // PHP build has, but build it by hand so this cannot surprise anyone.
    $binary = '';
    for ($i = 7; $i >= 0; $i--) {
        $binary = chr($counter & 0xFF) . $binary;
        $counter >>= 8;
    }

    $hash = hash_hmac('sha1', $binary, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);

    return str_pad((string)($truncated % (10 ** HX_TOTP_DIGITS)), HX_TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/** The code for right now. Handy for tests and for the admin preview. */
function hx_totp_code(string $secret, ?int $timestamp = null): string
{
    return hx_totp_code_at($secret, hx_totp_counter($timestamp));
}

/**
 * Check a code against the secret.
 *
 * Returns the counter it matched, or null. The caller should refuse a counter
 * it has already accepted — that is what stops someone replaying a code they
 * shoplifted within its 30-second life.
 */
function hx_totp_verify(string $secret, string $code, ?int $timestamp = null): ?int
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== HX_TOTP_DIGITS || $secret === '') return null;

    $now = hx_totp_counter($timestamp);
    for ($drift = -HX_TOTP_WINDOW; $drift <= HX_TOTP_WINDOW; $drift++) {
        $counter = $now + $drift;
        if ($counter < 0) continue;
        // hash_equals, not ===, so the comparison does not leak position by timing.
        if (hash_equals(hx_totp_code_at($secret, $counter), $code)) {
            return $counter;
        }
    }
    return null;
}

/** The otpauth:// URI an authenticator app scans. */
function hx_totp_uri(string $secret, string $account, string $issuer = 'HarbourX'): string
{
    return sprintf(
        'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
        rawurlencode($issuer),
        rawurlencode($account),
        $secret,
        rawurlencode($issuer),
        HX_TOTP_DIGITS,
        HX_TOTP_PERIOD
    );
}

/**
 * Backup codes for the day the phone is lost.
 *
 * These are 80-bit tokens straight from the CSPRNG, not passwords a person
 * chose, so they are stored as a plain SHA-256 rather than through
 * password_hash(). bcrypt's work factor exists to slow down guessing a
 * low-entropy secret; against 80 bits it buys nothing, and it costs plenty:
 * at PHP 8.4's default cost of 12, verifying a code meant walking ten bcrypt
 * hashes — about five seconds, on a path login.php exposes before the caller
 * has authenticated. A fast hash over a token this size is the same trade every
 * API key makes.
 *
 * The alphabet is base32 (A-Z, 2-7), which has no 0/O or 1/I to misread.
 *
 * Returns [$plain, $stored]. Only $plain is ever shown, once, at enrolment.
 */
function hx_backup_codes(int $count = HX_BACKUP_CODE_COUNT): array
{
    $plain = [];
    $stored = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = hx_base32_encode(random_bytes(10));          // 80 bits -> 16 chars
        $code = implode('-', str_split(substr($raw, 0, 16), 4));
        $plain[] = $code;
        $stored[] = hx_backup_code_hash($code);
    }
    return [$plain, $stored];
}

/** Canonical form of a backup code: just the base32 characters, upper case. */
function hx_backup_code_canonical(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $code));
}

/** The stored form of a backup code. */
function hx_backup_code_hash(string $code): string
{
    return hash('sha256', hx_backup_code_canonical($code));
}

/**
 * Spend a backup code.
 *
 * On a match the entry is removed from $stored (by reference) so the code
 * cannot be used twice, and true is returned. Comparison is constant-time and
 * the whole list is walked either way, so neither timing nor duration says
 * how close a guess was.
 */
function hx_backup_code_consume(string $candidate, array &$stored): bool
{
    $canonical = hx_backup_code_canonical($candidate);
    if ($canonical === '') return false;

    $wanted = hash('sha256', $canonical);
    $matchedAt = -1;
    foreach ($stored as $index => $hash) {
        if (is_string($hash) && hash_equals($hash, $wanted)) {
            $matchedAt = $index;
        }
    }
    if ($matchedAt < 0) return false;

    unset($stored[$matchedAt]);
    $stored = array_values($stored);
    return true;
}

/** The 2FA block on a client record, with every field defaulted. */
function hx_totp_state(array $user): array
{
    $totp = is_array($user['totp'] ?? null) ? $user['totp'] : [];
    return [
        'secret' => (string)($totp['secret'] ?? ''),
        'enabled' => !empty($totp['enabled']),
        'confirmedAt' => (string)($totp['confirmedAt'] ?? ''),
        'lastCounter' => (int)($totp['lastCounter'] ?? 0),
        'backupCodes' => array_values(array_filter(
            is_array($totp['backupCodes'] ?? null) ? $totp['backupCodes'] : [],
            'is_string'
        ))
    ];
}
