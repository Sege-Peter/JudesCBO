<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function api_reply(bool $ok, string $message, int $code = 200): never
{
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

function api_guard(string $endpoint, int $limit, int $minutes): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_reply(false, 'Method not allowed.', 405);
    }
    // Bots fill in the hidden "_gotcha" field; pretend success so they move on
    if (!empty($_POST['_gotcha'])) {
        api_reply(true, 'Thank you!');
    }
    $ip = client_ip();
    db()->exec("DELETE FROM api_hits WHERE hit_at < NOW() - INTERVAL 1 DAY");
    $stmt = db()->prepare('SELECT COUNT(*) FROM api_hits WHERE ip = ? AND endpoint = ? AND hit_at > NOW() - INTERVAL ' . (int) $minutes . ' MINUTE');
    $stmt->execute([$ip, $endpoint]);
    if ((int) $stmt->fetchColumn() >= $limit) {
        api_reply(false, 'Too many requests. Please try again a little later.', 429);
    }
    db()->prepare('INSERT INTO api_hits (ip, endpoint) VALUES (?, ?)')->execute([$ip, $endpoint]);
}

function clean_line(string $value, int $max): string
{
    // Single-line fields: no line breaks (also blocks email header injection)
    return mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', $value) ?? ''), 0, $max);
}
