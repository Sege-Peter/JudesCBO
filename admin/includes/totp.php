<?php
declare(strict_types=1);

/**
 * Time-based one-time codes (RFC 6238), compatible with Google Authenticator,
 * Microsoft Authenticator, Authy and similar apps.
 */

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;

function totp_new_secret(): string
{
    return base32_encode(random_bytes(20));
}

function base32_encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode(string $text): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $text = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $text) ?? '');
    $bits = '';
    foreach (str_split($text) as $c) {
        $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $out .= chr(bindec($byte));
    }
    return $out;
}

function totp_code(string $secret, int $step): string
{
    $hash = hash_hmac('sha1', pack('J', $step), base32_decode($secret), true);
    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string) ($value % (10 ** TOTP_DIGITS)), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * Checks a code against the current 30-second window and one either side
 * (for phone clocks that drift). Returns the matching time step, or null.
 * Steps at or before $lastUsedStep are rejected so a code can't be replayed.
 */
function totp_verify(string $secret, string $code, int $lastUsedStep = 0): ?int
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== TOTP_DIGITS) return null;
    $now = intdiv(time(), TOTP_PERIOD);
    for ($step = $now - 1; $step <= $now + 1; $step++) {
        if ($step > $lastUsedStep && hash_equals(totp_code($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}

function totp_uri(string $secret, string $account): string
{
    return 'otpauth://totp/' . rawurlencode('Judes CBO:' . $account)
        . '?secret=' . $secret . '&issuer=' . rawurlencode('Judes CBO') . '&digits=' . TOTP_DIGITS . '&period=' . TOTP_PERIOD;
}

/** Ten one-time recovery codes; only their hashes are stored. */
function make_recovery_codes(): array
{
    $plain = [];
    for ($i = 0; $i < 10; $i++) {
        $raw = strtoupper(bin2hex(random_bytes(5)));
        $plain[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
    }
    return $plain;
}

function hash_recovery_codes(array $plain): string
{
    return json_encode(array_map(fn($c) => password_hash(normalise_recovery($c), PASSWORD_DEFAULT), $plain));
}

function normalise_recovery(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}

/** Uses up a recovery code if it matches. Returns the remaining hashes JSON, or null if no match. */
function use_recovery_code(?string $storedJson, string $code): ?string
{
    $hashes = json_decode((string) $storedJson, true) ?: [];
    $code = normalise_recovery($code);
    if (strlen($code) !== 10) return null;
    foreach ($hashes as $i => $hash) {
        if (password_verify($code, $hash)) {
            unset($hashes[$i]);
            return json_encode(array_values($hashes));
        }
    }
    return null;
}
