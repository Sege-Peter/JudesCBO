<?php
/** Public page for leaving the newsletter from the link in our emails. Asks for a click so link scanners can't unsubscribe people. */
define('NO_SESSION', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$email = mb_strtolower(trim((string) ($_POST['e'] ?? $_GET['e'] ?? '')));
$token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$valid = filter_var($email, FILTER_VALIDATE_EMAIL) && unsubscribe_valid($email, $token);
$done = false;
if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = db()->prepare("UPDATE subscribers SET status = 'unsubscribed' WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 0) log_activity('subscriber.unsubscribe', $email . ' (from email link)', null);
    $done = true;
}
$site = rtrim((string) config('site_url'), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Newsletter | Judes CBO</title>
    <link rel="icon" href="../../jude-logo.png" type="image/png">
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box; background: #f4f6f9; font-family: Arial, Helvetica, sans-serif; color: #4a5568; }
        .card { max-width: 440px; width: 100%; background: #fff; border-radius: 18px; padding: 32px 28px; text-align: center; box-shadow: 0 10px 30px rgba(15,36,64,.08); }
        img { width: 72px; height: 72px; object-fit: contain; }
        h1 { color: #1a202c; font-size: 1.35rem; margin: 14px 0 8px; }
        p { line-height: 1.6; margin: 0 0 18px; }
        button, a.btn { display: inline-block; border: 0; border-radius: 12px; padding: 12px 22px; font-size: 1rem; font-weight: 700; cursor: pointer; text-decoration: none; }
        button { background: #2d5016; color: #fff; }
        a.btn { background: #edf2f7; color: #1a202c; margin-top: 6px; }
        strong { color: #1a202c; word-break: break-all; }
    </style>
</head>
<body>
<div class="card">
    <img src="../../images/jude-logo-192.webp" alt="Judes CBO">
    <?php if (!$valid): ?>
        <h1>This link doesn't work</h1>
        <p>It may be incomplete. Copy the whole link from the email, or contact us at <a href="mailto:<?= h(setting('org_email', 'info@judescbo.co.ke')) ?>"><?= h(setting('org_email', 'info@judescbo.co.ke')) ?></a> and we'll remove you.</p>
    <?php elseif ($done): ?>
        <h1>You're unsubscribed</h1>
        <p><strong><?= h($email) ?></strong> won't receive the Judes CBO newsletter any more. Sorry to see you go!</p>
        <a class="btn" href="<?= h($site) ?>/">Visit our website</a>
    <?php else: ?>
        <h1>Leave the newsletter?</h1>
        <p>Stop sending the Judes CBO newsletter to <strong><?= h($email) ?></strong>.</p>
        <form method="post">
            <input type="hidden" name="e" value="<?= h($email) ?>"><input type="hidden" name="t" value="<?= h($token) ?>">
            <button type="submit">Unsubscribe</button>
        </form>
        <a class="btn" href="<?= h($site) ?>/">No, keep me subscribed</a>
    <?php endif; ?>
</div>
</body>
</html>
