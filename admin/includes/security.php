<?php
declare(strict_types=1);

/* Blocked IP addresses, the "Force HTTPS" switch and the security checklist (System admins). */

const HTTPS_BEGIN = '# BEGIN force https (managed by the dashboard, do not edit)';
const HTTPS_END = '# END force https';

function is_https(): bool
{
    return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTPS'] ?? '') === '1'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function ip_blocked(string $ip): bool
{
    try {
        $stmt = db()->prepare('SELECT 1 FROM blocked_ips WHERE ip = ? AND (expires_at IS NULL OR expires_at > NOW())');
        $stmt->execute([$ip]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false; // table not created yet
    }
}

function block_ip(string $ip, string $reason, ?int $hours = null, bool $auto = false): void
{
    db()->prepare('INSERT INTO blocked_ips (ip, reason, auto, expires_at) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE reason = VALUES(reason), auto = VALUES(auto), expires_at = VALUES(expires_at), created_at = NOW()')
        ->execute([$ip, mb_substr($reason, 0, 200), $auto ? 1 : 0, $hours ? date('Y-m-d H:i:s', time() + $hours * 3600) : null]);
}

/** After a failed login: block an address that keeps guessing passwords (when turned on). */
function auto_block_check(string $ip): void
{
    if (setting('sec_autoblock', '1') !== '1') return;
    $limit = max(10, (int) setting('sec_autoblock_limit', '30'));
    $stmt = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > NOW() - INTERVAL 1 DAY');
    $stmt->execute([$ip]);
    if ((int) $stmt->fetchColumn() >= $limit) {
        block_ip($ip, "$limit+ failed logins in 24 hours", 24, true);
        log_activity('security.auto_block', "$ip blocked for 24 hours after $limit failed logins", null);
    }
}

function https_rules(): string
{
    if (setting('sec_force_https') !== '1') return HTTPS_BEGIN . "\n# (off)\n" . HTTPS_END;
    return HTTPS_BEGIN . "\n"
        . "RewriteCond %{HTTPS} !=on\n"
        . "RewriteCond %{HTTP:X-Forwarded-Proto} !https\n"
        . "RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n"
        . "<IfModule mod_headers.c>\n"
        . "    Header always set Strict-Transport-Security \"max-age=31536000\"\n"
        . "</IfModule>\n"
        . HTTPS_END;
}

/** Writes the HTTPS block into the website's .htaccess. Returns false if the file can't be changed. */
function write_htaccess_https(): bool
{
    require_once __DIR__ . '/publisher.php';
    $path = site_path('.htaccess');
    if (!is_file($path) || !is_writable($path)) return false;
    $htaccess = file_get_contents($path);
    $block = https_rules();
    $pattern = '/' . preg_quote(HTTPS_BEGIN, '/') . '.*?' . preg_quote(HTTPS_END, '/') . '/s';
    if (preg_match($pattern, $htaccess)) {
        $updated = preg_replace_callback($pattern, fn() => $block, $htaccess, 1);
    } elseif (preg_match('/^RewriteEngine On\s*$/m', $htaccess)) {
        // First thing after RewriteEngine, so visitors are sent to HTTPS before any other rule
        $updated = preg_replace_callback('/^RewriteEngine On\s*$/m', fn($m) => rtrim($m[0]) . "\n\n" . $block . "\n", $htaccess, 1);
    } else {
        $updated = rtrim($htaccess) . "\n\nRewriteEngine On\n" . $block . "\n";
    }
    if ($updated !== $htaccess) write_atomic($path, $updated);
    return true;
}

/**
 * The security checklist: [status ('ok'|'warn'|'bad'), title, detail].
 */
function security_checks(): array
{
    require_once __DIR__ . '/publisher.php';
    $checks = [];
    $local = in_array(client_ip(), ['127.0.0.1', '::1'], true);

    $https = is_https();
    $checks[] = $https
        ? ['ok', 'Dashboard uses HTTPS', 'Passwords and data travel encrypted.']
        : [$local ? 'warn' : 'bad', 'Dashboard is not on HTTPS', $local ? 'Fine on this computer. On the live server, install an SSL certificate (free in cPanel) and open the dashboard with https://.' : 'Passwords are sent unencrypted. Install an SSL certificate (free in cPanel → SSL/TLS Status) and open the dashboard with https://.'];
    $checks[] = setting('sec_force_https') === '1'
        ? ['ok', 'Visitors are sent to HTTPS', 'Every http:// visit is redirected, and browsers are told to always use HTTPS.']
        : ['warn', 'HTTPS is not enforced', 'Turn on "Force HTTPS" below once the SSL certificate works.'];
    $checks[] = secure_cookies()
        ? ['ok', 'Login cookies are HTTPS-only', 'Cookies are never sent over an unencrypted connection.']
        : ['warn', 'Login cookies also work over plain HTTP', 'This turns on automatically when the dashboard is opened over HTTPS.'];

    require_once __DIR__ . '/login-verify.php';
    $emailRoles = array_values(array_filter(array_keys(ROLES), fn($r) => login_verify_mode($r) !== 'off'));
    $skip = $emailRoles ? " AND role NOT IN ('" . implode("','", $emailRoles) . "')" : '';
    if ($emailRoles) {
        $checks[] = login_mail_available()
            ? ['ok', 'Sign-ins are confirmed by email', 'For: ' . implode(', ', array_map(fn($r) => ROLES[$r] . 's', $emailRoles)) . '. Set in Settings.']
            : ['bad', 'Sign-in emails cannot be sent', 'The email check at login is skipped until the ' . setting('org_email', 'info@judescbo.co.ke') . ' mailbox is linked (shared) on the Mailbox page.'];
    }
    $without = db()->query("SELECT name FROM users WHERE active = 1 AND role IN ('admin','developer') AND totp_enabled = 0$skip")->fetchAll(PDO::FETCH_COLUMN);
    $checks[] = !$without
        ? ['ok', 'All administrators use two-step login', 'A stolen password alone is not enough to get in.']
        : ['bad', count($without) . ' administrator' . (count($without) === 1 ? '' : 's') . ' without two-step login', implode(', ', $without) . '. They should set it up under My profile.'];
    $editors = in_array('editor', $emailRoles, true) ? 0 : (int) db()->query("SELECT COUNT(*) FROM users WHERE active = 1 AND role = 'editor' AND totp_enabled = 0")->fetchColumn();
    if ($editors) $checks[] = ['warn', "$editors editor" . ($editors === 1 ? '' : 's') . ' without two-step login', 'Optional for editors, but recommended. They can turn it on under My profile.'];
    if (!in_array('admin', $emailRoles, true) || !in_array('developer', $emailRoles, true)) $checks[] = setting('require_2fa_admins', '1') === '1'
        ? ['ok', 'Two-step login is required for administrators', 'Set in Settings.']
        : ['bad', 'Two-step login is optional for administrators', 'Turn on "Require two-step login" in Settings.'];

    $stale = (int) db()->query("SELECT COUNT(*) FROM users WHERE active = 1 AND (last_login IS NULL OR last_login < NOW() - INTERVAL 90 DAY) AND created_at < NOW() - INTERVAL 14 DAY")->fetchColumn();
    $checks[] = !$stale
        ? ['ok', 'No unused accounts', 'Every active account has logged in within 90 days.']
        : ['warn', "$stale account" . ($stale === 1 ? " hasn't" : "s haven't") . ' logged in for 90 days', 'Deactivate accounts people no longer use on the Users page.'];

    $leftovers = array_map('basename', array_merge(glob(__DIR__ . '/../*.local.txt') ?: [], glob(__DIR__ . '/../*.local.log') ?: [], glob(site_path('*.tmp-*')) ?: []));
    $checks[] = !$leftovers
        ? ['ok', 'No leftover notes or logs', 'No credential notes or test files on the server.']
        : [$local ? 'warn' : 'bad', 'Notes or log files on the server', implode(', ', $leftovers) . '. They are blocked from the web, but delete them from the live server.'];

    $checks[] = !ini_get('display_errors') || $local
        ? ['ok', 'Errors are hidden from visitors', $local ? 'Shown on this computer only, which is fine for development.' : 'PHP errors are not shown on pages.']
        : ['bad', 'PHP errors are shown on pages', 'Turn off display_errors in cPanel → MultiPHP INI Editor. Errors can reveal file paths.'];
    $checks[] = version_compare(PHP_VERSION, '8.1', '>=')
        ? ['ok', 'PHP ' . PHP_VERSION . ' is supported', 'Keep it updated in cPanel → MultiPHP Manager.']
        : ['bad', 'PHP ' . PHP_VERSION . ' is out of date', 'Switch to PHP 8.2 or newer in cPanel → MultiPHP Manager.'];
    $checks[] = is_file(site_path('uploads/.htaccess'))
        ? ['ok', 'Uploads cannot run as code', 'The uploads folder only serves images.']
        : ['bad', 'Uploads folder is unprotected', 'uploads/.htaccess is missing. Restore it from the website files.'];
    $checks[] = str_contains((string) @file_get_contents(site_path('.htaccess')), 'Content-Security-Policy')
        ? ['ok', 'Browser security headers are set', 'Pages tell browsers which sites may load scripts, frames and fonts.']
        : ['warn', 'Browser security headers are missing', 'The website .htaccess should set Content-Security-Policy. Restore it from the website files.'];
    return $checks;
}
