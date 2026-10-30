<?php
declare(strict_types=1);

// Receives page views, time on page and clicks from js/main.js
const NO_SESSION = true;
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/analytics.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function done(array $reply = []): never
{
    echo json_encode((object) $reply);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    done();
}
// Only the website itself may report visits
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]) {
    http_response_code(403);
    done();
}
$ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
if (setting('analytics_on', '1') !== '1' || isset($_COOKIE['judes_staff']) || is_bot($ua)) done();

$in = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true);
if (!is_array($in)) done();

$db = db();
$visitor = visitor_id($ua);

switch ($in['t'] ?? '') {
    case 'view':
        $path = clean_page_path((string) ($in['p'] ?? ''));
        if ($path === null) done();
        // A generous daily cap stops one script from flooding the numbers
        $stmt = $db->prepare('SELECT COUNT(*) FROM page_views WHERE visitor = ? AND viewed_at >= CURDATE()');
        $stmt->execute([$visitor]);
        if ((int) $stmt->fetchColumn() >= 300) done();

        [$browser, $os] = parse_user_agent($ua);
        $source = strtolower(preg_replace('/[^a-z0-9._\-]/i', '', (string) ($in['s'] ?? '')) ?? '');
        $db->prepare('INSERT INTO page_views (visitor, path, referrer, source, device, browser, os) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
            $visitor, $path, referrer_host((string) ($in['r'] ?? '')), substr($source, 0, 60),
            device_type($ua, (int) ($in['w'] ?? 0)), $browser, $os,
        ]);
        $id = (int) $db->lastInsertId();
        $title = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($in['ti'] ?? '')) ?? ''), 0, 190);
        if ($title !== '') {
            $db->prepare('INSERT INTO page_titles (path, title) VALUES (?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title)')->execute([$path, $title]);
        }
        if (random_int(1, 200) === 1) purge_old_analytics();
        done(['id' => $id]);

    case 'leave':
        // Time spent on the page; only the visitor who opened it can update it
        $seconds = min(1800, max(0, (int) ($in['s'] ?? 0)));
        $db->prepare('UPDATE page_views SET seconds = GREATEST(seconds, ?) WHERE id = ? AND visitor = ? AND viewed_at > NOW() - INTERVAL 3 HOUR')
            ->execute([$seconds, (int) ($in['id'] ?? 0), $visitor]);
        done();

    case 'event':
        $name = (string) ($in['n'] ?? '');
        if (!in_array($name, EVENT_NAMES, true)) done();
        $stmt = $db->prepare('SELECT COUNT(*) FROM site_events WHERE visitor = ? AND created_at >= CURDATE()');
        $stmt->execute([$visitor]);
        if ((int) $stmt->fetchColumn() >= 100) done();
        $label = mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', (string) ($in['l'] ?? '')) ?? ''), 0, 190);
        $db->prepare('INSERT INTO site_events (visitor, name, label, path) VALUES (?, ?, ?, ?)')
            ->execute([$visitor, $name, $label, clean_page_path((string) ($in['p'] ?? '')) ?? '']);
        done();
}
done();
