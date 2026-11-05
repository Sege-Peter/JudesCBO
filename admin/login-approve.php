<?php
/*
 * Opened from the "Yes, it's me" button in a sign-in email.
 * Opening the link changes nothing (email scanners open links too); the person must press a button.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/login-verify.php';

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$c = find_login_challenge_by_token($token);
$state = 'ask';
if (!$c || (int) $c['expired'] && $c['status'] === 'pending' || $c['status'] === 'expired') {
    $state = 'expired';
} elseif ($c['status'] === 'used' || $c['status'] === 'approved') {
    $state = 'done';
} elseif ($c['status'] === 'denied') {
    $state = 'denied';
}

if ($state === 'ask' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['answer'] ?? '') === 'yes') {
        db()->prepare("UPDATE login_challenges SET status = 'approved' WHERE id = ? AND status = 'pending'")->execute([$c['id']]);
        log_activity('login.approved', 'Sign-in from ' . $c['ip'] . ' approved from the email', (int) $c['user_id']);
        // Same browser that is signing in: finish straight away
        $p = $_SESSION['pending_2fa'] ?? null;
        if ($p && ($p['challenge'] ?? 0) === (int) $c['id']) redirect('login-2fa?finish=1');
        $state = 'approved';
    } else {
        db()->prepare("UPDATE login_challenges SET status = 'denied' WHERE id = ? AND status = 'pending'")->execute([$c['id']]);
        log_activity('login.denied', '"This wasn\'t me": sign-in from ' . $c['ip'] . ' (' . short_agent($c['user_agent']) . ') stopped. Someone knows this password.', (int) $c['user_id']);
        $state = 'stopped';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Approve sign-in | Judes CBO Admin</title>
    <link rel="icon" href="../favicon.ico" sizes="any">
    <link rel="preload" href="../fonts/raleway-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../fonts/montserrat-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../css/fonts.css">
    <link rel="stylesheet" href="assets/admin.css?v=<?= (int) @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body class="auth-page">
    <main class="auth-card">
        <?php if ($state === 'ask'): ?>
            <span class="auth-icon"><i class="fas fa-shield-halved"></i></span>
            <h1>Is this you signing in?</h1>
            <p class="auth-sub">Someone entered the right password for <strong><?= h($c['name']) ?></strong>.</p>
            <dl class="signin-facts">
                <div><dt>When</dt><dd><?= h(date('j M Y, g:i a', strtotime($c['created_at']))) ?></dd></div>
                <div><dt>Device</dt><dd><?= h(short_agent($c['user_agent'])) ?></dd></div>
                <div><dt>IP address</dt><dd><code><?= h($c['ip']) ?></code><?= $c['ip'] === client_ip() ? ' <small class="muted">(this device)</small>' : '' ?></dd></div>
            </dl>
            <form method="post" class="form-stack approve-actions">
                <?= csrf_field() ?><input type="hidden" name="t" value="<?= h($token) ?>">
                <button name="answer" value="yes" class="btn btn-primary btn-block"><i class="fas fa-circle-check"></i> Yes, it's me: sign in</button>
                <button name="answer" value="no" class="btn btn-danger-ghost btn-block"><i class="fas fa-ban"></i> This wasn't me</button>
            </form>
        <?php elseif ($state === 'approved'): ?>
            <span class="auth-icon"><i class="fas fa-circle-check"></i></span>
            <h1>Sign-in approved</h1>
            <p class="auth-sub">Go back to the window where you were signing in. It will continue on its own in a few seconds.</p>
        <?php elseif ($state === 'stopped'): ?>
            <span class="auth-icon icon-red"><i class="fas fa-ban"></i></span>
            <h1>Sign-in stopped</h1>
            <p class="auth-sub">That sign-in was blocked. Because it used your correct password, <strong>change your password now</strong> under My profile, and tell an administrator. They can block the address on the Activity log.</p>
            <p class="auth-foot"><a href="login"><i class="fas fa-arrow-right"></i> Log in and change my password</a></p>
        <?php elseif ($state === 'done'): ?>
            <span class="auth-icon"><i class="fas fa-circle-check"></i></span>
            <h1>Already approved</h1>
            <p class="auth-sub">This sign-in was already confirmed. You can close this page.</p>
        <?php elseif ($state === 'denied'): ?>
            <span class="auth-icon icon-red"><i class="fas fa-ban"></i></span>
            <h1>Sign-in was stopped</h1>
            <p class="auth-sub">This sign-in was already stopped with "This wasn't me".</p>
        <?php else: ?>
            <span class="auth-icon"><i class="fas fa-clock-rotate-left"></i></span>
            <h1>This link has expired</h1>
            <p class="auth-sub">Sign-in emails work for <?= LOGIN_VERIFY_MINUTES ?> minutes and only once. Log in again to get a new one.</p>
            <p class="auth-foot"><a href="login"><i class="fas fa-arrow-right"></i> Go to login</a></p>
        <?php endif; ?>
    </main>
</body>
</html>
