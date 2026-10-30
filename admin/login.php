<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

const MAX_ATTEMPTS = 5;
const LOCK_MINUTES = 15;

$error = '';
$username = '';
$remember = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = post_str('username', 60);
    $password = (string) ($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);
    $ip = client_ip();

    $stmt = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE (ip = ? OR username = ?) AND attempted_at > (NOW() - INTERVAL ' . LOCK_MINUTES . ' MINUTE)');
    $stmt->execute([$ip, $username]);
    if ((int) $stmt->fetchColumn() >= MAX_ATTEMPTS) {
        $error = 'Too many failed attempts. Please wait ' . LOCK_MINUTES . ' minutes and try again.';
    } else {
        $stmt = db()->prepare('SELECT id, password_hash, active, totp_enabled FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        if ($user && (int) $user['active'] === 1 && password_verify($password, $user['password_hash'])) {
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            db()->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$username]);
            if ((int) $user['totp_enabled'] === 1) {
                // Password is right; now ask for the code from the authenticator app
                session_regenerate_id(true);
                $_SESSION['pending_2fa'] = ['user_id' => (int) $user['id'], 'remember' => $remember, 'at' => time(), 'tries' => 0];
                redirect('login-2fa.php');
            }
            complete_login((int) $user['id'], $remember, 'Signed in');
        }
        db()->prepare('INSERT INTO login_attempts (ip, username) VALUES (?, ?)')->execute([$ip, $username]);
        $error = 'Incorrect username or password.';
    }
}
$flashes = take_flashes();
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700&family=Raleway:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <img src="../images/icons/icon-192.png" alt="Judes CBO" class="auth-logo">
        <h1>Staff login</h1>
        <p class="auth-sub">Judes CBO website dashboard</p>
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
                <a href="forgot-password.php" class="auth-link">Forgot password?</a>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Log in <i class="fas fa-arrow-right"></i></button>
        </form>
        <p class="auth-foot"><a href="../index.html"><i class="fas fa-arrow-left"></i> Back to website</a></p>
    </main>
    <script src="assets/admin.js"></script>
</body>
</html>
