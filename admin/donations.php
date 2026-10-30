<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
$user = require_login();

const CAUSES = ['Where needed most', 'Ending SGBV', 'Youth Health', 'Mental Health', 'Disability Inclusion'];
const METHODS = ['mpesa' => 'M-Pesa', 'bank' => 'Bank transfer', 'cash' => 'Cash', 'other' => 'Other'];

$errors = [];
$form = ['donor_name' => '', 'phone' => '', 'amount' => '', 'mpesa_code' => '', 'method' => 'mpesa', 'cause' => CAUSES[0], 'received_at' => date('Y-m-d'), 'note' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'delete' && is_admin()) {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM donations WHERE id = ?')->execute([$id]);
        log_activity('donation.delete', "Donation #$id");
        flash('success', 'Donation record deleted.');
        redirect('donations.php');
    }
    if ($action === 'add') {
        foreach ($form as $k => $_) $form[$k] = post_str($k, 300);
        $form['mpesa_code'] = strtoupper(preg_replace('/\s+/', '', $form['mpesa_code']));
        $amount = (float) str_replace([',', ' '], '', $form['amount']);
        if ($amount <= 0) $errors[] = 'Enter the amount received.';
        if (!isset(METHODS[$form['method']])) $form['method'] = 'mpesa';
        if (!in_array($form['cause'], CAUSES, true)) $form['cause'] = CAUSES[0];
        if ($form['method'] === 'mpesa' && !preg_match('/^[A-Z0-9]{8,12}$/', $form['mpesa_code'])) $errors[] = 'Enter the M-Pesa transaction code (e.g. SJK3ABC12X).';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['received_at'])) $errors[] = 'Choose the date received.';
        if ($form['mpesa_code'] !== '') {
            $dup = db()->prepare('SELECT COUNT(*) FROM donations WHERE mpesa_code = ?');
            $dup->execute([$form['mpesa_code']]);
            if ((int) $dup->fetchColumn() > 0) $errors[] = 'This M-Pesa code has already been recorded.';
        }
        if (!$errors) {
            db()->prepare('INSERT INTO donations (donor_name, phone, amount, mpesa_code, method, cause, received_at, note, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$form['donor_name'], $form['phone'], $amount, $form['mpesa_code'] ?: null, $form['method'], $form['cause'], $form['received_at'], $form['note'], $user['id']]);
            log_activity('donation.add', format_kes($amount) . ' ' . $form['cause'] . ($form['mpesa_code'] ? ' (' . $form['mpesa_code'] . ')' : ''));
            flash('success', 'Donation of ' . format_kes($amount) . ' recorded.');
            redirect('donations.php');
        }
    }
}

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
$cause = in_array($_GET['cause'] ?? '', CAUSES, true) ? $_GET['cause'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($from) { $where[] = 'received_at >= ?'; $params[] = $from; }
if ($to) { $where[] = 'received_at <= ?'; $params[] = $to; }
if ($cause) { $where[] = 'cause = ?'; $params[] = $cause; }
if ($q !== '') { $where[] = '(donor_name LIKE ? OR phone LIKE ? OR mpesa_code LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

if (isset($_GET['export'])) {
    $stmt = db()->prepare("SELECT received_at, donor_name, phone, amount, method, mpesa_code, cause, note FROM donations$whereSql ORDER BY received_at DESC");
    $stmt->execute($params);
    log_activity('donation.export', 'Exported donations');
    send_csv('judescbo-donations-' . date('Y-m-d') . '.csv', ['Date', 'Donor', 'Phone', 'Amount (KES)', 'Method', 'M-Pesa code', 'Cause', 'Note'], $stmt->fetchAll(PDO::FETCH_NUM));
}

$stmt = db()->prepare("SELECT d.*, u.name AS recorder FROM donations d LEFT JOIN users u ON u.id = d.recorded_by$whereSql ORDER BY received_at DESC, d.id DESC LIMIT 200");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$sum = db()->prepare("SELECT COALESCE(SUM(amount), 0) total, COUNT(*) n FROM donations$whereSql");
$sum->execute($params);
$filtered = $sum->fetch();

$thisMonth = db()->query("SELECT COALESCE(SUM(amount), 0) FROM donations WHERE received_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$thisYear = db()->query("SELECT COALESCE(SUM(amount), 0) FROM donations WHERE YEAR(received_at) = YEAR(CURDATE())")->fetchColumn();
$allTime = db()->query('SELECT COALESCE(SUM(amount), 0) FROM donations')->fetchColumn();
$byCause = db()->query('SELECT cause, SUM(amount) total FROM donations GROUP BY cause ORDER BY total DESC')->fetchAll(PDO::FETCH_KEY_PAIR);
$maxCause = $byCause ? max($byCause) : 1;
$query = array_filter(['from' => $from, 'to' => $to, 'cause' => $cause, 'q' => $q]);

render_header('Donations');
?>
<section class="stat-grid stat-grid-3">
    <div class="stat-card"><span class="stat-icon icon-green"><i class="fas fa-calendar-day"></i></span><span class="stat-body"><strong><?= h(format_kes($thisMonth)) ?></strong><small>This month</small></span></div>
    <div class="stat-card"><span class="stat-icon icon-blue"><i class="fas fa-calendar"></i></span><span class="stat-body"><strong><?= h(format_kes($thisYear)) ?></strong><small><?= date('Y') ?> so far</small></span></div>
    <div class="stat-card"><span class="stat-icon icon-gold"><i class="fas fa-sack-dollar"></i></span><span class="stat-body"><strong><?= h(format_kes($allTime)) ?></strong><small>All time</small></span></div>
</section>

<div class="grid-2 grid-2-wide">
    <section class="panel">
        <form method="get" class="filter-bar">
            <label>From<input type="date" name="from" value="<?= h($from) ?>"></label>
            <label>To<input type="date" name="to" value="<?= h($to) ?>"></label>
            <label>Cause<select name="cause"><option value="">All causes</option><?php foreach (CAUSES as $c): ?><option <?= $cause === $c ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select></label>
            <label>Search<input type="search" name="q" value="<?= h($q) ?>" placeholder="Name, phone, code"></label>
            <button class="btn btn-light btn-sm"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($query): ?><a href="donations.php" class="link">Clear</a><?php endif; ?>
            <a href="?<?= h(http_build_query(array_merge($query, ['export' => 1]))) ?>" class="btn btn-light btn-sm push-right"><i class="fas fa-file-csv"></i> Export</a>
        </form>
        <p class="muted small"><?= (int) $filtered['n'] ?> donation<?= (int) $filtered['n'] === 1 ? '' : 's' ?> · <strong><?= h(format_kes($filtered['total'])) ?></strong><?= $query ? ' matching these filters' : ' in total' ?></p>
        <?php if (!$rows): ?>
            <p class="empty"><i class="fas fa-hand-holding-dollar"></i> No donations recorded<?= $query ? ' for these filters' : ' yet' ?>.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Date</th><th>Donor</th><th>Amount</th><th>Cause</th><th>Reference</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td data-label="Date"><?= h(date('j M Y', strtotime($r['received_at']))) ?></td>
                            <td data-label="Donor"><strong><?= h($r['donor_name'] ?: 'Anonymous') ?></strong><?php if ($r['phone']): ?><br><small class="muted"><?= h($r['phone']) ?></small><?php endif; ?></td>
                            <td data-label="Amount" class="num"><strong><?= h(format_kes($r['amount'])) ?></strong></td>
                            <td data-label="Cause"><span class="tag"><?= h($r['cause']) ?></span></td>
                            <td data-label="Reference"><?= h(METHODS[$r['method']] ?? $r['method']) ?><?php if ($r['mpesa_code']): ?><br><code><?= h($r['mpesa_code']) ?></code><?php endif; ?><?php if ($r['note']): ?><br><small class="muted"><?= h($r['note']) ?></small><?php endif; ?></td>
                            <td class="cell-actions">
                                <?php if (is_admin()): ?>
                                    <form method="post" data-confirm="Delete this donation record?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="action" value="delete">
                                        <button class="icon-btn icon-btn-danger" aria-label="Delete"><i class="fas fa-trash"></i></button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <aside>
        <section class="panel" id="add">
            <h3 class="panel-title"><i class="fas fa-receipt"></i> Record a donation</h3>
            <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= h($e) ?></div><?php endforeach; ?>
            <form method="post" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="action" value="add">
                <label class="field">Amount (KES)<input type="text" inputmode="numeric" name="amount" value="<?= h($form['amount']) ?>" required placeholder="1000"></label>
                <label class="field">Date received<input type="date" name="received_at" value="<?= h($form['received_at']) ?>" required></label>
                <label class="field">Method<select name="method" id="method"><?php foreach (METHODS as $k => $label): ?><option value="<?= $k ?>" <?= $form['method'] === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
                <label class="field" id="code-field">M-Pesa code<input type="text" name="mpesa_code" value="<?= h($form['mpesa_code']) ?>" maxlength="12" placeholder="SJK3ABC12X" style="text-transform:uppercase"></label>
                <label class="field">Donor name<input type="text" name="donor_name" value="<?= h($form['donor_name']) ?>" maxlength="120" placeholder="Leave blank if anonymous"></label>
                <label class="field">Phone<input type="tel" name="phone" value="<?= h($form['phone']) ?>" maxlength="40" placeholder="07XX XXX XXX"></label>
                <label class="field span-2">Cause<select name="cause"><?php foreach (CAUSES as $c): ?><option <?= $form['cause'] === $c ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select></label>
                <label class="field span-2">Note<input type="text" name="note" value="<?= h($form['note']) ?>" maxlength="300" placeholder="e.g. Thanked on WhatsApp"></label>
                <button class="btn btn-primary span-2"><i class="fas fa-plus"></i> Record donation</button>
            </form>
        </section>
        <?php if ($byCause): ?>
            <section class="panel">
                <h3 class="panel-title"><i class="fas fa-chart-simple"></i> By cause (all time)</h3>
                <ul class="hbars">
                    <?php foreach ($byCause as $c => $t): ?>
                        <li><span class="hbar-label"><?= h($c) ?><strong><?= h(format_kes($t)) ?></strong></span><span class="hbar"><span style="width: <?= round($t / $maxCause * 100) ?>%"></span></span></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </aside>
</div>
<?php render_footer(); ?>
