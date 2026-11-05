<?php
declare(strict_types=1);

/*
 * Email verification at login (two-step login by email).
 * After the password, a sign-in email is sent with a 6-digit code and/or a "Yes, it's me" button.
 * Which of the two each role gets is set in Settings: off | code | link | both.
 */

const LOGIN_VERIFY_MODES = ['off' => 'Off', 'code' => 'Code only', 'link' => 'Button only', 'both' => 'Code or button'];
const LOGIN_VERIFY_MINUTES = 10;
const LOGIN_VERIFY_MAX_TRIES = 5;
const LOGIN_VERIFY_MAX_SENDS = 3;
const LOGIN_VERIFY_RESEND_SECONDS = 60;

function login_verify_mode(string $role): string
{
    $mode = setting('login_verify_' . $role, 'both');
    return isset(LOGIN_VERIFY_MODES[$mode]) ? $mode : 'both';
}

function login_verify_allows(string $mode, string $kind): bool
{
    return $mode === 'both' || $mode === $kind;
}

/** True when the dashboard has some way to send email (the linked info@ mailbox, or PHP mail()). */
function login_mail_available(): bool
{
    require_once __DIR__ . '/mailer.php';
    try {
        if (org_mailbox()) return true;
    } catch (PDOException $e) {
        // mailboxes table missing
    }
    return function_exists('mail') || is_localhost();
}

function is_localhost(): bool
{
    return in_array(strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]), ['localhost', '127.0.0.1'], true);
}

function admin_base_url(): string
{
    // Prefer the configured address so a forged Host header can't redirect the link
    $base = (string) config('admin_url');
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/';
    }
    return rtrim($base, '/') . '/';
}

function short_agent(string $ua): string
{
    $browser = match (true) {
        str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'SamsungBrowser') => 'Samsung Internet', str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari',
        default => 'Unknown browser',
    };
    $os = match (true) {
        str_contains($ua, 'Android') => 'Android', str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iPhone/iPad',
        str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'Linux') => 'Linux',
        default => 'unknown device',
    };
    return "$browser on $os";
}

function login_code_hash(int $challengeId, string $code): string
{
    return hash('sha256', $challengeId . ':' . preg_replace('/\D/', '', $code));
}

/**
 * Creates a sign-in check for $user, emails it, and puts it in the session as the pending login.
 * Returns 'sent', 'failed' (mail set up but sending failed), 'limit' (too many this hour) or 'unavailable' (no way to send email at all).
 */
function start_login_verify(array $user, bool $remember): string
{
    if (!login_mail_available()) return 'unavailable';
    // Someone who knows the password can't flood the inbox or keep drawing fresh codes
    $stmt = db()->prepare('SELECT COUNT(*) FROM login_challenges WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR');
    $stmt->execute([$user['id']]);
    if ((int) $stmt->fetchColumn() >= 10) return 'limit';
    $mode = login_verify_mode($user['role']);
    db()->prepare("UPDATE login_challenges SET status = 'expired' WHERE user_id = ? AND status = 'pending'")->execute([$user['id']]);
    db()->exec('DELETE FROM login_challenges WHERE created_at < NOW() - INTERVAL 30 DAY');
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO login_challenges (user_id, code_hash, token_hash, ip, user_agent, expires_at) VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ' . LOGIN_VERIFY_MINUTES . ' MINUTE)')
        ->execute([$user['id'], '', hash('sha256', $token), client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
    $id = (int) db()->lastInsertId();
    $code = (string) random_int(100000, 999999);
    db()->prepare('UPDATE login_challenges SET code_hash = ? WHERE id = ?')->execute([login_code_hash($id, $code), $id]);

    session_regenerate_id(true);
    $_SESSION['pending_2fa'] = ['user_id' => (int) $user['id'], 'remember' => $remember, 'at' => time(), 'tries' => 0, 'method' => 'email', 'challenge' => $id];
    return send_login_verify_email($user, $mode, $code, $token) ? 'sent' : 'failed';
}

/** Makes a new code for the pending check and emails it again. */
function resend_login_verify(array $user, int $challengeId): bool
{
    $token = bin2hex(random_bytes(32));
    $code = (string) random_int(100000, 999999);
    db()->prepare("UPDATE login_challenges SET code_hash = ?, token_hash = ?, sends = sends + 1, sent_at = NOW(), tries = 0,
        expires_at = NOW() + INTERVAL " . LOGIN_VERIFY_MINUTES . " MINUTE WHERE id = ? AND status = 'pending'")
        ->execute([login_code_hash($challengeId, $code), hash('sha256', $token), $challengeId]);
    return send_login_verify_email($user, login_verify_mode($user['role']), $code, $token);
}

function send_login_verify_email(array $user, string $mode, string $code, string $token): bool
{
    $link = admin_base_url() . 'login-approve?t=' . $token;
    $when = date('j M Y, g:i a');
    $where = client_ip() . ' · ' . short_agent((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $body = "Hello {$user['name']},\n\nSomeone is signing in to your Judes CBO dashboard account. To finish signing in:";
    if (login_verify_allows($mode, 'code')) {
        $body .= "\n\nYour sign-in code is: " . substr($code, 0, 3) . ' ' . substr($code, 3);
        if (login_verify_allows($mode, 'link')) $body .= "\n\nor approve the sign-in with this button:";
    }
    if (login_verify_allows($mode, 'link')) $body .= "\n\n$link";
    $body .= "\n\nThis works for " . LOGIN_VERIFY_MINUTES . " minutes.\n\nSign-in details: $when\n$where"
        . "\n\nIf this wasn't you, don't share the code or approve it: someone knows your password. "
        . (login_verify_allows($mode, 'link') ? 'Open the button and choose "This wasn\'t me", then ' : '')
        . "change your password under My profile.\n\nJudes CBO";
    $subject = 'Your Judes CBO sign-in' . (login_verify_allows($mode, 'code') ? ' code: ' . $code : '');

    $mailed = send_mail($user['email'], $subject, $body);
    if (!empty($user['personal_email']) && !empty($user['personal_email_verified_at']) && strcasecmp($user['personal_email'], $user['email']) !== 0) {
        $mailed = send_mail($user['personal_email'], $subject, $body) || $mailed;
    }
    // A local XAMPP install usually can't send email: keep the code and link in a private file for testing
    if (!$mailed && is_localhost()) {
        file_put_contents(dirname(__DIR__) . '/login-code.local.log', date('c') . "  {$user['email']}  code $code  $link\n", FILE_APPEND);
        $mailed = true;
    }
    return $mailed;
}

function find_login_challenge(int $id): ?array
{
    $stmt = db()->prepare('SELECT *, expires_at < NOW() AS expired, TIMESTAMPDIFF(SECOND, sent_at, NOW()) AS since_sent FROM login_challenges WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function find_login_challenge_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = db()->prepare('SELECT c.*, c.expires_at < NOW() AS expired, u.name, u.username FROM login_challenges c JOIN users u ON u.id = c.user_id WHERE c.token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

function login_user_row(int $id): ?array
{
    $stmt = db()->prepare('SELECT id, name, email, personal_email, personal_email_verified_at, role, active, totp_enabled, totp_secret, totp_last_step, recovery_codes FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}
