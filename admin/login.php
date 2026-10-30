<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

const MAX_ATTEMPTS = 5;
const LOCK_MINUTES = 15;

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = post_str('username', 60);
    $password = (string) ($_POST['password'] ?? '');
    $ip = client_ip();

    $stmt = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE (ip = ? OR username = ?) AND attempted_at > (NOW() - INTERVAL ' . LOCK_MINUTES . ' MINUTE)');
    $stmt->execute([$ip, $username]);
    if ((int) $stmt->fetchColumn() >= MAX_ATTEMPTS) {
        $error = 'Too many failed attempts. Please wait ' . LOCK_MINUTES . ' minutes and try again.';
    } else {
        $stmt = db()->prepare('SELECT id, password_hash, active FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        if ($user && (int) $user['active'] === 1 && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['last_seen'] = time();
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
            db()->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$username]);
            log_activity('login', 'Signed in', (int) $user['id']);
            redirect('index.php');
        }
        db()->prepare('INSERT INTO login_attempts (ip, username) VALUES (?, ?)')->execute([$ip, $username]);
        $error = 'Incorrect username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Log in | Judes CBO Admin</title>
    <link rel="icon" href="../jude-logo.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700&family=Raleway:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <img src="../jude-logo.png" alt="Judes CBO" class="auth-logo">
        <h1>Welcome back</h1>
        <p class="auth-sub">Log in to manage the Judes CBO website.</p>
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
            <button type="submit" class="btn btn-primary btn-block">Log in <i class="fas fa-arrow-right"></i></button>
        </form>
        <p class="auth-foot"><a href="../index.html"><i class="fas fa-arrow-left"></i> Back to website</a></p>
    </main>
    <script src="assets/admin.js"></script>
</body>
</html>
