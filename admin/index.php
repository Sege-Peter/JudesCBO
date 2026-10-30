<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/publisher.php';
$user = require_login();

// Keep date-based pages fresh (finished events move to "Past") with a rebuild once a day
if (substr(setting('last_published'), 0, 10) !== date('Y-m-d')) {
    try { publish_site(); } catch (Throwable $e) { /* shown on next manual rebuild */ }
}

$db = db();
$stats = [
    'new_messages' => (int) $db->query("SELECT COUNT(*) FROM messages WHERE status = 'new'")->fetchColumn(),
    'stories' => (int) $db->query("SELECT COUNT(*) FROM stories WHERE status = 'published'")->fetchColumn(),
    'drafts' => (int) $db->query("SELECT COUNT(*) FROM stories WHERE status = 'draft'")->fetchColumn(),
    'photos' => (int) $db->query('SELECT COUNT(*) FROM gallery')->fetchColumn(),
    'subscribers' => (int) $db->query("SELECT COUNT(*) FROM subscribers WHERE status = 'active'")->fetchColumn(),
    'month_total' => (float) $db->query("SELECT COALESCE(SUM(amount), 0) FROM donations WHERE received_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn(),
    'month_count' => (int) $db->query("SELECT COUNT(*) FROM donations WHERE received_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn(),
    'visitors_today' => (int) $db->query('SELECT COUNT(DISTINCT visitor) FROM page_views WHERE viewed_at >= CURDATE()')->fetchColumn(),
    'views_today' => (int) $db->query('SELECT COUNT(*) FROM page_views WHERE viewed_at >= CURDATE()')->fetchColumn(),
];

// Donations for the last 6 months, including months with nothing
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("first day of -$i month"));
    $months[$key] = 0.0;
}
$stmt = $db->query("SELECT DATE_FORMAT(received_at, '%Y-%m') AS m, SUM(amount) AS total FROM donations WHERE received_at >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH, '%Y-%m-01') GROUP BY m");
foreach ($stmt as $row) {
    if (isset($months[$row['m']])) $months[$row['m']] = (float) $row['total'];
}
$maxMonth = max($months) ?: 1;

$recentMessages = $db->query('SELECT id, name, topic, message, status, created_at FROM messages ORDER BY created_at DESC LIMIT 5')->fetchAll();
$recentDonations = $db->query('SELECT donor_name, amount, cause, received_at FROM donations ORDER BY received_at DESC, id DESC LIMIT 5')->fetchAll();
$lastPublished = setting('last_published');
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

render_header('Dashboard');
?>
<section class="welcome">
    <div>
        <h2><?= h($greeting) ?>, <?= h(explode(' ', $user['name'])[0]) ?></h2>
        <p>Here's what's happening with the Judes CBO website.<?php if ($lastPublished): ?> Website last updated <?= h(time_ago($lastPublished)) ?>.<?php endif; ?></p>
    </div>
    <div class="welcome-actions">
        <a href="story-edit.php" class="btn btn-primary"><i class="fas fa-plus"></i> New story</a>
        <a href="gallery.php#upload" class="btn btn-light"><i class="fas fa-cloud-arrow-up"></i> Upload photos</a>
    </div>
</section>

<section class="stat-grid stat-grid-home">
    <a href="analytics.php?range=today" class="stat-card">
        <span class="stat-icon icon-blue"><i class="fas fa-chart-line"></i></span>
        <span class="stat-body"><strong><?= number_format($stats['visitors_today']) ?></strong><small>Visitors today · <?= number_format($stats['views_today']) ?> page view<?= $stats['views_today'] === 1 ? '' : 's' ?></small></span>
    </a>
    <a href="messages.php?status=new" class="stat-card">
        <span class="stat-icon icon-red"><i class="fas fa-inbox"></i></span>
        <span class="stat-body"><strong><?= $stats['new_messages'] ?></strong><small>New messages</small></span>
    </a>
    <a href="donations.php" class="stat-card">
        <span class="stat-icon icon-green"><i class="fas fa-hand-holding-dollar"></i></span>
        <span class="stat-body"><strong><?= h(format_kes($stats['month_total'])) ?></strong><small><?= $stats['month_count'] ?> donation<?= $stats['month_count'] === 1 ? '' : 's' ?> this month</small></span>
    </a>
    <a href="stories.php" class="stat-card">
        <span class="stat-icon icon-blue"><i class="fas fa-newspaper"></i></span>
        <span class="stat-body"><strong><?= $stats['stories'] ?></strong><small>Published stories<?= $stats['drafts'] ? ' · ' . $stats['drafts'] . ' draft' . ($stats['drafts'] === 1 ? '' : 's') : '' ?></small></span>
    </a>
    <a href="gallery.php" class="stat-card">
        <span class="stat-icon icon-gold"><i class="fas fa-images"></i></span>
        <span class="stat-body"><strong><?= $stats['photos'] ?></strong><small>Gallery photos</small></span>
    </a>
    <a href="subscribers.php" class="stat-card">
        <span class="stat-icon icon-purple"><i class="fas fa-envelope-open-text"></i></span>
        <span class="stat-body"><strong><?= $stats['subscribers'] ?></strong><small>Newsletter subscribers</small></span>
    </a>
</section>

<div class="grid-2">
    <section class="panel">
        <header class="panel-head">
            <h3>Donations, last 6 months</h3>
            <a href="donations.php" class="link">View all</a>
        </header>
        <div class="bar-chart" role="img" aria-label="Donations per month">
            <?php foreach ($months as $m => $total): ?>
                <div class="bar-col">
                    <span class="bar-value"><?= $total ? h(number_format($total)) : '' ?></span>
                    <span class="bar" style="--h: <?= round($total / $maxMonth * 100) ?>%"></span>
                    <span class="bar-label"><?= h(date('M', strtotime($m . '-01'))) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <header class="panel-head">
            <h3>Recent messages</h3>
            <a href="messages.php" class="link">Open inbox</a>
        </header>
        <?php if (!$recentMessages): ?>
            <p class="empty"><i class="fas fa-inbox"></i> No messages yet. Messages from the website's contact form will appear here once the built-in inbox is turned on in Settings.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($recentMessages as $m): ?>
                    <li>
                        <a href="messages.php?id=<?= (int) $m['id'] ?>" class="list-row">
                            <span class="avatar avatar-sm"><?= h(mb_strtoupper(mb_substr($m['name'], 0, 1))) ?></span>
                            <span class="list-main"><strong><?= h($m['name']) ?></strong><small><?= h($m['topic']) ?> · <?= h(mb_strimwidth($m['message'], 0, 60, '…')) ?></small></span>
                            <span class="list-side"><?= status_pill($m['status']) ?><small><?= h(time_ago($m['created_at'])) ?></small></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<div class="grid-2">
    <section class="panel">
        <header class="panel-head">
            <h3>Latest donations</h3>
            <a href="donations.php#add" class="link">Record donation</a>
        </header>
        <?php if (!$recentDonations): ?>
            <p class="empty"><i class="fas fa-hand-holding-dollar"></i> No donations recorded yet. When a donor sends their M-Pesa code, record it under Donations.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($recentDonations as $d): ?>
                    <li class="list-row">
                        <span class="stat-icon icon-green icon-sm"><i class="fas fa-heart"></i></span>
                        <span class="list-main"><strong><?= h($d['donor_name'] ?: 'Anonymous') ?></strong><small><?= h($d['cause']) ?></small></span>
                        <span class="list-side"><strong><?= h(format_kes($d['amount'])) ?></strong><small><?= h(date('j M', strtotime($d['received_at']))) ?></small></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel">
        <header class="panel-head"><h3>Quick actions</h3></header>
        <div class="quick-actions">
            <a href="story-edit.php"><i class="fas fa-pen-to-square"></i><span>Write a story</span></a>
            <a href="gallery.php#upload"><i class="fas fa-cloud-arrow-up"></i><span>Upload photos</span></a>
            <a href="donations.php#add"><i class="fas fa-receipt"></i><span>Record donation</span></a>
            <a href="subscribers.php?export=1"><i class="fas fa-file-csv"></i><span>Export subscribers</span></a>
            <?php if (is_admin()): ?>
                <a href="users.php"><i class="fas fa-user-plus"></i><span>Add a user</span></a>
                <a href="settings.php"><i class="fas fa-rotate"></i><span>Update website</span></a>
            <?php endif; ?>
        </div>
    </section>
</div>
<?php render_footer(); ?>
