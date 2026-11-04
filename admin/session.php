<?php
// Session keep-alive for the inactivity timer: a signed-in POST refreshes the session
require __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('{"ok":false}');
}
require_csrf();
echo json_encode(['ok' => current_user() !== null]);
