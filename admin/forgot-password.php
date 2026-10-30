<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

const RESET_MINUTES = 60;
$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $login = post_str('login', 190);
    $ip = client_ip();

    // At most 5 reset requests per hour from one address
    $stmt = db()->prepare("SELECT COUNT(*) FROM api_hits WHERE ip = ? AND endpoint = 'reset' AND hit_at > NOW() - INTERVAL 1 HOUR");
    $stmt->execute([$ip]);
    if ((int) $stmt->fetchColumn() >= 5) {
        $error = 'Too many requests. Please try again in an hour.';
    } else {
        db()->prepare("INSERT INTO api_hits (ip, endpoint) VALUES (?, 'reset')")->execute([$ip]);
        $stmt = db()->prepare('SELECT id, name, email FROM users WHERE (username = ? OR email = ?) AND active = 1');
        $stmt->execute([$login, $login]);
        if ($user = $stmt->fetch()) {
            $token = bin2hex(random_bytes(32));
            db()->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')->execute([$user['id']]);
            db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL ' . RESET_MINUTES . ' MINUTE)')
                ->execute([$user['id'], hash('sha256', $token)]);

            // Prefer the configured address so a forged Host header can't redirect the reset link
            $base = (string) config('admin_url');
            if ($base === '') {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $base = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/';
            }
            $link = rtrim($base, '/') . '/reset-password.php?token=' . $token;
            $body = "Hello {$user['name']},\n\nSomeone (hopefully you) asked to reset the password for your Judes CBO dashboard account.\n\n"
                . "Choose a new password here (the link works for " . RESET_MINUTES . " minutes):\n$link\n\n"
                . "If you didn't ask for this, you can ignore this email. Your password won't change.\n\nJudes CBO";
            $mailed = send_mail($user['email'], 'Reset your Judes CBO dashboard password', $body);
            log_activity('password.reset_request', $mailed ? 'Reset email sent' : 'Reset requested but the email could not be sent', (int) $user['id']);

            // A local XAMPP install usually can't send email: keep the link in a private file for testing
            if (!$mailed && in_array(strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]), ['localhost', '127.0.0.1'], true)) {
                file_put_contents(__DIR__ . '/password-reset.local.log', date('c') . "  {$user['email']}  $link\n", FILE_APPEND);
            }
        }
        // Same answer whether or not the account exists
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Forgot password | Judes CBO Admin</title>
    <link rel="icon" href="../favicon.ico" sizes="any">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700&family=Raleway:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <span class="auth-icon"><i class="fas fa-key"></i></span>
        <?php if ($sent): ?>
            <h1>Check your email</h1>
            <p class="auth-sub">If that account exists, we've sent a link to reset the password. It works for <?= RESET_MINUTES ?> minutes.</p>
            <p class="auth-note">Didn't get it? Check your spam folder, or ask an administrator to reset your password from the Users page.</p>
            <a href="login.php" class="btn btn-primary btn-block"><i class="fas fa-arrow-left"></i> Back to login</a>
        <?php else: ?>
            <h1>Forgot password?</h1>
            <p class="auth-sub">Enter your username or email and we'll send you a reset link.</p>
            <?php if ($error): ?><div class="alert alert-error" role="alert"><?= h($error) ?></div><?php endif; ?>
            <form method="post" class="form-stack">
                <?= csrf_field() ?>
                <label>Username or email
                    <span class="input-icon"><i class="fas fa-envelope"></i><input type="text" name="login" required autocomplete="username" autofocus></span>
                </label>
                <button type="submit" class="btn btn-primary btn-block">Send reset link</button>
            </form>
            <p class="auth-foot"><a href="login.php"><i class="fas fa-arrow-left"></i> Back to login</a></p>
        <?php endif; ?>
    </main>
</body>
</html>
