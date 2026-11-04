<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
$me = require_developer();
$myIp = client_ip();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'block') {
        $ip = trim((string) ($_POST['ip'] ?? ''));
        $hours = ['1' => 24, '7' => 168, '30' => 720, '0' => null][$_POST['days'] ?? '7'] ?? 168;
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            flash('error', 'Enter a valid IP address, e.g. 41.90.12.34.');
        } elseif ($ip === $myIp) {
            flash('error', "That's your own address. Blocking it would lock you out of the dashboard.");
        } else {
            block_ip($ip, post_str('reason', 200) ?: 'Blocked by ' . $me['name'], $hours);
            log_activity('security.block', $ip . ($hours ? ' for ' . ($hours / 24) . ' day' . ($hours > 24 ? 's' : '') : ' permanently'));
            flash('success', "$ip is blocked" . ($hours ? ' for ' . ($hours / 24) . ' day' . ($hours > 24 ? 's' : '') : '') . '. It can no longer reach the dashboard or send website forms.');
        }
    } elseif ($action === 'unblock') {
        $ip = (string) ($_POST['ip'] ?? '');
        db()->prepare('DELETE FROM blocked_ips WHERE ip = ?')->execute([$ip]);
        db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
        log_activity('security.unblock', $ip);
        flash('success', "$ip is unblocked.");
    } elseif ($action === 'autoblock') {
        save_setting('sec_autoblock', !empty($_POST['sec_autoblock']) ? '1' : '0');
        $limit = (int) ($_POST['sec_autoblock_limit'] ?? 30);
        save_setting('sec_autoblock_limit', (string) (in_array($limit, [10, 20, 30, 50], true) ? $limit : 30));
        log_activity('security.autoblock', setting('sec_autoblock') === '1' ? 'On at ' . setting('sec_autoblock_limit') . ' failed logins' : 'Off');
        flash('success', 'Automatic blocking saved.');
    } elseif ($action === 'idle') {
        $m = (int) ($_POST['sec_idle_minutes'] ?? 30);
        save_setting('sec_idle_minutes', (string) (in_array($m, IDLE_CHOICES, true) ? $m : 30));
        log_activity('security.idle', 'Sign out after ' . setting('sec_idle_minutes') . ' minutes idle');
        flash('success', 'Session timeout saved.');
    } elseif ($action === 'https') {
        $on = !empty($_POST['sec_force_https']);
        if ($on && !is_https()) {
            flash('error', 'Open the dashboard with https:// first, to be sure the SSL certificate works before every visitor is sent there.');
        } else {
            save_setting('sec_force_https', $on ? '1' : '0');
            if (write_htaccess_https()) {
                log_activity('security.https', $on ? 'Force HTTPS on' : 'Force HTTPS off');
                flash('success', $on ? 'HTTPS is now enforced across the website.' : 'HTTPS is no longer enforced.');
            } else {
                flash('error', "Saved, but the website's .htaccess file couldn't be updated. This needs Apache (cPanel) hosting with a writable .htaccess.");
            }
        }
    } elseif ($action === 'clear-lockouts') {
        $n = db()->exec('DELETE FROM login_attempts');
        log_activity('security.clear_lockouts', "$n failed login records cleared");
        flash('success', 'Failed login records cleared. Anyone who was locked out can try again now.');
    }
    redirect('security');
}

db()->exec('DELETE FROM blocked_ips WHERE expires_at IS NOT NULL AND expires_at < NOW()');
$checks = security_checks();
$passed = count(array_filter($checks, fn($c) => $c[0] === 'ok'));
$score = (int) round($passed / max(1, count($checks)) * 100);
$problems = count(array_filter($checks, fn($c) => $c[0] === 'bad'));

$blocked = db()->query('SELECT * FROM blocked_ips ORDER BY created_at DESC')->fetchAll();
$blockedIps = array_column($blocked, 'ip');
$failed = db()->query("SELECT ip, COUNT(*) AS n, GROUP_CONCAT(DISTINCT username ORDER BY username SEPARATOR ', ') AS names, MAX(attempted_at) AS last_at
    FROM login_attempts WHERE attempted_at > NOW() - INTERVAL 1 DAY GROUP BY ip ORDER BY n DESC, last_at DESC LIMIT 20")->fetchAll();
$failedTotal = array_sum(array_column($failed, 'n'));
$signins = db()->query("SELECT a.action, a.details, a.ip, a.created_at, u.name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id
    WHERE a.action IN ('login', 'login.2fa_failed', 'password.reset') OR a.action LIKE 'security.%' ORDER BY a.created_at DESC LIMIT 15")->fetchAll();
$admins = db()->query("SELECT COUNT(*) AS total, SUM(totp_enabled) AS with2fa FROM users WHERE active = 1 AND role IN ('admin','developer')")->fetch();

render_header('Security');
?>
<section class="sec-hero <?= $problems ? 'sec-hero-bad' : ($score < 100 ? 'sec-hero-warn' : 'sec-hero-ok') ?>">
    <div class="sec-ring" style="--p: <?= $score ?>" role="img" aria-label="<?= $passed ?> of <?= count($checks) ?> checks passed"><strong><?= $score ?>%</strong></div>
    <div>
        <h2><?= $problems ? $problems . ' thing' . ($problems === 1 ? ' needs' : 's need') . ' attention' : ($score < 100 ? 'Good, with a few suggestions' : 'Everything looks secure') ?></h2>
        <p><?= $passed ?> of <?= count($checks) ?> security checks passed. Work through anything marked below.</p>
    </div>
</section>

<section class="stat-grid stat-grid-3">
    <div class="stat-card"><span class="stat-icon icon-red"><i class="fas fa-user-lock"></i></span>
        <div class="stat-body"><strong><?= $failedTotal ?></strong><small>Failed logins, last 24 hours</small></div></div>
    <div class="stat-card"><span class="stat-icon icon-purple"><i class="fas fa-ban"></i></span>
        <div class="stat-body"><strong><?= count($blocked) ?></strong><small>Blocked addresses</small></div></div>
    <div class="stat-card"><span class="stat-icon icon-green"><i class="fas fa-mobile-screen"></i></span>
        <div class="stat-body"><strong><?= (int) $admins['with2fa'] ?> / <?= (int) $admins['total'] ?></strong><small>Administrators with two-step login</small></div></div>
</section>

<div class="settings-grid">
    <section class="panel span-2" id="checks">
        <h3 class="panel-title"><i class="fas fa-list-check"></i> Security checklist</h3>
        <ul class="check-list">
            <?php foreach ($checks as [$status, $title, $detail]): ?>
                <li class="check-<?= $status ?>">
                    <i class="fas <?= ['ok' => 'fa-circle-check', 'warn' => 'fa-circle-exclamation', 'bad' => 'fa-circle-xmark'][$status] ?>" aria-label="<?= ['ok' => 'Passed', 'warn' => 'Suggestion', 'bad' => 'Problem'][$status] ?>"></i>
                    <span><strong><?= h($title) ?></strong><small><?= h($detail) ?></small></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="panel" id="https">
        <h3 class="panel-title"><i class="fas fa-lock"></i> Force HTTPS</h3>
        <p class="muted">Sends every visitor to the secure https:// address and tells browsers to never use plain http:// again. Turn on once the SSL certificate is installed.</p>
        <?php if (!is_https() && setting('sec_force_https') !== '1'): ?>
            <div class="alert alert-info"><i class="fas fa-circle-info"></i><span>You're using the dashboard over http://. Open it with https:// to switch this on.</span></div>
        <?php endif; ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="https">
            <label class="check"><input type="checkbox" name="sec_force_https" value="1" <?= setting('sec_force_https') === '1' ? 'checked' : '' ?> <?= !is_https() && setting('sec_force_https') !== '1' ? 'disabled' : '' ?>> Always use HTTPS</label>
            <button class="btn btn-primary" <?= !is_https() && setting('sec_force_https') !== '1' ? 'disabled' : '' ?>><i class="fas fa-floppy-disk"></i> Save</button>
        </form>
    </section>

    <section class="panel" id="idle">
        <h3 class="panel-title"><i class="fas fa-clock"></i> Session timeout</h3>
        <p class="muted">Signs staff out when the dashboard is left open with no activity, so an unattended computer can't be used to get in. Just before, they're asked if they're still there.</p>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="idle">
            <label class="field">Sign out after
                <select name="sec_idle_minutes">
                    <?php foreach (IDLE_CHOICES as $m): ?><option value="<?= $m ?>" <?= session_idle_limit() === $m * 60 ? 'selected' : '' ?>><?= $m < 60 ? "$m minutes" : ($m / 60) . ' hour' . ($m > 60 ? 's' : '') ?> of inactivity</option><?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save</button>
        </form>
    </section>

    <section class="panel" id="autoblock">
        <h3 class="panel-title"><i class="fas fa-robot"></i> Automatic blocking</h3>
        <p class="muted">Anyone who gets 5 passwords wrong waits 15 minutes. Addresses that keep guessing are blocked for 24 hours.</p>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="autoblock">
            <label class="check"><input type="checkbox" name="sec_autoblock" value="1" <?= setting('sec_autoblock', '1') === '1' ? 'checked' : '' ?>> Block addresses that keep guessing passwords</label>
            <label class="field">Block after
                <select name="sec_autoblock_limit">
                    <?php foreach ([10, 20, 30, 50] as $n): ?><option value="<?= $n ?>" <?= (int) setting('sec_autoblock_limit', '30') === $n ? 'selected' : '' ?>><?= $n ?> failed logins in 24 hours</option><?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save</button>
        </form>
    </section>

    <section class="panel span-2" id="blocked">
        <h3 class="panel-title"><i class="fas fa-ban"></i> Blocked addresses</h3>
        <p class="muted">A blocked IP address can't open the dashboard or send the website's contact and newsletter forms. The public pages stay visible.</p>
        <form method="post" class="block-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="block">
            <label class="field">IP address<input type="text" name="ip" required maxlength="45" placeholder="e.g. 41.90.12.34" inputmode="decimal"></label>
            <label class="field">Reason<input type="text" name="reason" maxlength="200" placeholder="Optional, e.g. spam messages"></label>
            <label class="field">For
                <select name="days"><option value="1">1 day</option><option value="7" selected>7 days</option><option value="30">30 days</option><option value="0">Until unblocked</option></select>
            </label>
            <button class="btn btn-primary"><i class="fas fa-ban"></i> Block</button>
        </form>
        <?php if (!$blocked): ?>
            <p class="empty"><i class="fas fa-circle-check"></i> No addresses are blocked.</p>
        <?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>IP address</th><th>Reason</th><th>Since</th><th>Until</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($blocked as $b): ?>
                    <tr>
                        <td data-label="IP address"><code><?= h($b['ip']) ?></code><?= (int) $b['auto'] ? ' <span class="pill pill-new">Automatic</span>' : '' ?></td>
                        <td data-label="Reason"><?= h($b['reason']) ?></td>
                        <td data-label="Since"><?= h(time_ago($b['created_at'])) ?></td>
                        <td data-label="Until"><?= $b['expires_at'] ? h(date('j M, g:i a', strtotime($b['expires_at']))) : 'Until unblocked' ?></td>
                        <td class="cell-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="unblock"><input type="hidden" name="ip" value="<?= h($b['ip']) ?>">
                            <button class="btn btn-light btn-sm"><i class="fas fa-unlock"></i> Unblock</button></form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="panel span-2" id="failed">
        <div class="panel-head-row">
            <h3 class="panel-title"><i class="fas fa-user-lock"></i> Failed logins, last 24 hours</h3>
            <?php if ($failed): ?>
                <form method="post" data-confirm="Clear all failed login records? Anyone locked out can try again straight away."><?= csrf_field() ?><input type="hidden" name="action" value="clear-lockouts">
                    <button class="btn btn-light btn-sm"><i class="fas fa-broom"></i> Clear lockouts</button></form>
            <?php endif; ?>
        </div>
        <?php if (!$failed): ?>
            <p class="empty"><i class="fas fa-circle-check"></i> No failed logins in the last 24 hours.</p>
        <?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>IP address</th><th>Attempts</th><th>Usernames tried</th><th>Last try</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($failed as $f): ?>
                    <tr>
                        <td data-label="IP address"><code><?= h($f['ip']) ?></code><?= $f['ip'] === $myIp ? ' <small class="muted">(you)</small>' : '' ?></td>
                        <td data-label="Attempts"><strong><?= (int) $f['n'] ?></strong></td>
                        <td data-label="Usernames tried"><?= h(mb_strimwidth((string) $f['names'], 0, 80, '…')) ?></td>
                        <td data-label="Last try"><?= h(time_ago($f['last_at'])) ?></td>
                        <td class="cell-actions">
                            <?php if (in_array($f['ip'], $blockedIps, true)): ?>
                                <span class="pill pill-closed">Blocked</span>
                            <?php elseif ($f['ip'] !== $myIp): ?>
                                <form method="post" data-confirm="Block <?= h($f['ip']) ?> for 7 days?"><?= csrf_field() ?><input type="hidden" name="action" value="block"><input type="hidden" name="ip" value="<?= h($f['ip']) ?>"><input type="hidden" name="days" value="7"><input type="hidden" name="reason" value="<?= (int) $f['n'] ?> failed logins">
                                    <button class="btn btn-danger-ghost btn-sm"><i class="fas fa-ban"></i> Block</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="panel span-2" id="events">
        <h3 class="panel-title"><i class="fas fa-clock-rotate-left"></i> Recent sign-ins and security events</h3>
        <?php if (!$signins): ?>
            <p class="empty">Nothing recorded yet.</p>
        <?php else: ?>
            <ul class="event-list">
                <?php foreach ($signins as $s):
                    [$icon, $tone] = match (true) {
                        $s['action'] === 'login' => ['fa-right-to-bracket', 'icon-green'],
                        $s['action'] === 'login.2fa_failed', $s['action'] === 'security.session_mismatch', $s['action'] === 'security.auto_block' => ['fa-triangle-exclamation', 'icon-red'],
                        default => ['fa-shield-halved', 'icon-blue'],
                    }; ?>
                    <li>
                        <span class="stat-icon icon-sm <?= $tone ?>"><i class="fas <?= $icon ?>"></i></span>
                        <span class="list-main"><strong><?= h($s['name'] ?: 'System') ?></strong><small><?= h($s['details'] ?: $s['action']) ?></small></span>
                        <span class="list-side"><code><?= h($s['ip']) ?></code><small><?= h(time_ago($s['created_at'])) ?></small></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="muted small"><a href="activity">Full activity log</a></p>
        <?php endif; ?>
    </section>
</div>
<?php render_footer(); ?>
