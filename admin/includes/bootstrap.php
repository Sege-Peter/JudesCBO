<?php
declare(strict_types=1);

$CONFIG = require dirname(__DIR__) . '/config.php';

date_default_timezone_set('Africa/Nairobi');
mb_internal_encoding('UTF-8');

require __DIR__ . '/security.php';
require __DIR__ . '/passwords.php';

// Security headers for every admin response
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cross-Origin-Opener-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
// Scripts, styles and fonts only from this site and the two libraries' CDNs; no other site may frame the dashboard
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
    . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
    . "font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com; img-src 'self' data: blob: https:; connect-src 'self'; "
    . "frame-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'");
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000');
}

function config(string $key)
{
    global $CONFIG;
    return $CONFIG[$key] ?? null;
}

/** Cookies are HTTPS-only when the config says so, or automatically whenever the dashboard is opened over HTTPS. */
function secure_cookies(): bool
{
    return (bool) config('secure_cookies') || is_https();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', config('db_host'), config('db_name'));
        $pdo = new PDO($dsn, config('db_user'), config('db_pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Stops with a styled error page, or plain text for scripts and API calls. */
function error_page(int $code, string $title, string $message, string $icon = 'fa-circle-exclamation'): never
{
    if (!headers_sent()) http_response_code($code);
    $api = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/') || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || isset($_SERVER['HTTP_X_CSRF_TOKEN']) || PHP_SAPI === 'cli';
    if ($api) {
        if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
        exit($message);
    }
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    $back = $code === 403 ? '<a class="btn primary" href="index.php"><i class="fas fa-gauge"></i> Dashboard</a>'
        : '<button type="button" class="btn primary" onclick="location.reload()"><i class="fas fa-rotate-right"></i> Try again</button>';
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title><?= h($title) ?> | Judes CBO dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 20px; padding: 32px 16px; text-align: center;
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: rgba(255,255,255,0.8);
        background: radial-gradient(ellipse at 10% 0%, rgba(45,80,22,0.7), transparent 55%), radial-gradient(ellipse at 95% 100%, rgba(155,44,44,0.35), transparent 50%), #0b1d35; }
    .brand { display: inline-flex; align-items: center; gap: 10px; color: #fff; font-weight: 700; letter-spacing: 1.4px; font-size: 13px; }
    .brand img { width: 36px; height: 36px; padding: 3px; border-radius: 10px; background: #fff; }
    .card { width: 100%; max-width: 480px; padding: 32px 26px 28px; border-radius: 24px; background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.14); box-shadow: 0 30px 70px rgba(0,0,0,0.35); }
    .icon { width: 76px; height: 76px; margin: 0 auto 16px; display: grid; place-items: center; border-radius: 50%; background: rgba(246,173,85,0.12); border: 1px solid rgba(246,173,85,0.35); color: #fbd38d; font-size: 30px; animation: bob 4s ease-in-out infinite; }
    @keyframes bob { 50% { transform: translateY(-6px); } }
    .code { display: inline-block; margin-bottom: 10px; padding: 4px 12px; border-radius: 999px; background: rgba(255,255,255,0.08); font-size: 12px; font-weight: 600; letter-spacing: .5px; }
    h1 { color: #fff; font-size: 26px; line-height: 1.2; margin-bottom: 10px; }
    p { font-size: 15.5px; line-height: 1.6; }
    .actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin-top: 24px; }
    .btn { display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 10px 18px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.2); background: rgba(255,255,255,0.08); color: #fff; font: inherit; font-weight: 700; font-size: 14px; text-decoration: none; cursor: pointer; }
    .btn:hover { background: rgba(255,255,255,0.16); }
    .btn.primary { background: #e53e3e; border-color: #e53e3e; }
    .btn.primary:hover { background: #c53030; }
    .btn:focus-visible { outline: 3px solid #68d391; outline-offset: 2px; }
    @media (max-width: 480px) { .btn { flex: 1 1 100%; justify-content: center; } }
    @media (prefers-reduced-motion: reduce) { .icon { animation: none; } }
</style>
</head>
<body>
<span class="brand"><img src="../jude-logo.png" alt="">JUDES CBO</span>
<main class="card">
    <div class="icon"><i class="fas <?= h($icon) ?>"></i></div>
    <span class="code">Error <?= $code ?></span>
    <h1><?= h($title) ?></h1>
    <p><?= h($message) ?></p>
    <div class="actions">
        <?= $back ?>
        <button type="button" class="btn" onclick="history.length > 1 ? history.back() : location.assign('index.php')"><i class="fas fa-arrow-left"></i> Go back</button>
    </div>
</main>
</body>
</html>
<?php
    exit;
}

// Unexpected errors (for example the database being down) get a friendly page; the details go to the PHP error log
set_exception_handler(function (Throwable $e): void {
    error_log('Judes CBO dashboard: ' . $e);
    error_page(500, 'Something went wrong', 'The dashboard ran into a problem. Please try again in a moment. If it keeps happening, tell the website administrator.', 'fa-gear');
});

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('judes_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => secure_cookies(),
    ]);
    session_start();
}

/* ---------- Flash messages ---------- */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function require_csrf(): void
{
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        error_page(400, 'Your session expired', 'Go back, refresh the page and try again.', 'fa-clock-rotate-left');
    }
}

/* ---------- Database upgrades ---------- */

const SCHEMA_VERSION = 7;

function column_exists(string $table, string $column): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Adds tables and columns introduced after the first install. Safe to run repeatedly. */
function migrate(): void
{
    try {
        $version = (int) (db()->query("SELECT value FROM settings WHERE name = 'schema_version'")->fetchColumn() ?: 1);
    } catch (PDOException $e) {
        return; // not installed yet
    }
    if ($version >= SCHEMA_VERSION) return;
    foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/schema.sql')))) as $sql) {
        db()->exec($sql);
    }
    // Version 3: two-step login
    $columns = [
        'totp_secret' => 'VARCHAR(64) NULL',
        'totp_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'totp_last_step' => 'BIGINT NOT NULL DEFAULT 0',
        'recovery_codes' => 'TEXT NULL',
    ];
    foreach ($columns as $name => $type) {
        if (!column_exists('users', $name)) {
            db()->exec("ALTER TABLE users ADD COLUMN $name $type");
        }
    }
    db()->exec("INSERT IGNORE INTO settings (name, value) VALUES ('require_2fa_admins', '1')");
    // Version 4: partners managed from the dashboard, starting with the logos already on the website
    if ((int) db()->query('SELECT COUNT(*) FROM partners')->fetchColumn() === 0) {
        $seed = [
            ['Triggerise (Tiko Africa)', 'images/partners/tiko.svg', ''],
            ['Ministry of Health, Kenya', 'images/partners/moh.png', ''],
            ['Homa Bay County', 'images/partners/homabay-county.png', ''],
            ['Dutch Flower Foundation', 'images/partners/dutch-flower-foundation.png', ''],
        ];
        $stmt = db()->prepare('INSERT INTO partners (name, logo, url, sort_order) VALUES (?, ?, ?, ?)');
        foreach ($seed as $i => $p) $stmt->execute([...$p, $i + 1]);
    }
    // Version 5: System admin role, signing users out remotely, "last seen"
    db()->exec("ALTER TABLE users MODIFY role ENUM('developer','admin','editor') NOT NULL DEFAULT 'editor'");
    $columns = [
        'session_version' => 'INT UNSIGNED NOT NULL DEFAULT 1',
        'last_seen_at' => 'DATETIME NULL',
        'last_ip' => 'VARCHAR(45) NULL',
    ];
    foreach ($columns as $name => $type) {
        if (!column_exists('users', $name)) {
            db()->exec("ALTER TABLE users ADD COLUMN $name $type");
        }
    }
    // The first administrator becomes the System admin, so someone can always reach the System page
    if ((int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'developer'")->fetchColumn() === 0) {
        db()->exec("UPDATE users SET role = 'developer' WHERE role = 'admin' ORDER BY active DESC, id LIMIT 1");
    }
    db()->exec("INSERT IGNORE INTO settings (name, value) VALUES ('maint_token', '" . bin2hex(random_bytes(16)) . "')");
    // Version 6: visitor statistics
    db()->exec("INSERT IGNORE INTO settings (name, value) VALUES ('analytics_on', '1'), ('analytics_keep_months', '13'), ('analytics_secret', '" . bin2hex(random_bytes(16)) . "')");
    // Version 7: blocked IP addresses and automatic blocking of password guessing
    db()->exec("INSERT IGNORE INTO settings (name, value) VALUES ('sec_autoblock', '1'), ('sec_autoblock_limit', '30'), ('sec_force_https', '0')");
    db()->prepare("INSERT INTO settings (name, value) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")->execute([SCHEMA_VERSION]);
}

/* ---------- Auth ---------- */

const SESSION_IDLE_LIMIT = 2 * 60 * 60; // log out after 2 hours of inactivity
const REMEMBER_DAYS = 30;
const REMEMBER_COOKIE = 'judes_remember';

function set_remember_cookie(string $value, int $expires): void
{
    setcookie(REMEMBER_COOKIE, $value, [
        'expires' => $expires,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => secure_cookies(),
    ]);
}

/** "Remember me": a selector/validator token; only a hash of the validator is stored. */
function issue_remember_token(int $userId): void
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + REMEMBER_DAYS * 86400;
    db()->prepare('INSERT INTO auth_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, FROM_UNIXTIME(?))')
        ->execute([$userId, $selector, hash('sha256', $validator), $expires]);
    set_remember_cookie("$selector:$validator", $expires);
}

function forget_remember_token(): void
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (is_string($cookie) && str_contains($cookie, ':')) {
        db()->prepare('DELETE FROM auth_tokens WHERE selector = ?')->execute([explode(':', $cookie)[0]]);
    }
    set_remember_cookie('', time() - 3600);
}

/** Signs this account out on every device (after a password change or reset). */
function revoke_all_tokens(int $userId): void
{
    db()->prepare('DELETE FROM auth_tokens WHERE user_id = ?')->execute([$userId]);
}

function login_from_remember_cookie(): void
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!is_string($cookie) || !preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $cookie, $m)) return;
    $stmt = db()->prepare('SELECT t.id, t.user_id, t.validator_hash FROM auth_tokens t JOIN users u ON u.id = t.user_id WHERE t.selector = ? AND t.expires_at > NOW() AND u.active = 1');
    $stmt->execute([$m[1]]);
    $row = $stmt->fetch();
    if (!$row || !hash_equals($row['validator_hash'], hash('sha256', $m[2]))) {
        set_remember_cookie('', time() - 3600);
        return;
    }
    // Replace the token on every use so a copied cookie stops working
    db()->prepare('DELETE FROM auth_tokens WHERE id = ?')->execute([$row['id']]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['user_id'];
    $_SESSION['last_seen'] = time();
    $_SESSION['sv'] = user_session_version((int) $row['user_id']);
    issue_remember_token((int) $row['user_id']);
}

function user_session_version(int $userId): int
{
    $stmt = db()->prepare('SELECT session_version FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/** Signs a user out everywhere: open sessions end on their next click and "remember me" stops working. */
function force_logout(int $userId): void
{
    db()->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = ?')->execute([$userId]);
    revoke_all_tokens($userId);
}

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    if (!empty($_SESSION['user_id']) && time() - ($_SESSION['last_seen'] ?? 0) > SESSION_IDLE_LIMIT) {
        unset($_SESSION['user_id']);
    }
    if (empty($_SESSION['user_id'])) {
        login_from_remember_cookie();
    }
    // A session copied to a different browser is refused (protects against stolen session cookies)
    $agent = substr(hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);
    if (!empty($_SESSION['user_id']) && isset($_SESSION['ua']) && $_SESSION['ua'] !== $agent) {
        log_activity('security.session_mismatch', 'Session used from a different browser; signed out', (int) $_SESSION['user_id']);
        unset($_SESSION['user_id'], $_SESSION['sv'], $_SESSION['ua']);
        login_from_remember_cookie();
    }
    if (!empty($_SESSION['user_id'])) {
        $_SESSION['ua'] ??= $agent;
        $_SESSION['last_seen'] = time();
        $stmt = db()->prepare('SELECT id, name, email, username, role, active, totp_enabled, session_version FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
        $_SESSION['sv'] ??= $row ? (int) $row['session_version'] : 0; // sessions from before this feature
        if ($row && (int) $_SESSION['sv'] !== (int) $row['session_version']) {
            unset($_SESSION['user_id'], $_SESSION['sv']);
            flash('info', 'You were signed out. Please log in again.');
            return $user = null;
        }
        $user = ($row && (int) $row['active'] === 1) ? $row : null;
        // "Last seen" for the Users and System pages, written at most once a minute
        if ($user && time() - ($_SESSION['seen_saved'] ?? 0) > 60) {
            $_SESSION['seen_saved'] = time();
            db()->prepare('UPDATE users SET last_seen_at = NOW(), last_ip = ? WHERE id = ?')->execute([client_ip(), $user['id']]);
        }
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    // While the dashboard is locked for maintenance only System admins can use it
    if (dashboard_locked() && $user['role'] !== 'developer') {
        unset($_SESSION['user_id'], $_SESSION['sv']);
        redirect('login.php');
    }
    maintenance_tick();
    staff_bypass_cookie();
    // Marks this browser as staff so the website's visitor statistics leave it out
    if (!isset($_COOKIE['judes_staff']) && !headers_sent()) {
        setcookie('judes_staff', '1', ['expires' => time() + 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => secure_cookies()]);
    }
    // Administrators must turn on two-step login before using the dashboard (when required in Settings)
    if (in_array($user['role'], ['admin', 'developer'], true) && !(int) $user['totp_enabled'] && setting('require_2fa_admins', '1') === '1'
        && !in_array(basename($_SERVER['SCRIPT_NAME']), ['profile.php', 'logout.php'], true)) {
        flash('info', 'Administrators need two-step login. Set it up below to continue.');
        redirect('profile.php#two-step');
    }
    return $user;
}

/** Finishes signing in once the password (and two-step code, if on) are confirmed. */
function complete_login(int $userId, bool $remember, string $note): never
{
    if (dashboard_locked() && user_role($userId) !== 'developer') {
        unset($_SESSION['pending_2fa']);
        flash('error', 'The dashboard is closed for maintenance. Only System admins can log in right now.');
        redirect('login.php');
    }
    session_regenerate_id(true);
    unset($_SESSION['pending_2fa']);
    $_SESSION['user_id'] = $userId;
    $_SESSION['last_seen'] = time();
    $_SESSION['sv'] = user_session_version($userId);
    if ($remember) {
        issue_remember_token($userId);
    }
    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$userId]);
    log_activity('login', $note . ($remember ? ' (remember me)' : ''), $userId);
    redirect('index.php');
}

/* ---------- Roles ---------- */

const ROLES = [
    'developer' => 'System admin',
    'admin' => 'Administrator',
    'editor' => 'Editor',
];
const ROLE_RANK = ['editor' => 1, 'admin' => 2, 'developer' => 3];

function role_label(string $role): string
{
    return ROLES[$role] ?? ucfirst($role);
}

function user_role(int $userId): string
{
    $stmt = db()->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return (string) $stmt->fetchColumn();
}

function require_admin(): array
{
    $user = require_login();
    if (!in_array($user['role'], ['admin', 'developer'], true)) {
        error_page(403, 'Administrators only', 'Only administrators can open this page. Ask an administrator if you need access.', 'fa-lock');
    }
    return $user;
}

function require_developer(): array
{
    $user = require_login();
    if ($user['role'] !== 'developer') {
        error_page(403, 'System admins only', 'Only System admins can open this page.', 'fa-lock');
    }
    return $user;
}

/** Administrators and System admins. */
function is_admin(): bool
{
    $user = current_user();
    return $user !== null && in_array($user['role'], ['admin', 'developer'], true);
}

function is_developer(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'developer';
}

/** Whether $me may change $target's account: System admins manage everyone, administrators manage everyone but System admins. */
function can_manage(array $me, array $target): bool
{
    return $me['role'] === 'developer' || ($me['role'] === 'admin' && $target['role'] !== 'developer');
}

/** Roles $me may hand out. */
function assignable_roles(array $me): array
{
    return $me['role'] === 'developer' ? ROLES : array_diff_key(ROLES, ['developer' => true]);
}

/* ---------- Maintenance ---------- */

function dashboard_locked(): bool
{
    return setting('dash_lock') === '1';
}

function maintenance_on(): bool
{
    return setting('maint_on') === '1';
}

/** Ends public maintenance once the "back by" time passes, if auto-end is on. Returns true when it just ended. */
function maintenance_tick(): bool
{
    if (!maintenance_on() || setting('maint_auto_end') !== '1') return false;
    $until = strtotime(setting('maint_until'));
    if (!$until || $until > time()) return false;
    save_setting('maint_on', '0');
    require_once __DIR__ . '/maintenance.php';
    write_maintenance_files();
    log_activity('maintenance.auto_end', 'Public maintenance ended automatically', null);
    return true;
}

/** Signed-in staff get a cookie that lets them see the real website during maintenance. */
function staff_bypass_cookie(): void
{
    if (headers_sent()) return;
    $token = setting('maint_token');
    $want = maintenance_on() && setting('maint_staff_bypass', '1') === '1' && $token !== '';
    $have = ($_COOKIE['judes_bypass'] ?? '') === $token;
    if ($want === $have) return;
    setcookie('judes_bypass', $want ? $token : '', [
        'expires' => $want ? time() + 12 * 3600 : time() - 3600,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => secure_cookies(),
    ]);
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

/* ---------- Activity log ---------- */

function log_activity(string $action, string $details = '', ?int $userId = null): void
{
    $userId ??= current_user()['id'] ?? null;
    $stmt = db()->prepare('INSERT INTO activity_log (user_id, action, details, ip) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $action, mb_substr($details, 0, 500), client_ip()]);
}

/* ---------- Settings ---------- */

function &settings_cache(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT name, value FROM settings') as $row) {
            $cache[$row['name']] = $row['value'];
        }
    }
    return $cache;
}

function setting(string $key, string $default = ''): string
{
    return settings_cache()[$key] ?? $default;
}

function save_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    $stmt->execute([$key, $value]);
    $cache = &settings_cache();
    $cache[$key] = $value; // so a publish later in this request sees the new value
}

/* ---------- Small helpers ---------- */

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'story';
}

function format_kes($amount): string
{
    return 'KES ' . number_format((float) $amount, 0);
}

function time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' h ago';
    if ($diff < 7 * 86400) return floor($diff / 86400) . ' d ago';
    return date('j M Y', strtotime($datetime));
}

function post_str(string $key, int $max = 2000): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
}

function send_csv(string $filename, array $header, iterable $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8 correctly
    fputcsv($out, $header);
    foreach ($rows as $row) {
        // Neutralise spreadsheet formulas in user-supplied text
        $row = array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $row);
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/**
 * Sends a plain-text email. Returns false if the server can't send mail
 * (common on a local XAMPP install).
 */
function send_mail(string $to, string $subject, string $body): bool
{
    $from = setting('org_email', 'info@judescbo.co.ke');
    $headers = "From: Judes CBO <$from>\r\nContent-Type: text/plain; charset=utf-8";
    return @mail($to, $subject, $body, $headers);
}

// Public endpoints that don't need a login (visitor statistics) skip the session so visitors get no cookie
if (!defined('NO_SESSION')) start_session();
migrate();

// Addresses blocked on the Security page get nothing from the dashboard or its forms
if (ip_blocked(client_ip())) {
    error_page(403, 'Access blocked', 'Access from your network has been blocked. If this is a mistake, contact the website administrator.', 'fa-ban');
}
