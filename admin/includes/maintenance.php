<?php
declare(strict_types=1);

require_once __DIR__ . '/publisher.php';

/*
 * Public-site maintenance mode. The website is static, so it's switched by
 * writing a block of Apache rules into the site's .htaccess: every page answers
 * "503 Service Unavailable" with maintenance.html, except for the dashboard,
 * allowed IP addresses and signed-in staff (who carry a bypass cookie).
 * Needs Apache hosting (cPanel); GitHub Pages ignores .htaccess.
 */

const MAINT_BEGIN = '# BEGIN maintenance mode (managed by the dashboard, do not edit)';
const MAINT_END = '# END maintenance mode';

function site_base_path(): string
{
    $path = parse_url((string) config('site_url'), PHP_URL_PATH) ?: '/';
    return '/' . trim($path, '/') . (trim($path, '/') === '' ? '' : '/');
}

/** IP addresses (one per line) that still see the website during maintenance. */
function maintenance_ips(): array
{
    $ips = preg_split('/[\s,]+/', setting('maint_ips'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique(array_filter($ips, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP))));
}

function maintenance_rules(): string
{
    if (!maintenance_on()) {
        return MAINT_BEGIN . "\n# (off)\n" . MAINT_END;
    }
    $base = site_base_path();
    $lines = [
        MAINT_BEGIN,
        'ErrorDocument 503 ' . $base . 'maintenance.html',
        'RewriteCond %{REQUEST_URI} !^' . preg_quote($base, ' ') . '(admin/|maintenance(\.html)?$|sw\.js$|site\.webmanifest$|favicon\.ico$|images/|css/|js/|uploads/)',
    ];
    $token = setting('maint_token');
    if (setting('maint_staff_bypass', '1') === '1' && preg_match('/^[a-f0-9]{32}$/', $token)) {
        $lines[] = 'RewriteCond %{HTTP_COOKIE} !(^|;\s*)judes_bypass=' . $token . '(;|$)';
    }
    foreach (maintenance_ips() as $ip) {
        $lines[] = 'RewriteCond %{REMOTE_ADDR} !^' . preg_quote($ip, ' ') . '$';
    }
    $lines[] = 'RewriteRule ^ - [R=503,L]';
    $lines[] = '<IfModule mod_headers.c>';
    $lines[] = '    Header always set Retry-After "' . maintenance_retry_after() . '" "expr=%{REQUEST_STATUS} == 503"';
    $lines[] = '    Header always set Cache-Control "no-store" "expr=%{REQUEST_STATUS} == 503"';
    $lines[] = '</IfModule>';
    $lines[] = MAINT_END;
    return implode("\n", $lines);
}

/** Seconds until the expected end, so search engines come back then instead of dropping pages. */
function maintenance_retry_after(): int
{
    $until = strtotime(setting('maint_until'));
    return $until && $until > time() ? min($until - time(), 86400 * 3) : 3600;
}

function write_htaccess_maintenance(): bool
{
    $path = site_path('.htaccess');
    if (!is_file($path) || !is_writable($path)) return false;
    $htaccess = file_get_contents($path);
    $block = maintenance_rules();
    $pattern = '/' . preg_quote(MAINT_BEGIN, '/') . '.*?' . preg_quote(MAINT_END, '/') . '/s';
    if (preg_match($pattern, $htaccess)) {
        $updated = preg_replace_callback($pattern, fn() => $block, $htaccess, 1);
    } elseif (preg_match('/^RewriteEngine On\s*$/m', $htaccess)) {
        // Right after RewriteEngine so it runs before the clean-URL rules
        $updated = preg_replace_callback('/^RewriteEngine On\s*$/m', fn($m) => rtrim($m[0]) . "\n\n" . $block . "\n", $htaccess, 1);
    } else {
        $updated = rtrim($htaccess) . "\n\nRewriteEngine On\n" . $block . "\n";
    }
    if ($updated !== $htaccess) write_atomic($path, $updated);
    return true;
}

function render_maintenance_page(): string
{
    $base = site_base_path();
    $title_h = h(setting('maint_title') ?: "We'll be back soon");
    $message = setting('maint_message') ?: "We're making some improvements to our website. Thank you for your patience.";
    $until = strtotime(setting('maint_until'));
    $phone = '+254712109310';
    $email = setting('org_email', 'info@judescbo.org');
    $contact = setting('maint_show_contact', '1') === '1';
    $paras = implode("\n", array_map(fn($p) => '            <p class="lead">' . nl2br(h(trim($p)), false) . '</p>', array_filter(preg_split('/\R{2,}/', trim($message)) ?: [])));
    $whatsapp = 'https://wa.me/254712109310?text=' . rawurlencode('Hello Judes CBO!');

    $countdown = $until && $until > time()
        ? '            <div class="eta" id="eta" data-until="' . date('c', $until) . '">' . "\n"
        . '                <span class="eta-label">Expected back</span>' . "\n"
        . '                <strong>' . h(date('D j M, g:i a', $until)) . '</strong>' . "\n"
        . '                <div class="countdown" id="countdown" aria-live="polite"></div>' . "\n"
        . "            </div>\n"
        : '';
    $contactHtml = $contact
        ? '            <div class="contact">' . "\n"
        . '                <a href="tel:' . $phone . '" class="btn"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg> Call us</a>' . "\n"
        . '                <a href="' . h($whatsapp) . '" class="btn"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg> WhatsApp</a>' . "\n"
        . '                <a href="mailto:' . h($email) . '" class="btn"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg> Email</a>' . "\n"
        . "            </div>\n"
        : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title_h} | Judes CBO</title>
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#0f2440">
    <link rel="icon" href="{$base}images/icons/favicon-32.png" type="image/png">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 40px 16px;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: rgba(255,255,255,0.82); text-align: center;
            background: #0f2440;
            background-image: radial-gradient(ellipse at 12% 0%, rgba(45,80,22,0.85), transparent 55%), radial-gradient(ellipse at 95% 100%, rgba(229,62,62,0.22), transparent 50%);
            background-attachment: fixed;
        }
        main { width: 100%; max-width: 560px; }
        .badge { position: relative; width: 112px; height: 112px; margin: 0 auto 24px; }
        .logo { width: 112px; height: 112px; border-radius: 32px; background: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 18px 44px rgba(0,0,0,0.35); }
        .logo img { width: 84px; height: 84px; }
        .gear { position: absolute; right: -10px; bottom: -10px; width: 46px; height: 46px; border-radius: 50%; background: #d69e2e; color: #fff; display: flex; align-items: center; justify-content: center; border: 4px solid #0f2440; }
        .gear svg { animation: spin 6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .status { display: inline-flex; align-items: center; gap: 8px; padding: 7px 14px; border-radius: 999px; background: rgba(255,255,255,0.1); font-size: 14px; font-weight: 600; margin-bottom: 16px; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: #f6ad55; animation: pulse 1.6s infinite; }
        @keyframes pulse { 50% { opacity: .3; } }
        h1 { color: #fff; font-size: clamp(26px, 7vw, 36px); line-height: 1.15; margin-bottom: 14px; letter-spacing: -.3px; }
        .lead { font-size: 16px; line-height: 1.65; max-width: 460px; margin: 0 auto 12px; }
        .bar { height: 6px; max-width: 280px; margin: 24px auto; border-radius: 999px; background: rgba(255,255,255,0.12); overflow: hidden; }
        .bar span { display: block; width: 40%; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #68d391, #f6ad55); animation: slide 1.8s ease-in-out infinite; }
        @keyframes slide { 0% { transform: translateX(-100%); } 100% { transform: translateX(250%); } }
        .eta { margin: 8px auto 24px; padding: 16px 18px; max-width: 420px; border-radius: 18px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.14); }
        .eta-label { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: 1.2px; opacity: .7; margin-bottom: 4px; }
        .eta strong { color: #fff; font-size: 18px; }
        .countdown { display: flex; justify-content: center; gap: 10px; margin-top: 12px; }
        .countdown div { min-width: 62px; padding: 8px 6px; border-radius: 12px; background: rgba(0,0,0,0.25); }
        .countdown b { display: block; color: #fff; font-size: 22px; font-variant-numeric: tabular-nums; }
        .countdown small { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; opacity: .7; }
        .contact { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin-top: 8px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 46px; padding: 11px 18px; border-radius: 14px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.2); color: #fff; font-weight: 700; font-size: 15px; text-decoration: none; transition: background .2s; }
        .btn:hover { background: rgba(255,255,255,0.16); }
        .btn:focus-visible { outline: 3px solid #68d391; outline-offset: 2px; }
        footer { margin-top: 30px; font-size: 13px; opacity: .6; line-height: 1.6; }
        @media (max-width: 420px) {
            .contact .btn { flex: 1 1 100%; }
            .countdown div { min-width: 0; flex: 1; }
        }
        @media (prefers-reduced-motion: reduce) { .gear svg, .dot, .bar span { animation: none; } }
    </style>
</head>
<body>
    <main>
        <div class="badge">
            <div class="logo"><img src="{$base}images/icons/icon-192.png" alt="Judes CBO"></div>
            <span class="gear" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg></span>
        </div>
        <span class="status"><span class="dot"></span>Scheduled maintenance</span>
        <h1>{$title_h}</h1>
{$paras}
        <div class="bar" aria-hidden="true"><span></span></div>
{$countdown}{$contactHtml}        <footer>Judes Community Based Organization<br>Oyugis, Homa Bay County</footer>
    </main>
    <script>
    (function () {
        var eta = document.getElementById('eta');
        if (eta) {
            var until = new Date(eta.dataset.until).getTime();
            var box = document.getElementById('countdown');
            var tick = function () {
                var s = Math.max(0, Math.round((until - Date.now()) / 1000));
                var parts = [[Math.floor(s / 86400), 'days'], [Math.floor(s % 86400 / 3600), 'hrs'], [Math.floor(s % 3600 / 60), 'min'], [s % 60, 'sec']];
                if (parts[0][0] === 0) parts.shift();
                box.innerHTML = parts.map(function (p) { return '<div><b>' + String(p[0]).padStart(2, '0') + '</b><small>' + p[1] + '</small></div>'; }).join('');
                if (s === 0) { clearInterval(timer); setTimeout(check, 3000); }
            };
            var timer = setInterval(tick, 1000);
            tick();
        }
        // Reload as soon as the website is back
        function check() {
            fetch('{$base}admin/api/maintenance.php', { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d && d.on === false) location.reload(); })
                .catch(function () {});
        }
        setInterval(check, 60000);
    })();
    </script>
</body>
</html>

HTML;
}

/** Writes maintenance.html and switches the .htaccess rules to match the current settings. */
function write_maintenance_files(): bool
{
    write_atomic(site_path('maintenance.html'), render_maintenance_page());
    return write_htaccess_maintenance();
}
