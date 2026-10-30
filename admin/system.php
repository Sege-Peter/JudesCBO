<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/publisher.php';
require_once __DIR__ . '/includes/maintenance.php';
$me = require_developer();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $section = $_POST['section'] ?? '';

    if ($section === 'maintenance') {
        $on = ($_POST['maint_on'] ?? '') === '1';
        $until = post_str('maint_until', 16);
        $untilTs = $until !== '' ? strtotime($until) : false;
        $autoEnd = !empty($_POST['maint_auto_end']);
        $badIps = array_filter(preg_split('/[\s,]+/', (string) ($_POST['maint_ips'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [], fn($ip) => !filter_var($ip, FILTER_VALIDATE_IP));
        $errors = [];
        if ($until !== '' && !$untilTs) $errors[] = 'Enter a valid "back by" date and time.';
        if ($on && $autoEnd && (!$untilTs || $untilTs <= time())) $errors[] = 'To end maintenance automatically, set a "back by" time in the future.';
        if ($badIps) $errors[] = 'These are not valid IP addresses: ' . implode(', ', array_slice($badIps, 0, 5));
        if ($errors) {
            foreach ($errors as $e) flash('error', $e);
            redirect('system.php#maintenance');
        }
        $wasOn = maintenance_on();
        save_setting('maint_on', $on ? '1' : '0');
        save_setting('maint_title', post_str('maint_title', 80));
        save_setting('maint_message', mb_substr(trim((string) ($_POST['maint_message'] ?? '')), 0, 800));
        save_setting('maint_until', $untilTs ? date('Y-m-d\TH:i', $untilTs) : '');
        save_setting('maint_auto_end', $autoEnd ? '1' : '0');
        save_setting('maint_show_contact', !empty($_POST['maint_show_contact']) ? '1' : '0');
        save_setting('maint_staff_bypass', !empty($_POST['maint_staff_bypass']) ? '1' : '0');
        save_setting('maint_ips', implode("\n", maintenance_ips_from((string) ($_POST['maint_ips'] ?? ''))));
        if ($on && !$wasOn) save_setting('maint_started', date('Y-m-d H:i:s'));
        $written = write_maintenance_files();
        log_activity('maintenance.' . ($on ? 'on' : 'off'), $on ? 'Public website in maintenance mode' . ($untilTs ? ' until ' . date('j M Y, g:i a', $untilTs) : '') : 'Public website live');
        if (!$written) {
            flash('error', "Settings saved, but the website's .htaccess file couldn't be updated, so visitors aren't affected. This needs Apache (cPanel) hosting with a writable .htaccess.");
        } else {
            flash('success', $on ? 'Maintenance mode is on. Visitors now see the maintenance page.' : ($wasOn ? 'Maintenance mode is off. The website is live again.' : 'Maintenance settings saved.'));
        }
        redirect('system.php#maintenance');
    }

    if ($section === 'lock') {
        $lock = !empty($_POST['dash_lock']);
        save_setting('dash_lock', $lock ? '1' : '0');
        save_setting('dash_lock_message', post_str('dash_lock_message', 200));
        if ($lock) {
            // Sign out everyone who can't use a locked dashboard
            db()->exec("UPDATE users SET session_version = session_version + 1 WHERE role <> 'developer'");
            db()->exec("DELETE t FROM auth_tokens t JOIN users u ON u.id = t.user_id WHERE u.role <> 'developer'");
        }
        log_activity('maintenance.dashboard_' . ($lock ? 'locked' : 'unlocked'), $lock ? 'Dashboard locked to System admins' : 'Dashboard open to all staff');
        flash('success', $lock ? 'Dashboard locked. Everyone except System admins has been signed out.' : 'Dashboard unlocked. All staff can log in again.');
        redirect('system.php#lock');
    }

    if ($section === 'sessions') {
        $action = $_POST['action'] ?? '';
        if ($action === 'logout-user') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if ($target && (int) $target['id'] !== (int) $me['id']) {
                force_logout($id);
                log_activity('user.force_logout', $target['username']);
                flash('success', $target['name'] . ' has been signed out on every device.');
            }
        } elseif ($action === 'logout-all') {
            $ids = db()->prepare('SELECT id FROM users WHERE id <> ?');
            $ids->execute([$me['id']]);
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) force_logout((int) $id);
            log_activity('user.force_logout_all', 'Everyone except ' . $me['username']);
            flash('success', 'Everyone else has been signed out on every device.');
        } elseif ($action === 'clear-lockouts') {
            $n = db()->exec('DELETE FROM login_attempts');
            log_activity('security.clear_lockouts', "$n failed login records cleared");
            flash('success', 'Login lockouts cleared. Anyone who was locked out can try again now.');
        } elseif ($action === 'rotate-token') {
            save_setting('maint_token', bin2hex(random_bytes(16)));
            write_maintenance_files();
            log_activity('maintenance.rotate_token', 'New staff preview key');
            flash('success', 'Staff preview key renewed. Staff get the new one automatically next time they open the dashboard.');
        }
        redirect('system.php#sessions');
    }
}

function maintenance_ips_from(string $raw): array
{
    $ips = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique(array_filter($ips, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP))));
}

$users = db()->query('SELECT id, name, username, role, active, last_seen_at, last_ip, last_login FROM users ORDER BY (last_seen_at IS NULL), last_seen_at DESC, name')->fetchAll();
$activeNow = count(array_filter($users, fn($u) => $u['last_seen_at'] && time() - strtotime($u['last_seen_at']) < 300));
$maintOn = maintenance_on();
$locked = dashboard_locked();
$until = setting('maint_until');
$htaccess = site_path('.htaccess');
$htaccessOk = is_file($htaccess) && is_writable($htaccess);
$onGithub = str_contains((string) config('site_url'), 'github.io');
$dbVersion = (string) db()->query('SELECT VERSION()')->fetchColumn();
$lockouts = (int) db()->query('SELECT COUNT(*) FROM login_attempts WHERE attempted_at > NOW() - INTERVAL 15 MINUTE')->fetchColumn();
$free = @disk_free_space(config('site_root'));
$uploadsSize = 0;
if (is_dir(site_path('uploads'))) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(site_path('uploads'), FilesystemIterator::SKIP_DOTS)) as $f) $uploadsSize += $f->getSize();
}
$mb = fn($bytes) => $bytes >= 1073741824 ? round($bytes / 1073741824, 1) . ' GB' : round($bytes / 1048576, 1) . ' MB';

render_header('System');
?>
<section class="stat-grid stat-grid-3">
    <div class="stat-card"><span class="stat-icon <?= $maintOn ? "icon-amber" : "icon-green" ?>"><i class="fas <?= $maintOn ? 'fa-screwdriver-wrench' : 'fa-globe' ?>"></i></span>
        <div class="stat-body"><strong><?= $maintOn ? 'Maintenance' : 'Live' ?></strong><small>Public website</small></div></div>
    <div class="stat-card"><span class="stat-icon <?= $locked ? "icon-red" : "icon-blue" ?>"><i class="fas <?= $locked ? 'fa-lock' : 'fa-lock-open' ?>"></i></span>
        <div class="stat-body"><strong><?= $locked ? 'Locked' : 'Open' ?></strong><small>Dashboard</small></div></div>
    <div class="stat-card"><span class="stat-icon icon-purple"><i class="fas fa-signal"></i></span>
        <div class="stat-body"><strong><?= $activeNow ?></strong><small>Active in the last 5 min</small></div></div>
</section>

<div class="settings-grid">
    <section class="panel span-2 <?= $maintOn ? 'panel-warning' : '' ?>" id="maintenance">
        <h3 class="panel-title"><i class="fas fa-screwdriver-wrench"></i> Website maintenance mode</h3>
        <p class="muted">Shows visitors a maintenance page while you work on the website. The dashboard keeps working, and signed-in staff can still browse the real site.</p>
        <?php if ($onGithub): ?>
            <div class="alert alert-info"><i class="fas fa-circle-info"></i><span>The website is on GitHub Pages, which can't run maintenance mode. It works once the site is on cPanel hosting.</span></div>
        <?php elseif (!$htaccessOk): ?>
            <div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span>The website's <code>.htaccess</code> file is missing or not writable, so maintenance mode can't switch on.</span></div>
        <?php endif; ?>
        <?php if ($maintOn): ?>
            <div class="maint-live"><span class="pulse-dot"></span><div><strong>Maintenance mode is on</strong>
                <small>Since <?= h(date('j M, g:i a', strtotime(setting('maint_started') ?: 'now'))) ?><?= $until ? ' · back by ' . h(date('j M, g:i a', strtotime($until))) . (setting('maint_auto_end') === '1' ? ' (ends automatically)' : '') : '' ?></small></div></div>
        <?php endif; ?>
        <form method="post" id="maint-form">
            <?= csrf_field() ?><input type="hidden" name="section" value="maintenance">
            <div class="mode-switch">
                <label class="radio-card"><input type="radio" name="maint_on" value="0" <?= $maintOn ? '' : 'checked' ?>><span><strong><i class="fas fa-globe text-green"></i> Live</strong><small>Everyone sees the website as normal.</small></span></label>
                <label class="radio-card"><input type="radio" name="maint_on" value="1" <?= $maintOn ? 'checked' : '' ?>><span><strong><i class="fas fa-screwdriver-wrench text-amber"></i> Maintenance</strong><small>Visitors see the maintenance page instead.</small></span></label>
            </div>
            <h4 class="form-subhead"><i class="fas fa-message"></i> What visitors see</h4>
            <div class="form-grid">
                <label class="field span-2">Headline<input type="text" name="maint_title" value="<?= h(setting('maint_title')) ?>" maxlength="80" placeholder="We'll be back soon"></label>
                <label class="field span-2">Message <small>leave a blank line between paragraphs</small><textarea name="maint_message" rows="3" maxlength="800" placeholder="We're making some improvements to our website. Thank you for your patience."><?= h(setting('maint_message')) ?></textarea></label>
                <label class="field">Back by <small>optional, shows a countdown</small><input type="datetime-local" name="maint_until" value="<?= h($until) ?>"></label>
                <div class="field field-checks">
                    <label class="check"><input type="checkbox" name="maint_auto_end" value="1" <?= setting('maint_auto_end') === '1' ? 'checked' : '' ?>> End automatically at this time</label>
                    <label class="check"><input type="checkbox" name="maint_show_contact" value="1" <?= setting('maint_show_contact', '1') === '1' ? 'checked' : '' ?>> Show call, WhatsApp and email buttons</label>
                </div>
            </div>
            <h4 class="form-subhead"><i class="fas fa-user-check"></i> Who can still see the website</h4>
            <label class="check"><input type="checkbox" name="maint_staff_bypass" value="1" <?= setting('maint_staff_bypass', '1') === '1' ? 'checked' : '' ?>> Staff signed in to this dashboard (on the same browser)</label>
            <label class="field">Allowed IP addresses <small>one per line, e.g. the office network</small>
                <textarea name="maint_ips" id="maint-ips" rows="3" placeholder="e.g. 41.90.12.34"><?= h(setting('maint_ips')) ?></textarea></label>
            <button type="button" class="link small" id="add-my-ip" data-ip="<?= h(client_ip()) ?>"><i class="fas fa-plus"></i> Add my IP address (<?= h(client_ip()) ?>)</button>
            <div class="form-actions">
                <button class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save</button>
                <a href="../maintenance.html" target="_blank" rel="noopener" class="btn btn-light"><i class="fas fa-eye"></i> Preview maintenance page</a>
            </div>
        </form>
    </section>

    <section class="panel <?= $locked ? 'panel-warning' : '' ?>" id="lock">
        <h3 class="panel-title"><i class="fas fa-lock"></i> Dashboard access</h3>
        <p class="muted">Lock the dashboard during upgrades or a security problem. Only System admins can stay signed in or log in.</p>
        <form method="post" class="form-stack" <?= $locked ? '' : 'data-confirm="Lock the dashboard? Everyone except System admins will be signed out straight away."' ?>>
            <?= csrf_field() ?><input type="hidden" name="section" value="lock">
            <label class="check"><input type="checkbox" name="dash_lock" value="1" <?= $locked ? 'checked' : '' ?>> Lock the dashboard to System admins</label>
            <label class="field">Message on the login page <small>optional</small><input type="text" name="dash_lock_message" value="<?= h(setting('dash_lock_message')) ?>" maxlength="200" placeholder="We're upgrading the dashboard. Back by 3 pm."></label>
            <button class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save</button>
        </form>
    </section>

    <section class="panel" id="security">
        <h3 class="panel-title"><i class="fas fa-shield-halved"></i> Security tools</h3>
        <ul class="sys-list">
            <li><span>Failed logins (last 15 min)</span><strong><?= $lockouts ?></strong></li>
        </ul>
        <div class="tool-row">
            <a href="security.php" class="btn btn-primary btn-sm"><i class="fas fa-shield-halved"></i> Open the Security page</a>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="section" value="sessions"><input type="hidden" name="action" value="clear-lockouts">
                <button class="btn btn-light btn-sm"><i class="fas fa-unlock"></i> Clear login lockouts</button></form>
            <form method="post" data-confirm="Renew the staff preview key? Staff who are browsing the website during maintenance will need to open the dashboard again."><?= csrf_field() ?><input type="hidden" name="section" value="sessions"><input type="hidden" name="action" value="rotate-token">
                <button class="btn btn-light btn-sm"><i class="fas fa-key"></i> Renew staff preview key</button></form>
        </div>
    </section>

    <section class="panel span-2" id="sessions">
        <div class="panel-head-row">
            <h3 class="panel-title"><i class="fas fa-users-viewfinder"></i> Who's signed in</h3>
            <form method="post" data-confirm="Sign out everyone except you, on every device?"><?= csrf_field() ?><input type="hidden" name="section" value="sessions"><input type="hidden" name="action" value="logout-all">
                <button class="btn btn-danger-ghost btn-sm"><i class="fas fa-right-from-bracket"></i> Log out everyone else</button></form>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Person</th><th>Role</th><th>Last active</th><th>IP address</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $u):
                    $recent = $u['last_seen_at'] && time() - strtotime($u['last_seen_at']) < 300; ?>
                    <tr class="<?= (int) $u['active'] ? '' : 'row-muted' ?>">
                        <td data-label="Person"><strong><?= h($u['name']) ?></strong><?= (int) $u['id'] === (int) $me['id'] ? ' <small class="muted">(you)</small>' : '' ?><br><small class="muted"><?= h($u['username']) ?></small></td>
                        <td data-label="Role"><span class="pill role-<?= h($u['role']) ?>"><?= h(role_label($u['role'])) ?></span><?= (int) $u['active'] ? '' : ' <span class="pill pill-closed">Deactivated</span>' ?></td>
                        <td data-label="Last active"><?php if ($recent): ?><span class="online-dot"></span> Active now<?php else: ?><?= $u['last_seen_at'] ? h(time_ago($u['last_seen_at'])) : '<span class="muted">Never</span>' ?><?php endif; ?></td>
                        <td data-label="IP address"><code><?= h($u['last_ip'] ?: '—') ?></code></td>
                        <td class="cell-actions">
                            <?php if ((int) $u['id'] !== (int) $me['id']): ?>
                                <form method="post" data-confirm="Sign <?= h($u['name']) ?> out on every device?"><?= csrf_field() ?><input type="hidden" name="section" value="sessions"><input type="hidden" name="action" value="logout-user"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <button class="btn btn-light btn-sm"><i class="fas fa-right-from-bracket"></i> Log out</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted small">Logging someone out ends their open sessions and "remember me" on every device. It doesn't stop them logging in again; deactivate the account on the <a href="users.php">Users</a> page for that.</p>
    </section>

    <section class="panel span-2" id="info">
        <h3 class="panel-title"><i class="fas fa-server"></i> System health</h3>
        <ul class="sys-list sys-list-2">
            <li><span>Website address</span><strong><?= h(config('site_url')) ?></strong></li>
            <li><span>Server time</span><strong><?= h(date('j M Y, g:i a T')) ?></strong></li>
            <li><span>PHP version</span><strong><?= h(PHP_VERSION) ?></strong></li>
            <li><span>Database</span><strong><?= h($dbVersion) ?></strong></li>
            <li><span>Dashboard database version</span><strong><?= h(setting('schema_version')) ?></strong></li>
            <li><span>Last website update</span><strong><?= setting('last_published') ? h(date('j M Y, g:i a', strtotime(setting('last_published')))) : 'never' ?></strong></li>
            <li><span>.htaccess writable</span><strong><?= $htaccessOk ? '<i class="fas fa-check text-green"></i> Yes' : '<i class="fas fa-xmark text-red"></i> No' ?></strong></li>
            <li><span>Image processing</span><strong><?= function_exists('imagewebp') ? '<i class="fas fa-check text-green"></i> Available' : '<i class="fas fa-xmark text-red"></i> Missing' ?></strong></li>
            <li><span>Uploaded files</span><strong><?= h($mb($uploadsSize)) ?></strong></li>
            <li><span>Free disk space</span><strong><?= $free ? h($mb($free)) : 'unknown' ?></strong></li>
            <li><span>Max upload size</span><strong><?= h(ini_get('upload_max_filesize')) ?></strong></li>
            <li><span>Secure cookies (HTTPS)</span><strong><?= secure_cookies() ? '<i class="fas fa-check text-green"></i> On' : '<i class="fas fa-xmark text-amber"></i> Off' ?></strong></li>
        </ul>
    </section>
</div>
<script>
document.getElementById('add-my-ip').addEventListener('click', function () {
    var box = document.getElementById('maint-ips');
    var ip = this.dataset.ip;
    var list = box.value.split(/[\s,]+/).filter(Boolean);
    if (list.indexOf(ip) === -1) box.value = list.concat(ip).join('\n');
    box.focus();
});
</script>
<?php render_footer(); ?>
