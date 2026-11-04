<?php
require __DIR__ . '/common.php';

api_guard('subscribe', 8, 10);

$email = mb_strtolower(clean_line((string) ($_POST['email'] ?? ''), 190));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_reply(false, 'Please enter a valid email address.', 422);
}

$prev = db()->prepare('SELECT status FROM subscribers WHERE email = ?');
$prev->execute([$email]);
$was = $prev->fetchColumn();
db()->prepare("INSERT INTO subscribers (email, source) VALUES (?, 'website') ON DUPLICATE KEY UPDATE status = 'active'")->execute([$email]);

// Welcome email for new (or returning) subscribers only, so repeat sign-ups don't send it again
if ($was !== 'active' && setting('newsletter_welcome', '1') === '1') {
    $body = setting('newsletter_welcome_body', NEWSLETTER_WELCOME_BODY)
        . "\n\n--\nYou're receiving this because $email signed up on judescbo.co.ke.\nUnsubscribe: " . unsubscribe_url($email);
    send_mail($email, setting('newsletter_welcome_subject', NEWSLETTER_WELCOME_SUBJECT), $body);
}
api_reply(true, "Thanks! You're subscribed.");
