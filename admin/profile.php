<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/totp.php';
$me = require_login();
$required = in_array($me['role'], ['admin', 'developer'], true) && setting('require_2fa_admins', '1') === '1';

function load_2fa(int $id): array
{
    $stmt = db()->prepare('SELECT password_hash, totp_secret, totp_enabled, totp_last_step, recovery_codes FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'details') {
        $name = post_str('name', 120);
        $email = post_str('email', 190);
        $personal = strtolower(post_str('personal_email', 190));
        $dup = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
        $dup->execute([$email, $me['id']]);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter your name and a valid work email.');
        } elseif ($personal !== '' && !filter_var($personal, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid personal email, or leave it blank.');
        } elseif ($personal !== '' && $personal === strtolower($email)) {
            flash('error', 'Your personal email should be different from your work email.');
        } elseif ((int) $dup->fetchColumn() > 0) {
            flash('error', 'Another account already uses that email.');
        } else {
            db()->prepare('UPDATE users SET name = ?, email = ?, personal_email = ? WHERE id = ?')->execute([$name, $email, $personal !== '' ? $personal : null, $me['id']]);
            log_activity('profile.update', 'Updated name or email');
            flash('success', 'Your details were saved.');
        }
    } elseif ($action === '2fa-start') {
        $_SESSION['totp_setup'] = totp_new_secret();
        redirect('profile.php#two-step');
    } elseif ($action === '2fa-confirm' && !empty($_SESSION['totp_setup'])) {
        $secret = $_SESSION['totp_setup'];
        $step = totp_verify($secret, post_str('code', 10));
        if ($step === null) {
            flash('error', "That code didn't match. Make sure your phone's time is correct and enter the newest code.");
        } else {
            $codes = make_recovery_codes();
            db()->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_step = ?, recovery_codes = ? WHERE id = ?')
                ->execute([$secret, $step, hash_recovery_codes($codes), $me['id']]);
            unset($_SESSION['totp_setup']);
            $_SESSION['show_recovery'] = $codes;
            log_activity('profile.2fa_on', 'Turned on two-step login');
            flash('success', 'Two-step login is on. Save your recovery codes now.');
        }
        redirect('profile.php#two-step');
    } elseif ($action === '2fa-cancel') {
        unset($_SESSION['totp_setup']);
        redirect('profile.php#two-step');
    } elseif ($action === '2fa-codes' || $action === '2fa-off') {
        $row = load_2fa((int) $me['id']);
        $step = totp_verify((string) $row['totp_secret'], post_str('code', 10), (int) $row['totp_last_step']);
        if ($step === null) {
            flash('error', 'Enter a current code from your authenticator app to confirm.');
        } elseif ($action === '2fa-codes') {
            $codes = make_recovery_codes();
            db()->prepare('UPDATE users SET recovery_codes = ?, totp_last_step = ? WHERE id = ?')->execute([hash_recovery_codes($codes), $step, $me['id']]);
            $_SESSION['show_recovery'] = $codes;
            log_activity('profile.2fa_codes', 'Created new recovery codes');
            flash('success', 'New recovery codes created. The old ones no longer work.');
        } elseif ($required) {
            flash('error', "Two-step login is required for administrators, so it can't be turned off.");
        } elseif (!password_verify((string) ($_POST['password'] ?? ''), $row['password_hash'])) {
            flash('error', 'Your password is incorrect.');
        } else {
            db()->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0, recovery_codes = NULL WHERE id = ?')->execute([$me['id']]);
            log_activity('profile.2fa_off', 'Turned off two-step login');
            flash('success', 'Two-step login is off.');
        }
        redirect('profile.php#two-step');
    } elseif ($action === 'password') {
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$me['id']]);
        $hash = (string) $stmt->fetchColumn();
        $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify((string) ($_POST['current_password'] ?? ''), $hash)) {
            flash('error', 'Your current password is incorrect.');
        } elseif ($problem = password_problem($new, [$me['name'], $me['username'], $me['email']])) {
            flash('error', $problem);
        } elseif (password_verify($new, $hash)) {
            flash('error', 'The new password must be different from your current one.');
        } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
            flash('error', 'The new passwords do not match.');
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            revoke_all_tokens((int) $me['id']);
            forget_remember_token();
            session_regenerate_id(true);
            log_activity('profile.password', 'Changed password');
            flash('success', 'Password changed.');
        }
    }
    redirect('profile.php');
}

$setupSecret = $_SESSION['totp_setup'] ?? null;
$showCodes = $_SESSION['show_recovery'] ?? null;
unset($_SESSION['show_recovery']);
$twofa = load_2fa((int) $me['id']);
$codesLeft = count(json_decode((string) $twofa['recovery_codes'], true) ?: []);
$stmt = db()->prepare("SELECT action, details, ip, created_at FROM activity_log WHERE user_id = ? AND action IN ('login', 'login.2fa_failed', 'security.session_mismatch', 'password.reset', 'profile.password') ORDER BY created_at DESC LIMIT 8");
$stmt->execute([$me['id']]);
$mySignins = $stmt->fetchAll();

render_header('My profile');
?>
<section class="panel twofa-panel" id="two-step">
    <div class="twofa-head">
        <span class="stat-icon <?= (int) $twofa['totp_enabled'] ? 'icon-green' : 'icon-gold' ?>"><i class="fas fa-shield-halved"></i></span>
        <div>
            <h3>Two-step login <?= (int) $twofa['totp_enabled'] ? '<span class="pill pill-published">On</span>' : '<span class="pill pill-draft">Off</span>' ?></h3>
            <p class="muted">After your password, you'll also enter a 6-digit code from an authenticator app on your phone (Google Authenticator, Microsoft Authenticator or Authy).<?= $required ? ' <strong>Required for administrators.</strong>' : '' ?></p>
        </div>
    </div>

    <?php if ($showCodes): ?>
        <div class="recovery-box">
            <h4><i class="fas fa-life-ring"></i> Your recovery codes</h4>
            <p>If you lose your phone, each code lets you log in once. Store them somewhere safe, like a password manager or a printed copy. <strong>They won't be shown again.</strong></p>
            <ol class="recovery-codes"><?php foreach ($showCodes as $c): ?><li><code><?= h($c) ?></code></li><?php endforeach; ?></ol>
            <textarea id="recovery-text" hidden><?= h("Judes CBO dashboard recovery codes for {$me['username']}\n\n" . implode("\n", $showCodes)) ?></textarea>
            <div class="btn-row">
                <button type="button" class="btn btn-light btn-sm" data-copy-target="recovery-text"><i class="far fa-copy"></i> Copy</button>
                <button type="button" class="btn btn-light btn-sm" id="download-codes"><i class="fas fa-download"></i> Download</button>
                <button type="button" class="btn btn-light btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!(int) $twofa['totp_enabled'] && !$setupSecret): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="2fa-start">
            <button class="btn btn-primary"><i class="fas fa-qrcode"></i> Set up two-step login</button></form>
    <?php elseif ($setupSecret): ?>
        <div class="twofa-setup">
            <div class="twofa-step">
                <span class="step-num">1</span>
                <div>
                    <strong>Scan this QR code with your authenticator app</strong>
                    <div class="qr-box" id="qr" data-uri="<?= h(totp_uri($setupSecret, $me['username'])) ?>"></div>
                    <p class="muted small">Can't scan? Choose "Enter a setup key" in the app and type:</p>
                    <code class="secret-key"><?= h(trim(chunk_split($setupSecret, 4, ' '))) ?></code>
                </div>
            </div>
            <div class="twofa-step">
                <span class="step-num">2</span>
                <div>
                    <strong>Enter the 6-digit code the app shows</strong>
                    <form method="post" class="twofa-confirm">
                        <?= csrf_field() ?><input type="hidden" name="action" value="2fa-confirm">
                        <input type="text" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required placeholder="123 456" class="code-input">
                        <button class="btn btn-primary"><i class="fas fa-check"></i> Turn on</button>
                    </form>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="2fa-cancel"><button class="link small">Cancel</button></form>
                </div>
            </div>
        </div>
    <?php else: ?>
        <p class="small">Recovery codes left: <strong><?= $codesLeft ?></strong><?= $codesLeft <= 2 ? ' <span class="text-red">· create new ones soon</span>' : '' ?></p>
        <div class="twofa-manage">
            <form method="post" class="inline-code-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="2fa-codes">
                <input type="text" name="code" inputmode="numeric" maxlength="7" required placeholder="App code" class="code-input code-input-sm" aria-label="Current app code">
                <button class="btn btn-light btn-sm"><i class="fas fa-rotate"></i> New recovery codes</button>
            </form>
            <?php if (!$required): ?>
                <form method="post" class="inline-code-form" data-confirm="Turn off two-step login? Your account will be protected by your password only.">
                    <?= csrf_field() ?><input type="hidden" name="action" value="2fa-off">
                    <input type="text" name="code" inputmode="numeric" maxlength="7" required placeholder="App code" class="code-input code-input-sm" aria-label="Current app code">
                    <input type="password" name="password" required placeholder="Password" class="code-input-sm" aria-label="Your password" autocomplete="current-password">
                    <button class="btn btn-danger-ghost btn-sm"><i class="fas fa-power-off"></i> Turn off</button>
                </form>
            <?php endif; ?>
        </div>
        <p class="muted small">Lost your phone and your codes? Ask another administrator to reset two-step login for you from the Users page.</p>
    <?php endif; ?>
</section>

<div class="grid-2">
    <section class="panel">
        <h3 class="panel-title"><i class="fas fa-id-card"></i> Your details</h3>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="details">
            <label class="field">Full name<input type="text" name="name" value="<?= h($me['name']) ?>" required></label>
            <label class="field">Work email <small>Your @judescbo.co.ke address. Used to sign in and for password resets.</small><input type="email" name="email" value="<?= h($me['email']) ?>" required></label>
            <label class="field">Personal email <small>Optional, e.g. Gmail. You can forward your work email to it from Mailbox.</small><input type="email" name="personal_email" value="<?= h($me['personal_email'] ?? '') ?>" placeholder="name@gmail.com"></label>
            <label class="field">Username<input type="text" value="<?= h($me['username']) ?>" disabled></label>
            <label class="field">Role<input type="text" value="<?= h(role_label($me['role'])) ?>" disabled></label>
            <button class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save details</button>
        </form>
    </section>
    <section class="panel">
        <h3 class="panel-title"><i class="fas fa-key"></i> Change password</h3>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="password">
            <label class="field">Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
            <label class="field">New password <small>At least 10 characters. Three or four random words work well.</small><input type="password" name="new_password" minlength="10" required autocomplete="new-password"></label>
            <label class="field">Confirm new password<input type="password" name="confirm_password" minlength="10" required autocomplete="new-password"></label>
            <button class="btn btn-primary"><i class="fas fa-key"></i> Change password</button>
        </form>
    </section>
</div>

<section class="panel" id="signins">
    <h3 class="panel-title"><i class="fas fa-clock-rotate-left"></i> Recent activity on your account</h3>
    <?php if (!$mySignins): ?>
        <p class="empty">Nothing recorded yet.</p>
    <?php else: ?>
        <ul class="event-list">
            <?php foreach ($mySignins as $s): $bad = in_array($s['action'], ['login.2fa_failed', 'security.session_mismatch'], true); ?>
                <li>
                    <span class="stat-icon icon-sm <?= $bad ? 'icon-red' : 'icon-green' ?>"><i class="fas <?= $bad ? 'fa-triangle-exclamation' : ($s['action'] === 'login' ? 'fa-right-to-bracket' : 'fa-key') ?>"></i></span>
                    <span class="list-main"><strong><?= h($s['details'] ?: $s['action']) ?></strong><small><?= h(date('D j M Y, g:i a', strtotime($s['created_at']))) ?></small></span>
                    <span class="list-side"><code><?= h($s['ip']) ?></code><small><?= h(time_ago($s['created_at'])) ?></small></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="muted small">Don't recognise something? Change your password straight away and tell a System admin.</p>
    <?php endif; ?>
</section>
<?php render_footer(['https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js', 'assets/twofa.js']); ?>
