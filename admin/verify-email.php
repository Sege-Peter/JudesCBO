<?php
/** Confirms a personal email from the link we emailed. Works whether or not the person is logged in. */
require __DIR__ . '/includes/bootstrap.php';

$token = (string) ($_GET['token'] ?? '');
$ok = false;
$email = '';
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = db()->prepare('SELECT v.id, v.user_id, v.email FROM email_verifications v JOIN users u ON u.id = v.user_id
        WHERE v.token_hash = ? AND v.used_at IS NULL AND v.expires_at > NOW() AND u.active = 1');
    $stmt->execute([hash('sha256', $token)]);
    if ($row = $stmt->fetch()) {
        // Only confirm if it's still the personal email on the account
        $upd = db()->prepare('UPDATE users SET personal_email_verified_at = NOW() WHERE id = ? AND personal_email = ?');
        $upd->execute([$row['user_id'], $row['email']]);
        db()->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        if ($upd->rowCount() > 0) {
            $ok = true;
            $email = $row['email'];
            log_activity('profile.email_verified', 'Personal email confirmed: ' . $email, (int) $row['user_id']);
        }
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
    <title>Confirm email | Judes CBO Admin</title>
    <link rel="icon" href="../favicon.ico" sizes="any">
    <link rel="stylesheet" href="../css/fonts.css">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <?php if ($ok): ?>
            <span class="auth-icon"><i class="fas fa-circle-check"></i></span>
            <h1>Email confirmed</h1>
            <p class="auth-sub"><strong><?= h($email) ?></strong> is now confirmed. Password reset links will also be sent there.</p>
        <?php else: ?>
            <span class="auth-icon"><i class="fas fa-triangle-exclamation"></i></span>
            <h1>Link not valid</h1>
            <p class="auth-sub">This confirmation link has expired or was already used. Open <strong>My profile</strong> in the dashboard and choose <strong>Resend link</strong>.</p>
        <?php endif; ?>
        <a href="<?= current_user() ? 'profile.php' : 'login.php' ?>" class="btn btn-primary btn-block"><?= current_user() ? 'Go to my profile' : 'Go to login' ?></a>
    </main>
</body>
</html>
