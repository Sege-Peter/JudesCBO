<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $added = 0;
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', post_str('emails', 20000)) as $email) {
            if ($email === '') continue;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $invalid[] = $email; continue; }
            $stmt = db()->prepare("INSERT INTO subscribers (email, source) VALUES (?, 'manual') ON DUPLICATE KEY UPDATE status = 'active'");
            $stmt->execute([mb_strtolower($email)]);
            $added++;
        }
        if ($added) { log_activity('subscriber.add', "$added email(s)"); flash('success', "$added subscriber(s) added."); }
        if ($invalid) flash('error', 'Skipped invalid addresses: ' . implode(', ', array_slice($invalid, 0, 10)));
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'unsubscribe' || $action === 'resubscribe') {
            db()->prepare('UPDATE subscribers SET status = ? WHERE id = ?')->execute([$action === 'unsubscribe' ? 'unsubscribed' : 'active', $id]);
            log_activity('subscriber.' . $action, "Subscriber #$id");
            flash('success', $action === 'unsubscribe' ? 'Subscriber removed from the mailing list.' : 'Subscriber re-added.');
        } elseif ($action === 'delete') {
            db()->prepare('DELETE FROM subscribers WHERE id = ?')->execute([$id]);
            log_activity('subscriber.delete', "Subscriber #$id");
            flash('success', 'Subscriber deleted.');
        }
    }
    redirect('subscribers');
}

$status = in_array($_GET['status'] ?? '', ['active', 'unsubscribed'], true) ? $_GET['status'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($status) { $where[] = 'status = ?'; $params[] = $status; }
if ($q !== '') { $where[] = 'email LIKE ?'; $params[] = "%$q%"; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

if (isset($_GET['export'])) {
    $stmt = db()->prepare("SELECT email, status, source, created_at FROM subscribers$whereSql ORDER BY created_at DESC");
    $stmt->execute($params);
    log_activity('subscriber.export', 'Exported subscribers');
    send_csv('judescbo-subscribers-' . date('Y-m-d') . '.csv', ['Email', 'Status', 'Source', 'Subscribed'], $stmt->fetchAll(PDO::FETCH_NUM));
}

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$count = db()->prepare("SELECT COUNT(*) FROM subscribers$whereSql");
$count->execute($params);
$total = (int) $count->fetchColumn();
$stmt = db()->prepare("SELECT * FROM subscribers$whereSql ORDER BY created_at DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$rows = $stmt->fetchAll();
$counts = db()->query('SELECT status, COUNT(*) c FROM subscribers GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$newThisMonth = (int) db()->query("SELECT COUNT(*) FROM subscribers WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$activeEmails = db()->query("SELECT email FROM subscribers WHERE status = 'active' ORDER BY email")->fetchAll(PDO::FETCH_COLUMN);

render_header('Subscribers');
?>
<section class="stat-grid stat-grid-3">
    <div class="stat-card"><span class="stat-icon icon-green"><i class="fas fa-user-check"></i></span><span class="stat-body"><strong><?= (int) ($counts['active'] ?? 0) ?></strong><small>Active subscribers</small></span></div>
    <div class="stat-card"><span class="stat-icon icon-blue"><i class="fas fa-user-plus"></i></span><span class="stat-body"><strong><?= $newThisMonth ?></strong><small>New this month</small></span></div>
    <div class="stat-card"><span class="stat-icon icon-red"><i class="fas fa-user-xmark"></i></span><span class="stat-body"><strong><?= (int) ($counts['unsubscribed'] ?? 0) ?></strong><small>Unsubscribed</small></span></div>
</section>

<div class="grid-2 grid-2-wide">
    <section class="panel">
        <div class="toolbar toolbar-inner">
            <div class="tabs">
                <a href="subscribers" class="<?= $status === '' ? 'active' : '' ?>">All</a>
                <a href="?status=active" class="<?= $status === 'active' ? 'active' : '' ?>">Active</a>
                <a href="?status=unsubscribed" class="<?= $status === 'unsubscribed' ? 'active' : '' ?>">Unsubscribed</a>
            </div>
            <form class="search" method="get">
                <?php if ($status): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
                <i class="fas fa-magnifying-glass"></i><input type="search" name="q" value="<?= h($q) ?>" placeholder="Search email">
            </form>
        </div>
        <?php if (!$rows): ?>
            <p class="empty"><i class="fas fa-envelope-open-text"></i> No subscribers<?= $where ? ' match' : ' yet' ?>.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Email</th><th>Status</th><th>Source</th><th>Subscribed</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td data-label="Email"><a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a></td>
                            <td data-label="Status"><?= status_pill($r['status']) ?></td>
                            <td data-label="Source"><?= h(ucfirst($r['source'])) ?></td>
                            <td data-label="Subscribed"><?= h(date('j M Y', strtotime($r['created_at']))) ?></td>
                            <td class="cell-actions">
                                <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <?php if ($r['status'] === 'active'): ?>
                                        <button name="action" value="unsubscribe" class="btn btn-light btn-sm">Unsubscribe</button>
                                    <?php else: ?>
                                        <button name="action" value="resubscribe" class="btn btn-light btn-sm">Re-add</button>
                                    <?php endif; ?>
                                </form>
                                <form method="post" data-confirm="Delete <?= h($r['email']) ?> permanently?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button name="action" value="delete" class="icon-btn icon-btn-danger" aria-label="Delete"><i class="fas fa-trash"></i></button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= paginate($total, $perPage, $page, array_filter(['status' => $status, 'q' => $q])) ?>
        <?php endif; ?>
    </section>

    <aside>
        <section class="panel">
            <h3 class="panel-title"><i class="fas fa-paper-plane"></i> Send an update</h3>
            <p class="muted">Copy all active addresses and paste them into the <strong>BCC</strong> field of your email so subscribers can't see each other.</p>
            <textarea class="copy-source" id="all-emails" rows="3" readonly><?= h(implode(', ', $activeEmails)) ?></textarea>
            <div class="btn-row">
                <button type="button" class="btn btn-primary btn-sm" data-copy-target="all-emails"><i class="far fa-copy"></i> Copy <?= count($activeEmails) ?> emails</button>
                <a href="?export=1&amp;status=active" class="btn btn-light btn-sm"><i class="fas fa-file-csv"></i> Export CSV</a>
            </div>
        </section>
        <section class="panel">
            <h3 class="panel-title"><i class="fas fa-user-plus"></i> Add subscribers</h3>
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="add">
                <label class="field">Email addresses <small>One per line, or separated by commas</small>
                    <textarea name="emails" rows="4" placeholder="jane@example.com&#10;john@example.com" required></textarea>
                </label>
                <p class="muted small">Only add people who have agreed to receive updates.</p>
                <button class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add</button>
            </form>
        </section>
    </aside>
</div>
<?php render_footer(); ?>
