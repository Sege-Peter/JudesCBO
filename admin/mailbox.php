<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/mailer.php';
$me = require_login();

const MBX_PER_PAGE = 30;
const MBX_MAX_UPLOAD = 15 * 1024 * 1024;

$orgDomain = strtolower(substr(strrchr(setting('org_email', 'info@judescbo.co.ke'), '@') ?: '@judescbo.co.ke', 1));
$boxes = user_mailboxes($me);

/* ---------- Current view ---------- */
$boxId = (int) ($_GET['box'] ?? $_POST['box'] ?? ($boxes[0]['id'] ?? 0));
$box = $boxId ? find_mailbox($me, $boxId) : null;
if (!$box && $boxes) $box = $boxes[0];
$folder = (string) ($_GET['folder'] ?? $_POST['folder'] ?? 'INBOX');
$q = trim((string) ($_GET['q'] ?? ''));
$filter = in_array($_GET['filter'] ?? '', ['unread', 'starred'], true) ? $_GET['filter'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$uid = (int) ($_GET['uid'] ?? 0);

function mbx_url(array $set = [], array $drop = []): string
{
    global $box, $folder, $q, $filter, $page;
    $p = array_filter(['box' => $box['id'] ?? null, 'folder' => $folder !== 'INBOX' ? $folder : null, 'q' => $q ?: null, 'filter' => $filter ?: null, 'page' => $page > 1 ? $page : null]);
    foreach ($drop as $k) unset($p[$k]);
    $p = array_filter(array_merge($p, $set), fn($v) => $v !== null && $v !== '');
    return 'mailbox.php' . ($p ? '?' . http_build_query($p) : '');
}

function can_edit_box(array $me, array $box): bool
{
    return $box['owner_id'] === null ? is_admin() : (int) $box['owner_id'] === (int) $me['id'];
}

function uid_list($v): array
{
    $v = is_array($v) ? $v : explode(',', (string) $v);
    return array_values(array_filter(array_map('intval', $v), fn($u) => $u > 0));
}

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'link') {
        $address = strtolower(post_str('address', 190));
        $password = (string) ($_POST['password'] ?? '');
        $shared = is_admin() && !empty($_POST['shared']);
        $name = post_str('display_name', 120);
        if (!filter_var($address, FILTER_VALIDATE_EMAIL) || !str_ends_with($address, '@' . $orgDomain)) {
            flash('error', "Enter a full @$orgDomain email address.");
            redirect(mbx_url(['link' => 1]));
        }
        $dup = db()->prepare('SELECT id FROM mailboxes WHERE address = ? AND (owner_id IS NULL OR owner_id = ?)');
        $dup->execute([$address, $me['id']]);
        $existing = $dup->fetchColumn();
        try {
            $imap = new ImapClient(mail_host($address));
            $imap->login($address, $password);
            unset($imap);
        } catch (ImapAuthError $e) {
            flash('error', "That password wasn't accepted for $address. Check it and try again.");
            redirect(mbx_url(['link' => 1]));
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect(mbx_url(['link' => 1]));
        }
        if ($existing) {
            // Same mailbox again: treat it as a password update
            $row = find_mailbox($me, (int) $existing);
            if ($row && can_edit_box($me, $row)) {
                db()->prepare('UPDATE mailboxes SET password_enc = ? WHERE id = ?')->execute([mail_encrypt($password), $existing]);
                log_activity('mailbox.relink', $address);
                flash('success', "Password updated for $address.");
            } else {
                flash('error', "$address is already linked.");
            }
            redirect('mailbox.php?box=' . (int) $existing);
        }
        db()->prepare('INSERT INTO mailboxes (address, display_name, password_enc, owner_id, added_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$address, $name ?: 'Judes CBO', mail_encrypt($password), $shared ? null : $me['id'], $me['id']]);
        $newId = (int) db()->lastInsertId();
        log_activity('mailbox.link', $address . ($shared ? ' (shared)' : ''));
        flash('success', "$address is linked.");
        redirect('mailbox.php?box=' . $newId);
    }

    if (!$box) redirect('mailbox.php');

    if ($action === 'settings') {
        if (!can_edit_box($me, $box)) error_page(403, 'Not allowed', 'Only an administrator can change a shared mailbox.');
        db()->prepare('UPDATE mailboxes SET display_name = ?, signature = ? WHERE id = ?')
            ->execute([post_str('display_name', 120) ?: 'Judes CBO', post_str('signature', 2000), $box['id']]);
        flash('success', 'Mailbox settings saved.');
        redirect(mbx_url(['settings' => 1]));
    }

    if ($action === 'autoreply') {
        if (!can_edit_box($me, $box)) error_page(403, 'Not allowed', 'Only an administrator can change a shared mailbox.');
        $on = !empty($_POST['autoreply_on']);
        try {
            autoreply_set($box, $on, post_str('autoreply_subject', 200) ?: MAIL_AUTOREPLY_SUBJECT, post_str('autoreply_body', 4000) ?: MAIL_AUTOREPLY_BODY, (int) ($_POST['autoreply_interval'] ?? 24));
            log_activity('mailbox.autoreply', $box['address'] . ($on ? ' on' : ' off'));
            flash('success', $on ? 'Automatic reply is on for ' . $box['address'] . '.' : 'Automatic reply is off.');
        } catch (ImapAuthError | RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect(mbx_url(['settings' => 1]) . '#autoreply');
    }

    if ($action === 'forward_add' || $action === 'forward_remove') {
        if (!can_edit_box($me, $box)) error_page(403, 'Not allowed', 'Only an administrator can change a shared mailbox.');
        $to = strtolower(post_str('forward_to', 190));
        try {
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address to forward to.');
            if ($to === strtolower($box['address'])) throw new RuntimeException("A mailbox can't forward to itself.");
            if ($action === 'forward_add') {
                if (in_array($to, array_map('strtolower', forward_list($box)), true)) throw new RuntimeException("Already forwarding to $to.");
                forward_add($box, $to);
                log_activity('mailbox.forward', $box['address'] . " now forwards to $to");
                flash('success', "Emails to {$box['address']} will now also go to $to. A copy stays in this mailbox.");
            } else {
                forward_remove($box, $to);
                log_activity('mailbox.forward', $box['address'] . " stopped forwarding to $to");
                flash('success', "Stopped forwarding to $to.");
            }
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect(mbx_url(['settings' => 1]) . '#forwarding');
    }

    if ($action === 'unlink') {
        if (!can_edit_box($me, $box)) error_page(403, 'Not allowed', 'Only an administrator can remove a shared mailbox.');
        db()->prepare('DELETE FROM mailboxes WHERE id = ?')->execute([$box['id']]);
        log_activity('mailbox.unlink', $box['address']);
        flash('success', $box['address'] . ' was removed from the dashboard. The mailbox itself and its emails are untouched.');
        redirect('mailbox.php');
    }

    try {
        if ($action === 'send') {
            [$to, $badTo] = parse_recipients(post_str('to', 4000));
            [$cc, $badCc] = parse_recipients(post_str('cc', 4000));
            [$bcc, $badBcc] = parse_recipients(post_str('bcc', 4000));
            $bad = array_merge($badTo, $badCc, $badBcc);
            $subject = post_str('subject', 300);
            $text = (string) ($_POST['body'] ?? '');
            $text = mb_substr(str_replace("\r\n", "\n", $text), 0, 100000);
            $back = $_POST['back'] ?? '';
            if ($bad || (!$to && !$cc && !$bcc)) {
                $_SESSION['mbx_draft'] = $_POST;
                flash('error', $bad ? 'Check these addresses: ' . implode(', ', $bad) : 'Add at least one recipient.');
                redirect(is_string($back) && str_starts_with($back, 'mailbox.php') ? $back : mbx_url(['compose' => 1]));
            }
            $attachments = [];
            $total = 0;
            if (!empty($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
                foreach ($_FILES['files']['name'] as $i => $fname) {
                    $err = $_FILES['files']['error'][$i];
                    if ($err === UPLOAD_ERR_NO_FILE) continue;
                    if ($err !== UPLOAD_ERR_OK) throw new RuntimeException("Couldn't upload $fname. Files can be up to " . ini_get('upload_max_filesize') . ' each.');
                    $data = (string) file_get_contents($_FILES['files']['tmp_name'][$i]);
                    $total += strlen($data);
                    $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($data) ?: 'application/octet-stream';
                    $attachments[] = ['name' => Mime::cleanName((string) $fname), 'type' => $type, 'data' => $data];
                }
            }
            $inReplyTo = '';
            $references = '';
            $replyUid = (int) ($_POST['reply_uid'] ?? 0);
            $replyFolder = (string) ($_POST['reply_folder'] ?? 'INBOX');
            $mode = (string) ($_POST['mode'] ?? '');
            if ($replyUid) {
                $imap = mailbox_connect($box);
                $imap->select($replyFolder);
                $raw = $imap->raw($replyUid, true);
                if ($raw !== null) {
                    $orig = Mime::parse($raw);
                    $mid = trim($orig['headers']['message-id'][0] ?? '');
                    if ($mode !== 'fwd' && $mid !== '') {
                        $inReplyTo = $mid;
                        $references = trim(($orig['headers']['references'][0] ?? '') . ' ' . $mid);
                    }
                    if ($mode === 'fwd' && !empty($_POST['keep_attachments'])) {
                        foreach ($orig['attachments'] as $a) {
                            if ($a['inline']) continue;
                            $total += $a['size'];
                            $attachments[] = ['name' => $a['name'], 'type' => $a['type'], 'data' => $a['data']];
                        }
                    }
                }
            }
            if ($total > MBX_MAX_UPLOAD) throw new RuntimeException('Attachments add up to more than 15 MB. Send large files as a link instead.');
            mailbox_send($box, [
                'to' => $to, 'cc' => $cc, 'subject' => $subject, 'text' => $text, 'html' => text_to_html($text),
                'in_reply_to' => $inReplyTo, 'references' => $references, 'attachments' => $attachments,
            ], $bcc);
            if ($replyUid && $mode !== 'fwd') {
                try { $imap->setFlag([$replyUid], '\\Answered', true); } catch (Throwable $e) {}
            }
            $contactId = (int) ($_POST['contact'] ?? 0);
            if ($contactId) {
                db()->prepare("UPDATE messages SET status = 'replied', replied_at = COALESCE(replied_at, NOW()) WHERE id = ?")->execute([$contactId]);
            }
            unset($_SESSION['mbx_draft']);
            log_activity('mailbox.send', 'From ' . $box['address'] . ' to ' . implode(', ', array_merge($to, $cc, $bcc)) . ': ' . mb_strimwidth($subject, 0, 80, '…'));
            flash('success', 'Email sent.');
            if ($contactId) redirect('messages.php?id=' . $contactId);
            redirect($replyUid ? mbx_url(['folder' => $replyFolder, 'uid' => $replyUid]) : mbx_url());
        }

        // Message actions on one or more UIDs in the current folder
        $uids = uid_list($_POST['uids'] ?? []);
        if ($uids && in_array($action, ['read', 'unread', 'star', 'unstar', 'delete', 'move', 'spam'], true)) {
            $imap = mailbox_connect($box);
            $folders = $imap->folders();
            $imap->select($folder);
            $trash = mail_special_folder($folders, 'trash');
            $n = count($uids);
            switch ($action) {
                case 'read': $imap->setFlag($uids, '\\Seen', true); break;
                case 'unread': $imap->setFlag($uids, '\\Seen', false); break;
                case 'star': $imap->setFlag($uids, '\\Flagged', true); break;
                case 'unstar': $imap->setFlag($uids, '\\Flagged', false); break;
                case 'spam':
                    $junk = mail_special_folder($folders, 'junk');
                    if ($junk === null) throw new RuntimeException('This mailbox has no Spam folder.');
                    $imap->move($uids, $junk);
                    flash('success', $n === 1 ? 'Moved to Spam.' : "$n emails moved to Spam.");
                    redirect(mbx_url([], ['uid']));
                case 'move':
                    $target = (string) ($_POST['to_folder'] ?? '');
                    $valid = array_column($folders, 'name');
                    if (!in_array($target, $valid, true) || $target === $folder) throw new RuntimeException('Choose a folder to move to.');
                    $imap->move($uids, $target);
                    flash('success', ($n === 1 ? 'Moved' : "$n emails moved") . ' to ' . preg_replace('/^INBOX[.\/]/i', '', ImapClient::decodeFolder($target)) . '.');
                    redirect(mbx_url([], ['uid']));
                case 'delete':
                    if ($trash !== null && $folder !== $trash) {
                        $imap->move($uids, $trash);
                        flash('success', $n === 1 ? 'Moved to Trash.' : "$n emails moved to Trash.");
                    } else {
                        $imap->setFlag($uids, '\\Deleted', true);
                        $imap->expunge($uids);
                        flash('success', $n === 1 ? 'Deleted for good.' : "$n emails deleted for good.");
                    }
                    log_activity('mailbox.delete', $box['address'] . ": $n email(s) from " . ImapClient::decodeFolder($folder));
                    redirect(mbx_url([], ['uid']));
            }
            if ($action === 'unread') redirect(mbx_url([], ['uid']));
            $back = (string) ($_POST['back'] ?? '');
            redirect(str_starts_with($back, 'mailbox.php') ? $back : mbx_url());
        }
    } catch (ImapAuthError $e) {
        flash('error', 'The password for ' . $box['address'] . ' has changed. Link it again with the new password.');
        redirect(mbx_url(['link' => 1, 'address' => $box['address']]));
    } catch (Throwable $e) {
        if ($action === 'send') $_SESSION['mbx_draft'] = $_POST;
        flash('error', $e->getMessage());
        $back = (string) ($_POST['back'] ?? '');
        redirect(str_starts_with($back, 'mailbox.php') ? $back : mbx_url());
    }
    redirect(mbx_url());
}

/* ---------- Attachment download ---------- */
if ($box && $uid && isset($_GET['att'])) {
    try {
        $imap = mailbox_connect($box);
        $imap->select($folder);
        $raw = $imap->raw($uid, true);
        $msg = $raw !== null ? Mime::parse($raw) : null;
        $a = $msg['attachments'][(int) $_GET['att']] ?? null;
        if (!$a) error_page(404, 'Attachment not found', 'This attachment is no longer in the mailbox.');
        $inlineOk = isset($_GET['view']) && preg_match('#^(image/(png|jpe?g|gif|webp)|application/pdf)$#', $a['type']);
        header('Content-Type: ' . ($inlineOk ? $a['type'] : 'application/octet-stream'));
        header('Content-Disposition: ' . ($inlineOk ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($a['name']));
        header('Content-Length: ' . strlen($a['data']));
        header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: private, no-store');
        echo $a['data'];
        exit;
    } catch (Throwable $e) {
        error_page(502, 'Mail server problem', $e->getMessage());
    }
}

/* ---------- Load the view ---------- */
$error = '';
$authFailed = false;
$folders = [];
$list = [];
$total = 0;
$message = null;
$special = [];
if ($box && !isset($_GET['link'])) {
    try {
        $imap = mailbox_connect($box);
        $folders = $imap->folders();
        foreach (['sent', 'trash', 'drafts', 'junk', 'archive'] as $use) $special[$use] = mail_special_folder($folders, $use);
        if (!in_array($folder, array_column($folders, 'name'), true)) $folder = 'INBOX';
        // Order: Inbox, Drafts, Sent, Archive, Spam, Trash, then the rest A–Z
        $rank = fn($f) => match (true) {
            strtoupper($f['name']) === 'INBOX' => 0,
            $f['name'] === $special['drafts'] => 1,
            $f['name'] === $special['sent'] => 2,
            $f['name'] === $special['archive'] => 3,
            $f['name'] === $special['junk'] => 4,
            $f['name'] === $special['trash'] => 5,
            default => 10,
        };
        usort($folders, fn($a, $b) => [$rank($a), strtolower($a['label'])] <=> [$rank($b), strtolower($b['label'])]);
        $imap->select($folder);
        if ($uid) {
            $raw = $imap->raw($uid);
            if ($raw === null) {
                $error = 'That email is no longer in this folder.';
                $uid = 0;
            } else {
                $message = Mime::parse($raw);
                $message['uid'] = $uid;
                $flagInfo = $imap->summaries([$uid]);
                $message['flagged'] = $flagInfo[$uid]['flagged'] ?? false;
            }
        }
        // Unread counts after opening the email, so it no longer counts as unread
        foreach ($folders as &$f) {
            $f['unseen'] = in_array($f['name'], [$special['sent'], $special['trash'], $special['drafts']], true) ? 0 : $imap->status($f['name'])['unseen'];
        }
        unset($f);
        $all = $imap->search($q, $filter === 'unread', $filter === 'starred');
        $total = count($all);
        $pageUids = array_slice($all, ($page - 1) * MBX_PER_PAGE, MBX_PER_PAGE);
        $sum = $imap->summaries($pageUids);
        foreach ($pageUids as $u) if (isset($sum[$u])) $list[] = $sum[$u];
    } catch (ImapAuthError $e) {
        $authFailed = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$folderLabel = function (string $name) use ($special): string {
    if (strtoupper($name) === 'INBOX') return 'Inbox';
    foreach (['sent' => 'Sent', 'trash' => 'Trash', 'drafts' => 'Drafts', 'junk' => 'Spam', 'archive' => 'Archive'] as $k => $l) {
        if (($special[$k] ?? null) === $name) return $l;
    }
    $label = ImapClient::decodeFolder($name);
    return preg_replace('/^INBOX[.\/]/i', '', $label);
};
$folderIcon = function (string $name) use ($special): string {
    if (strtoupper($name) === 'INBOX') return 'fa-inbox';
    return match ($name) {
        $special['sent'] ?? '' => 'fa-paper-plane',
        $special['trash'] ?? '' => 'fa-trash',
        $special['drafts'] ?? '' => 'fa-file-pen',
        $special['junk'] ?? '' => 'fa-ban',
        $special['archive'] ?? '' => 'fa-box-archive',
        default => 'fa-folder',
    };
};

function mbx_when(int $ts): string
{
    if (date('Y-m-d', $ts) === date('Y-m-d')) return date('g:i a', $ts);
    if (date('Y', $ts) === date('Y')) return date('j M', $ts);
    return date('j M Y', $ts);
}

function mbx_name(string $from): string
{
    $a = Mime::addresses($from)[0] ?? null;
    if (!$a) return $from ?: '(unknown)';
    return $a['name'] !== '' ? $a['name'] : $a['email'];
}

function mbx_size(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}

/** Email HTML for a sandboxed iframe: no scripts, remote images blocked unless allowed, cid: images inlined. */
function mbx_html_doc(array $msg, bool $images, ?bool &$hasRemote): string
{
    $html = $msg['html'];
    $html = preg_replace('#<(script|iframe|object|embed|form)\b[^>]*>.*?</\1\s*>#is', '', $html);
    $html = preg_replace('#<(script|iframe|object|embed|base|meta|link)\b[^>]*>#i', '', (string) $html);
    foreach ($msg['attachments'] as $a) {
        if ($a['cid'] !== '' && str_starts_with($a['type'], 'image/') && $a['size'] < 3 * 1048576) {
            $html = str_ireplace('cid:' . $a['cid'], 'data:' . $a['type'] . ';base64,' . base64_encode($a['data']), (string) $html);
        }
    }
    $hasRemote = (bool) preg_match('#(src|background)\s*=\s*["\']?\s*https?://|url\(\s*["\']?https?://#i', (string) $html);
    $csp = "default-src 'none'; style-src 'unsafe-inline'; font-src data:; img-src data:" . ($images ? ' https: http:' : '');
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' . $csp . '">'
        . '<base target="_blank"><style>html,body{margin:0}body{padding:18px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#1a202c;word-wrap:break-word;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}</style></head><body>'
        . $html . '</body></html>';
}

function mbx_text_html(string $text): string
{
    $out = nl2br(h($text));
    return preg_replace('~(https?://[^\s<]+)~', '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>', $out);
}

/** Plain-text quote of a message for replies and forwards. */
function mbx_quote(array $msg, string $mode): string
{
    $h = $msg['headers'];
    $from = Mime::decodeHeader($h['from'][0] ?? '');
    $date = date('D j M Y \a\t g:i a', strtotime($h['date'][0] ?? 'now') ?: time());
    $text = $msg['text'] !== '' ? $msg['text'] : html_to_text($msg['html']);
    $text = trim(str_replace("\r\n", "\n", $text));
    if ($mode === 'fwd') {
        return "\n\n---------- Forwarded message ----------\nFrom: $from\nDate: $date\nSubject: " . Mime::decodeHeader($h['subject'][0] ?? '')
            . "\nTo: " . Mime::decodeHeader($h['to'][0] ?? '') . "\n\n" . $text;
    }
    return "\n\nOn $date, $from wrote:\n" . preg_replace('/^/m', '> ', $text);
}

function html_to_text(string $html): string
{
    $html = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', '', $html);
    $html = preg_replace('#<br\s*/?>|</(p|div|tr|h[1-6]|li)>#i', "\n", (string) $html);
    $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text)));
}

/* ---------- Compose prefill ---------- */
$compose = null;
if ($box && !$authFailed && (isset($_GET['compose']) || in_array($_GET['mode'] ?? '', ['reply', 'all', 'fwd'], true))) {
    $sig = trim((string) ($box['signature'] ?? ''));
    $compose = ['to' => '', 'cc' => '', 'bcc' => '', 'subject' => '', 'body' => $sig !== '' ? "\n\n-- \n$sig" : '', 'mode' => '', 'reply_uid' => 0, 'contact' => 0, 'atts' => []];
    $mode = $_GET['mode'] ?? '';
    if ($message && in_array($mode, ['reply', 'all', 'fwd'], true)) {
        $h = $message['headers'];
        $subj = Mime::decodeHeader($h['subject'][0] ?? '');
        $compose['mode'] = $mode;
        $compose['reply_uid'] = $message['uid'];
        if ($mode === 'fwd') {
            $compose['subject'] = preg_match('/^fwd?:/i', $subj) ? $subj : 'Fwd: ' . $subj;
            $compose['atts'] = array_values(array_filter($message['attachments'], fn($a) => !$a['inline']));
        } else {
            $compose['subject'] = preg_match('/^re:/i', $subj) ? $subj : 'Re: ' . $subj;
            $replyTo = Mime::decodeHeader($h['reply-to'][0] ?? '') ?: Mime::decodeHeader($h['from'][0] ?? '');
            $toList = array_column(Mime::addresses($replyTo), 'email');
            if ($mode === 'all') {
                $others = array_merge(Mime::addresses(Mime::decodeHeader($h['to'][0] ?? '')), Mime::addresses(Mime::decodeHeader($h['cc'][0] ?? '')));
                $cc = [];
                foreach ($others as $o) {
                    $e = strtolower($o['email']);
                    if ($e !== strtolower($box['address']) && !in_array($e, array_map('strtolower', $toList), true)) $cc[$e] = $o['email'];
                }
                $compose['cc'] = implode(', ', $cc);
            }
            $compose['to'] = implode(', ', $toList);
        }
        $compose['body'] .= mbx_quote($message, $mode);
    } elseif (!empty($_GET['contact'])) {
        $stmt = db()->prepare('SELECT * FROM messages WHERE id = ?');
        $stmt->execute([(int) $_GET['contact']]);
        if ($c = $stmt->fetch()) {
            $compose['contact'] = (int) $c['id'];
            $compose['to'] = $c['email'];
            $compose['subject'] = 'Re: ' . $c['topic'] . ' - Judes CBO';
            $compose['body'] = "Dear {$c['name']},\n\nThank you for contacting Judes CBO.\n\n" . ($sig !== '' ? "\n-- \n$sig" : "Kind regards,\nJudes CBO")
                . "\n\nOn " . date('D j M Y \a\t g:i a', strtotime($c['created_at'])) . ", {$c['name']} wrote:\n" . preg_replace('/^/m', '> ', $c['message']);
        }
    } elseif (!empty($_GET['to'])) {
        $compose['to'] = (string) $_GET['to'];
    }
    if (!empty($_SESSION['mbx_draft'])) {
        $d = $_SESSION['mbx_draft'];
        foreach (['to', 'cc', 'bcc', 'subject', 'body', 'mode'] as $k) if (isset($d[$k])) $compose[$k] = (string) $d[$k];
        $compose['reply_uid'] = (int) ($d['reply_uid'] ?? $compose['reply_uid']);
        $compose['contact'] = (int) ($d['contact'] ?? $compose['contact']);
        unset($_SESSION['mbx_draft']);
    }
}

$showLink = !$boxes || isset($_GET['link']) || $authFailed;
$showSettings = $box && isset($_GET['settings']) && can_edit_box($me, $box);
$images = isset($_GET['images']);
$autoreply = null;
$autoreplyError = '';
$forwards = [];
$forwardError = '';
if ($showSettings) {
    try { $autoreply = autoreply_get($box); } catch (Throwable $e) { $autoreplyError = $e->getMessage(); }
    try { $forwards = forward_list($box); } catch (Throwable $e) { $forwardError = $e->getMessage(); }
}
$myPersonal = (string) ($me['personal_email'] ?? '');

render_header('Mailbox');
?>
<?php if ($boxes): ?>
<div class="toolbar mbx-toolbar">
    <?php if (count($boxes) > 1): ?>
        <form method="get" class="mbx-switch">
            <i class="fas fa-at"></i>
            <select name="box" onchange="this.form.submit()" aria-label="Mailbox">
                <?php foreach ($boxes as $b): ?>
                    <option value="<?= (int) $b['id'] ?>" <?= $box && (int) $box['id'] === (int) $b['id'] ? 'selected' : '' ?>><?= h($b['address']) ?><?= $b['owner_id'] === null ? ' (shared)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php else: ?>
        <span class="mbx-address"><i class="fas fa-at"></i> <?= h($box['address']) ?></span>
    <?php endif; ?>
    <form class="search" method="get">
        <input type="hidden" name="box" value="<?= (int) $box['id'] ?>">
        <?php if ($folder !== 'INBOX'): ?><input type="hidden" name="folder" value="<?= h($folder) ?>"><?php endif; ?>
        <i class="fas fa-magnifying-glass"></i><input type="search" name="q" value="<?= h($q) ?>" placeholder="Search sender, recipient or subject">
    </form>
    <a href="<?= h(mbx_url(['compose' => 1], ['uid'])) ?>" class="btn btn-primary"><i class="fas fa-pen"></i> Compose</a>
    <details class="menu-details">
        <summary class="btn btn-light" aria-label="Mailbox options"><i class="fas fa-ellipsis"></i></summary>
        <div class="menu-pop">
            <a class="menu-link" href="<?= h(mbx_url(['link' => 1], ['uid'])) ?>"><i class="fas fa-plus"></i> Link another mailbox</a>
            <?php if ($box && can_edit_box($me, $box)): ?>
                <a class="menu-link" href="<?= h(mbx_url(['settings' => 1], ['uid'])) ?>"><i class="fas fa-sliders"></i> Signature, auto-reply &amp; forwarding</a>
                <form method="post" data-confirm="Remove <?= h($box['address']) ?> from the dashboard? The mailbox and its emails stay on the mail server.">
                    <?= csrf_field() ?><input type="hidden" name="action" value="unlink"><input type="hidden" name="box" value="<?= (int) $box['id'] ?>">
                    <button type="submit"><i class="fas fa-link-slash"></i> Remove from dashboard</button>
                </form>
            <?php endif; ?>
        </div>
    </details>
</div>
<?php endif; ?>

<?php if ($showLink): ?>
    <section class="panel mbx-link">
        <h3 class="panel-title"><i class="fas fa-envelope-circle-check"></i> <?= $authFailed ? 'Password changed for ' . h($box['address']) : 'Link a mailbox' ?></h3>
        <p class="muted">Read and answer email for a <strong>@<?= h($orgDomain) ?></strong> address right here in the dashboard. Use the same password as webmail. It's stored encrypted and only used to reach the mail server.</p>
        <form method="post" class="form-stack" autocomplete="off">
            <?= csrf_field() ?><input type="hidden" name="action" value="link">
            <label class="field">Email address
                <input type="email" name="address" required placeholder="info@<?= h($orgDomain) ?>" value="<?= h((string) ($_GET['address'] ?? ($authFailed ? $box['address'] : ''))) ?>">
            </label>
            <label class="field">Mailbox password
                <span class="input-icon"><input type="password" name="password" id="mbx-pass" required autocomplete="new-password"><button type="button" class="toggle-pass" data-target="mbx-pass" aria-label="Show password"><i class="fas fa-eye"></i></button></span>
            </label>
            <label class="field">Sender name <small>Shown to people you email</small>
                <input type="text" name="display_name" maxlength="120" placeholder="Judes CBO" value="<?= h($authFailed ? $box['display_name'] : '') ?>">
            </label>
            <?php if (is_admin()): ?>
                <label class="check"><input type="checkbox" name="shared" value="1" <?= !$boxes ? 'checked' : '' ?>> Shared with the whole team <small class="muted">(e.g. info@ or support@). Leave unticked for your own mailbox.</small></label>
            <?php endif; ?>
            <div class="btn-row">
                <button class="btn btn-primary"><i class="fas fa-link"></i> <?= $authFailed ? 'Save new password' : 'Link mailbox' ?></button>
                <?php if ($boxes && !$authFailed): ?><a href="<?= h(mbx_url([], ['uid'])) ?>" class="btn btn-light">Cancel</a><?php endif; ?>
            </div>
        </form>
    </section>
<?php elseif ($showSettings): ?>
    <section class="panel mbx-link">
        <a href="<?= h(mbx_url([], ['uid'])) ?>" class="back-link"><i class="fas fa-arrow-left"></i> Back to mailbox</a>
        <h3 class="panel-title"><i class="fas fa-signature"></i> <?= h($box['address']) ?></h3>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="settings"><input type="hidden" name="box" value="<?= (int) $box['id'] ?>">
            <label class="field">Sender name <small>Shown to people you email</small>
                <input type="text" name="display_name" maxlength="120" value="<?= h($box['display_name']) ?>">
            </label>
            <label class="field">Signature <small>Added to new emails and replies</small>
                <textarea name="signature" rows="5" maxlength="2000" placeholder="Judes CBO&#10;Homa Bay, Kenya&#10;+254 712 109310 · judescbo.co.ke"><?= h($box['signature'] ?? '') ?></textarea>
            </label>
            <button class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save</button>
        </form>
    </section>
    <section class="panel mbx-link" id="forwarding">
        <h3 class="panel-title"><i class="fas fa-share-from-square"></i> Forwarding</h3>
        <p class="muted">Send a copy of every email that arrives at <?= h($box['address']) ?> to another address, such as your personal Gmail. The original always stays here in the mailbox.</p>
        <?php if ($forwardError): ?><div class="alert alert-error"><?= h($forwardError) ?></div><?php endif; ?>
        <?php if ($forwards): ?>
            <ul class="fwd-list">
                <?php foreach ($forwards as $f): ?>
                    <li><i class="fas fa-arrow-right"></i><strong><?= h($f) ?></strong>
                        <form method="post" data-confirm="Stop forwarding to <?= h($f) ?>?">
                            <?= csrf_field() ?><input type="hidden" name="action" value="forward_remove"><input type="hidden" name="box" value="<?= (int) $box['id'] ?>"><input type="hidden" name="forward_to" value="<?= h($f) ?>">
                            <button class="btn btn-danger-ghost btn-sm"><i class="fas fa-xmark"></i> Stop</button>
                        </form></li>
                <?php endforeach; ?>
            </ul>
        <?php elseif (!$forwardError): ?>
            <p class="empty"><i class="fas fa-share"></i> Not forwarding anywhere.</p>
        <?php endif; ?>
        <form method="post" class="fwd-add">
            <?= csrf_field() ?><input type="hidden" name="action" value="forward_add"><input type="hidden" name="box" value="<?= (int) $box['id'] ?>">
            <input type="email" name="forward_to" required placeholder="name@gmail.com" value="<?= h($myPersonal !== '' && !in_array(strtolower($myPersonal), array_map('strtolower', $forwards), true) ? $myPersonal : '') ?>" aria-label="Forward to">
            <button class="btn btn-primary"><i class="fas fa-plus"></i> Add forwarding</button>
        </form>
        <?php if ($myPersonal === ''): ?>
            <p class="small muted fwd-tip">Tip: add your personal email in <a href="profile.php">My profile</a> and it will be filled in here for you.</p>
        <?php endif; ?>
    </section>
    <section class="panel mbx-link" id="autoreply">
        <h3 class="panel-title"><i class="fas fa-reply-all"></i> Automatic reply</h3>
        <p class="muted">Everyone who emails <?= h($box['address']) ?> gets this reply straight away, at most once a day per sender. It runs on the mail server, so it works even when nobody is logged in.</p>
        <?php if ($autoreplyError): ?><div class="alert alert-error"><?= h($autoreplyError) ?></div><?php endif; ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="autoreply"><input type="hidden" name="box" value="<?= (int) $box['id'] ?>">
            <label class="check"><input type="checkbox" name="autoreply_on" value="1" <?= $autoreply ? 'checked' : '' ?>> Send an automatic reply</label>
            <label class="field">Subject <small>%subject% is replaced with the subject of the email received</small>
                <input type="text" name="autoreply_subject" maxlength="200" value="<?= h($autoreply['subject'] ?? MAIL_AUTOREPLY_SUBJECT) ?>"></label>
            <label class="field">Message
                <textarea name="autoreply_body" rows="9" maxlength="4000"><?= h($autoreply['body'] ?? MAIL_AUTOREPLY_BODY) ?></textarea></label>
            <label class="field">Reply to the same person again after
                <select name="autoreply_interval">
                    <?php foreach ([1 => '1 hour', 8 => '8 hours', 24 => '1 day', 72 => '3 days', 168 => '1 week'] as $hrs => $label): ?>
                        <option value="<?= $hrs ?>" <?= (int) ($autoreply['interval'] ?? 24) === $hrs ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select></label>
            <button class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save automatic reply</button>
        </form>
    </section>
<?php elseif ($error && !$folders): ?>
    <div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?= h($error) ?> <a href="<?= h(mbx_url()) ?>">Try again</a></span></div>
<?php else: ?>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?><button type="button" class="alert-close" aria-label="Dismiss">&times;</button></div><?php endif; ?>
    <div class="mbx <?= $message || $compose ? 'has-open' : '' ?>">
        <nav class="panel mbx-folders" aria-label="Folders">
            <?php foreach ($folders as $f): ?>
                <a href="<?= h(mbx_url(['folder' => $f['name']], ['uid', 'page', 'q', 'filter'])) ?>" class="<?= $f['name'] === $folder ? 'active' : '' ?>">
                    <i class="fas <?= $folderIcon($f['name']) ?>"></i><span><?= h($folderLabel($f['name'])) ?></span>
                    <?php if ($f['unseen'] > 0): ?><em><?= (int) $f['unseen'] ?></em><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <section class="panel mbx-list">
            <form method="post" id="bulk-form">
                <?= csrf_field() ?><input type="hidden" name="box" value="<?= (int) $box['id'] ?>"><input type="hidden" name="folder" value="<?= h($folder) ?>">
                <input type="hidden" name="back" value="<?= h(mbx_url()) ?>">
                <div class="mbx-list-head">
                    <label class="mbx-check" title="Select all"><input type="checkbox" id="mbx-all" aria-label="Select all"></label>
                    <div class="mbx-bulk" id="mbx-bulk" hidden>
                        <button name="action" value="read" class="icon-btn" title="Mark read"><i class="fas fa-envelope-open"></i></button>
                        <button name="action" value="unread" class="icon-btn" title="Mark unread"><i class="fas fa-envelope"></i></button>
                        <button name="action" value="star" class="icon-btn" title="Star"><i class="fas fa-star"></i></button>
                        <button name="action" value="delete" class="icon-btn" title="<?= $folder === ($special['trash'] ?? '') ? 'Delete for good' : 'Delete' ?>"><i class="fas fa-trash"></i></button>
                    </div>
                    <div class="mbx-filters">
                        <a href="<?= h(mbx_url(['filter' => null, 'page' => null], ['uid'])) ?>" class="<?= $filter === '' ? 'active' : '' ?>">All</a>
                        <a href="<?= h(mbx_url(['filter' => 'unread', 'page' => null], ['uid'])) ?>" class="<?= $filter === 'unread' ? 'active' : '' ?>">Unread</a>
                        <a href="<?= h(mbx_url(['filter' => 'starred', 'page' => null], ['uid'])) ?>" class="<?= $filter === 'starred' ? 'active' : '' ?>">Starred</a>
                    </div>
                </div>
                <?php if (!$list): ?>
                    <p class="empty"><i class="fas fa-inbox"></i> <?= $q !== '' || $filter ? 'No emails match.' : 'This folder is empty.' ?></p>
                <?php endif; ?>
                <?php $isSent = $folder === ($special['sent'] ?? '') || $folder === ($special['drafts'] ?? ''); ?>
                <?php foreach ($list as $m):
                    $who = $isSent ? 'To: ' . mbx_name($m['to']) : mbx_name($m['from']); ?>
                    <div class="mbx-item <?= $m['seen'] ? '' : 'unread' ?> <?= $uid === $m['uid'] ? 'active' : '' ?>">
                        <label class="mbx-check"><input type="checkbox" name="uids[]" value="<?= (int) $m['uid'] ?>" aria-label="Select"></label>
                        <a href="<?= h(mbx_url(['uid' => $m['uid']])) ?>" class="mbx-item-link">
                            <span class="mbx-top"><strong><?= h($who) ?></strong><small><?= h(mbx_when($m['date'])) ?></small></span>
                            <span class="mbx-subject"><?= h($m['subject'] !== '' ? $m['subject'] : '(no subject)') ?></span>
                            <span class="mbx-icons">
                                <?php if ($m['flagged']): ?><i class="fas fa-star text-gold" title="Starred"></i><?php endif; ?>
                                <?php if ($m['answered']): ?><i class="fas fa-reply" title="Replied"></i><?php endif; ?>
                                <?php if ($m['attach']): ?><i class="fas fa-paperclip" title="Has attachments"></i><?php endif; ?>
                            </span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </form>
            <?php $pages = (int) ceil($total / MBX_PER_PAGE); if ($pages > 1): ?>
                <nav class="mbx-pager">
                    <?php if ($page > 1): ?><a href="<?= h(mbx_url(['page' => $page - 1], ['uid'])) ?>" class="btn btn-light btn-sm"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
                    <span><?= ($page - 1) * MBX_PER_PAGE + 1 ?>–<?= min($total, $page * MBX_PER_PAGE) ?> of <?= $total ?></span>
                    <?php if ($page < $pages): ?><a href="<?= h(mbx_url(['page' => $page + 1], ['uid'])) ?>" class="btn btn-light btn-sm"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>

        <section class="panel mbx-view">
            <?php if ($compose): ?>
                <a href="<?= h(mbx_url([], ['uid', 'compose', 'mode'])) ?>" class="back-link mbx-back"><i class="fas fa-arrow-left"></i> Back</a>
                <h2 class="mbx-compose-title"><?= ['reply' => 'Reply', 'all' => 'Reply all', 'fwd' => 'Forward'][$compose['mode']] ?? 'New email' ?></h2>
                <form method="post" enctype="multipart/form-data" class="mbx-compose" id="compose-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="send"><input type="hidden" name="box" value="<?= (int) $box['id'] ?>">
                    <input type="hidden" name="mode" value="<?= h($compose['mode']) ?>"><input type="hidden" name="reply_uid" value="<?= (int) $compose['reply_uid'] ?>">
                    <input type="hidden" name="reply_folder" value="<?= h($folder) ?>"><input type="hidden" name="contact" value="<?= (int) $compose['contact'] ?>">
                    <input type="hidden" name="back" value="<?= h('mailbox.php?' . ($_SERVER['QUERY_STRING'] ?? '')) ?>">
                    <div class="mbx-from">From <strong><?= h($box['display_name'] ?: 'Judes CBO') ?> &lt;<?= h($box['address']) ?>&gt;</strong></div>
                    <label class="mbx-row">To <input type="text" name="to" value="<?= h($compose['to']) ?>" placeholder="name@example.com, …" <?= $compose['to'] === '' ? 'autofocus' : '' ?>>
                        <button type="button" class="mbx-ccbtn" id="show-cc" <?= $compose['cc'] !== '' || $compose['bcc'] !== '' ? 'hidden' : '' ?>>Cc / Bcc</button></label>
                    <div id="cc-rows" <?= $compose['cc'] === '' && $compose['bcc'] === '' ? 'hidden' : '' ?>>
                        <label class="mbx-row">Cc <input type="text" name="cc" value="<?= h($compose['cc']) ?>"></label>
                        <label class="mbx-row">Bcc <input type="text" name="bcc" value="<?= h($compose['bcc']) ?>"></label>
                    </div>
                    <label class="mbx-row">Subject <input type="text" name="subject" value="<?= h($compose['subject']) ?>" maxlength="300"></label>
                    <textarea name="body" rows="16" class="mbx-body" <?= $compose['to'] !== '' ? 'autofocus' : '' ?>><?= h($compose['body']) ?></textarea>
                    <?php if ($compose['atts']): ?>
                        <label class="check"><input type="checkbox" name="keep_attachments" value="1" checked> Include the original attachments
                            <small class="muted">(<?= h(implode(', ', array_column($compose['atts'], 'name'))) ?>)</small></label>
                    <?php endif; ?>
                    <div class="mbx-compose-foot">
                        <button class="btn btn-primary" id="send-btn"><i class="fas fa-paper-plane"></i> Send</button>
                        <label class="btn btn-light mbx-attach"><i class="fas fa-paperclip"></i> Attach files<input type="file" name="files[]" multiple id="mbx-files"></label>
                        <span class="muted small" id="mbx-file-list"></span>
                        <a href="<?= h($compose['contact'] ? 'messages.php?id=' . $compose['contact'] : mbx_url([], ['compose', 'mode'])) ?>" class="btn btn-light mbx-discard">Discard</a>
                    </div>
                </form>
            <?php elseif ($message):
                $h = $message['headers'];
                $subject = Mime::decodeHeader($h['subject'][0] ?? '') ?: '(no subject)';
                $fromList = Mime::addresses(Mime::decodeHeader($h['from'][0] ?? ''));
                $from = $fromList[0] ?? ['name' => '', 'email' => ''];
                $hasRemote = false;
                $doc = $message['html'] !== '' ? mbx_html_doc($message, $images, $hasRemote) : null;
                $files = array_filter($message['attachments'], fn($a) => !$a['inline'] || $message['html'] === '');
            ?>
                <a href="<?= h(mbx_url([], ['uid'])) ?>" class="back-link mbx-back"><i class="fas fa-arrow-left"></i> Back to <?= h($folderLabel($folder)) ?></a>
                <h2 class="mbx-subject-big"><?= h($subject) ?></h2>
                <header class="message-head">
                    <span class="avatar"><?= h(mb_strtoupper(mb_substr($from['name'] ?: $from['email'] ?: '?', 0, 1))) ?></span>
                    <div>
                        <strong><?= h($from['name'] ?: $from['email']) ?></strong> <?php if ($from['name']): ?><span class="muted small">&lt;<?= h($from['email']) ?>&gt;</span><?php endif; ?>
                        <p class="small muted">To <?= h(Mime::decodeHeader($h['to'][0] ?? '')) ?><?php if (!empty($h['cc'][0])): ?> · Cc <?= h(Mime::decodeHeader($h['cc'][0])) ?><?php endif; ?></p>
                    </div>
                    <small class="muted"><?= h(date('D j M Y, g:i a', strtotime($h['date'][0] ?? '') ?: time())) ?></small>
                </header>
                <div class="message-actions mbx-actions">
                    <a href="<?= h(mbx_url(['uid' => $uid, 'mode' => 'reply'])) ?>" class="btn btn-primary btn-sm"><i class="fas fa-reply"></i> Reply</a>
                    <a href="<?= h(mbx_url(['uid' => $uid, 'mode' => 'all'])) ?>" class="btn btn-light btn-sm"><i class="fas fa-reply-all"></i> Reply all</a>
                    <a href="<?= h(mbx_url(['uid' => $uid, 'mode' => 'fwd'])) ?>" class="btn btn-light btn-sm"><i class="fas fa-share"></i> Forward</a>
                    <form method="post" class="inline-form">
                        <?= csrf_field() ?><input type="hidden" name="box" value="<?= (int) $box['id'] ?>"><input type="hidden" name="folder" value="<?= h($folder) ?>">
                        <input type="hidden" name="uids" value="<?= $uid ?>"><input type="hidden" name="back" value="<?= h(mbx_url(['uid' => $uid])) ?>">
                        <button name="action" value="<?= $message['flagged'] ? 'unstar' : 'star' ?>" class="icon-btn" title="<?= $message['flagged'] ? 'Remove star' : 'Star' ?>"><i class="<?= $message['flagged'] ? 'fas text-gold' : 'far' ?> fa-star"></i></button>
                        <button name="action" value="unread" class="icon-btn" title="Mark unread"><i class="fas fa-envelope"></i></button>
                        <?php if (($special['junk'] ?? null) && $folder !== $special['junk']): ?><button name="action" value="spam" class="icon-btn" title="Move to Spam"><i class="fas fa-ban"></i></button><?php endif; ?>
                        <button name="action" value="delete" class="icon-btn" title="Delete"><i class="fas fa-trash"></i></button>
                        <select name="to_folder" class="mbx-move" aria-label="Move to folder" onchange="if(this.value){const b=document.createElement('input');b.type='hidden';b.name='action';b.value='move';this.form.appendChild(b);this.form.submit()}">
                            <option value="">Move to…</option>
                            <?php foreach ($folders as $f): if ($f['name'] === $folder) continue; ?>
                                <option value="<?= h($f['name']) ?>"><?= h($folderLabel($f['name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <?php if ($doc !== null): ?>
                    <?php if ($hasRemote && !$images): ?>
                        <div class="mbx-images-note"><i class="far fa-image"></i> Pictures from the internet are hidden to protect your privacy. <a href="<?= h(mbx_url(['uid' => $uid, 'images' => 1])) ?>">Show pictures</a></div>
                    <?php endif; ?>
                    <iframe class="mbx-frame" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" srcdoc="<?= h($doc) ?>" title="Email content"></iframe>
                <?php else: ?>
                    <div class="message-body mbx-text"><?= mbx_text_html($message['text']) ?></div>
                <?php endif; ?>
                <?php if ($files): ?>
                    <div class="mbx-files">
                        <?php foreach ($files as $i => $a):
                            $viewable = preg_match('#^(image/(png|jpe?g|gif|webp)|application/pdf)$#', $a['type']); ?>
                            <span class="mbx-file">
                                <i class="fas <?= str_starts_with($a['type'], 'image/') ? 'fa-file-image' : ($a['type'] === 'application/pdf' ? 'fa-file-pdf' : 'fa-file') ?>"></i>
                                <span><strong><?= h($a['name']) ?></strong><small><?= mbx_size($a['size']) ?></small></span>
                                <?php if ($viewable): ?><a href="<?= h(mbx_url(['uid' => $uid, 'att' => $i, 'view' => 1])) ?>" target="_blank" rel="noopener" class="icon-btn" title="Open"><i class="fas fa-eye"></i></a><?php endif; ?>
                                <a href="<?= h(mbx_url(['uid' => $uid, 'att' => $i])) ?>" class="icon-btn" title="Download"><i class="fas fa-download"></i></a>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-envelope-open"></i><h3>Select an email</h3><p>Choose an email from the list to read it, or write a new one.</p>
                    <a href="<?= h(mbx_url(['compose' => 1])) ?>" class="btn btn-primary"><i class="fas fa-pen"></i> Compose</a></div>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
<script>
(function () {
    const all = document.getElementById('mbx-all');
    const bulk = document.getElementById('mbx-bulk');
    const boxes = () => [...document.querySelectorAll('#bulk-form input[name="uids[]"]')];
    const sync = () => { if (bulk) bulk.hidden = !boxes().some(b => b.checked); };
    if (all) all.addEventListener('change', () => { boxes().forEach(b => b.checked = all.checked); sync(); });
    boxes().forEach(b => b.addEventListener('change', sync));
    const bulkForm = document.getElementById('bulk-form');
    if (bulkForm) bulkForm.addEventListener('submit', e => {
        if (e.submitter && e.submitter.value === 'delete' && !confirm('Delete the selected emails?')) e.preventDefault();
    });
    const cc = document.getElementById('show-cc');
    if (cc) cc.addEventListener('click', () => { document.getElementById('cc-rows').hidden = false; cc.hidden = true; });
    const files = document.getElementById('mbx-files');
    if (files) files.addEventListener('change', () => {
        const list = [...files.files];
        const size = list.reduce((s, f) => s + f.size, 0);
        document.getElementById('mbx-file-list').textContent = list.map(f => f.name).join(', ') + (size > 15 * 1048576 ? ' (over 15 MB, too large)' : '');
    });
    const form = document.getElementById('compose-form');
    if (form) {
        let dirty = false;
        form.addEventListener('input', () => { dirty = true; });
        form.addEventListener('submit', () => { dirty = false; const b = document.getElementById('send-btn'); b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…'; });
        window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
        const body = form.querySelector('textarea[name="body"]');
        if (body && document.activeElement === body) { body.setSelectionRange(0, 0); body.scrollTop = 0; }
    }
})();
</script>
<?php render_footer(); ?>
