<?php
require __DIR__ . '/common.php';

api_guard('subscribe', 8, 10);

$email = mb_strtolower(clean_line((string) ($_POST['email'] ?? ''), 190));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_reply(false, 'Please enter a valid email address.', 422);
}

db()->prepare("INSERT INTO subscribers (email, source) VALUES (?, 'website') ON DUPLICATE KEY UPDATE status = 'active'")->execute([$email]);
api_reply(true, "Thanks! You're subscribed.");
