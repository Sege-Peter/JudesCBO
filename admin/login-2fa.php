<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/totp.php';

const PENDING_MINUTES = 10;
const MAX_TRIES = 5;

$pending = $_SESSION['pending_2fa'] ?? null;
if (!$pending || time() - $pending['at'] > PENDING_MINUTES * 60) {
    unset($_SESSION['pending_2fa']);
    flash('error', 'Please log in again.');
    redirect('login.php');
}

$useRecovery = ($_GET['method'] ?? $_POST['method'] ?? '') === 'recovery';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $stmt = db()->prepare('SELECT id, totp_secret, totp_enabled, totp_last_step, recovery_codes, active FROM users WHERE id = ?');
    $stmt->execute([$pending['user_id']]);
    $user = $stmt->fetch();
    if (!$user || !(int) $user['active']) {
        unset($_SESSION['pending_2fa']);
        redirect('login.php');
    }
    $code = post_str('code', 20);
    if ($useRecovery) {
        $remaining = use_recovery_code($user['recovery_codes'], $code);
        if ($remaining !== null) {
            db()->prepare('UPDATE users SET recovery_codes = ? WHERE id = ?')->execute([$remaining, $user['id']]);
            $left = count(json_decode($remaining, true) ?: []);
            flash($left <= 2 ? 'error' : 'info', "You used a recovery code. $left left" . ($left <= 2 ? ': create new ones in My profile soon.' : '.'));
            complete_login((int) $user['id'], (bool) $pending['remember'], 'Signed in with a recovery code');
        }
    } elseif ($step = totp_verify((string) $user['totp_secret'], $code, (int) $user['totp_last_step'])) {
        db()->prepare('UPDATE users SET totp_last_step = ? WHERE id = ?')->execute([$step, $user['id']]);
        complete_login((int) $user['id'], (bool) $pending['remember'], 'Signed in with two-step code');
    }
    $_SESSION['pending_2fa']['tries'] = ++$pending['tries'];
    log_activity('login.2fa_failed', 'Wrong two-step code', (int) $user['id']);
    if ($pending['tries'] >= MAX_TRIES) {
        unset($_SESSION['pending_2fa']);
        db()->prepare('INSERT INTO login_attempts (ip, username) VALUES (?, ?)')->execute([client_ip(), 'user#' . $user['id']]);
        flash('error', 'Too many wrong codes. Please log in again.');
        redirect('login.php');
    }
    $error = $useRecovery ? 'That recovery code is not valid or was already used.' : 'That code is not right. Check your app and try the newest code.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Two-step login | Judes CBO Admin</title>
    <link rel="icon" href="../favicon.ico" sizes="any">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700&family=Raleway:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <span class="auth-icon"><i class="fas <?= $useRecovery ? 'fa-life-ring' : 'fa-mobile-screen-button' ?>"></i></span>
        <h1><?= $useRecovery ? 'Use a recovery code' : 'Two-step login' ?></h1>
        <p class="auth-sub"><?= $useRecovery ? 'Enter one of the recovery codes you saved when you set up two-step login.' : 'Open your authenticator app and enter the 6-digit code for Judes CBO.' ?></p>
        <?php if ($error): ?><div class="alert alert-error" role="alert"><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="method" value="<?= $useRecovery ? 'recovery' : 'app' ?>">
            <?php if ($useRecovery): ?>
                <label>Recovery code
                    <input type="text" name="code" required autocomplete="off" autofocus placeholder="ABCDE-12345" class="code-input code-input-wide">
                </label>
            <?php else: ?>
                <label>6-digit code
                    <input type="text" name="code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" autofocus placeholder="123 456" class="code-input">
                </label>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary btn-block">Verify <i class="fas fa-arrow-right"></i></button>
        </form>
        <p class="auth-foot">
            <?php if ($useRecovery): ?>
                <a href="login-2fa.php"><i class="fas fa-mobile-screen-button"></i> Use the app code instead</a>
            <?php else: ?>
                <a href="login-2fa.php?method=recovery"><i class="fas fa-life-ring"></i> Lost your phone? Use a recovery code</a>
            <?php endif; ?>
        </p>
        <p class="auth-foot"><a href="login.php"><i class="fas fa-arrow-left"></i> Back to login</a></p>
    </main>
</body>
</html>
