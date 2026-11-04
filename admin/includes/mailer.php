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
    foreach ($opt['headers'] ?? [] as $name => $value) {
        $h[] = $name . ': ' . str_replace(["\r", "\n"], '', (string) $value);
    }

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

    // Inline images (the logo) referenced from the HTML as cid:...
    if (!empty($opt['inline']) && $html !== '') {
        $rel = 'rel' . bin2hex(random_bytes(8));
        $related = "--$rel\r\nContent-Type: $contentType\r\n\r\n$body";
        foreach ($opt['inline'] as $img) {
            $related .= "--$rel\r\nContent-Type: {$img['type']}; name=\"{$img['name']}\"\r\nContent-Transfer-Encoding: base64\r\n"
                . "Content-ID: <{$img['cid']}>\r\nContent-Disposition: inline; filename=\"{$img['name']}\"\r\n\r\n"
                . chunk_split(base64_encode($img['data']), 76, "\r\n");
        }
        $related .= "--$rel--\r\n";
        $body = $related;
        $contentType = "multipart/related; boundary=\"$rel\"";
    }

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
            mailbox_send($box, ['to' => [$to], 'subject' => $subject, 'text' => $body, 'html' => email_brand_html(email_text_block($body), EMAIL_LOGO_CID),
                'inline' => email_logo_inline(), 'reply_to' => $replyTo, 'from_name' => 'Judes CBO'], [], false);
            return true;
        } catch (Throwable $e) {
            // fall back to mail() below
        }
    }
    if (!function_exists('mail')) return false;
    $from = setting('org_email', 'info@judescbo.co.ke');
    [$raw] = build_message(['from' => $from, 'from_name' => 'Judes CBO', 'to' => [$to], 'subject' => $subject, 'text' => $body,
        'html' => email_brand_html(email_text_block($body), email_logo_url()), 'reply_to' => $replyTo]);
    // mail() takes the headers and body separately; To and Subject are passed as arguments
    [$head, $content] = explode("\r\n\r\n", $raw, 2);
    $head = preg_replace('/^(To|Subject): .*(\r\n[ \t].*)*\r\n/mi', '', $head . "\r\n");
    return @mail($to, mime_header($subject), $content, rtrim($head));
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
    $body = rtrim((string) ($d['body'] ?? ''));
    if (!empty($d['is_html'])) {
        // We keep the plain text the user typed; the server only has the designed HTML
        $body = setting('autoreply_text_' . $box['id']) ?: trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return ['subject' => (string) ($d['subject'] ?? ''), 'body' => $body, 'interval' => (int) ($d['interval'] ?? 24)];
}

function autoreply_set(array $box, bool $on, string $subject, string $body, int $intervalHours = 24): void
{
    [$user, $domain] = explode('@', $box['address'], 2);
    if (!$on) {
        if (autoreply_get($box) !== null) webmail_api($box, 'delete_auto_responder', ['email' => $box['address']]);
        return;
    }
    save_setting('autoreply_text_' . $box['id'], $body);
    webmail_api($box, 'add_auto_responder', [
        'email' => $user, 'domain' => $domain, 'from' => $box['display_name'] ?: 'Judes CBO',
        'subject' => $subject, 'body' => email_brand_html(email_text_block($body), email_logo_url()), 'is_html' => 1,
        'interval' => max(1, $intervalHours), 'start' => '', 'stop' => '',
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

/* ---------- Unsubscribe and block sender ---------- */

/** Parses List-Unsubscribe into ['https' => url|null, 'mailto' => addr|null, 'oneclick' => bool]. */
function unsubscribe_options(array $headers): array
{
    $out = ['https' => null, 'mailto' => null, 'subject' => 'unsubscribe', 'oneclick' => false];
    $value = Mime::decodeHeader(implode(',', $headers['list-unsubscribe'] ?? []));
    foreach (preg_match_all('/<([^>]+)>/', $value, $m) ? $m[1] : [] as $u) {
        $u = trim($u);
        if ($out['https'] === null && preg_match('#^https://#i', $u) && filter_var($u, FILTER_VALIDATE_URL)) $out['https'] = $u;
        if ($out['mailto'] === null && stripos($u, 'mailto:') === 0) {
            $parts = parse_url($u);
            $addr = rawurldecode((string) ($parts['path'] ?? ''));
            if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $out['mailto'] = $addr;
                parse_str((string) ($parts['query'] ?? ''), $q);
                if (!empty($q['subject']) && is_string($q['subject'])) $out['subject'] = mb_substr($q['subject'], 0, 120);
            }
        }
    }
    $out['oneclick'] = $out['https'] !== null && stripos(implode(' ', $headers['list-unsubscribe-post'] ?? []), 'One-Click') !== false;
    return $out;
}

/** True only for public internet addresses (keeps the one-click request away from internal services). */
function public_host(string $host): bool
{
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
}

/**
 * Unsubscribes from a mailing list on the user's behalf.
 * Returns ['done' => bool, 'open' => url to open in the browser when it can't be done automatically].
 */
function mailbox_unsubscribe(array $box, array $headers): array
{
    $o = unsubscribe_options($headers);
    if ($o['oneclick']) {
        $host = (string) parse_url($o['https'], PHP_URL_HOST);
        if ($host !== '' && public_host($host)) {
            $ctx = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: JudesCBO-Mailbox/1.0",
                'content' => 'List-Unsubscribe=One-Click',
                'timeout' => 15,
                'follow_location' => 0,
                'ignore_errors' => true,
            ]]);
            $res = @file_get_contents($o['https'], false, $ctx);
            $status = 0;
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int) $m[1];
            }
            if ($res !== false && $status >= 200 && $status < 300) return ['done' => true, 'open' => null];
        }
    }
    if ($o['mailto']) {
        mailbox_send($box, ['to' => [$o['mailto']], 'subject' => $o['subject'], 'text' => 'Please unsubscribe ' . $box['address'] . ' from this mailing list.', 'html' => ''], [], false);
        return ['done' => true, 'open' => null];
    }
    return ['done' => false, 'open' => $o['https']];
}

/** Folder name the mail server's filters use for a mailbox folder (INBOX.spam -> spam). */
function filter_folder(string $imapName): string
{
    return preg_replace('/^INBOX[.\/]/i', '', $imapName);
}

const BLOCK_PREFIX = 'Blocked: ';

/** Senders blocked for this mailbox (filters named "Blocked: address"). */
function blocked_senders(array $box): array
{
    $rows = webmail_api($box, 'list_filters', ['account' => $box['address']])['data'] ?? [];
    $out = [];
    foreach ($rows as $r) {
        if (str_starts_with((string) ($r['filtername'] ?? ''), BLOCK_PREFIX)) $out[] = substr($r['filtername'], strlen(BLOCK_PREFIX));
    }
    sort($out);
    return $out;
}

/** Future mail from $sender goes straight to the Spam folder. */
function block_sender(array $box, string $sender, string $spamFolder): void
{
    webmail_api($box, 'store_filter', [
        'account' => $box['address'], 'filtername' => BLOCK_PREFIX . $sender,
        'part1' => '$header_from:', 'match1' => 'contains', 'val1' => $sender, 'opt1' => 'or',
        'action1' => 'save', 'dest1' => filter_folder($spamFolder),
    ]);
}

function unblock_sender(array $box, string $sender): void
{
    webmail_api($box, 'delete_filter', ['account' => $box['address'], 'filtername' => BLOCK_PREFIX . $sender]);
}

/* ---------- Branded email design ---------- */

const EMAIL_LOGO_CID = 'cid:judes-logo@judescbo.co.ke';
const DEFAULT_SIGNATURE = "Judes CBO\nEmpowerment for Development\n+254 712 109310 · judescbo.co.ke";

function email_logo_inline(): array
{
    $file = dirname(__DIR__, 2) . '/images/email-logo.png';
    if (!is_file($file)) return [];
    return [['cid' => 'judes-logo@judescbo.co.ke', 'type' => 'image/png', 'name' => 'judes-cbo-logo.png', 'data' => (string) file_get_contents($file)]];
}

function email_logo_url(): string
{
    return rtrim((string) config('site_url'), '/') . '/images/email-logo.png';
}

/** Plain text -> email-safe HTML paragraphs; a link on its own line becomes a button. */
function email_text_block(string $text): string
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    $out = '';
    foreach (preg_split("/\n{2,}/", $text) as $para) {
        $para = trim($para);
        if ($para === '') continue;
        if (preg_match('~^(https?://\S+)$~', $para, $m)) {
            $url = $m[1];
            $label = str_contains($url, 'reset-password') ? 'Choose a new password' : (str_contains($url, 'verify-email') ? 'Confirm my email' : 'Open link');
            $out .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px 0 20px"><tr><td style="border-radius:999px;background:#2d5016">'
                . '<a href="' . h($url) . '" style="display:inline-block;padding:13px 26px;font:700 15px Arial,Helvetica,sans-serif;color:#ffffff;text-decoration:none;border-radius:999px">' . h($label) . '</a></td></tr></table>'
                . '<p style="margin:0 0 18px;font-size:12px;color:#8a94a6;word-break:break-all">Or copy this link: ' . h($url) . '</p>';
            continue;
        }
        $html = nl2br(h($para));
        $html = preg_replace('~(https?://[^\s<]+)~', '<a href="$1" style="color:#2d5016;font-weight:bold">$1</a>', $html);
        $out .= '<p style="margin:0 0 16px">' . $html . '</p>';
    }
    return $out;
}

/** The full branded layout used for automated emails. $logo is a cid: reference or an https URL. */
function email_brand_html(string $inner, string $logo, string $unsubscribe = ''): string
{
    $site = rtrim((string) config('site_url'), '/');
    $email = setting('org_email', 'info@judescbo.co.ke');
    if ($unsubscribe !== '') {
        // Newsletter emails: a quiet footer with a single Unsubscribe button and no other links
        $footer = '<tr><td style="padding:22px 30px 26px;background:#f8fafc;border-top:1px solid #e7ebf0;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.7;color:#718096;text-align:center">'
            . '<strong style="color:#1a365d">Judes Community Based Organization</strong><br>Oyugis, Homa Bay County, Kenya'
            . '<table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:14px auto 6px"><tr><td style="border:1px solid #cbd5e0;border-radius:999px">'
            . '<a href="' . h($unsubscribe) . '" style="display:inline-block;padding:8px 20px;font:bold 12px Arial,Helvetica,sans-serif;color:#4a5568;text-decoration:none;border-radius:999px">Unsubscribe</a>'
            . '</td></tr></table>You are receiving this because you subscribed on our website.</td></tr>';
    }
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"></head>'
        . '<body style="margin:0;padding:0;background:#f4f6f9">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9"><tr><td align="center" style="padding:28px 12px">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e7ebf0">'
        // Header
        . '<tr><td style="background:#0f2440;padding:22px 28px">'
        . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="background:#ffffff;border-radius:12px;padding:6px;line-height:0"><img src="' . h($logo) . '" width="56" height="39" alt="Judes CBO" style="display:block;border:0;width:56px;height:auto"></td>'
        . '<td style="padding-left:14px;font-family:Arial,Helvetica,sans-serif">'
        . '<div style="font-size:19px;font-weight:bold;letter-spacing:2px;color:#ffffff">JUDES CBO</div>'
        . '<div style="font-size:11px;font-weight:bold;letter-spacing:1.5px;color:#9ae6b4;padding-top:3px">EMPOWERMENT FOR DEVELOPMENT</div>'
        . '</td></tr></table></td></tr>'
        . '<tr><td style="height:4px;line-height:4px;font-size:0;background:#2d5016">&nbsp;</td></tr>'
        // Content
        . '<tr><td style="padding:30px 30px 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.65;color:#1a202c">' . $inner . '</td></tr>'
        // Footer
        . ($unsubscribe !== '' ? $footer : '<tr><td style="padding:20px 30px 24px;background:#f8fafc;border-top:1px solid #e7ebf0;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.7;color:#718096;text-align:center">'
        . '<strong style="color:#1a365d">Judes Community Based Organization</strong><br>Oyugis, Homa Bay County, Kenya<br>'
        . '<a href="tel:+254712109310" style="color:#2d5016;text-decoration:none">+254 712 109310</a> &nbsp;·&nbsp; '
        . '<a href="mailto:' . h($email) . '" style="color:#2d5016;text-decoration:none">' . h($email) . '</a> &nbsp;·&nbsp; '
        . '<a href="' . h($site) . '" style="color:#2d5016;text-decoration:none">' . h(preg_replace('#^https?://#', '', $site)) . '</a>'
        . '</td></tr>')
        . '</table></td></tr></table></body></html>';
}

/** Signature card for everyday emails: logo beside the signature lines. */
function email_signature_html(string $sig, string $logo): string
{
    $lines = array_values(array_filter(array_map('trim', explode("\n", trim($sig))), 'strlen'));
    if (!$lines) return '';
    $first = array_shift($lines);
    $rest = '';
    foreach ($lines as $l) {
        $l = h($l);
        // Phone numbers become tap-to-call links (the dial link has no spaces)
        $l = preg_replace_callback('~(\+?\d[\d \-]{7,}\d)~', fn($m) => '<a href="tel:' . preg_replace('/[^\d+]/', '', $m[1]) . '" style="color:#2d5016;text-decoration:none">' . $m[1] . '</a>', $l);
        $l = preg_replace('~\b((?:https?://)?(?:www\.)?judescbo\.co\.ke[^\s<]*)~', '<a href="https://judescbo.co.ke" style="color:#2d5016;font-weight:bold;text-decoration:none">$1</a>', $l);
        $l = preg_replace('~([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})~', '<a href="mailto:$1" style="color:#2d5016;text-decoration:none">$1</a>', $l);
        $rest .= '<br>' . $l;
    }
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:22px;border-top:3px solid #2d5016;padding-top:14px">'
        . '<tr><td style="padding:14px 14px 0 0;vertical-align:top;line-height:0"><img src="' . h($logo) . '" width="64" alt="Judes CBO" style="display:block;border:0;width:64px;height:auto;border-radius:10px"></td>'
        . '<td style="padding-top:14px;vertical-align:top;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.55;color:#4a5568">'
        . '<strong style="font-size:15px;color:#1a365d">' . h($first) . '</strong>' . $rest . '</td></tr></table>';
}

/**
 * HTML for an email typed in the dashboard: the message, the signature card (after the "-- " line)
 * and any quoted earlier email in a grey block.
 */
function compose_html(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $quoteRe = '/(^|\n)(On [^\n]{6,200} wrote:\n|---------- Forwarded message ----------)/';
    $main = $text;
    $sig = '';
    $quote = '';
    if (($p = strpos($text, "\n-- \n")) !== false) {
        $main = substr($text, 0, $p);
        $sig = substr($text, $p + 5);
        if (preg_match($quoteRe, $sig, $m, PREG_OFFSET_CAPTURE)) {
            $quote = substr($sig, $m[0][1]);
            $sig = substr($sig, 0, $m[0][1]);
        }
    } elseif (preg_match($quoteRe, $text, $m, PREG_OFFSET_CAPTURE)) {
        $main = substr($text, 0, $m[0][1]);
        $quote = substr($text, $m[0][1]);
    }
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#1a202c">' . email_text_block($main) . '</div>';
    if (trim($sig) !== '') $html .= email_signature_html($sig, EMAIL_LOGO_CID);
    if (trim($quote) !== '') {
        $q = preg_replace('/^> ?/m', '', trim($quote));
        $html .= '<div style="margin-top:22px;padding:4px 0 4px 14px;border-left:3px solid #cbd5e0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.55;color:#718096">'
            . nl2br(h($q)) . '</div>';
    }
    return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:16px">' . $html . '</body></html>';
}

/** Newsletter email (welcome and future issues): no links in the text, one Unsubscribe button, and the
 *  List-Unsubscribe headers so Gmail and Outlook show their own unsubscribe option. */
function newsletter_mail(string $to, string $subject, string $text, string $unsubscribeUrl): bool
{
    $plain = $text . "\n\n--\nUnsubscribe: " . $unsubscribeUrl;
    $html = email_brand_html(email_text_block($text), EMAIL_LOGO_CID, $unsubscribeUrl);
    $headers = [
        'List-Unsubscribe' => '<' . $unsubscribeUrl . '>',
        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
    ];
    $box = org_mailbox();
    if ($box) {
        try {
            mailbox_send($box, ['to' => [$to], 'subject' => $subject, 'text' => $plain, 'html' => $html, 'inline' => email_logo_inline(),
                'from_name' => 'Judes CBO', 'headers' => $headers], [], false);
            return true;
        } catch (Throwable $e) {
            // fall back to mail() below
        }
    }
    if (!function_exists('mail')) return false;
    $from = setting('org_email', 'info@judescbo.co.ke');
    [$raw] = build_message(['from' => $from, 'from_name' => 'Judes CBO', 'to' => [$to], 'subject' => $subject, 'text' => $plain,
        'html' => email_brand_html(email_text_block($text), email_logo_url(), $unsubscribeUrl), 'headers' => $headers]);
    [$head, $content] = explode("\r\n\r\n", $raw, 2);
    $head = preg_replace('/^(To|Subject): .*(\r\n[ \t].*)*\r\n/mi', '', $head . "\r\n");
    return @mail($to, mime_header($subject), $content, rtrim($head));
}
