<?php
require __DIR__ . '/common.php';

api_guard('contact', 5, 10);

$name = clean_line((string) ($_POST['name'] ?? ''), 120);
$email = clean_line((string) ($_POST['email'] ?? ''), 190);
$phone = clean_line((string) ($_POST['phone'] ?? ''), 40);
$topic = clean_line((string) ($_POST['reason'] ?? 'General enquiry'), 60) ?: 'General enquiry';
$message = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 5000);

if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_reply(false, 'Please fill in your name, a valid email and your message.', 422);
}

db()->prepare('INSERT INTO messages (name, email, phone, topic, message, ip) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$name, $email, $phone, $topic, $message, client_ip()]);

$notify = setting('notify_email');
if ($notify !== '' && filter_var($notify, FILTER_VALIDATE_EMAIL)) {
    $subject = 'New website message: ' . $topic;
    $body = "You have a new message on the Judes CBO website.\n\n"
        . "From: $name <$email>\n" . ($phone ? "Phone: $phone\n" : '') . "Topic: $topic\n\n$message\n\n"
        . "Reply from the dashboard: " . rtrim((string) config('site_url'), '/') . "/admin/messages.php\n";
    send_mail($notify, $subject, $body, $email);
}

api_reply(true, "Thank you! Your message has been sent. We'll get back to you soon.");
