<?php
// Live updates ask for new entries in the background; that must not count as using the dashboard (idle sign-out)
if (isset($_GET['live'])) define('PASSIVE_REQUEST', true);
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
db()->exec('DELETE FROM blocked_ips WHERE expires_at IS NOT NULL AND expires_at < NOW()');
$blockedIps = db()->query('SELECT ip FROM blocked_ips')->fetchAll(PDO::FETCH_COLUMN);
$failed24 = (int) db()->query("SELECT COUNT(*) FROM activity_log WHERE action = 'login.failed' AND created_at > NOW() - INTERVAL 1 DAY")->fetchColumn();

const ACTIVITY_ICONS = [
    'login' => 'fa-right-to-bracket', 'logout' => 'fa-right-from-bracket', 'story' => 'fa-newspaper', 'gallery' => 'fa-images',
    'message' => 'fa-inbox', 'subscriber' => 'fa-envelope-open-text', 'donation' => 'fa-hand-holding-dollar',
    'user' => 'fa-users-gear', 'settings' => 'fa-sliders', 'site' => 'fa-rotate', 'install' => 'fa-flag-checkered', 'profile' => 'fa-user',
    'security' => 'fa-shield-halved',
];

/** One entry of the timeline (also sent to open pages by the live updates). */
function activity_item(array $r, array $ctx): string
{
    $group = explode('.', $r['action'])[0];
    $alert = in_array($r['action'], $ctx['failed'], true);
    $ip = (string) $r['ip'];
    $hidden = '<input type="hidden" name="user" value="' . $ctx['user'] . '"><input type="hidden" name="show" value="' . $ctx['show'] . '"><input type="hidden" name="page" value="' . $ctx['page'] . '">';
    ob_start(); ?>
            <li data-id="<?= (int) $r['id'] ?>"<?= $alert ? ' class="is-alert"' : '' ?>>
                <span class="timeline-icon"><i class="fas <?= h($alert ? 'fa-triangle-exclamation' : (ACTIVITY_ICONS[$group] ?? 'fa-circle')) ?>"></i></span>
                <div class="timeline-body">
                    <p><strong><?= h($r['name'] ?? 'Website visitor') ?></strong> <span class="muted"><?= h(str_replace(['.', '_'], ' ', $r['action'])) ?></span><?= $r['details'] ? ': ' . h($r['details']) : '' ?></p>
                    <small class="muted"><?= h(date('j M Y, g:i a', strtotime($r['created_at']))) ?> · <code><?= h($ip) ?></code><?= $ip === $ctx['me'] ? ' (you)' : '' ?></small>
                </div>
                <?php if ($alert && filter_var($ip, FILTER_VALIDATE_IP)): ?>
                    <div class="timeline-actions">
                        <?php if (in_array($ip, $ctx['blocked'], true)): ?>
                            <form method="post" data-confirm="Unblock <?= h($ip) ?>?"><?= csrf_field() ?><?= $hidden ?><input type="hidden" name="action" value="unblock"><input type="hidden" name="ip" value="<?= h($ip) ?>">
                                <button class="btn btn-light btn-sm" title="This address is blocked"><i class="fas fa-unlock"></i> Unblock</button></form>
                        <?php elseif ($ip !== $ctx['me']): ?>
                            <form method="post" class="block-inline" data-confirm="Block <?= h($ip) ?>? It won't be able to open the dashboard or send website forms."><?= csrf_field() ?><?= $hidden ?><input type="hidden" name="action" value="block"><input type="hidden" name="ip" value="<?= h($ip) ?>">
                                <select name="days" aria-label="Block for"><option value="1">1 day</option><option value="7" selected>7 days</option><option value="30">30 days</option><option value="0">Until unblocked</option></select>
                                <button class="btn btn-danger-ghost btn-sm"><i class="fas fa-ban"></i> Block IP</button></form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </li>
<?php
    return (string) ob_get_clean();
}

$ctx = ['failed' => $failedActions, 'user' => $userId, 'show' => $show, 'page' => $page, 'me' => $myIp, 'blocked' => $blockedIps];

// Live updates: entries newer than the newest one on the page, with the same filters
if (isset($_GET['live'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $after = max(0, (int) ($_GET['after'] ?? 0));
    $new = db()->query("SELECT a.*, u.name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id"
        . ($where ? "$where AND" : ' WHERE') . " a.id > $after ORDER BY a.id DESC LIMIT 50")->fetchAll();
    echo json_encode([
        'html' => implode('', array_map(fn($r) => activity_item($r, $ctx), $new)),
        'count' => count($new),
        'last' => $new ? (int) $new[0]['id'] : $after,
        'failed24' => $failed24,
    ]);
    exit;
}

$total = (int) db()->query("SELECT COUNT(*) FROM activity_log a$where")->fetchColumn();
$rows = db()->query("SELECT a.*, u.name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id$where ORDER BY a.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage))->fetchAll();
$users = db()->query('SELECT id, name FROM users ORDER BY name')->fetchAll();
$lastId = $rows ? (int) $rows[0]['id'] : (int) db()->query("SELECT COALESCE(MAX(a.id), 0) FROM activity_log a$where")->fetchColumn();

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
        <a class="pill pill-alert" id="failed24" href="?show=failed" <?= $failed24 ? '' : 'hidden' ?>><i class="fas fa-triangle-exclamation"></i> <span><?= $failed24 ?> failed login<?= $failed24 === 1 ? '' : 's' ?> in 24 hours</span></a>
        <?php if ($page === 1): ?><span class="live-pill" id="live-pill" title="New activity appears here without refreshing"><span class="live-dot" aria-hidden="true"></span> Live</span><?php endif; ?>
    </form>
    <p class="empty" id="activity-empty" <?= $rows ? 'hidden' : '' ?>><?= $show ? 'No failed logins recorded.' : 'No activity yet.' ?></p>
    <ol class="timeline" id="activity-list" data-last="<?= $lastId ?>" aria-live="polite">
        <?php foreach ($rows as $r) echo activity_item($r, $ctx); ?>
    </ol>
    <?= paginate($total, $perPage, $page, array_filter(['user' => $userId, 'show' => $show])) ?>
</section>
<?php if ($page === 1): ?>
<script>
(function () {
    var list = document.getElementById('activity-list'), pill = document.getElementById('live-pill');
    var base = new URLSearchParams(location.search); base.delete('page'); base.set('live', '1');
    var busy = false, fails = 0;
    function confirmForms(root) {
        root.querySelectorAll('form[data-confirm]').forEach(function (f) {
            f.addEventListener('submit', function (e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
        });
    }
    function tick() {
        if (busy || document.hidden) return;
        busy = true;
        base.set('after', list.dataset.last);
        fetch('activity?' + base.toString(), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
            .then(function (r) {
                if (!r.ok || (r.headers.get('Content-Type') || '').indexOf('json') < 0) throw new Error('signed out');
                return r.json();
            })
            .then(function (d) {
                fails = 0; pill.classList.remove('is-off');
                list.dataset.last = d.last;
                var f = document.getElementById('failed24');
                f.hidden = !d.failed24;
                f.querySelector('span').textContent = d.failed24 + ' failed login' + (d.failed24 === 1 ? '' : 's') + ' in 24 hours';
                if (!d.count) return;
                var tmp = document.createElement('ol'); tmp.innerHTML = d.html;
                var items = Array.prototype.slice.call(tmp.children);
                items.reverse().forEach(function (li) {
                    if (list.querySelector('li[data-id="' + li.dataset.id + '"]')) return;
                    li.classList.add('is-new');
                    list.insertBefore(li, list.firstChild);
                    confirmForms(li);
                });
                document.getElementById('activity-empty').hidden = true;
                while (list.children.length > 200) list.removeChild(list.lastElementChild);
            })
            .catch(function () { if (++fails >= 3) pill.classList.add('is-off'); })
            .finally(function () { busy = false; });
    }
    setInterval(tick, 5000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
