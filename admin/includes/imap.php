<?php
declare(strict_types=1);

/**
 * Small IMAP client over a TLS socket (no imap extension needed).
 * Covers what the dashboard mailbox uses: folders, listing, reading, flags, moving and saving sent mail.
 */
final class ImapClient
{
    /** @var resource */
    private $sock;
    private int $tag = 0;
    private array $literals = [];
    public array $caps = [];

    public function __construct(string $host, int $port = 993, int $timeout = 20)
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => (bool) (config('mail_verify_ssl') ?? true),
            'verify_peer_name' => (bool) (config('mail_verify_ssl') ?? true),
            'peer_name' => $host,
        ]]);
        $sock = @stream_socket_client("ssl://$host:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) throw new RuntimeException("Couldn't reach the mail server ($errstr).");
        stream_set_timeout($sock, $timeout);
        $this->sock = $sock;
        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK')) throw new RuntimeException('The mail server refused the connection.');
    }

    public function __destruct()
    {
        if (is_resource($this->sock)) {
            @fwrite($this->sock, 'Z' . ($this->tag + 1) . " LOGOUT\r\n");
            @fclose($this->sock);
        }
    }

    public static function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    /** Folder names on the wire use modified UTF-7. */
    public static function encodeFolder(string $name): string
    {
        return self::quote(mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8'));
    }

    public static function decodeFolder(string $name): string
    {
        return mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
    }

    public function login(string $user, string $pass): void
    {
        [$ok, , $text] = $this->cmd('LOGIN ' . self::quote($user) . ' ' . self::quote($pass), false);
        if (!$ok) throw new ImapAuthError($text ?: 'Login failed.');
        [, $lines] = $this->cmd('CAPABILITY');
        foreach ($lines as $l) {
            if (stripos($l, '* CAPABILITY') === 0) $this->caps = array_map('strtoupper', explode(' ', substr($l, 13)));
        }
    }

    public function has(string $cap): bool
    {
        return in_array(strtoupper($cap), $this->caps, true);
    }

    /** Returns [['name' => raw, 'label' => utf8, 'flags' => [...], 'delim' => '.']]. */
    public function folders(): array
    {
        [, $lines] = $this->cmd('LIST "" "*"');
        $out = [];
        foreach ($lines as $l) {
            if (stripos($l, '* LIST ') !== 0) continue;
            $t = $this->tokens(substr($l, 7));
            $flags = is_array($t[0] ?? null) ? array_map('strtolower', $t[0]) : [];
            if (in_array('\\noselect', $flags, true) || in_array('\\nonexistent', $flags, true)) continue;
            $name = (string) ($t[2] ?? '');
            $out[] = ['name' => $name, 'label' => self::decodeFolder($name), 'flags' => $flags, 'delim' => is_string($t[1] ?? null) ? $t[1] : '.'];
        }
        return $out;
    }

    public function status(string $folder): array
    {
        [$ok, $lines] = $this->cmd('STATUS ' . self::encodeFolder($folder) . ' (MESSAGES UNSEEN)', false);
        $res = ['messages' => 0, 'unseen' => 0];
        if (!$ok) return $res;
        foreach ($lines as $l) {
            if (preg_match('/MESSAGES (\d+)/i', $l, $m)) $res['messages'] = (int) $m[1];
            if (preg_match('/UNSEEN (\d+)/i', $l, $m)) $res['unseen'] = (int) $m[1];
        }
        return $res;
    }

    public function select(string $folder): int
    {
        [$ok, $lines, $text] = $this->cmd('SELECT ' . self::encodeFolder($folder), false);
        if (!$ok) throw new RuntimeException("Couldn't open the folder: $text");
        foreach ($lines as $l) {
            if (preg_match('/^\* (\d+) EXISTS/i', $l, $m)) return (int) $m[1];
        }
        return 0;
    }

    /** UIDs matching the search, newest first. $query is plain text searched in From, To and Subject. */
    public function search(string $query = '', bool $unseenOnly = false, bool $flaggedOnly = false, string $tab = ''): array
    {
        $crit = [];
        // Newsletters and marketing carry a List-Unsubscribe header
        if ($tab === 'promo') $crit[] = 'HEADER List-Unsubscribe ""';
        if ($tab === 'primary') $crit[] = 'NOT HEADER List-Unsubscribe ""';
        $charset = '';
        if ($query !== '') {
            $q = str_replace(["\r", "\n"], ' ', $query);
            if (preg_match('/[^\x20-\x7e]/', $q)) {
                // Non-ASCII search terms are sent as literals
                $charset = 'CHARSET UTF-8 ';
                $lit = fn() => '{' . strlen($q) . "}\r\n" . $q;
                $crit[] = 'OR OR FROM ' . $lit() . ' SUBJECT ' . $lit() . ' TO ' . $lit();
            } else {
                $crit[] = 'OR OR FROM ' . self::quote($q) . ' SUBJECT ' . self::quote($q) . ' TO ' . self::quote($q);
            }
        }
        if ($unseenOnly) $crit[] = 'UNSEEN';
        if ($flaggedOnly) $crit[] = 'FLAGGED';
        if (!$crit) $crit[] = 'ALL';
        [, $lines] = $this->cmd('UID SEARCH ' . $charset . implode(' ', $crit));
        $uids = [];
        foreach ($lines as $l) {
            if (stripos($l, '* SEARCH') === 0) {
                foreach (preg_split('/\s+/', trim(substr($l, 8))) as $u) if ($u !== '') $uids[] = (int) $u;
            }
        }
        rsort($uids);
        return $uids;
    }

    /** Envelope summaries for the given UIDs, keyed by UID. */
    public function summaries(array $uids): array
    {
        if (!$uids) return [];
        [, $lines] = $this->cmd('UID FETCH ' . implode(',', $uids) . ' (UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE LIST-UNSUBSCRIBE)] BODY.PEEK[TEXT]<0.1500>)');
        $out = [];
        foreach ($lines as $l) {
            $f = $this->fetchData($l);
            if (!$f || !isset($f['UID'])) continue;
            $hdr = '';
            $text = '';
            foreach ($f as $k => $v) {
                if (str_starts_with($k, 'BODY[HEADER')) $hdr = (string) $v;
                if (str_starts_with($k, 'BODY[TEXT]')) $text = (string) $v;
            }
            $h = Mime::headers($hdr);
            $flags = array_map('strtolower', (array) ($f['FLAGS'] ?? []));
            $out[(int) $f['UID']] = [
                'uid' => (int) $f['UID'],
                'from' => Mime::decodeHeader($h['from'][0] ?? ''),
                'to' => Mime::decodeHeader($h['to'][0] ?? ''),
                'subject' => Mime::decodeHeader($h['subject'][0] ?? ''),
                'date' => strtotime((string) ($f['INTERNALDATE'] ?? '')) ?: (strtotime($h['date'][0] ?? '') ?: time()),
                'size' => (int) ($f['RFC822.SIZE'] ?? 0),
                'seen' => in_array('\\seen', $flags, true),
                'flagged' => in_array('\\flagged', $flags, true),
                'answered' => in_array('\\answered', $flags, true),
                'attach' => self::hasAttachment($f['BODYSTRUCTURE'] ?? null),
                'promo' => !empty($h['list-unsubscribe']),
                'snippet' => self::snippet($text),
            ];
        }
        return $out;
    }

    /** A short plain-text preview from the start of the body (best effort, no full MIME parse). */
    private static function snippet(string $raw): string
    {
        if ($raw === '') return '';
        // Skip a MIME part header block if the body starts with a boundary
        if (preg_match('/^\s*--\S+\r?\n/', $raw)) {
            $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
            $head = $parts[0];
            $raw = $parts[1] ?? '';
            if (stripos($head, 'base64') !== false) {
                $raw = (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', (string) preg_replace('/\r?\n--.*/s', '', $raw)));
            } elseif (stripos($head, 'quoted-printable') !== false) {
                $raw = quoted_printable_decode($raw);
            }
        } elseif (preg_match('/=\r?\n|=[0-9A-F]{2}/', $raw)) {
            $raw = quoted_printable_decode($raw);
        } elseif (preg_match('/^[A-Za-z0-9+\/=\r\n]{80,}$/', trim(substr($raw, 0, 400)))) {
            $raw = (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $raw));
        }
        $raw = preg_replace('/\r?\n--[^\r\n]+.*$/s', '', $raw);
        $raw = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', ' ', (string) $raw);
        // Block-level tags become spaces so words don't run together
        $raw = preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr|/td|/table)\b[^>]*>#i', ' ', (string) $raw);
        $text = html_entity_decode(strip_tags((string) $raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/^>.*$/m', '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
        if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        return mb_strimwidth($text, 0, 140, '…');
    }

    private static function hasAttachment($bs): bool
    {
        if (!is_array($bs)) return false;
        $flat = json_encode($bs);
        return (bool) preg_match('/"(attachment)"/i', (string) $flat) || (bool) preg_match('/"(application|audio|video)"/i', (string) $flat);
    }

    /** Full raw message (marks it read unless $peek). */
    public function raw(int $uid, bool $peek = false): ?string
    {
        [, $lines] = $this->cmd("UID FETCH $uid (UID RFC822.SIZE BODY" . ($peek ? '.PEEK' : '') . '[])');
        foreach ($lines as $l) {
            $f = $this->fetchData($l);
            if ($f && (int) ($f['UID'] ?? 0) === $uid && isset($f['BODY[]'])) return (string) $f['BODY[]'];
        }
        return null;
    }

    public function size(int $uid): int
    {
        [, $lines] = $this->cmd("UID FETCH $uid (UID RFC822.SIZE)");
        foreach ($lines as $l) {
            $f = $this->fetchData($l);
            if ($f && (int) ($f['UID'] ?? 0) === $uid) return (int) ($f['RFC822.SIZE'] ?? 0);
        }
        return 0;
    }

    public function setFlag(array $uids, string $flag, bool $on): void
    {
        if (!$uids) return;
        $this->cmd('UID STORE ' . implode(',', array_map('intval', $uids)) . ' ' . ($on ? '+' : '-') . "FLAGS.SILENT ($flag)");
    }

    public function move(array $uids, string $folder): void
    {
        if (!$uids) return;
        $set = implode(',', array_map('intval', $uids));
        if ($this->has('MOVE')) {
            $this->cmd("UID MOVE $set " . self::encodeFolder($folder));
            return;
        }
        $this->cmd("UID COPY $set " . self::encodeFolder($folder));
        $this->setFlag($uids, '\\Deleted', true);
        $this->expunge($uids);
    }

    public function expunge(array $uids): void
    {
        $set = implode(',', array_map('intval', $uids));
        $this->has('UIDPLUS') ? $this->cmd("UID EXPUNGE $set") : $this->cmd('EXPUNGE');
    }

    public function append(string $folder, string $message, string $flags = '\\Seen'): bool
    {
        $this->literals = [];
        $tag = 'A' . (++$this->tag);
        fwrite($this->sock, "$tag APPEND " . self::encodeFolder($folder) . " ($flags) {" . strlen($message) . "}\r\n");
        $line = $this->readLine();
        if (!str_starts_with($line, '+')) return false;
        fwrite($this->sock, $message . "\r\n");
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, "$tag ")) return stripos($line, "$tag OK") === 0;
        }
    }

    public function createFolder(string $name): bool
    {
        [$ok] = $this->cmd('CREATE ' . self::encodeFolder($name), false);
        if ($ok) $this->cmd('SUBSCRIBE ' . self::encodeFolder($name), false);
        return $ok;
    }

    /* ---------- protocol ---------- */

    /** Sends a command; returns [ok, untagged logical lines, status text]. Throws on failure unless $throw is false. */
    private function cmd(string $command, bool $throw = true): array
    {
        $this->literals = [];
        $tag = 'A' . (++$this->tag);
        // Inline literals in the command ({n}\r\ndata) need the server's go-ahead
        $parts = preg_split('/(\{\d+\}\r\n)/', $command, -1, PREG_SPLIT_DELIM_CAPTURE);
        fwrite($this->sock, "$tag " . array_shift($parts));
        while ($parts) {
            $marker = array_shift($parts);
            fwrite($this->sock, $marker);
            $cont = $this->readLine();
            if (!str_starts_with($cont, '+')) break;
            fwrite($this->sock, (string) array_shift($parts));
        }
        fwrite($this->sock, "\r\n");
        $lines = [];
        while (true) {
            $line = $this->readLogical();
            if (str_starts_with($line, "$tag ")) {
                $rest = substr($line, strlen($tag) + 1);
                $ok = stripos($rest, 'OK') === 0;
                $text = trim(preg_replace('/^(OK|NO|BAD)\s*(\[[^\]]*\])?/i', '', $rest));
                if (!$ok && $throw) throw new RuntimeException('Mail server error: ' . $text);
                return [$ok, $lines, $text];
            }
            $lines[] = $line;
        }
    }

    private function readLine(): string
    {
        $line = @fgets($this->sock);
        if ($line === false) {
            $meta = stream_get_meta_data($this->sock);
            throw new RuntimeException($meta['timed_out'] ? 'The mail server took too long to answer.' : 'Lost the connection to the mail server.');
        }
        return rtrim($line, "\r\n");
    }

    /** Reads one response line, pulling in {n} literals and replacing them with \0index\0 markers. */
    private function readLogical(): string
    {
        $out = '';
        while (true) {
            $line = $this->readLine();
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $out .= substr($line, 0, -strlen($m[0]));
                $len = (int) $m[1];
                $data = '';
                while (strlen($data) < $len) {
                    $chunk = @fread($this->sock, min(65536, $len - strlen($data)));
                    if ($chunk === false || $chunk === '') throw new RuntimeException('Lost the connection to the mail server.');
                    $data .= $chunk;
                }
                $this->literals[] = $data;
                $out .= "\0" . (count($this->literals) - 1) . "\0";
                continue;
            }
            return $out . $line;
        }
    }

    /** Parses "* n FETCH (...)" into an assoc array. */
    private function fetchData(string $line): ?array
    {
        if (!preg_match('/^\* \d+ FETCH /i', $line)) return null;
        $t = $this->tokens(preg_replace('/^\* \d+ FETCH /i', '', $line));
        $list = $t[0] ?? null;
        if (!is_array($list)) return null;
        $out = [];
        for ($i = 0; $i + 1 < count($list); $i += 2) {
            $out[strtoupper((string) $list[$i])] = $list[$i + 1];
        }
        return $out;
    }

    /** Tokenises an IMAP response: atoms, "quoted", literals and (nested lists). */
    private function tokens(string $s): array
    {
        $pos = 0;
        return $this->parseList($s, $pos, false);
    }

    private function parseList(string $s, int &$pos, bool $nested): array
    {
        $out = [];
        $len = strlen($s);
        while ($pos < $len) {
            $c = $s[$pos];
            if ($c === ' ') { $pos++; continue; }
            if ($c === ')') { $pos++; if ($nested) return $out; continue; }
            if ($c === '(') { $pos++; $out[] = $this->parseList($s, $pos, true); continue; }
            if ($c === '"') {
                $pos++;
                $str = '';
                while ($pos < $len && $s[$pos] !== '"') {
                    if ($s[$pos] === '\\' && $pos + 1 < $len) $pos++;
                    $str .= $s[$pos++];
                }
                $pos++;
                $out[] = $str;
                continue;
            }
            if ($c === "\0") {
                $end = strpos($s, "\0", $pos + 1);
                $out[] = $this->literals[(int) substr($s, $pos + 1, $end - $pos - 1)] ?? '';
                $pos = $end + 1;
                continue;
            }
            // Atom; BODY[...] sections may contain spaces and parentheses
            $atom = '';
            while ($pos < $len && !in_array($s[$pos], [' ', '(', ')'], true)) {
                if ($s[$pos] === '[') {
                    $close = strpos($s, ']', $pos);
                    if ($close === false) $close = $len - 1;
                    $atom .= substr($s, $pos, $close - $pos + 1);
                    $pos = $close + 1;
                    continue;
                }
                $atom .= $s[$pos++];
            }
            $out[] = strtoupper($atom) === 'NIL' ? null : $atom;
        }
        return $out;
    }
}

final class ImapAuthError extends RuntimeException {}

/** MIME parsing helpers for reading messages. */
final class Mime
{
    /** Parses a header block into lowercase name => [values]. */
    public static function headers(string $block): array
    {
        $block = preg_replace("/\r?\n[ \t]+/", ' ', $block);
        $out = [];
        foreach (preg_split("/\r?\n/", (string) $block) as $line) {
            $p = strpos($line, ':');
            if ($p === false) continue;
            $out[strtolower(trim(substr($line, 0, $p)))][] = trim(substr($line, $p + 1));
        }
        return $out;
    }

    public static function decodeHeader(string $v): string
    {
        if ($v === '') return '';
        if (str_contains($v, '=?')) {
            // Join adjacent encoded words, then decode
            $v = preg_replace('/\?=\s+=\?/', '?==?', $v);
            $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($d !== false) $v = $d;
        }
        return self::toUtf8($v, 'UTF-8');
    }

    public static function toUtf8(string $s, string $charset): string
    {
        $charset = strtoupper(trim($charset, " \"'")) ?: 'UTF-8';
        if (in_array($charset, ['UTF-8', 'US-ASCII', 'ASCII'], true)) {
            return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        if ($charset === 'ISO-8859-1' || $charset === 'LATIN1') $charset = 'Windows-1252';
        $c = @iconv($charset, 'UTF-8//IGNORE', $s);
        return $c !== false ? $c : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    /** Splits "Type/sub; a=b; c="d"" into [type, params]. Handles RFC 2231 (name*=utf-8''...). */
    public static function params(string $v): array
    {
        $parts = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $v);
        $main = strtolower(trim((string) array_shift($parts)));
        $params = [];
        $ext = [];
        foreach ($parts as $p) {
            $eq = strpos($p, '=');
            if ($eq === false) continue;
            $k = strtolower(trim(substr($p, 0, $eq)));
            $val = trim(substr($p, $eq + 1));
            if (strlen($val) > 1 && $val[0] === '"' && str_ends_with($val, '"')) $val = stripslashes(substr($val, 1, -1));
            if (preg_match('/^([a-z0-9_-]+)\*(\d+)?(\*)?$/', $k, $m)) {
                $ext[$m[1]][(int) ($m[2] ?? 0)] = [$val, isset($m[3]) || ($m[2] ?? '') === ''];
                continue;
            }
            $params[$k] = self::decodeHeader($val);
        }
        foreach ($ext as $k => $chunks) {
            ksort($chunks);
            $joined = '';
            $charset = 'UTF-8';
            foreach ($chunks as $i => [$val, $encoded]) {
                if ($i === 0 && $encoded && preg_match("/^([^']*)'[^']*'(.*)$/", $val, $m)) { $charset = $m[1] ?: 'UTF-8'; $val = $m[2]; }
                $joined .= $encoded ? rawurldecode($val) : $val;
            }
            $params[$k] = self::toUtf8($joined, $charset);
        }
        return [$main, $params];
    }

    /**
     * Parses a raw message into ['headers', 'text', 'html', 'attachments' => [[name, type, size, data, cid, inline]]].
     */
    public static function parse(string $raw): array
    {
        $msg = ['headers' => [], 'text' => '', 'html' => '', 'attachments' => []];
        [$h, $body] = self::split($raw);
        $msg['headers'] = $h;
        self::walk($h, $body, $msg, 0);
        return $msg;
    }

    private static function split(string $raw): array
    {
        $p = strpos($raw, "\r\n\r\n");
        $sepLen = 4;
        $q = strpos($raw, "\n\n");
        if ($p === false || ($q !== false && $q < $p)) { $p = $q; $sepLen = 2; }
        if ($p === false) return [self::headers($raw), ''];
        return [self::headers(substr($raw, 0, $p)), substr($raw, $p + $sepLen)];
    }

    private static function walk(array $h, string $body, array &$msg, int $depth): void
    {
        [$type, $params] = self::params($h['content-type'][0] ?? 'text/plain');
        [$disp, $dparams] = self::params($h['content-disposition'][0] ?? '');
        if (str_starts_with($type, 'multipart/') && !empty($params['boundary']) && $depth < 20) {
            $b = '--' . $params['boundary'];
            $chunks = explode($b, $body);
            array_shift($chunks); // preamble
            foreach ($chunks as $chunk) {
                if (str_starts_with($chunk, '--')) break;
                $chunk = preg_replace('/^\r?\n/', '', $chunk);
                $chunk = preg_replace('/\r?\n$/', '', (string) $chunk);
                [$ph, $pb] = self::split((string) $chunk);
                self::walk($ph, $pb, $msg, $depth + 1);
            }
            return;
        }
        $data = self::decodeBody($body, strtolower(trim($h['content-transfer-encoding'][0] ?? '')));
        $name = $dparams['filename'] ?? ($params['name'] ?? '');
        $cid = trim($h['content-id'][0] ?? '', " <>");
        $isAttachment = $disp === 'attachment' || ($name !== '' && !in_array($type, ['text/plain', 'text/html'], true)) || $name !== '' && $disp !== 'inline';
        if (!$isAttachment && $type === 'text/plain' && $msg['text'] === '') {
            $msg['text'] = self::toUtf8($data, $params['charset'] ?? 'UTF-8');
            return;
        }
        if (!$isAttachment && $type === 'text/html' && $msg['html'] === '') {
            $msg['html'] = self::toUtf8($data, $params['charset'] ?? 'UTF-8');
            return;
        }
        if (!$isAttachment && str_starts_with($type, 'text/') && $name === '') {
            return; // alternative text parts we don't show (e.g. text/calendar body is kept below only when named)
        }
        if ($type === 'message/rfc822' && $name === '') $name = 'attached-message.eml';
        if ($name === '') $name = 'attachment' . (count($msg['attachments']) + 1) . self::extFor($type);
        $msg['attachments'][] = [
            'name' => self::cleanName($name),
            'type' => $type,
            'size' => strlen($data),
            'data' => $data,
            'cid' => $cid,
            'inline' => $cid !== '' && $disp !== 'attachment' && str_starts_with($type, 'image/'),
        ];
    }

    private static function decodeBody(string $body, string $enc): string
    {
        return match ($enc) {
            'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private static function extFor(string $type): string
    {
        return ['image/png' => '.png', 'image/jpeg' => '.jpg', 'image/gif' => '.gif', 'application/pdf' => '.pdf', 'text/calendar' => '.ics', 'message/rfc822' => '.eml'][$type] ?? '';
    }

    public static function cleanName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/', '_', $name);
        return mb_substr(trim((string) $name, ' ._') ?: 'attachment', 0, 120);
    }

    /** "Name <a@b>" list → [['name'=>..,'email'=>..]]. */
    public static function addresses(string $v): array
    {
        $out = [];
        foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $v) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            if (preg_match('/^(.*)<([^>]+)>$/', $part, $m)) {
                $out[] = ['name' => trim($m[1], " \"'"), 'email' => trim($m[2])];
            } elseif (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $out[] = ['name' => '', 'email' => $part];
            }
        }
        return $out;
    }
}
