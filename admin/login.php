<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/login-verify.php';

if (current_user()) {
    redirect('index');
}

const MAX_ATTEMPTS = 4;   // wrong passwords in a row...
const LOCK_SECONDS = 120; // ...then wait 2 minutes

/** Seconds until this address/username may try again (0 = not locked). Each lockout gives a fresh 4 tries afterwards. */
function login_wait(string $ip, string $username): int
{
    $stmt = db()->prepare('SELECT UNIX_TIMESTAMP(attempted_at) FROM login_attempts WHERE (ip = ? OR username = ?) AND attempted_at > NOW() - INTERVAL 1 HOUR ORDER BY attempted_at, id');
    $stmt->execute([$ip, $username]);
    $until = 0;
    $n = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (++$n >= MAX_ATTEMPTS) { $until = (int) $t + LOCK_SECONDS; $n = 0; }
    }
    $now = (int) db()->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
    return max(0, $until - $now);
}

function wait_text(int $s): string
{
    return $s > 60 ? ceil($s / 60) . ' minutes' : $s . ' seconds';
}

$error = '';
$username = '';
$remember = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = post_str('username', 60);
    $password = (string) ($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);
    $ip = client_ip();

    if ($wait = login_wait($ip, $username)) {
        $error = 'Too many failed attempts. Please wait ' . wait_text($wait) . ' and try again.';
    } else {
        $stmt = db()->prepare('SELECT id, password_hash, active, totp_enabled, role FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        // Check a dummy hash when the account doesn't exist, so response times don't reveal which usernames are real
        $valid = password_verify($password, $user['password_hash'] ?? '$2y$10$LT.uwPz6RQDFPpPB121Zn.MEecelLqXhTKEosjbF/ULJACmhJY/F.');
        if ($user && (int) $user['active'] === 1 && $valid) {
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            db()->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$username]);
            if ((int) $user['totp_enabled'] === 1) {
                // Password is right; now ask for the code from the authenticator app
                session_regenerate_id(true);
                $_SESSION['pending_2fa'] = ['user_id' => (int) $user['id'], 'remember' => $remember, 'at' => time(), 'tries' => 0, 'method' => 'app'];
                redirect('login-2fa');
            }
            if (login_verify_mode($user['role']) !== 'off') {
                // Password is right; now confirm by email (a code and/or a "Yes, it's me" button, depending on the role)
                $full = login_user_row((int) $user['id']);
                $result = start_login_verify($full, $remember);
                if ($result === 'sent') redirect('login-2fa');
                if ($result === 'failed' || $result === 'limit') {
                    unset($_SESSION['pending_2fa']);
                    $error = $result === 'limit' ? 'Too many sign-in emails were sent for this account. Please try again in an hour.'
                        : "Your password is right, but we couldn't send the sign-in email. Please try again in a minute.";
                    log_activity('login.verify_unsent', $result === 'limit' ? 'Sign-in email limit reached (10 an hour)' : 'Sign-in email could not be sent', (int) $user['id']);
                } else {
                    // No way to send email has been set up yet: let the login through, but leave a warning in the log
                    log_activity('security.verify_skipped', 'Email check skipped: no mailbox is set up to send sign-in emails', (int) $user['id']);
                    complete_login((int) $user['id'], $remember, 'Signed in (email check skipped)');
                }
            } else {
                complete_login((int) $user['id'], $remember, 'Signed in');
            }
        }
        if ($error === '') {
            // Audit trail (the password itself is never recorded)
            $why = !$user ? 'Unknown username "' . mb_substr($username, 0, 60) . '"'
                : ((int) $user['active'] !== 1 ? 'Deactivated account tried to log in' : 'Wrong password');
            log_activity('login.failed', $why, $user ? (int) $user['id'] : null);
            db()->prepare('INSERT INTO login_attempts (ip, username) VALUES (?, ?)')->execute([$ip, $username]);
            db()->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 30 DAY');
            auto_block_check($ip);
            if ($wait = login_wait($ip, $username)) {
                log_activity('login.locked', MAX_ATTEMPTS . ' failed logins; must wait ' . wait_text($wait) . ($user ? '' : ' (username "' . mb_substr($username, 0, 60) . '")'), $user ? (int) $user['id'] : null);
                $error = 'Too many failed attempts. Please wait ' . wait_text($wait) . ' and try again.';
            } else {
                $error = 'Incorrect username or password.';
            }
        }
    }
}
$flashes = take_flashes();
if (isset($_GET['idle']) && !$flashes) {
    $flashes[] = ['type' => 'info', 'message' => 'You were signed out because the dashboard was left idle. Please log in again.'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Log in | Judes CBO Admin</title>
    <link rel="icon" href="../favicon.ico" sizes="any">
    <link rel="icon" href="../images/icons/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="preload" href="../fonts/raleway-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../fonts/montserrat-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../css/fonts.css">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <img src="../images/icons/icon-192.png" alt="Judes CBO" class="auth-logo">
        <h1>Staff login</h1>
        <p class="auth-sub">Judes CBO website dashboard</p>
        <?php if (dashboard_locked()): ?><div class="alert alert-info" role="status"><i class="fas fa-lock"></i><span><strong>The dashboard is closed for maintenance.</strong> Only System admins can log in right now.<?= setting('dash_lock_message') ? ' ' . h(setting('dash_lock_message')) : '' ?></span></div><?php endif; ?>
        <?php foreach ($flashes as $f): ?><div class="alert alert-<?= h($f['type']) ?>" role="status"><?= h($f['message']) ?></div><?php endforeach; ?>
        <?php if ($error): ?><div class="alert alert-error" role="alert"><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?>
            <label>Username or email
                <span class="input-icon"><i class="fas fa-user"></i><input type="text" name="username" value="<?= h($username) ?>" required autocomplete="username" autofocus></span>
            </label>
            <label>Password
                <span class="input-icon"><i class="fas fa-lock"></i><input type="password" name="password" id="password" required autocomplete="current-password">
                <button type="button" class="toggle-pass" data-target="password" aria-label="Show password"><i class="fas fa-eye"></i></button></span>
            </label>
            <div class="auth-row">
                <label class="check"><input type="checkbox" name="remember" value="1" <?= $remember ? 'checked' : '' ?>> Remember me</label>
                <a href="forgot-password" class="auth-link">Forgot password?</a>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Log in <i class="fas fa-arrow-right"></i></button>
        </form>
        <p class="auth-foot"><a href="../index.html"><i class="fas fa-arrow-left"></i> Back to website</a></p>
    </main>
    <script src="assets/admin.js"></script>
</body>
</html>
