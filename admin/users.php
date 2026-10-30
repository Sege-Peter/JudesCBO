<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
$me = require_admin();

$errors = [];
$form = ['name' => '', 'email' => '', 'username' => '', 'role' => 'editor'];

function active_developers(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'developer' AND active = 1")->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $target = null;
    if ($id) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();
    }

    if ($action === 'create') {
        foreach ($form as $k => $_) $form[$k] = post_str($k, 190);
        $password = (string) ($_POST['password'] ?? '');
        if ($form['name'] === '') $errors[] = 'Enter a name.';
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $form['username'])) $errors[] = 'Username must be 3 to 60 letters, numbers, dots, dashes or underscores.';
        if ($problem = password_problem($password, [$form['name'], $form['username'], $form['email']])) $errors[] = $problem;
        if (!isset(assignable_roles($me)[$form['role']])) $form['role'] = 'editor';
        $dup = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR email = ?');
        $dup->execute([$form['username'], $form['email']]);
        if ((int) $dup->fetchColumn() > 0) $errors[] = 'That username or email is already in use.';
        if (!$errors) {
            db()->prepare('INSERT INTO users (name, email, username, password_hash, role) VALUES (?, ?, ?, ?, ?)')
                ->execute([$form['name'], $form['email'], $form['username'], password_hash($password, PASSWORD_DEFAULT), $form['role']]);
            log_activity('user.create', $form['username'] . ' (' . role_label($form['role']) . ')');
            flash('success', $form['name'] . ' can now log in. Share the password with them privately.');
            redirect('users.php');
        }
    } elseif ($target && !can_manage($me, $target)) {
        flash('error', 'Only a System admin can change a System admin\'s account.');
        redirect('users.php');
    } elseif ($target) {
        $lastDeveloper = $target['role'] === 'developer' && (int) $target['active'] === 1 && active_developers() <= 1;
        $isMe = (int) $target['id'] === (int) $me['id'];
        if ($action === 'role' && isset(assignable_roles($me)[$_POST['role'] ?? ''])) {
            $role = $_POST['role'];
            if ($lastDeveloper && $role !== 'developer') {
                flash('error', 'There must be at least one active System admin. Make someone else a System admin first.');
            } elseif ($role !== $target['role']) {
                db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
                // A change of role takes effect straight away: sign them out so they start fresh with the new permissions
                if (!$isMe) force_logout($id);
                log_activity('user.role', $target['username'] . ': ' . role_label($target['role']) . ' → ' . role_label($role));
                flash('success', $target['name'] . ' is now ' . (in_array($role, ['admin', 'editor'], true) ? 'an ' : 'a ') . role_label($role) . '.' . ($isMe ? '' : ' They\'ve been signed out so the change applies straight away.'));
            }
        } elseif ($action === 'logout') {
            if ($isMe) {
                flash('error', 'Use Log out in the top-right menu to sign yourself out.');
            } else {
                force_logout($id);
                log_activity('user.force_logout', $target['username']);
                flash('success', $target['name'] . ' has been signed out on every device.');
            }
        } elseif ($action === 'toggle') {
            if ($isMe) {
                flash('error', "You can't deactivate your own account.");
            } elseif ($lastDeveloper) {
                flash('error', 'There must be at least one active System admin.');
            } else {
                $new = (int) $target['active'] === 1 ? 0 : 1;
                db()->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$new, $id]);
                if (!$new) force_logout($id);
                log_activity('user.' . ($new ? 'activate' : 'deactivate'), $target['username']);
                flash('success', $target['name'] . ($new ? ' can log in again.' : ' can no longer log in.'));
            }
        } elseif ($action === 'reset-2fa') {
            db()->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0, recovery_codes = NULL WHERE id = ?')->execute([$id]);
            revoke_all_tokens($id);
            log_activity('user.reset_2fa', $target['username']);
            flash('success', 'Two-step login reset for ' . $target['name'] . '. They\'ll set it up again at their next login.');
        } elseif ($action === 'reset') {
            $password = (string) ($_POST['password'] ?? '');
            if ($problem = password_problem($password, [$target['name'], $target['username'], $target['email']])) {
                flash('error', $problem);
            } else {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
                revoke_all_tokens($id);
                log_activity('user.reset_password', $target['username']);
                flash('success', 'Password reset for ' . $target['name'] . '. Share it with them privately.');
            }
        }
        redirect('users.php');
    }
}

$users = db()->query("SELECT * FROM users ORDER BY active DESC, FIELD(role, 'developer', 'admin', 'editor'), name")->fetchAll();
$roleCounts = array_count_values(array_column(array_filter($users, fn($u) => (int) $u['active']), 'role'));
render_header('Users');
?>
<div class="grid-2 grid-2-wide">
    <section class="panel">
        <h3 class="panel-title"><i class="fas fa-users"></i> Team members</h3>
        <div class="user-cards">
            <?php foreach ($users as $u): ?>
                <article class="user-card <?= (int) $u['active'] ? '' : 'inactive' ?>">
                    <span class="avatar"><?= h(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span>
                    <div class="user-card-main">
                        <strong><?= h($u['name']) ?><?= (int) $u['id'] === (int) $me['id'] ? ' <small class="muted">(you)</small>' : '' ?></strong>
                        <small><?= h($u['username']) ?> · <?= h($u['email']) ?></small>
                        <small class="muted"><?php if ($u['last_seen_at'] && time() - strtotime($u['last_seen_at']) < 300): ?><span class="online-dot"></span> Active now<?php elseif ($u['last_seen_at']): ?>Last active <?= h(time_ago($u['last_seen_at'])) ?><?php else: ?><?= $u['last_login'] ? 'Last login ' . h(time_ago($u['last_login'])) : 'Never logged in' ?><?php endif; ?></small>
                    </div>
                    <div class="user-card-actions">
                        <span class="pill role-<?= h($u['role']) ?>"><?php if ($u['role'] === 'developer'): ?><i class="fas fa-code"></i>&nbsp;<?php endif; ?><?= h(role_label($u['role'])) ?></span>
                        <?php if (!(int) $u['active']): ?><span class="pill pill-closed">Deactivated</span><?php endif; ?>
                        <span class="pill <?= (int) $u['totp_enabled'] ? 'pill-published' : 'pill-draft' ?>" title="Two-step login"><i class="fas fa-shield-halved"></i>&nbsp;<?= (int) $u['totp_enabled'] ? '2-step on' : '2-step off' ?></span>
                        <?php if (can_manage($me, $u)): ?>
                        <details class="menu-details">
                            <summary class="icon-btn" aria-label="Manage <?= h($u['name']) ?>"><i class="fas fa-ellipsis"></i></summary>
                            <div class="menu-pop">
                                <form method="post" class="role-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="action" value="role">
                                    <label>Role<select name="role">
                                        <?php foreach (assignable_roles($me) as $value => $label): ?><option value="<?= h($value) ?>" <?= $u['role'] === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                                    </select></label>
                                    <button><i class="fas fa-user-shield"></i> Change role</button></form>
                                <?php if ((int) $u['id'] !== (int) $me['id']): ?>
                                    <form method="post" data-confirm="Sign <?= h($u['name']) ?> out on every device?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="action" value="logout">
                                        <button><i class="fas fa-right-from-bracket"></i> Log out everywhere</button></form>
                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="action" value="toggle">
                                        <button><i class="fas <?= (int) $u['active'] ? 'fa-user-slash' : 'fa-user-check' ?>"></i> <?= (int) $u['active'] ? 'Deactivate' : 'Reactivate' ?></button></form>
                                <?php endif; ?>
                                <?php if ((int) $u['totp_enabled'] && (int) $u['id'] !== (int) $me['id']): ?>
                                    <form method="post" data-confirm="Reset two-step login for <?= h($u['name']) ?>? Use this if they lost their phone and recovery codes."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="action" value="reset-2fa">
                                        <button><i class="fas fa-shield-halved"></i> Reset two-step login</button></form>
                                <?php endif; ?>
                                <form method="post" class="reset-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="action" value="reset">
                                    <label>New password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
                                    <button><i class="fas fa-key"></i> Reset password</button></form>
                            </div>
                        </details>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="role-help">
            <p><span class="pill role-developer"><i class="fas fa-code"></i>&nbsp;System admin</span> <?= (int) ($roleCounts['developer'] ?? 0) ?> · The developer. Everything administrators can do, plus the System page: maintenance mode, locking the dashboard, signing people out and system health. Only System admins can manage other System admins.</p>
            <p><span class="pill role-admin">Administrator</span> <?= (int) ($roleCounts['admin'] ?? 0) ?> · Runs the organisation's side: everything editors can do, plus users, settings, the activity log and deleting donation records.</p>
            <p><span class="pill role-editor">Editor</span> <?= (int) ($roleCounts['editor'] ?? 0) ?> · Manages content: stories, gallery, events, team, partners, messages, subscribers and donations.</p>
        </div>
    </section>

    <aside>
        <section class="panel">
            <h3 class="panel-title"><i class="fas fa-user-plus"></i> Add a team member</h3>
            <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= h($e) ?></div><?php endforeach; ?>
            <form method="post" class="form-stack">
                <?= csrf_field() ?><input type="hidden" name="action" value="create">
                <label class="field">Full name<input type="text" name="name" value="<?= h($form['name']) ?>" required></label>
                <label class="field">Email<input type="email" name="email" value="<?= h($form['email']) ?>" required></label>
                <label class="field">Username<input type="text" name="username" value="<?= h($form['username']) ?>" required autocomplete="off"></label>
                <label class="field">Password <small>At least 10 characters</small>
                    <span class="input-icon"><input type="password" name="password" id="new-pass" minlength="10" required autocomplete="new-password">
                    <button type="button" class="toggle-pass" data-target="new-pass" aria-label="Show password"><i class="fas fa-eye"></i></button></span>
                </label>
                <button type="button" class="link small" id="gen-pass"><i class="fas fa-wand-magic-sparkles"></i> Generate a strong password</button>
                <label class="field">Role<select name="role"><?php foreach (assignable_roles($me) as $value => $label): ?><option value="<?= h($value) ?>" <?= $form['role'] === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
                <button class="btn btn-primary"><i class="fas fa-plus"></i> Add member</button>
            </form>
        </section>
    </aside>
</div>
<?php render_footer(); ?>
