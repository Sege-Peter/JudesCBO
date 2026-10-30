<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'details') {
        $name = post_str('name', 120);
        $email = post_str('email', 190);
        $dup = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
        $dup->execute([$email, $me['id']]);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter your name and a valid email.');
        } elseif ((int) $dup->fetchColumn() > 0) {
            flash('error', 'Another account already uses that email.');
        } else {
            db()->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $me['id']]);
            log_activity('profile.update', 'Updated name or email');
            flash('success', 'Your details were saved.');
        }
    } elseif ($action === 'password') {
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$me['id']]);
        $hash = (string) $stmt->fetchColumn();
        $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify((string) ($_POST['current_password'] ?? ''), $hash)) {
            flash('error', 'Your current password is incorrect.');
        } elseif (strlen($new) < 10) {
            flash('error', 'The new password must be at least 10 characters.');
        } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
            flash('error', 'The new passwords do not match.');
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            session_regenerate_id(true);
            log_activity('profile.password', 'Changed password');
            flash('success', 'Password changed.');
        }
    }
    redirect('profile.php');
}

render_header('My profile');
?>
<div class="grid-2">
    <section class="panel">
        <h3 class="panel-title"><i class="fas fa-id-card"></i> Your details</h3>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="details">
            <label class="field">Full name<input type="text" name="name" value="<?= h($me['name']) ?>" required></label>
            <label class="field">Email<input type="email" name="email" value="<?= h($me['email']) ?>" required></label>
            <label class="field">Username<input type="text" value="<?= h($me['username']) ?>" disabled></label>
            <label class="field">Role<input type="text" value="<?= h(ucfirst($me['role'])) ?>" disabled></label>
            <button class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save details</button>
        </form>
    </section>
    <section class="panel">
        <h3 class="panel-title"><i class="fas fa-key"></i> Change password</h3>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="password">
            <label class="field">Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
            <label class="field">New password <small>At least 10 characters</small><input type="password" name="new_password" minlength="10" required autocomplete="new-password"></label>
            <label class="field">Confirm new password<input type="password" name="confirm_password" minlength="10" required autocomplete="new-password"></label>
            <button class="btn btn-primary"><i class="fas fa-key"></i> Change password</button>
        </form>
    </section>
</div>
<?php render_footer(); ?>
