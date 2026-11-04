<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/analytics.php';
$user = require_login();
$db = db();

/* ---------- Settings (administrators) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!is_admin()) {
        error_page(403, 'Administrators only', 'Only administrators can change these settings.', 'fa-lock');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'settings') {
        save_setting('analytics_on', isset($_POST['analytics_on']) ? '1' : '0');
        $keep = (int) ($_POST['analytics_keep_months'] ?? 13);
        save_setting('analytics_keep_months', (string) (in_array($keep, [3, 6, 13, 25], true) ? $keep : 13));
        purge_old_analytics();
        log_activity('analytics.settings', setting('analytics_on') === '1' ? 'Visitor statistics on' : 'Visitor statistics off');
        flash('success', 'Analytics settings saved.');
    } elseif ($action === 'clear') {
        $db->exec('DELETE FROM page_views');
        $db->exec('DELETE FROM site_events');
        log_activity('analytics.clear', 'All visitor statistics deleted');
        flash('success', 'All visitor statistics have been deleted.');
    }
    redirect('analytics');
}

/* ---------- Period ---------- */
const RANGES = [
    'today' => ['Today', 1],
    '7d' => ['7 days', 7],
    '30d' => ['30 days', 30],
    '90d' => ['90 days', 90],
    '12m' => ['12 months', 365],
];
$range = isset(RANGES[$_GET['range'] ?? '']) ? $_GET['range'] : '30d';
$now = new DateTimeImmutable();
if ($range === '12m') {
    $start = $now->modify('first day of this month midnight')->modify('-11 months');
    $prevStart = $start->modify('-12 months');
    $prevEnd = $now->modify('-12 months');
} else {
    $days = RANGES[$range][1];
    $start = $now->setTime(0, 0)->modify('-' . ($days - 1) . ' days');
    $prevStart = $start->modify("-$days days");
    $prevEnd = $now->modify("-$days days");
}
$from = $start->format('Y-m-d H:i:s');
$to = $now->format('Y-m-d H:i:s');

function query_all(string $sql, array $params): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Visitors, views, pages per visit, bounce rate and time on page between two moments. */
function summary(string $from, string $to): array
{
    $row = query_all("SELECT COUNT(*) AS visits, COALESCE(SUM(views), 0) AS views, COALESCE(SUM(views = 1), 0) AS bounces
        FROM (SELECT COUNT(*) AS views FROM page_views WHERE viewed_at BETWEEN ? AND ? GROUP BY visitor, DATE(viewed_at)) v", [$from, $to])[0];
    $time = query_all('SELECT AVG(seconds) FROM page_views WHERE viewed_at BETWEEN ? AND ? AND seconds > 0', [$from, $to])[0];
    $visits = (int) $row['visits'];
    return [
        'visitors' => $visits,
        'views' => (int) $row['views'],
        'per_visit' => $visits ? $row['views'] / $visits : 0,
        'bounce' => $visits ? $row['bounces'] / $visits * 100 : 0,
        'seconds' => (int) round((float) array_values($time)[0]),
    ];
}

$cur = summary($from, $to);
$prev = summary($prevStart->format('Y-m-d H:i:s'), $prevEnd->format('Y-m-d H:i:s'));
$liveNow = (int) query_all('SELECT COUNT(DISTINCT visitor) AS n FROM page_views WHERE viewed_at > NOW() - INTERVAL 5 MINUTE', [])[0]['n'];

/* ---------- Visitors over time ---------- */
[$fmt, $step, $labelFmt, $tipFmt] = match ($range) {
    'today' => ['%Y-%m-%d %H', '+1 hour', 'H:00', 'H:00'],
    '12m' => ['%Y-%m', '+1 month', 'M', 'F Y'],
    default => ['%Y-%m-%d', '+1 day', 'j M', 'D j M'],
};
$phpKey = ['%Y-%m-%d %H' => 'Y-m-d H', '%Y-%m' => 'Y-m', '%Y-%m-%d' => 'Y-m-d'][$fmt];
$series = [];
for ($t = $start; $t <= $now; $t = $t->modify($step)) {
    $series[$t->format($phpKey)] = ['label' => $t->format($labelFmt), 'tip' => $t->format($tipFmt), 'visitors' => 0, 'views' => 0];
}
foreach (query_all("SELECT DATE_FORMAT(viewed_at, '$fmt') AS k, COUNT(DISTINCT visitor, DATE(viewed_at)) AS visitors, COUNT(*) AS views
    FROM page_views WHERE viewed_at BETWEEN ? AND ? GROUP BY k", [$from, $to]) as $r) {
    if (isset($series[$r['k']])) {
        $series[$r['k']]['visitors'] = (int) $r['visitors'];
        $series[$r['k']]['views'] = (int) $r['views'];
    }
}
$series = array_values($series);

/* ---------- Breakdowns ---------- */
$pages = query_all('SELECT p.path, t.title, COUNT(*) AS views, COUNT(DISTINCT p.visitor, DATE(p.viewed_at)) AS visitors, AVG(NULLIF(p.seconds, 0)) AS secs
    FROM page_views p LEFT JOIN page_titles t ON t.path = p.path
    WHERE p.viewed_at BETWEEN ? AND ? GROUP BY p.path, t.title ORDER BY views DESC LIMIT 10', [$from, $to]);

$sources = [];
foreach (query_all('SELECT referrer, source, COUNT(DISTINCT visitor, DATE(viewed_at)) AS n FROM page_views WHERE viewed_at BETWEEN ? AND ? GROUP BY referrer, source', [$from, $to]) as $r) {
    $label = source_label($r['referrer'], $r['source']);
    $sources[$label] = ($sources[$label] ?? 0) + (int) $r['n'];
}
arsort($sources);
$sources = array_slice($sources, 0, 8, true);

function breakdown(string $column, string $from, string $to): array
{
    $out = [];
    foreach (query_all("SELECT $column AS k, COUNT(DISTINCT visitor, DATE(viewed_at)) AS n FROM page_views WHERE viewed_at BETWEEN ? AND ? GROUP BY $column ORDER BY n DESC LIMIT 6", [$from, $to]) as $r) {
        $out[$r['k'] ?: 'Other'] = (int) $r['n'];
    }
    return $out;
}
$devices = [];
foreach (breakdown('device', $from, $to) as $k => $v) $devices[ucfirst($k)] = $v;
$browsers = breakdown('browser', $from, $to);
$systems = breakdown('os', $from, $to);

$events = array_fill_keys(EVENT_NAMES, 0);
foreach (query_all('SELECT name, COUNT(*) AS n FROM site_events WHERE created_at BETWEEN ? AND ? GROUP BY name', [$from, $to]) as $r) {
    $events[$r['name']] = (int) $r['n'];
}
$outbound = query_all("SELECT label, COUNT(*) AS n FROM site_events WHERE name = 'outbound' AND created_at BETWEEN ? AND ? GROUP BY label ORDER BY n DESC LIMIT 6", [$from, $to]);

// What visits led to, from the dashboard's own records
$fromDate = $start->format('Y-m-d');
$results = [
    'messages' => (int) query_all('SELECT COUNT(*) AS n FROM messages WHERE created_at BETWEEN ? AND ?', [$from, $to])[0]['n'],
    'subscribers' => (int) query_all('SELECT COUNT(*) AS n FROM subscribers WHERE created_at BETWEEN ? AND ?', [$from, $to])[0]['n'],
    'donations' => query_all('SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM donations WHERE received_at >= ?', [$fromDate])[0],
];

/* ---------- CSV export ---------- */
if (isset($_GET['export'])) {
    send_csv('website-visitors-' . $range . '-' . date('Y-m-d') . '.csv', ['Period', 'Visitors', 'Page views'],
        array_map(fn($p) => [$p['tip'], $p['visitors'], $p['views']], $series));
}

/* ---------- Helpers for the view ---------- */
function change_badge(float $now, float $before, bool $lowerIsBetter = false): string
{
    if ($before <= 0) return $now > 0 ? '<span class="delta delta-flat">New</span>' : '';
    $pct = ($now - $before) / $before * 100;
    if (abs($pct) < 1) return '<span class="delta delta-flat" title="About the same as the previous period">±0%</span>';
    $good = $lowerIsBetter ? $pct < 0 : $pct > 0;
    return '<span class="delta ' . ($good ? 'delta-up' : 'delta-down') . '" title="Compared with the previous period"><i class="fas fa-arrow-' . ($pct > 0 ? 'up' : 'down') . '"></i> ' . abs(round($pct)) . '%</span>';
}

function duration(int $seconds): string
{
    if ($seconds <= 0) return '—';
    return $seconds < 60 ? $seconds . 's' : intdiv($seconds, 60) . 'm ' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT) . 's';
}

function share_list(array $items, string $empty): void
{
    if (!$items) {
        echo '<p class="empty-sm">' . h($empty) . '</p>';
        return;
    }
    $total = array_sum($items) ?: 1;
    $max = max($items) ?: 1;
    echo '<ul class="hbars">';
    foreach ($items as $label => $n) {
        echo '<li><span class="hbar-label">' . h((string) $label) . '<strong>' . number_format($n) . ' <small>' . round($n / $total * 100) . '%</small></strong></span>'
            . '<span class="hbar"><span style="width: ' . round($n / $max * 100) . '%"></span></span></li>';
    }
    echo '</ul>';
}

// Chart scale: a round top value split into 4 gridlines
$peak = max(array_column($series, 'visitors') ?: [0]);
$magnitude = 10 ** max(0, floor(log10(max($peak, 1))));
$top = max(4, (int) (ceil($peak / $magnitude * 2) / 2 * $magnitude));
if ($top % 4) $top += 4 - $top % 4;
$n = count($series);
$x = fn(int $i) => $n > 1 ? round($i / ($n - 1) * 1000, 2) : 500;
$y = fn(int $v) => round(240 - $v / $top * 230, 2);
$points = [];
foreach ($series as $i => $p) $points[] = $x($i) . ',' . $y($p['visitors']);
$line = 'M' . implode(' L', $points);
$area = $line . ' L' . $x($n - 1) . ',240 L' . $x(0) . ',240 Z';
$labelEvery = max(1, (int) ceil($n / 8));

$eventInfo = [
    'call' => ['Phone calls', 'fa-phone', 'icon-blue'],
    'whatsapp' => ['WhatsApp chats', 'fa-brands fa-whatsapp', 'icon-green'],
    'email' => ['Emails', 'fa-envelope', 'icon-purple'],
    'donate' => ['Donate clicks', 'fa-hand-holding-heart', 'icon-red'],
    'copy' => ['M-Pesa numbers copied', 'fa-copy', 'icon-gold'],
    'outbound' => ['Links to other sites', 'fa-arrow-up-right-from-square', 'icon-blue'],
];

render_header('Analytics');
?>
<div class="toolbar">
    <div class="tabs tabs-scroll" role="tablist" aria-label="Period">
        <?php foreach (RANGES as $key => [$label]): ?>
            <a href="?range=<?= $key ?>" class="<?= $range === $key ? 'active' : '' ?>" <?= $range === $key ? 'aria-current="page"' : '' ?>><?= h($label) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="toolbar-actions">
        <span class="live-pill" title="People on the website in the last 5 minutes"><span class="online-dot"></span> <?= $liveNow ?> on the site now</span>
        <a href="?range=<?= $range ?>&amp;export=1" class="btn btn-light btn-sm"><i class="fas fa-file-csv"></i> Export</a>
    </div>
</div>

<?php if (setting('analytics_on', '1') !== '1'): ?>
    <div class="alert alert-info"><i class="fas fa-pause"></i><span>Visitor statistics are turned off, so no new visits are being counted.<?= is_admin() ? ' Turn them back on at the bottom of this page.' : '' ?></span></div>
<?php endif; ?>

<section class="stat-grid stat-grid-analytics">
    <div class="stat-card">
        <span class="stat-icon icon-green"><i class="fas fa-user-group"></i></span>
        <span class="stat-body"><strong><?= number_format($cur['visitors']) ?></strong><small>Visitors <?= change_badge($cur['visitors'], $prev['visitors']) ?></small></span>
    </div>
    <div class="stat-card">
        <span class="stat-icon icon-blue"><i class="fas fa-eye"></i></span>
        <span class="stat-body"><strong><?= number_format($cur['views']) ?></strong><small>Page views <?= change_badge($cur['views'], $prev['views']) ?></small></span>
    </div>
    <div class="stat-card">
        <span class="stat-icon icon-purple"><i class="fas fa-layer-group"></i></span>
        <span class="stat-body"><strong><?= number_format($cur['per_visit'], 1) ?></strong><small>Pages per visit <?= change_badge($cur['per_visit'], $prev['per_visit']) ?></small></span>
    </div>
    <div class="stat-card">
        <span class="stat-icon icon-gold"><i class="fas fa-stopwatch"></i></span>
        <span class="stat-body"><strong><?= duration($cur['seconds']) ?></strong><small>Avg. time on a page <?= change_badge($cur['seconds'], $prev['seconds']) ?></small></span>
    </div>
    <div class="stat-card">
        <span class="stat-icon icon-red"><i class="fas fa-person-walking-arrow-right"></i></span>
        <span class="stat-body"><strong><?= round($cur['bounce']) ?>%</strong><small>Left after one page <?= change_badge($cur['bounce'], $prev['bounce'], true) ?></small></span>
    </div>
</section>

<section class="panel">
    <header class="panel-head">
        <h3>Visitors, <?= h(strtolower(RANGES[$range][0])) ?></h3>
        <small class="muted"><?= number_format($cur['visitors']) ?> visitors · <?= number_format($cur['views']) ?> page views</small>
    </header>
    <?php if (!$cur['views']): ?>
        <p class="empty"><i class="fas fa-chart-line"></i> No visits counted in this period yet. Visits are counted once the website is on PHP hosting with this dashboard; staff who are logged in aren't counted.</p>
    <?php else: ?>
        <figure class="line-chart" data-points='<?= h(json_encode(array_map(fn($p) => [$p['tip'], $p['visitors'], $p['views']], $series))) ?>'>
            <div class="lc-plot">
                <?php for ($g = 0; $g <= 4; $g++): ?>
                    <span class="lc-grid" style="top: <?= round((240 - $top * $g / 4 / $top * 230) / 250 * 100, 2) ?>%"><em><?= number_format($top * $g / 4) ?></em></span>
                <?php endfor; ?>
                <svg viewBox="0 0 1000 250" preserveAspectRatio="none" aria-hidden="true">
                    <defs><linearGradient id="lc-fill" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#2f855a" stop-opacity=".28"/><stop offset="1" stop-color="#2f855a" stop-opacity="0"/></linearGradient></defs>
                    <path d="<?= $area ?>" fill="url(#lc-fill)"/>
                    <path d="<?= $line ?>" fill="none" stroke="#2f855a" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
                </svg>
                <span class="lc-cursor" hidden></span>
                <span class="lc-dot" hidden></span>
                <div class="lc-tip" role="status" hidden></div>
            </div>
            <div class="lc-x" aria-hidden="true">
                <?php foreach ($series as $i => $p): if ($i % $labelEvery) continue; ?>
                    <span style="left: <?= round($x($i) / 10, 2) ?>%"><?= h($p['label']) ?></span>
                <?php endforeach; ?>
            </div>
            <figcaption class="sr-only">Visitors per <?= $range === 'today' ? 'hour' : ($range === '12m' ? 'month' : 'day') ?>. The table below the chart lists the busiest pages.</figcaption>
        </figure>
        <details class="lc-table">
            <summary>Show as a table</summary>
            <div class="table-wrap"><table class="table">
                <thead><tr><th><?= $range === 'today' ? 'Hour' : ($range === '12m' ? 'Month' : 'Day') ?></th><th>Visitors</th><th>Page views</th></tr></thead>
                <tbody><?php foreach (array_reverse($series) as $p): ?><tr><td><?= h($p['tip']) ?></td><td><?= $p['visitors'] ?></td><td><?= $p['views'] ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
        </details>
    <?php endif; ?>
</section>

<div class="grid-2">
    <section class="panel">
        <header class="panel-head"><h3>Most visited pages</h3></header>
        <?php if (!$pages): ?>
            <p class="empty-sm">No page views yet.</p>
        <?php else: $maxViews = max(array_column($pages, 'views')); ?>
            <ul class="hbars page-bars">
                <?php foreach ($pages as $p): ?>
                    <li>
                        <span class="hbar-label"><span class="page-name" title="<?= h($p['path']) ?>"><?= h(page_label($p['path'], $p['title'])) ?> <small><?= h($p['path']) ?></small></span><strong><?= number_format((int) $p['views']) ?></strong></span>
                        <span class="hbar"><span style="width: <?= round($p['views'] / $maxViews * 100) ?>%"></span></span>
                        <small class="muted"><?= number_format((int) $p['visitors']) ?> visitor<?= (int) $p['visitors'] === 1 ? '' : 's' ?> · <?= duration((int) round((float) $p['secs'])) ?> avg.</small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel">
        <header class="panel-head"><h3>Where visitors come from</h3></header>
        <?php share_list($sources, 'No visits yet.'); ?>
        <p class="hint">Tip: add <code>?ref=facebook</code> (or whatsapp, poster…) to links you share, and those visits show under that name.</p>
    </section>
</div>

<section class="panel">
    <header class="panel-head"><h3>What visitors did</h3><small class="muted">Taps and clicks on the website</small></header>
    <div class="action-grid">
        <?php foreach ($eventInfo as $key => [$label, $icon, $color]): ?>
            <div class="action-tile">
                <span class="stat-icon icon-sm <?= $color ?>"><i class="<?= str_contains($icon, ' ') ? $icon : 'fas ' . $icon ?>"></i></span>
                <span><strong><?= number_format($events[$key]) ?></strong><small><?= h($label) ?></small></span>
            </div>
        <?php endforeach; ?>
        <a class="action-tile" href="messages">
            <span class="stat-icon icon-sm icon-purple"><i class="fas fa-inbox"></i></span>
            <span><strong><?= number_format($results['messages']) ?></strong><small>Messages sent</small></span>
        </a>
        <a class="action-tile" href="subscribers">
            <span class="stat-icon icon-sm icon-blue"><i class="fas fa-envelope-open-text"></i></span>
            <span><strong><?= number_format($results['subscribers']) ?></strong><small>New subscribers</small></span>
        </a>
        <a class="action-tile" href="donations">
            <span class="stat-icon icon-sm icon-green"><i class="fas fa-hand-holding-dollar"></i></span>
            <span><strong><?= h(format_kes($results['donations']['total'])) ?></strong><small><?= (int) $results['donations']['n'] ?> donation<?= (int) $results['donations']['n'] === 1 ? '' : 's' ?> recorded</small></span>
        </a>
    </div>
    <?php if ($outbound): ?>
        <h4 class="section-label">Links followed to other websites</h4>
        <ul class="chip-list">
            <?php foreach ($outbound as $o): ?><li><?= h($o['label']) ?> <em><?= (int) $o['n'] ?></em></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<div class="an-grid-3">
    <section class="panel"><header class="panel-head"><h3>Devices</h3></header><?php share_list($devices, 'No visits yet.'); ?></section>
    <section class="panel"><header class="panel-head"><h3>Browsers</h3></header><?php share_list($browsers, 'No visits yet.'); ?></section>
    <section class="panel"><header class="panel-head"><h3>Operating systems</h3></header><?php share_list($systems, 'No visits yet.'); ?></section>
</div>

<section class="panel">
    <header class="panel-head"><h3><i class="fas fa-shield-halved"></i> Privacy &amp; settings</h3></header>
    <p class="muted">Visits are counted without cookies and nothing personal is kept: each visitor is a code made from their connection that changes every day, so a "visitor" means one person on one day. Search engine bots and logged-in staff aren't counted.</p>
    <?php if (is_admin()): ?>
        <form method="post" class="analytics-settings">
            <?= csrf_field() ?><input type="hidden" name="action" value="settings">
            <label class="check"><input type="checkbox" name="analytics_on" value="1" <?= setting('analytics_on', '1') === '1' ? 'checked' : '' ?>> Count website visits</label>
            <label class="field">Keep statistics for
                <select name="analytics_keep_months">
                    <?php foreach ([3 => '3 months', 6 => '6 months', 13 => '13 months', 25 => '2 years'] as $m => $l): ?>
                        <option value="<?= $m ?>" <?= (int) setting('analytics_keep_months', '13') === $m ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary"><i class="fas fa-check"></i> Save</button>
        </form>
        <form method="post" data-confirm="Delete all visitor statistics? This can't be undone." class="analytics-clear">
            <?= csrf_field() ?><input type="hidden" name="action" value="clear">
            <button class="btn btn-danger-ghost"><i class="fas fa-trash"></i> Delete all statistics</button>
        </form>
    <?php endif; ?>
</section>
<?php render_footer(['assets/analytics.js']); ?>
