<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/totp.php';
require __DIR__ . '/includes/login-verify.php';

const PENDING_MINUTES = 10;
const MAX_TRIES = 5;

$pending = $_SESSION['pending_2fa'] ?? null;
if (!$pending || time() - $pending['at'] > PENDING_MINUTES * 60) {
    unset($_SESSION['pending_2fa']);
    if (isset($_GET['poll'])) {
        header('Content-Type: application/json');
        exit('{"status":"gone"}');
    }
    flash('error', 'Please log in again.');
    redirect('login');
}
$user = login_user_row((int) $pending['user_id']);
if (!$user || !(int) $user['active']) {
    unset($_SESSION['pending_2fa']);
    redirect('login');
}
$mode = login_verify_mode($user['role']);
$method = $pending['method'] ?? 'app';
$challenge = $method === 'email' ? find_login_challenge((int) ($pending['challenge'] ?? 0)) : null;
if ($method === 'email' && (!$challenge || (int) $challenge['user_id'] !== (int) $user['id'])) {
    unset($_SESSION['pending_2fa']);
    redirect('login');
}

// The waiting page asks every few seconds whether the email button was pressed
if (isset($_GET['poll'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['status' => $challenge ? ((int) $challenge['expired'] && $challenge['status'] === 'pending' ? 'expired' : $challenge['status']) : 'none']);
    exit;
}

// Approved from the email (possibly on another device): finish signing in here
if (isset($_GET['finish']) && $challenge) {
    if ($challenge['status'] === 'approved') {
        db()->prepare("UPDATE login_challenges SET status = 'used' WHERE id = ? AND status = 'approved'")->execute([$challenge['id']]);
        complete_login((int) $user['id'], (bool) $pending['remember'], 'Signed in (approved from email)');
    }
    if ($challenge['status'] === 'denied') {
        unset($_SESSION['pending_2fa']);
        flash('error', 'This sign-in was stopped from the email ("This wasn\'t me").');
        redirect('login');
    }
    redirect('login-2fa');
}

// Switch between the authenticator app and email
if (($_GET['use'] ?? '') === 'email' && $method === 'app' && $mode !== 'off') {
    $result = start_login_verify($user, (bool) $pending['remember']);
    flash($result === 'sent' ? 'info' : 'error', $result === 'sent' ? 'We sent you a sign-in email.' : ($result === 'limit' ? 'Too many sign-in emails were sent. Please use your app, or try again in an hour.' : "We couldn't send the sign-in email. Please use your app or try again."));
    if ($result !== 'sent') {
        $_SESSION['pending_2fa'] = $pending; // stay on the app screen
    }
    redirect('login-2fa');
}
if (($_GET['use'] ?? '') === 'app' && $method === 'email' && (int) $user['totp_enabled']) {
    db()->prepare("UPDATE login_challenges SET status = 'expired' WHERE id = ? AND status = 'pending'")->execute([$challenge['id']]);
    $_SESSION['pending_2fa']['method'] = 'app';
    unset($_SESSION['pending_2fa']['challenge']);
    redirect('login-2fa');
}

$useRecovery = $method === 'app' && ($_GET['method'] ?? $_POST['method'] ?? '') === 'recovery';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (($_POST['action'] ?? '') === 'resend' && $challenge) {
        if ($challenge['status'] !== 'pending') {
            $error = 'This sign-in was already used or stopped. Please log in again.';
        } elseif ((int) $challenge['since_sent'] < LOGIN_VERIFY_RESEND_SECONDS) {
            $error = 'Please wait ' . (LOGIN_VERIFY_RESEND_SECONDS - (int) $challenge['since_sent']) . ' seconds before asking for another email.';
        } elseif ((int) $challenge['sends'] >= LOGIN_VERIFY_MAX_SENDS) {
            $error = 'You asked for the email ' . LOGIN_VERIFY_MAX_SENDS . ' times already. Check your spam folder, or log in again.';
        } elseif (resend_login_verify($user, (int) $challenge['id'])) {
            $_SESSION['pending_2fa']['at'] = time();
            $_SESSION['pending_2fa']['tries'] = 0;
            flash('info', 'We sent a new sign-in email. Use the newest one.');
            redirect('login-2fa');
        } else {
            $error = "We couldn't send the email. Please try again in a minute.";
        }
    } else {
        $code = post_str('code', 20);
        if ($method === 'email') {
            if ($challenge['status'] === 'pending' && !(int) $challenge['expired'] && login_verify_allows($mode, 'code')
                && hash_equals($challenge['code_hash'], login_code_hash((int) $challenge['id'], $code))) {
                db()->prepare("UPDATE login_challenges SET status = 'used' WHERE id = ? AND status = 'pending'")->execute([$challenge['id']]);
                complete_login((int) $user['id'], (bool) $pending['remember'], 'Signed in with email code');
            }
            db()->prepare('UPDATE login_challenges SET tries = tries + 1 WHERE id = ?')->execute([$challenge['id']]);
        } elseif ($useRecovery) {
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
        log_activity('login.2fa_failed', $method === 'email' ? 'Wrong email sign-in code' : ($useRecovery ? 'Wrong recovery code' : 'Wrong two-step code'), (int) $user['id']);
        if ($pending['tries'] >= MAX_TRIES) {
            unset($_SESSION['pending_2fa']);
            if ($challenge) db()->prepare("UPDATE login_challenges SET status = 'expired' WHERE id = ?")->execute([$challenge['id']]);
            db()->prepare('INSERT INTO login_attempts (ip, username) VALUES (?, ?)')->execute([client_ip(), 'user#' . $user['id']]);
            auto_block_check(client_ip());
            flash('error', 'Too many wrong codes. Please log in again.');
            redirect('login');
        }
        $error = match (true) {
            $method === 'email' && (int) $challenge['expired'] => 'That code has expired. Send a new email below.',
            $method === 'email' => 'That code is not right. Use the code from the newest email.',
            $useRecovery => 'That recovery code is not valid or was already used.',
            default => 'That code is not right. Check your app and try the newest code.',
        };
    }
}

function mask_email(string $email): string
{
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($name, 0, 2) . str_repeat('•', max(1, min(6, mb_strlen($name) - 2))) . '@' . $domain;
}

$flashes = take_flashes();
$sentTo = [mask_email($user['email'])];
if (!empty($user['personal_email']) && !empty($user['personal_email_verified_at']) && strcasecmp($user['personal_email'], $user['email']) !== 0) {
    $sentTo[] = mask_email($user['personal_email']);
}
$askCode = $method === 'email' && login_verify_allows($mode, 'code');
$askLink = $method === 'email' && login_verify_allows($mode, 'link');
if ($method === 'email') {
    $title = 'Check your email';
    $icon = 'fa-envelope-open-text';
    $sub = 'We sent a sign-in email to <strong>' . h(implode('</strong> and <strong>', $sentTo)) . '</strong>. '
        . ($askCode && $askLink ? 'Enter the 6-digit code from it, or press <strong>"Yes, it\'s me"</strong> in the email.'
            : ($askCode ? 'Enter the 6-digit code from it.' : 'Press <strong>"Yes, it\'s me"</strong> in the email. You can open it on your phone; this page continues on its own.'));
} elseif ($useRecovery) {
    [$title, $icon, $sub] = ['Use a recovery code', 'fa-life-ring', 'Enter one of the recovery codes you saved when you set up two-step login.'];
} else {
    [$title, $icon, $sub] = ['Two-step login', 'fa-mobile-screen-button', 'Open your authenticator app and enter the 6-digit code for Judes CBO.'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($title) ?> | Judes CBO Admin</title>
    <link rel="icon" href="../favicon.ico" sizes="any">
    <link rel="preload" href="../fonts/raleway-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../fonts/montserrat-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../css/fonts.css">
    <link rel="stylesheet" href="assets/admin.css?v=<?= (int) @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body class="auth-page">
    <main class="auth-card">
        <span class="auth-icon"><i class="fas <?= $icon ?>"></i></span>
        <h1><?= h($title) ?></h1>
        <p class="auth-sub"><?= $method === 'email' ? $sub : h($sub) ?></p>
        <?php foreach ($flashes as $f): ?><div class="alert alert-<?= h($f['type']) ?>" role="status"><?= h($f['message']) ?></div><?php endforeach; ?>
        <?php if ($error): ?><div class="alert alert-error" role="alert"><?= h($error) ?></div><?php endif; ?>

        <?php if ($method !== 'email' || $askCode): ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="method" value="<?= $useRecovery ? 'recovery' : $method ?>">
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
        <?php endif; ?>

        <?php if ($askLink): ?>
            <p class="verify-wait" id="verify-wait" role="status"><span class="verify-dot" aria-hidden="true"></span> Waiting for you to approve from the email…</p>
            <script>
            (function () {
                var tries = 0;
                function check() {
                    if (++tries > 300) return; // stop after about 15 minutes
                    fetch('login-2fa?poll=1', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
                        if (d.status === 'approved' || d.status === 'denied') { location.href = 'login-2fa?finish=1'; return; }
                        if (d.status === 'gone' || d.status === 'used') { location.href = 'login'; return; }
                        if (d.status === 'expired') { document.getElementById('verify-wait').textContent = 'The email has expired. Send a new one below.'; return; }
                        setTimeout(check, 3000);
                    }).catch(function () { setTimeout(check, 6000); });
                }
                setTimeout(check, 3000);
            })();
            </script>
        <?php endif; ?>

        <?php if ($method === 'email'): ?>
            <form method="post" class="auth-foot"><?= csrf_field() ?><input type="hidden" name="action" value="resend">
                <button class="link-btn"><i class="fas fa-rotate"></i> Didn't get it? Send a new email</button></form>
            <p class="muted small" style="text-align:center">Check your spam folder too. The email works for <?= LOGIN_VERIFY_MINUTES ?> minutes.</p>
            <?php if ((int) $user['totp_enabled']): ?><p class="auth-foot"><a href="login-2fa?use=app"><i class="fas fa-mobile-screen-button"></i> Use my authenticator app instead</a></p><?php endif; ?>
        <?php else: ?>
            <p class="auth-foot">
                <?php if ($useRecovery): ?>
                    <a href="login-2fa"><i class="fas fa-mobile-screen-button"></i> Use the app code instead</a>
                <?php else: ?>
                    <a href="login-2fa?method=recovery"><i class="fas fa-life-ring"></i> Lost your phone? Use a recovery code</a>
                <?php endif; ?>
            </p>
            <?php if ($mode !== 'off'): ?><p class="auth-foot"><a href="login-2fa?use=email"><i class="fas fa-envelope-open-text"></i> Email me a sign-in code instead</a></p><?php endif; ?>
        <?php endif; ?>
        <p class="auth-foot"><a href="login"><i class="fas fa-arrow-left"></i> Back to login</a></p>
    </main>
</body>
</html>
