<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_admin();

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$userId = (int) ($_GET['user'] ?? 0);
$where = $userId ? ' WHERE a.user_id = ' . $userId : '';
$total = (int) db()->query("SELECT COUNT(*) FROM activity_log a$where")->fetchColumn();
$rows = db()->query("SELECT a.*, u.name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id$where ORDER BY a.created_at DESC, a.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage))->fetchAll();
$users = db()->query('SELECT id, name FROM users ORDER BY name')->fetchAll();

$icons = [
    'login' => 'fa-right-to-bracket', 'logout' => 'fa-right-from-bracket', 'story' => 'fa-newspaper', 'gallery' => 'fa-images',
    'message' => 'fa-inbox', 'subscriber' => 'fa-envelope-open-text', 'donation' => 'fa-hand-holding-dollar',
    'user' => 'fa-users-gear', 'settings' => 'fa-sliders', 'site' => 'fa-rotate', 'install' => 'fa-flag-checkered', 'profile' => 'fa-user',
];

render_header('Activity log');
?>
<section class="panel">
    <form method="get" class="filter-bar">
        <label>Person<select name="user" onchange="this.form.submit()"><option value="0">Everyone</option>
            <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $userId === (int) $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
        </select></label>
    </form>
    <?php if (!$rows): ?><p class="empty">No activity yet.</p><?php endif; ?>
    <ol class="timeline">
        <?php foreach ($rows as $r): $group = explode('.', $r['action'])[0]; ?>
            <li>
                <span class="timeline-icon"><i class="fas <?= h($icons[$group] ?? 'fa-circle') ?>"></i></span>
                <div>
                    <p><strong><?= h($r['name'] ?? 'Website visitor') ?></strong> <span class="muted"><?= h(str_replace(['.', '_'], ' ', $r['action'])) ?></span><?= $r['details'] ? ': ' . h($r['details']) : '' ?></p>
                    <small class="muted"><?= h(date('j M Y, g:i a', strtotime($r['created_at']))) ?> · <?= h($r['ip']) ?></small>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
    <?= paginate($total, $perPage, $page, array_filter(['user' => $userId])) ?>
</section>
<?php render_footer(); ?>
