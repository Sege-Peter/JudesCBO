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

/** SVG path for a gear centred on 0,0. */
function gear_path(float $outer, float $inner, int $teeth, float $hole): string
{
    $points = [];
    $step = M_PI * 2 / $teeth;
    for ($i = 0; $i < $teeth; $i++) {
        $a = $i * $step;
        foreach ([[-0.32, $inner], [-0.18, $outer], [0.18, $outer], [0.32, $inner]] as [$offset, $r]) {
            $points[] = round(cos($a + $offset * $step * 1.4) * $r, 2) . ' ' . round(sin($a + $offset * $step * 1.4) * $r, 2);
        }
    }
    // Outline plus a hole (even-odd fill)
    return 'M' . implode(' L', $points) . ' Z M' . $hole . ' 0 A' . $hole . ' ' . $hole . ' 0 1 0 -' . $hole . ' 0 A' . $hole . ' ' . $hole . ' 0 1 0 ' . $hole . ' 0 Z';
}

function render_maintenance_page(): string
{
    $base = site_base_path();
    $title_h = h(setting('maint_title') ?: "We'll be back soon");
    $message = setting('maint_message') ?: "We're making some improvements to our website. Thank you for your patience.";
    $until = strtotime(setting('maint_until'));
    $started = strtotime(setting('maint_started')) ?: time();
    $email = setting('org_email', 'info@judescbo.co.ke');
    $contact = setting('maint_show_contact', '1') === '1';
    $whatsapp = 'https://wa.me/254712109310?text=' . rawurlencode('Hello Judes CBO!');
    $year = date('Y');
    $bigGear = gear_path(54, 44, 12, 14);
    $smallGear = gear_path(32, 25, 8, 9);

    $paras = '';
    $d = 0.55;
    foreach (array_filter(preg_split('/\R{2,}/', trim($message)) ?: []) as $p) {
        $paras .= '            <p class="lead rise" style="--d:' . $d . 's">' . nl2br(h(trim($p)), false) . "</p>\n";
        $d += 0.08;
    }

    $timed = $until && $until > time();
    $progress = $timed
        ? '            <div class="progress rise" style="--d:.75s" id="progress" data-start="' . date('c', $started) . '" data-until="' . date('c', $until) . '">' . "\n"
        . '                <div class="progress-top"><span>Progress</span><b id="pct">0%</b></div>' . "\n"
        . '                <div class="track"><span class="fill" id="fill"></span></div>' . "\n"
        . '                <ol class="stages" id="stages"><li>Backing up</li><li>Updating</li><li>Final checks</li></ol>' . "\n"
        . "            </div>\n"
        . '            <div class="eta rise" style="--d:.85s">' . "\n"
        . '                <span class="eta-label">Expected back ' . h(date('D j M, g:i a', $until)) . '</span>' . "\n"
        . '                <div class="countdown" id="countdown" aria-live="off"></div>' . "\n"
        . "            </div>\n"
        : '            <div class="progress rise" style="--d:.75s"><div class="track"><span class="fill fill-loop"></span></div></div>' . "\n";

    $contactHtml = $contact
        ? '            <p class="contact-label rise" style="--d:.95s">Need us in the meantime?</p>' . "\n"
        . '            <div class="contact rise" style="--d:1s">' . "\n"
        . '                <a href="tel:+254712109310" class="btn"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg> Call</a>' . "\n"
        . '                <a href="' . h($whatsapp) . '" class="btn btn-wa"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg> WhatsApp</a>' . "\n"
        . '                <a href="mailto:' . h($email) . '" class="btn"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg> Email</a>' . "\n"
        . "            </div>\n"
        : '';

    $particles = str_repeat('<i></i>', 14);

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title_h} | Judes CBO</title>
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#0b1d35">
    <link rel="icon" href="{$base}images/icons/favicon-32.png" type="image/png">
    <link rel="stylesheet" href="{$base}css/fonts.css">
    <style>
        :root { --navy: #0b1d35; --green: #2d5016; --leaf: #68d391; --amber: #f6ad55; --red: #e53e3e; --glass: rgba(255,255,255,0.07); --line: rgba(255,255,255,0.14); }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { min-height: 100%; }
        body {
            min-height: 100vh; min-height: 100dvh;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 32px 16px;
            font-family: 'Raleway', system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: rgba(255,255,255,0.8); text-align: center;
            background: var(--navy);
            overflow-x: hidden;
        }
        h1, .brand, .countdown b, .btn, .progress-top b { font-family: 'Montserrat', system-ui, sans-serif; }

        /* Moving aurora and drifting particles */
        .sky { position: fixed; inset: 0; z-index: -1; overflow: hidden; }
        .sky::after { content: ""; position: absolute; inset: 0; background-image: radial-gradient(rgba(255,255,255,0.07) 1px, transparent 1px); background-size: 26px 26px; mask-image: radial-gradient(ellipse at center, #000 30%, transparent 75%); }
        .blob { position: absolute; border-radius: 50%; filter: blur(70px); opacity: .55; animation: drift 18s ease-in-out infinite alternate; }
        .b1 { width: 520px; height: 520px; left: -140px; top: -160px; background: #2d5016; }
        .b2 { width: 420px; height: 420px; right: -120px; top: 20%; background: #1a365d; animation-duration: 22s; animation-delay: -6s; }
        .b3 { width: 380px; height: 380px; left: 30%; bottom: -200px; background: #9b2c2c; opacity: .32; animation-duration: 26s; animation-delay: -12s; }
        @keyframes drift { 0% { transform: translate(0, 0) scale(1); } 50% { transform: translate(60px, 40px) scale(1.12); } 100% { transform: translate(-40px, 70px) scale(.95); } }
        .particles { position: absolute; inset: 0; }
        .particles i { position: absolute; bottom: -20px; width: 6px; height: 6px; border-radius: 50%; background: rgba(255,255,255,0.35); animation: float 16s linear infinite; }
        .particles i:nth-child(odd) { background: rgba(104,211,145,0.45); width: 4px; height: 4px; }
        .particles i:nth-child(3n) { background: rgba(246,173,85,0.45); }
        .particles i:nth-child(1) { left: 6%; animation-duration: 14s; }
        .particles i:nth-child(2) { left: 14%; animation-duration: 19s; animation-delay: -4s; }
        .particles i:nth-child(3) { left: 22%; animation-duration: 12s; animation-delay: -9s; }
        .particles i:nth-child(4) { left: 30%; animation-duration: 21s; animation-delay: -2s; }
        .particles i:nth-child(5) { left: 38%; animation-duration: 15s; animation-delay: -11s; }
        .particles i:nth-child(6) { left: 46%; animation-duration: 18s; animation-delay: -6s; }
        .particles i:nth-child(7) { left: 54%; animation-duration: 13s; animation-delay: -1s; }
        .particles i:nth-child(8) { left: 62%; animation-duration: 20s; animation-delay: -14s; }
        .particles i:nth-child(9) { left: 70%; animation-duration: 16s; animation-delay: -7s; }
        .particles i:nth-child(10) { left: 78%; animation-duration: 22s; animation-delay: -3s; }
        .particles i:nth-child(11) { left: 86%; animation-duration: 14s; animation-delay: -10s; }
        .particles i:nth-child(12) { left: 93%; animation-duration: 19s; animation-delay: -5s; }
        .particles i:nth-child(13) { left: 50%; animation-duration: 24s; animation-delay: -16s; }
        .particles i:nth-child(14) { left: 10%; animation-duration: 23s; animation-delay: -18s; }
        @keyframes float { 0% { transform: translateY(0) translateX(0); opacity: 0; } 10% { opacity: 1; } 90% { opacity: 1; } 100% { transform: translateY(-110vh) translateX(30px); opacity: 0; } }

        /* Entrance: each block rises in turn */
        .rise { opacity: 0; transform: translateY(18px); animation: rise .8s cubic-bezier(.2,.8,.2,1) var(--d, 0s) forwards; }
        @keyframes rise { to { opacity: 1; transform: none; } }

        .brand { display: inline-flex; align-items: center; gap: 10px; margin-bottom: 26px; color: #fff; font-weight: 700; letter-spacing: 1.5px; font-size: 14px; text-decoration: none; }
        .brand img { width: 40px; height: 40px; padding: 4px; border-radius: 12px; background: #fff; box-shadow: 0 8px 20px rgba(0,0,0,0.3); }

        .card { position: relative; width: 100%; max-width: 600px; padding: 36px 32px 30px; border-radius: 28px; background: var(--glass); border: 1px solid var(--line); box-shadow: 0 30px 80px rgba(0,0,0,0.35), inset 0 1px 0 rgba(255,255,255,0.08); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); }
        .card::before { content: ""; position: absolute; inset: -1px; border-radius: inherit; padding: 1px; background: linear-gradient(135deg, rgba(104,211,145,0.6), transparent 35%, transparent 65%, rgba(246,173,85,0.5)); -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0); -webkit-mask-composite: xor; mask-composite: exclude; pointer-events: none; }

        /* Gears illustration */
        .art { position: relative; width: 190px; height: 130px; margin: 0 auto 18px; }
        .art svg { overflow: visible; }
        .g-big { animation: spin 9s linear infinite; transform-origin: 70px 70px; }
        .g-small { animation: spin 6s linear infinite reverse; transform-origin: 148px 44px; }
        .wrench { animation: tap 2.4s ease-in-out infinite; transform-origin: 160px 112px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes tap { 0%, 100% { transform: rotate(0); } 20% { transform: rotate(-16deg); } 35% { transform: rotate(4deg); } 50% { transform: rotate(0); } }
        .spark { animation: spark 2.4s ease-out infinite; transform-origin: 128px 104px; opacity: 0; }
        @keyframes spark { 0%, 30% { opacity: 0; transform: scale(.4); } 38% { opacity: 1; transform: scale(1); } 60% { opacity: 0; transform: scale(1.5); } 100% { opacity: 0; } }

        .status { display: inline-flex; align-items: center; gap: 8px; padding: 7px 14px; margin-bottom: 14px; border-radius: 999px; background: rgba(246,173,85,0.14); border: 1px solid rgba(246,173,85,0.3); color: #fbd38d; font-size: 13px; font-weight: 600; letter-spacing: .3px; }
        .dot { position: relative; width: 8px; height: 8px; border-radius: 50%; background: var(--amber); }
        .dot::after { content: ""; position: absolute; inset: 0; border-radius: 50%; background: var(--amber); animation: ping 1.6s ease-out infinite; }
        @keyframes ping { to { transform: scale(2.8); opacity: 0; } }
        h1 { color: #fff; font-size: clamp(27px, 6.5vw, 40px); font-weight: 800; line-height: 1.12; letter-spacing: -.5px; margin-bottom: 14px; }
        h1 span { background: linear-gradient(90deg, #fff, #c6f6d5, #fbd38d, #fff); background-size: 300% 100%; -webkit-background-clip: text; background-clip: text; color: transparent; animation: shine 7s linear infinite; }
        @keyframes shine { to { background-position: -300% 0; } }
        .lead { font-size: 16.5px; line-height: 1.7; max-width: 470px; margin: 0 auto 10px; }

        .progress { margin: 22px auto 0; max-width: 440px; text-align: left; }
        .progress-top { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; font-size: 13px; }
        .progress-top b { color: #fff; font-size: 14px; }
        .track { position: relative; height: 10px; border-radius: 999px; background: rgba(255,255,255,0.1); overflow: hidden; }
        .fill { position: absolute; inset: 0 auto 0 0; width: 4%; border-radius: inherit; background: linear-gradient(90deg, #38a169, var(--leaf), var(--amber)); transition: width 1s ease; overflow: hidden; }
        .fill::after { content: ""; position: absolute; inset: 0; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.55), transparent); transform: translateX(-100%); animation: sheen 1.8s ease-in-out infinite; }
        @keyframes sheen { to { transform: translateX(100%); } }
        .fill-loop { width: 35%; animation: loop 2s ease-in-out infinite; }
        @keyframes loop { 0% { left: -35%; } 100% { left: 100%; } }
        .stages { display: flex; justify-content: space-between; gap: 8px; margin-top: 12px; list-style: none; font-size: 12.5px; }
        .stages li { display: flex; align-items: center; gap: 6px; opacity: .5; transition: opacity .4s; }
        .stages li::before { content: ""; width: 14px; height: 14px; flex-shrink: 0; border-radius: 50%; border: 2px solid rgba(255,255,255,0.4); box-sizing: border-box; }
        .stages li.now { opacity: 1; color: #fff; }
        .stages li.now::before { border-color: var(--amber); border-top-color: transparent; animation: spin 1s linear infinite; }
        .stages li.done { opacity: .9; }
        .stages li.done::before { border: none; background: var(--leaf) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230b1d35' stroke-width='4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M5 12.5 10 17.5 19 7'/%3E%3C/svg%3E") center / 10px no-repeat; }

        .eta { margin: 22px auto 0; }
        .eta-label { display: block; margin-bottom: 10px; font-size: 12px; text-transform: uppercase; letter-spacing: 1.4px; opacity: .7; }
        .countdown { display: flex; justify-content: center; gap: 10px; }
        .unit { position: relative; min-width: 74px; padding: 12px 6px 8px; border-radius: 16px; background: rgba(0,0,0,0.28); border: 1px solid rgba(255,255,255,0.08); }
        .unit b { display: block; color: #fff; font-size: 28px; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
        .unit b.pop { animation: pop .45s cubic-bezier(.3,1.6,.5,1); }
        @keyframes pop { 0% { transform: translateY(-6px) scale(.85); opacity: .3; } 100% { transform: none; opacity: 1; } }
        .unit small { display: block; margin-top: 6px; font-size: 10.5px; text-transform: uppercase; letter-spacing: 1.2px; opacity: .65; }
        .unit + .unit::before { content: ":"; position: absolute; left: -9px; top: 9px; color: rgba(255,255,255,0.35); font-size: 22px; font-weight: 700; }

        .contact-label { margin-top: 26px; font-size: 13px; opacity: .7; }
        .contact { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin-top: 10px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 46px; padding: 11px 18px; border-radius: 14px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.18); color: #fff; font-weight: 700; font-size: 14px; text-decoration: none; transition: transform .25s, background .25s, border-color .25s; }
        .btn:hover { transform: translateY(-3px); background: rgba(255,255,255,0.16); border-color: rgba(255,255,255,0.32); }
        .btn-wa { background: #25d366; border-color: #25d366; color: #fff; }
        .btn-wa:hover { background: #1fb457; border-color: #1fb457; }
        .btn:focus-visible { outline: 3px solid var(--leaf); outline-offset: 2px; }

        footer { margin-top: 24px; font-size: 12.5px; opacity: .55; line-height: 1.6; }
        .back-msg { position: fixed; left: 50%; bottom: 20px; transform: translate(-50%, 140%); padding: 12px 18px; border-radius: 14px; background: #38a169; color: #fff; font-weight: 700; box-shadow: 0 14px 30px rgba(0,0,0,0.3); transition: transform .4s cubic-bezier(.2,.8,.2,1); }
        .back-msg.show { transform: translate(-50%, 0); }

        @media (max-width: 480px) {
            body { padding: 22px 16px; }
            .card { padding: 28px 18px 24px; border-radius: 24px; }
            .art { width: 160px; height: 110px; }
            .art svg { width: 160px; height: 110px; }
            .lead { font-size: 15.5px; }
            .unit { min-width: 0; flex: 1; padding: 10px 4px 7px; }
            .unit b { font-size: 23px; }
            .unit + .unit::before { left: -8px; }
            .stages { font-size: 11.5px; }
            .contact .btn { flex: 1 1 100%; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
            .rise { opacity: 1; transform: none; }
            .particles { display: none; }
        }
    </style>
</head>
<body>
    <div class="sky" aria-hidden="true"><span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span><div class="particles">{$particles}</div></div>

    <a class="brand rise" style="--d:.05s" href="{$base}"><img src="{$base}images/icons/icon-192.png" alt="">JUDES CBO</a>

    <main class="card">
        <div class="art rise" style="--d:.15s" aria-hidden="true">
            <svg width="190" height="130" viewBox="0 0 190 130">
                <defs>
                    <linearGradient id="gBig" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#9ae6b4"/><stop offset="1" stop-color="#38a169"/></linearGradient>
                    <linearGradient id="gSmall" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fbd38d"/><stop offset="1" stop-color="#dd6b20"/></linearGradient>
                </defs>
                <g class="g-big"><path transform="translate(70 70)" d="{$bigGear}" fill="url(#gBig)" fill-rule="evenodd"/></g>
                <g class="g-small"><path transform="translate(148 44)" d="{$smallGear}" fill="url(#gSmall)" fill-rule="evenodd"/></g>
                <g class="spark" stroke="#fbd38d" stroke-width="3" stroke-linecap="round"><path d="M128 92v-6M118 98l-5-3M138 98l5-3"/></g>
                <g class="wrench"><path d="M160 112 L130 102" stroke="#e2e8f0" stroke-width="8" stroke-linecap="round"/><circle cx="127" cy="101" r="8" fill="none" stroke="#e2e8f0" stroke-width="5"/></g>
            </svg>
        </div>
        <span class="status rise" style="--d:.3s"><span class="dot"></span>Scheduled maintenance</span>
        <h1 class="rise" style="--d:.4s"><span>{$title_h}</span></h1>
{$paras}{$progress}{$contactHtml}    </main>

    <footer class="rise" style="--d:1.1s">Judes Community Based Organization · Oyugis, Homa Bay County<br>&copy; {$year} Judes CBO</footer>
    <div class="back-msg" id="back-msg" role="status">We're back! Opening the website…</div>

    <script>
    (function () {
        var box = document.getElementById('countdown');
        var bar = document.getElementById('progress');
        var start = bar ? new Date(bar.dataset.start).getTime() : 0;
        var until = bar ? new Date(bar.dataset.until).getTime() : 0;
        var last = {};

        function render() {
            if (!bar) return;
            var now = Date.now();
            var s = Math.max(0, Math.round((until - now) / 1000));
            var parts = [['d', Math.floor(s / 86400), 'days'], ['h', Math.floor(s % 86400 / 3600), 'hours'], ['m', Math.floor(s % 3600 / 60), 'min'], ['s', s % 60, 'sec']];
            if (parts[0][1] === 0) parts.shift();
            if (box.children.length !== parts.length) {
                box.innerHTML = parts.map(function (p) { return '<div class="unit"><b data-k="' + p[0] + '"></b><small>' + p[2] + '</small></div>'; }).join('');
                last = {};
            }
            parts.forEach(function (p) {
                var el = box.querySelector('[data-k="' + p[0] + '"]');
                var text = String(p[1]).padStart(2, '0');
                if (last[p[0]] !== text) {
                    el.textContent = text;
                    el.classList.remove('pop');
                    void el.offsetWidth;
                    el.classList.add('pop');
                    last[p[0]] = text;
                }
            });
            // Progress follows the time: from when maintenance began to the expected end
            var pct = until > start ? Math.min(99, Math.max(3, Math.round((now - start) / (until - start) * 100))) : 50;
            document.getElementById('fill').style.width = pct + '%';
            document.getElementById('pct').textContent = pct + '%';
            var stage = pct < 25 ? 0 : pct < 85 ? 1 : 2;
            document.querySelectorAll('#stages li').forEach(function (li, i) {
                li.className = i < stage ? 'done' : i === stage ? 'now' : '';
            });
            if (s === 0) { clearInterval(timer); setTimeout(check, 2500); }
        }
        var timer = setInterval(render, 1000);
        render();

        // Reload as soon as the website is back
        function check() {
            fetch('{$base}admin/api/maintenance.php', { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.on === false) {
                        document.getElementById('back-msg').classList.add('show');
                        setTimeout(function () { location.reload(); }, 1200);
                    }
                })
                .catch(function () {});
        }
        setInterval(check, 45000);
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
