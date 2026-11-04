<?php
require __DIR__ . '/includes/bootstrap.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$reset = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = db()->prepare('SELECT r.id, r.user_id, u.name, u.username, u.email FROM password_resets r JOIN users u ON u.id = r.user_id WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW() AND u.active = 1');
    $stmt->execute([hash('sha256', $token)]);
    $reset = $stmt->fetch() ?: null;
}

$error = '';
if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $password = (string) ($_POST['password'] ?? '');
    if ($problem = password_problem($password, [$reset['name'], $reset['username'], $reset['email']])) {
        $error = $problem;
    } elseif ($password !== ($_POST['confirm'] ?? '')) {
        $error = 'The two passwords do not match.';
    } else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
        db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$reset['id']]);
        revoke_all_tokens((int) $reset['user_id']);
        log_activity('password.reset', 'Password reset by email link', (int) $reset['user_id']);
        flash('success', 'Your password has been changed. You can log in now.');
        redirect('login.php');
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
    <title>Choose a new password | Judes CBO Admin</title>
    <link rel="icon" href="../favicon.ico" sizes="any">
    <link rel="preload" href="../fonts/raleway-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../fonts/montserrat-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../css/fonts.css">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <span class="auth-icon"><i class="fas fa-lock-open"></i></span>
        <?php if (!$reset): ?>
            <h1>Link expired</h1>
            <p class="auth-sub">This reset link is invalid, already used, or older than an hour.</p>
            <a href="forgot-password.php" class="btn btn-primary btn-block">Request a new link</a>
            <p class="auth-foot"><a href="login.php"><i class="fas fa-arrow-left"></i> Back to login</a></p>
        <?php else: ?>
            <h1>New password</h1>
            <p class="auth-sub">Hi <?= h(explode(' ', $reset['name'])[0]) ?>, choose a new password for your account.</p>
            <?php if ($error): ?><div class="alert alert-error" role="alert"><?= h($error) ?></div><?php endif; ?>
            <form method="post" class="form-stack">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <label>New password <small>At least 10 characters</small>
                    <span class="input-icon"><i class="fas fa-lock"></i><input type="password" name="password" id="new-password" minlength="10" required autocomplete="new-password" autofocus>
                    <button type="button" class="toggle-pass" data-target="new-password" aria-label="Show password"><i class="fas fa-eye"></i></button></span>
                </label>
                <label>Confirm new password
                    <span class="input-icon"><i class="fas fa-lock"></i><input type="password" name="confirm" minlength="10" required autocomplete="new-password"></span>
                </label>
                <button type="submit" class="btn btn-primary btn-block">Save new password</button>
            </form>
        <?php endif; ?>
    </main>
    <script src="assets/admin.js"></script>
</body>
</html>
