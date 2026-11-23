<?php
// Streams an applicant's document to signed-in staff. The files live outside the website folder.
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/applications.php';
require_login();

$stmt = db()->prepare('SELECT id, ref_code, full_name, files FROM job_applications WHERE id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$app = $stmt->fetch();
$slot = (string) ($_GET['f'] ?? '');
$file = $app ? (json_decode($app['files'] ?: '{}', true)[$slot] ?? null) : null;
$path = $file && !str_contains((string) $file['path'], '..') ? applications_dir() . '/' . $file['path'] : '';
if (!$file || !isset(APP_FILES[$slot]) || !is_file($path)) {
    error_page(404, 'Document not found', 'This document is no longer available.', 'fa-file-circle-xmark');
}

log_activity('application.file', "{$app['ref_code']} {$app['full_name']}: " . APP_FILES[$slot][0]);
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$inline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true);
$download = preg_replace('/[^A-Za-z0-9._-]+/', '-', $app['ref_code'] . '-' . $app['full_name'] . '-' . $slot) . '.' . $ext;

header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $download . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
