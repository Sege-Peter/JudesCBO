<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_login();

const STATUSES = ['new', 'read', 'replied', 'closed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'status' && in_array($_POST['status'] ?? '', STATUSES, true)) {
        $status = $_POST['status'];
        db()->prepare('UPDATE messages SET status = ?, replied_at = IF(? = "replied", COALESCE(replied_at, NOW()), replied_at) WHERE id = ?')->execute([$status, $status, $id]);
        log_activity('message.status', "Message #$id marked $status");
        if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo '{"ok":true}'; exit; }
        flash('success', 'Message marked as ' . $status . '.');
    } elseif ($action === 'note') {
        db()->prepare('UPDATE messages SET internal_note = ? WHERE id = ?')->execute([post_str('note', 2000), $id]);
        log_activity('message.note', "Message #$id");
        flash('success', 'Note saved.');
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM messages WHERE id = ?')->execute([$id]);
        log_activity('message.delete', "Message #$id");
        flash('success', 'Message deleted.');
        redirect('messages.php');
    }
    redirect('messages.php?id=' . $id);
}

$status = in_array($_GET['status'] ?? '', STATUSES, true) ? $_GET['status'] : '';
$topic = trim((string) ($_GET['topic'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($status) { $where[] = 'status = ?'; $params[] = $status; }
if ($topic !== '') { $where[] = 'topic = ?'; $params[] = $topic; }
if ($q !== '') { $where[] = '(name LIKE ? OR email LIKE ? OR message LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

if (isset($_GET['export'])) {
    $stmt = db()->prepare("SELECT created_at, name, email, phone, topic, status, message, internal_note FROM messages$whereSql ORDER BY created_at DESC");
    $stmt->execute($params);
    log_activity('message.export', 'Exported messages');
    send_csv('judescbo-messages-' . date('Y-m-d') . '.csv', ['Received', 'Name', 'Email', 'Phone', 'Topic', 'Status', 'Message', 'Internal note'], $stmt->fetchAll(PDO::FETCH_NUM));
}

// Open the selected message first so it's already marked read in the list
$current = null;
if (!empty($_GET['id'])) {
    $one = db()->prepare('SELECT * FROM messages WHERE id = ?');
    $one->execute([(int) $_GET['id']]);
    $current = $one->fetch() ?: null;
    if ($current && $current['status'] === 'new') {
        db()->prepare("UPDATE messages SET status = 'read' WHERE id = ?")->execute([$current['id']]);
        $current['status'] = 'read';
    }
}
$perPage = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));
$count = db()->prepare("SELECT COUNT(*) FROM messages$whereSql");
$count->execute($params);
$total = (int) $count->fetchColumn();
$stmt = db()->prepare("SELECT id, name, email, topic, message, status, created_at FROM messages$whereSql ORDER BY created_at DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$list = $stmt->fetchAll();

$counts = db()->query('SELECT status, COUNT(*) c FROM messages GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$topics = db()->query('SELECT DISTINCT topic FROM messages ORDER BY topic')->fetchAll(PDO::FETCH_COLUMN);
$query = array_filter(['status' => $status, 'topic' => $topic, 'q' => $q]);

render_header('Messages');

if (setting('form_mode', 'formspree') !== 'builtin'): ?>
    <div class="alert alert-info"><i class="fas fa-circle-info"></i> The website's contact form currently sends messages to Formspree, so new messages won't appear here.
        <?php if (is_admin()): ?><a href="settings.php#forms">Switch to the built-in inbox</a> once the site is on PHP hosting.<?php else: ?>An administrator can switch to the built-in inbox in Settings.<?php endif; ?></div>
<?php endif; ?>

<div class="toolbar">
    <div class="tabs">
        <a href="messages.php" class="<?= $status === '' ? 'active' : '' ?>">All <em><?= array_sum($counts) ?></em></a>
        <?php foreach (STATUSES as $s): ?>
            <a href="?<?= h(http_build_query(array_merge($query, ['status' => $s]))) ?>" class="<?= $status === $s ? 'active' : '' ?>"><?= ucfirst($s) ?> <em><?= (int) ($counts[$s] ?? 0) ?></em></a>
        <?php endforeach; ?>
    </div>
    <form class="search" method="get">
        <?php if ($status): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
        <?php if ($topics): ?>
            <select name="topic" onchange="this.form.submit()" aria-label="Filter by topic">
                <option value="">All topics</option>
                <?php foreach ($topics as $t): ?><option <?= $topic === $t ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>
        <i class="fas fa-magnifying-glass"></i><input type="search" name="q" value="<?= h($q) ?>" placeholder="Search name, email or text">
    </form>
    <a href="?<?= h(http_build_query(array_merge($query, ['export' => 1]))) ?>" class="btn btn-light"><i class="fas fa-file-csv"></i> Export</a>
</div>

<div class="inbox <?= $current ? 'has-open' : '' ?>">
    <section class="panel inbox-list">
        <?php if (!$list): ?>
            <p class="empty"><i class="fas fa-inbox"></i> No messages<?= $where ? ' match these filters' : ' yet' ?>.</p>
        <?php endif; ?>
        <?php foreach ($list as $m): ?>
            <a href="?<?= h(http_build_query(array_merge($query, ['id' => $m['id'], 'page' => $page]))) ?>" class="inbox-item <?= $m['status'] === 'new' ? 'unread' : '' ?> <?= $current && (int) $current['id'] === (int) $m['id'] ? 'active' : '' ?>">
                <span class="avatar avatar-sm"><?= h(mb_strtoupper(mb_substr($m['name'], 0, 1))) ?></span>
                <span class="list-main">
                    <span class="inbox-top"><strong><?= h($m['name']) ?></strong><small><?= h(time_ago($m['created_at'])) ?></small></span>
                    <span class="inbox-topic"><?= h($m['topic']) ?> <?= status_pill($m['status']) ?></span>
                    <small class="inbox-snippet"><?= h(mb_strimwidth($m['message'], 0, 90, '…')) ?></small>
                </span>
            </a>
        <?php endforeach; ?>
        <?= paginate($total, $perPage, $page, $query) ?>
    </section>

    <section class="panel inbox-view">
        <?php if (!$current): ?>
            <div class="empty-state"><i class="fas fa-envelope-open"></i><h3>Select a message</h3><p>Choose a message from the list to read and reply.</p></div>
        <?php else:
            $replySubject = 'Re: ' . $current['topic'] . ' - Judes CBO';
            $replyBody = "Dear {$current['name']},\n\nThank you for contacting Judes CBO.\n\n\n\nKind regards,\nJudes CBO\n\n--- Your message (" . date('j M Y', strtotime($current['created_at'])) . ") ---\n" . $current['message'];
            $mailto = 'mailto:' . rawurlencode($current['email']) . '?subject=' . rawurlencode($replySubject) . '&body=' . rawurlencode($replyBody);
            $waNumber = preg_replace('/\D+/', '', $current['phone']);
            if (str_starts_with($waNumber, '0')) $waNumber = '254' . substr($waNumber, 1);
        ?>
            <a href="messages.php?<?= h(http_build_query($query)) ?>" class="back-link inbox-back"><i class="fas fa-arrow-left"></i> Back to inbox</a>
            <header class="message-head">
                <span class="avatar"><?= h(mb_strtoupper(mb_substr($current['name'], 0, 1))) ?></span>
                <div>
                    <h2><?= h($current['name']) ?></h2>
                    <p><a href="mailto:<?= h($current['email']) ?>"><?= h($current['email']) ?></a><?php if ($current['phone']): ?> · <a href="tel:<?= h($current['phone']) ?>"><?= h($current['phone']) ?></a><?php endif; ?></p>
                </div>
                <?= status_pill($current['status']) ?>
            </header>
            <div class="message-meta">
                <span><i class="fas fa-tag"></i> <?= h($current['topic']) ?></span>
                <span><i class="far fa-clock"></i> <?= h(date('l j F Y, g:i a', strtotime($current['created_at']))) ?></span>
                <?php if ($current['replied_at']): ?><span><i class="fas fa-reply"></i> Replied <?= h(time_ago($current['replied_at'])) ?></span><?php endif; ?>
            </div>
            <div class="message-body"><?= nl2br(h($current['message'])) ?></div>

            <div class="message-actions">
                <a href="<?= h($mailto) ?>" class="btn btn-primary" data-mark-replied="<?= (int) $current['id'] ?>"><i class="fas fa-reply"></i> Reply by email</a>
                <?php if (strlen($waNumber) >= 9): ?>
                    <a href="https://wa.me/<?= h($waNumber) ?>?text=<?= rawurlencode('Hello ' . $current['name'] . ', this is Judes CBO responding to your message.') ?>" target="_blank" rel="noopener" class="btn btn-wa" data-mark-replied="<?= (int) $current['id'] ?>"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                <?php endif; ?>
                <form method="post" class="inline-form">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $current['id'] ?>"><input type="hidden" name="action" value="status">
                    <select name="status" onchange="this.form.submit()" aria-label="Change status">
                        <?php foreach (STATUSES as $s): ?><option value="<?= $s ?>" <?= $current['status'] === $s ? 'selected' : '' ?>>Mark as <?= $s ?></option><?php endforeach; ?>
                    </select>
                </form>
                <form method="post" class="inline-form" data-confirm="Delete this message? This can't be undone.">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $current['id'] ?>"><input type="hidden" name="action" value="delete">
                    <button class="btn btn-danger-ghost"><i class="fas fa-trash"></i> Delete</button>
                </form>
            </div>

            <form method="post" class="note-form">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $current['id'] ?>"><input type="hidden" name="action" value="note">
                <label class="field">Internal note <small>Only visible to the team</small>
                    <textarea name="note" rows="3" placeholder="e.g. Called back on Tuesday, will visit the office"><?= h($current['internal_note'] ?? '') ?></textarea>
                </label>
                <button class="btn btn-light btn-sm"><i class="fas fa-floppy-disk"></i> Save note</button>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php render_footer(); ?>
