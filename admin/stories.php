<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/images.php';
require_once __DIR__ . '/includes/publisher.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $stmt = db()->prepare('SELECT * FROM stories WHERE id = ?');
    $stmt->execute([$id]);
    $story = $stmt->fetch();
    if ($story) {
        if ($action === 'delete') {
            db()->prepare('DELETE FROM stories WHERE id = ?')->execute([$id]);
            $retired = json_decode(setting('retired_story_files', '[]'), true) ?: [];
            $retired[] = 'blog-' . $story['slug'] . '.html';
            save_setting('retired_story_files', json_encode(array_values(array_unique($retired))));
            delete_upload($story['cover_image']);
            log_activity('story.delete', $story['title']);
            publish_with_feedback('Story deleted and removed from the website.');
        } elseif ($action === 'toggle') {
            $new = $story['status'] === 'published' ? 'draft' : 'published';
            db()->prepare('UPDATE stories SET status = ?, published_at = COALESCE(published_at, CURDATE()) WHERE id = ?')->execute([$new, $id]);
            log_activity('story.' . ($new === 'published' ? 'publish' : 'unpublish'), $story['title']);
            publish_with_feedback($new === 'published' ? 'Story published to the website.' : 'Story moved to drafts and hidden from the website.');
        }
    }
    redirect('stories.php');
}

$status = in_array($_GET['status'] ?? '', ['published', 'draft'], true) ? $_GET['status'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($status) { $where[] = 'status = ?'; $params[] = $status; }
if ($q !== '') { $where[] = '(title LIKE ? OR excerpt LIKE ? OR category LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
$sql = 'SELECT s.*, u.name AS author FROM stories s LEFT JOIN users u ON u.id = s.created_by' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY (status = "draft") DESC, published_at DESC, id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$stories = $stmt->fetchAll();
$counts = db()->query("SELECT status, COUNT(*) c FROM stories GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

render_header('Stories');
?>
<div class="toolbar">
    <div class="tabs">
        <a href="stories.php" class="<?= $status === '' ? 'active' : '' ?>">All <em><?= array_sum($counts) ?></em></a>
        <a href="?status=published" class="<?= $status === 'published' ? 'active' : '' ?>">Published <em><?= (int) ($counts['published'] ?? 0) ?></em></a>
        <a href="?status=draft" class="<?= $status === 'draft' ? 'active' : '' ?>">Drafts <em><?= (int) ($counts['draft'] ?? 0) ?></em></a>
    </div>
    <form class="search" method="get">
        <?php if ($status): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
        <i class="fas fa-magnifying-glass"></i><input type="search" name="q" value="<?= h($q) ?>" placeholder="Search stories">
    </form>
    <a href="story-edit.php" class="btn btn-primary"><i class="fas fa-plus"></i> New story</a>
</div>

<?php if (!$stories): ?>
    <div class="panel empty-state">
        <i class="fas fa-newspaper"></i>
        <h3><?= $q || $status ? 'No stories match' : 'No stories yet' ?></h3>
        <p>Stories appear on the website's Stories page and homepage.</p>
        <a href="story-edit.php" class="btn btn-primary">Write the first story</a>
    </div>
<?php else: ?>
    <div class="story-list">
        <?php foreach ($stories as $s): ?>
            <article class="story-row panel">
                <a href="story-edit.php?id=<?= (int) $s['id'] ?>" class="story-cover">
                    <?php if ($s['cover_image']): ?><img src="../<?= h($s['cover_image']) ?>" alt="" loading="lazy">
                    <?php else: ?><i class="fas <?= h(CATEGORY_ICONS[$s['category']] ?? 'fa-newspaper') ?>"></i><?php endif; ?>
                </a>
                <div class="story-info">
                    <div class="story-meta-row"><?= status_pill($s['status']) ?><span class="tag"><?= h($s['category']) ?></span>
                        <small><?= $s['published_at'] ? h(date('j M Y', strtotime($s['published_at']))) : 'No date' ?><?= $s['author'] ? ' · by ' . h($s['author']) : '' ?></small></div>
                    <h3><a href="story-edit.php?id=<?= (int) $s['id'] ?>"><?= h($s['title']) ?></a></h3>
                    <p><?= h(mb_strimwidth($s['excerpt'], 0, 160, '…')) ?></p>
                </div>
                <div class="row-actions">
                    <a href="story-edit.php?id=<?= (int) $s['id'] ?>" class="btn btn-light btn-sm"><i class="fas fa-pen"></i> Edit</a>
                    <?php if ($s['status'] === 'published'): ?>
                        <a href="../blog-<?= h($s['slug']) ?>.html" target="_blank" rel="noopener" class="btn btn-light btn-sm"><i class="fas fa-eye"></i> View</a>
                    <?php endif; ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="action" value="toggle">
                        <button class="btn btn-light btn-sm"><i class="fas <?= $s['status'] === 'published' ? 'fa-eye-slash' : 'fa-upload' ?>"></i> <?= $s['status'] === 'published' ? 'Unpublish' : 'Publish' ?></button></form>
                    <form method="post" data-confirm="Delete “<?= h($s['title']) ?>”? This removes it from the website and can't be undone."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="action" value="delete">
                        <button class="btn btn-danger-ghost btn-sm"><i class="fas fa-trash"></i> Delete</button></form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php render_footer(); ?>
