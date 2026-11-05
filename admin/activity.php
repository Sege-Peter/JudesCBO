<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
$me = require_admin();
$myIp = client_ip();

// Block / unblock a suspicious address straight from the log
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $ip = trim((string) ($_POST['ip'] ?? ''));
    $back = array_filter(['user' => (int) ($_POST['user'] ?? 0), 'show' => ($_POST['show'] ?? '') === 'failed' ? 'failed' : '', 'page' => max(0, (int) ($_POST['page'] ?? 0))]);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        flash('error', 'That is not a valid IP address.');
    } elseif (($_POST['action'] ?? '') === 'unblock') {
        db()->prepare('DELETE FROM blocked_ips WHERE ip = ?')->execute([$ip]);
        db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
        log_activity('security.unblock', $ip);
        flash('success', "$ip is unblocked.");
    } elseif ($ip === $myIp) {
        flash('error', "That's your own address. Blocking it would lock you out of the dashboard.");
    } else {
        $hours = ['1' => 24, '7' => 168, '30' => 720, '0' => null][$_POST['days'] ?? '7'] ?? 168;
        $stmt = db()->prepare("SELECT COUNT(*) FROM activity_log WHERE ip = ? AND action IN ('login.failed', 'login.2fa_failed') AND created_at > NOW() - INTERVAL 7 DAY");
        $stmt->execute([$ip]);
        $n = (int) $stmt->fetchColumn();
        block_ip($ip, ($n ? $n . ($n === 1 ? " failed login; " : " failed logins; ") : '') . 'blocked by ' . $me['name'] . ' from the activity log', $hours);
        $for = $hours ? ' for ' . ($hours / 24) . ' day' . ($hours > 24 ? 's' : '') : ' permanently';
        log_activity('security.block', $ip . $for);
        flash('success', "$ip is blocked$for. It can no longer reach the dashboard or send website forms.");
    }
    redirect('activity' . ($back ? '?' . http_build_query($back) : ''));
}

$failedActions = ['login.failed', 'login.locked', 'login.2fa_failed', 'login.denied', 'security.session_mismatch', 'security.auto_block', 'security.verify_skipped'];
$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$userId = (int) ($_GET['user'] ?? 0);
$show = ($_GET['show'] ?? '') === 'failed' ? 'failed' : '';
$conds = [];
if ($userId) $conds[] = 'a.user_id = ' . $userId;
if ($show) $conds[] = "a.action IN ('" . implode("','", $failedActions) . "')";
$where = $conds ? ' WHERE ' . implode(' AND ', $conds) : '';
$total = (int) db()->query("SELECT COUNT(*) FROM activity_log a$where")->fetchColumn();
$rows = db()->query("SELECT a.*, u.name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id$where ORDER BY a.created_at DESC, a.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage))->fetchAll();
$users = db()->query('SELECT id, name FROM users ORDER BY name')->fetchAll();
db()->exec('DELETE FROM blocked_ips WHERE expires_at IS NOT NULL AND expires_at < NOW()');
$blockedIps = db()->query('SELECT ip FROM blocked_ips')->fetchAll(PDO::FETCH_COLUMN);
$failed24 = (int) db()->query("SELECT COUNT(*) FROM activity_log WHERE action = 'login.failed' AND created_at > NOW() - INTERVAL 1 DAY")->fetchColumn();

$icons = [
    'login' => 'fa-right-to-bracket', 'logout' => 'fa-right-from-bracket', 'story' => 'fa-newspaper', 'gallery' => 'fa-images',
    'message' => 'fa-inbox', 'subscriber' => 'fa-envelope-open-text', 'donation' => 'fa-hand-holding-dollar',
    'user' => 'fa-users-gear', 'settings' => 'fa-sliders', 'site' => 'fa-rotate', 'install' => 'fa-flag-checkered', 'profile' => 'fa-user',
    'security' => 'fa-shield-halved',
];
$hidden = fn() => '<input type="hidden" name="user" value="' . $userId . '"><input type="hidden" name="show" value="' . $show . '"><input type="hidden" name="page" value="' . $page . '">';

render_header('Activity log');
?>
<section class="panel">
    <form method="get" class="filter-bar">
        <label>Person<select name="user" onchange="this.form.submit()"><option value="0">Everyone</option>
            <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $userId === (int) $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
        </select></label>
        <label>Show<select name="show" onchange="this.form.submit()">
            <option value="">All activity</option>
            <option value="failed" <?= $show ? 'selected' : '' ?>>Failed logins and security alerts</option>
        </select></label>
        <?php if ($failed24): ?><a class="pill pill-alert" href="?show=failed"><i class="fas fa-triangle-exclamation"></i> <?= $failed24 ?> failed login<?= $failed24 === 1 ? '' : 's' ?> in 24 hours</a><?php endif; ?>
    </form>
    <?php if (!$rows): ?><p class="empty"><?= $show ? 'No failed logins recorded.' : 'No activity yet.' ?></p><?php endif; ?>
    <ol class="timeline">
        <?php foreach ($rows as $r):
            $group = explode('.', $r['action'])[0];
            $alert = in_array($r['action'], $failedActions, true);
            $ip = (string) $r['ip']; ?>
            <li<?= $alert ? ' class="is-alert"' : '' ?>>
                <span class="timeline-icon"><i class="fas <?= h($alert ? 'fa-triangle-exclamation' : ($icons[$group] ?? 'fa-circle')) ?>"></i></span>
                <div class="timeline-body">
                    <p><strong><?= h($r['name'] ?? 'Website visitor') ?></strong> <span class="muted"><?= h(str_replace(['.', '_'], ' ', $r['action'])) ?></span><?= $r['details'] ? ': ' . h($r['details']) : '' ?></p>
                    <small class="muted"><?= h(date('j M Y, g:i a', strtotime($r['created_at']))) ?> · <code><?= h($ip) ?></code><?= $ip === $myIp ? ' (you)' : '' ?></small>
                </div>
                <?php if ($alert && filter_var($ip, FILTER_VALIDATE_IP)): ?>
                    <div class="timeline-actions">
                        <?php if (in_array($ip, $blockedIps, true)): ?>
                            <form method="post" data-confirm="Unblock <?= h($ip) ?>?"><?= csrf_field() ?><?= $hidden() ?><input type="hidden" name="action" value="unblock"><input type="hidden" name="ip" value="<?= h($ip) ?>">
                                <button class="btn btn-light btn-sm" title="This address is blocked"><i class="fas fa-unlock"></i> Unblock</button></form>
                        <?php elseif ($ip !== $myIp): ?>
                            <form method="post" class="block-inline" data-confirm="Block <?= h($ip) ?>? It won't be able to open the dashboard or send website forms."><?= csrf_field() ?><?= $hidden() ?><input type="hidden" name="action" value="block"><input type="hidden" name="ip" value="<?= h($ip) ?>">
                                <select name="days" aria-label="Block for"><option value="1">1 day</option><option value="7" selected>7 days</option><option value="30">30 days</option><option value="0">Until unblocked</option></select>
                                <button class="btn btn-danger-ghost btn-sm"><i class="fas fa-ban"></i> Block IP</button></form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
    <?= paginate($total, $perPage, $page, array_filter(['user' => $userId, 'show' => $show])) ?>
</section>
<?php render_footer(); ?>
