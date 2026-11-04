<?php
declare(strict_types=1);

require_once __DIR__ . '/imap.php';

/*
 * Linked mailboxes, encrypted password storage, SMTP sending and building outgoing messages.
 * Passwords are encrypted with a key kept outside the website folder (private_dir).
 */

function mail_private_dir(): string
{
    $dir = (string) (config('private_dir') ?: dirname((string) config('site_root')) . '/judes_private');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        // In case the folder ends up inside a web root (local installs), keep it unreadable
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

function mail_key(): string
{
    $file = mail_private_dir() . '/mail.key';
    if (!is_file($file)) {
        file_put_contents($file, base64_encode(random_bytes(32)), LOCK_EX);
        @chmod($file, 0600);
    }
    $key = base64_decode((string) file_get_contents($file), true);
    if ($key === false || strlen($key) !== 32) throw new RuntimeException('The mailbox encryption key is damaged.');
    return $key;
}

function mail_encrypt(string $plain): string
{
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', mail_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ct);
}

function mail_decrypt(string $enc): string
{
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Saved mailbox password is unreadable. Link the mailbox again.');
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', mail_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($plain === false) throw new RuntimeException('Saved mailbox password is unreadable. Link the mailbox again.');
    return $plain;
}

function mail_host(string $address = ''): string
{
    $h = (string) (config('mail_host') ?: '');
    if ($h !== '') return $h;
    $domain = substr(strrchr($address ?: (string) setting('org_email', 'info@judescbo.co.ke'), '@') ?: '@judescbo.co.ke', 1);
    return 'mail.' . $domain;
}

/** Mailboxes this user may open: shared ones plus their own. */
function user_mailboxes(array $user): array
{
    $stmt = db()->prepare('SELECT * FROM mailboxes WHERE owner_id IS NULL OR owner_id = ? ORDER BY owner_id IS NOT NULL, address');
    $stmt->execute([$user['id']]);
    return $stmt->fetchAll();
}

function find_mailbox(array $user, int $id): ?array
{
    foreach (user_mailboxes($user) as $m) if ((int) $m['id'] === $id) return $m;
    return null;
}

function mailbox_connect(array $box): ImapClient
{
    $imap = new ImapClient(mail_host($box['address']));
    $imap->login($box['address'], mail_decrypt($box['password_enc']));
    return $imap;
}

/** Shared mailbox for the organisation address, used for system emails. */
function org_mailbox(): ?array
{
    $stmt = db()->prepare('SELECT * FROM mailboxes WHERE owner_id IS NULL AND address = ? LIMIT 1');
    $stmt->execute([strtolower(setting('org_email', 'info@judescbo.co.ke'))]);
    return $stmt->fetch() ?: null;
}

/** Finds a folder by special-use flag or common names. */
function mail_special_folder(array $folders, string $use): ?string
{
    $names = [
        'sent' => ['sent', 'sent items', 'sent messages', 'inbox.sent'],
        'trash' => ['trash', 'deleted items', 'deleted messages', 'inbox.trash'],
        'drafts' => ['drafts', 'inbox.drafts'],
        'junk' => ['junk', 'spam', 'inbox.junk', 'inbox.spam'],
        'archive' => ['archive', 'inbox.archive'],
    ][$use] ?? [];
    foreach ($folders as $f) if (in_array('\\' . $use, $f['flags'], true)) return $f['name'];
    foreach ($folders as $f) if (in_array(strtolower($f['label']), $names, true)) return $f['name'];
    return null;
}

/* ---------- Building and sending ---------- */

function mime_header(string $text): string
{
    return preg_match('/[^\x20-\x7e]/', $text) ? mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n") : $text;
}

function mime_address(string $email, string $name = ''): string
{
    $email = str_replace(["\r", "\n", '<', '>'], '', $email);
    if ($name === '') return $email;
    $name = str_replace(["\r", "\n", '"'], '', $name);
    return (preg_match('/[^\x20-\x7e]/', $name) ? mb_encode_mimeheader($name, 'UTF-8', 'B') : '"' . $name . '"') . " <$email>";
}

/** Splits "a@b, Name <c@d>; e@f" into valid addresses. Returns [list, invalid parts]. */
function parse_recipients(string $input): array
{
    $ok = [];
    $bad = [];
    foreach (preg_split('/[,;\n]+/', $input) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $email = preg_match('/<([^>]+)>/', $part, $m) ? trim($m[1]) : $part;
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) $ok[strtolower($email)] = $email;
        else $bad[] = $part;
    }
    return [array_values($ok), $bad];
}

/**
 * Builds a complete MIME message.
 * $opt: from, from_name, to[], cc[], subject, text, html, reply_to, in_reply_to, references, attachments [[name, type, data]]
 */
function build_message(array $opt): array
{
    $domain = substr(strrchr($opt['from'], '@') ?: '@localhost', 1);
    $msgId = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
    $h = [];
    $h[] = 'Date: ' . date('r');
    $h[] = 'From: ' . mime_address($opt['from'], $opt['from_name'] ?? '');
    if (!empty($opt['to'])) $h[] = 'To: ' . implode(', ', $opt['to']);
    if (!empty($opt['cc'])) $h[] = 'Cc: ' . implode(', ', $opt['cc']);
    if (!empty($opt['reply_to'])) $h[] = 'Reply-To: ' . str_replace(["\r", "\n"], '', $opt['reply_to']);
    $h[] = 'Subject: ' . mime_header(str_replace(["\r", "\n"], ' ', $opt['subject']));
    $h[] = 'Message-ID: ' . $msgId;
    if (!empty($opt['in_reply_to'])) $h[] = 'In-Reply-To: ' . str_replace(["\r", "\n"], '', $opt['in_reply_to']);
    if (!empty($opt['references'])) $h[] = 'References: ' . str_replace(["\r", "\n"], '', $opt['references']);
    $h[] = 'MIME-Version: 1.0';
    $h[] = 'X-Mailer: Judes CBO dashboard';

    $text = str_replace(["\r\n", "\r"], "\n", (string) $opt['text']);
    $html = $opt['html'] ?? '';
    $alt = 'alt' . bin2hex(random_bytes(8));
    $body = "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode(str_replace("\n", "\r\n", $text)) . "\r\n";
    if ($html !== '') {
        $body .= "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($html) . "\r\n";
    }
    $body .= "--$alt--\r\n";
    $contentType = "multipart/alternative; boundary=\"$alt\"";

    if (!empty($opt['attachments'])) {
        $mix = 'mix' . bin2hex(random_bytes(8));
        $outer = "--$mix\r\nContent-Type: $contentType\r\n\r\n$body";
        foreach ($opt['attachments'] as $a) {
            $name = str_replace(['"', "\r", "\n"], '', $a['name']);
            $encName = preg_match('/[^\x20-\x7e]/', $name) ? "filename*=UTF-8''" . rawurlencode($name) : "filename=\"$name\"";
            $type = preg_match('#^[\w.+-]+/[\w.+-]+$#', $a['type'] ?? '') ? $a['type'] : 'application/octet-stream';
            $outer .= "--$mix\r\nContent-Type: $type\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; $encName\r\n\r\n"
                . chunk_split(base64_encode($a['data']), 76, "\r\n");
        }
        $outer .= "--$mix--\r\n";
        $body = $outer;
        $contentType = "multipart/mixed; boundary=\"$mix\"";
    }
    $h[] = 'Content-Type: ' . $contentType;
    return [implode("\r\n", $h) . "\r\n\r\n" . $body, $msgId];
}

/** Sends a raw message through the mailbox's SMTP login. Throws with a readable message on failure. */
function smtp_send(string $address, string $password, array $recipients, string $raw): void
{
    $host = mail_host($address);
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => (bool) (config('mail_verify_ssl') ?? true),
        'verify_peer_name' => (bool) (config('mail_verify_ssl') ?? true),
        'peer_name' => $host,
    ]]);
    $s = @stream_socket_client("ssl://$host:465", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) throw new RuntimeException("Couldn't reach the mail server to send ($errstr).");
    stream_set_timeout($s, 60);
    $read = function () use ($s): array {
        $text = '';
        while (($line = fgets($s)) !== false) {
            $text .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return [(int) substr($text, 0, 3), trim($text)];
    };
    $send = function (string $cmd, array $expect) use ($s, $read): string {
        fwrite($s, $cmd . "\r\n");
        [$code, $text] = $read();
        if (!in_array($code, $expect, true)) {
            throw new RuntimeException('The mail server refused the message: ' . preg_replace('/^\d{3}[ -]/m', '', $text));
        }
        return $text;
    };
    [$code] = $read();
    if ($code !== 220) throw new RuntimeException('The mail server is not accepting mail right now.');
    $send('EHLO ' . (parse_url((string) config('site_url'), PHP_URL_HOST) ?: 'localhost'), [250]);
    try {
        $send('AUTH LOGIN', [334]);
        $send(base64_encode($address), [334]);
        $send(base64_encode($password), [235]);
    } catch (RuntimeException $e) {
        throw new RuntimeException('The mailbox password was not accepted for sending.');
    }
    $send('MAIL FROM:<' . $address . '>', [250]);
    foreach ($recipients as $r) $send('RCPT TO:<' . $r . '>', [250, 251]);
    $send('DATA', [354]);
    // Dot-stuffing: a line starting with "." gets an extra "."
    $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], ["\n", "\n"], $raw));
    $send(str_replace("\n", "\r\n", $data) . "\r\n.", [250]);
    @fwrite($s, "QUIT\r\n");
    @fclose($s);
}

/**
 * Sends from a linked mailbox and saves a copy in its Sent folder.
 * Returns the Message-ID.
 */
function mailbox_send(array $box, array $opt, array $bcc = [], bool $saveSent = true): string
{
    $opt['from'] = $box['address'];
    $opt['from_name'] = $opt['from_name'] ?? ($box['display_name'] ?: 'Judes CBO');
    [$raw, $msgId] = build_message($opt);
    $password = mail_decrypt($box['password_enc']);
    $rcpts = array_unique(array_merge(array_map('mail_bare', $opt['to'] ?? []), array_map('mail_bare', $opt['cc'] ?? []), array_map('mail_bare', $bcc)));
    smtp_send($box['address'], $password, $rcpts, $raw);
    if (!$saveSent) return $msgId;
    try {
        $imap = new ImapClient(mail_host($box['address']));
        $imap->login($box['address'], $password);
        $folders = $imap->folders();
        $sent = mail_special_folder($folders, 'sent');
        if ($sent === null) {
            $delim = $folders[0]['delim'] ?? '.';
            $sent = 'INBOX' . $delim . 'Sent';
            $imap->createFolder($sent);
        }
        $imap->append($sent, $raw);
    } catch (Throwable $e) {
        // The message went out; a missing Sent copy isn't worth failing over
    }
    return $msgId;
}

function mail_bare(string $addr): string
{
    return preg_match('/<([^>]+)>/', $addr, $m) ? trim($m[1]) : trim($addr);
}

/** Plain text → simple HTML email body. */
function text_to_html(string $text, string $quoteHtml = ''): string
{
    $body = nl2br(h($text));
    $body = preg_replace('~(https?://[^\s<]+)~', '<a href="$1">$1</a>', $body);
    return '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#1a202c">'
        . '<div>' . $body . '</div>' . $quoteHtml . '</body></html>';
}

/**
 * System emails (password resets, form alerts). Uses the linked organisation mailbox when there is one,
 * otherwise PHP mail(). Returns false if nothing could send it.
 */
function system_mail(string $to, string $subject, string $body, string $replyTo = ''): bool
{
    $box = org_mailbox();
    if ($box) {
        try {
            mailbox_send($box, ['to' => [$to], 'subject' => $subject, 'text' => $body, 'html' => text_to_html($body), 'reply_to' => $replyTo, 'from_name' => 'Judes CBO'], [], false);
            return true;
        } catch (Throwable $e) {
            // fall back to mail() below
        }
    }
    if (!function_exists('mail')) return false;
    $from = setting('org_email', 'info@judescbo.co.ke');
    $headers = "From: Judes CBO <$from>\r\nContent-Type: text/plain; charset=utf-8" . ($replyTo ? "\r\nReply-To: " . str_replace(["\r", "\n"], '', $replyTo) : '');
    return @mail($to, mime_header($subject), $body, $headers);
}

/* ---------- Automatic replies (cPanel autoresponder, managed with the mailbox's own login) ---------- */

const MAIL_AUTOREPLY_SUBJECT = 'Thanks for your email - Judes CBO';
const MAIL_AUTOREPLY_BODY = "Hello,\n\nThank you for contacting Judes CBO. We have received your email and a member of our team will get back to you soon, usually within 2 working days.\n\nIf your matter is urgent, call or WhatsApp us on +254 712 109310.\n\nKind regards,\nJudes CBO\nhttps://judescbo.co.ke\n\nThis is an automatic reply.";

function webmail_api(array $box, string $function, array $params): array
{
    $host = mail_host($box['address']);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Basic " . base64_encode($box['address'] . ':' . mail_decrypt($box['password_enc'])) . "\r\nContent-Type: application/x-www-form-urlencoded",
            'content' => http_build_query($params),
            'timeout' => 20,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => (bool) (config('mail_verify_ssl') ?? true), 'verify_peer_name' => (bool) (config('mail_verify_ssl') ?? true)],
    ]);
    $raw = @file_get_contents("https://$host:2096/execute/Email/$function", false, $ctx);
    $res = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($res)) throw new RuntimeException("Couldn't reach the mail settings service. Try again in a minute.");
    if (empty($res['status'])) throw new RuntimeException('The mail server said: ' . implode(' ', (array) ($res['errors'] ?? ['unknown error'])));
    return $res;
}

/** Current automatic reply for the mailbox, or null when it's off. */
function autoreply_get(array $box): ?array
{
    $list = webmail_api($box, 'list_auto_responders', ['domain' => substr(strrchr($box['address'], '@'), 1)])['data'] ?? [];
    $on = false;
    foreach ($list as $r) if (strtolower($r['email'] ?? '') === strtolower($box['address'])) $on = true;
    if (!$on) return null;
    $d = webmail_api($box, 'get_auto_responder', ['email' => $box['address']])['data'] ?? [];
    return ['subject' => (string) ($d['subject'] ?? ''), 'body' => rtrim((string) ($d['body'] ?? '')), 'interval' => (int) ($d['interval'] ?? 24)];
}

function autoreply_set(array $box, bool $on, string $subject, string $body, int $intervalHours = 24): void
{
    [$user, $domain] = explode('@', $box['address'], 2);
    if (!$on) {
        if (autoreply_get($box) !== null) webmail_api($box, 'delete_auto_responder', ['email' => $box['address']]);
        return;
    }
    webmail_api($box, 'add_auto_responder', [
        'email' => $user, 'domain' => $domain, 'from' => $box['display_name'] ?: 'Judes CBO',
        'subject' => $subject, 'body' => $body, 'is_html' => 0, 'interval' => max(1, $intervalHours), 'start' => '', 'stop' => '',
    ]);
}

/* ---------- Forwarding (cPanel forwarders, managed with the mailbox's own login) ---------- */

/** Addresses this mailbox forwards copies to. */
function forward_list(array $box): array
{
    $rows = webmail_api($box, 'list_forwarders', ['domain' => substr(strrchr($box['address'], '@'), 1)])['data'] ?? [];
    $out = [];
    foreach ($rows as $r) {
        if (strtolower($r['dest'] ?? '') === strtolower($box['address']) && filter_var($r['forward'] ?? '', FILTER_VALIDATE_EMAIL)) $out[] = $r['forward'];
    }
    return array_values(array_unique($out));
}

function forward_add(array $box, string $to): void
{
    webmail_api($box, 'add_forwarder', ['domain' => substr(strrchr($box['address'], '@'), 1), 'email' => $box['address'], 'fwdopt' => 'fwd', 'fwdemail' => $to]);
}

function forward_remove(array $box, string $to): void
{
    webmail_api($box, 'delete_forwarder', ['address' => $box['address'], 'forwarder' => $to]);
}
