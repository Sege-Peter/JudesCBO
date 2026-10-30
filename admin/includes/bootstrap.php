<?php
declare(strict_types=1);

$CONFIG = require dirname(__DIR__) . '/config.php';

date_default_timezone_set('Africa/Nairobi');
mb_internal_encoding('UTF-8');

// Security headers for every admin response
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function config(string $key)
{
    global $CONFIG;
    return $CONFIG[$key] ?? null;
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
    session_name('judes_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => (bool) config('secure_cookies'),
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
        http_response_code(400);
        exit('Your session expired. Go back, refresh the page and try again.');
    }
}

/* ---------- Database upgrades ---------- */

const SCHEMA_VERSION = 4;

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
        'secure' => (bool) config('secure_cookies'),
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
    issue_remember_token((int) $row['user_id']);
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
    if (!empty($_SESSION['user_id'])) {
        $_SESSION['last_seen'] = time();
        $stmt = db()->prepare('SELECT id, name, email, username, role, active, totp_enabled FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
        $user = ($row && (int) $row['active'] === 1) ? $row : null;
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    // Administrators must turn on two-step login before using the dashboard (when required in Settings)
    if ($user['role'] === 'admin' && !(int) $user['totp_enabled'] && setting('require_2fa_admins', '1') === '1'
        && !in_array(basename($_SERVER['SCRIPT_NAME']), ['profile.php', 'logout.php'], true)) {
        flash('info', 'Administrators need two-step login. Set it up below to continue.');
        redirect('profile.php#two-step');
    }
    return $user;
}

/** Finishes signing in once the password (and two-step code, if on) are confirmed. */
function complete_login(int $userId, bool $remember, string $note): never
{
    session_regenerate_id(true);
    unset($_SESSION['pending_2fa']);
    $_SESSION['user_id'] = $userId;
    $_SESSION['last_seen'] = time();
    if ($remember) {
        issue_remember_token($userId);
    }
    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$userId]);
    log_activity('login', $note . ($remember ? ' (remember me)' : ''), $userId);
    redirect('index.php');
}

function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Only administrators can open this page.');
    }
    return $user;
}

function is_admin(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'admin';
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
    $from = setting('org_email', 'info@judescbo.org');
    $headers = "From: Judes CBO <$from>\r\nContent-Type: text/plain; charset=utf-8";
    return @mail($to, $subject, $body, $headers);
}

start_session();
migrate();
