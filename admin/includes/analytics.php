<?php
declare(strict_types=1);

/*
 * Visitor statistics for the public website. No cookies and nothing personal is stored:
 * a visitor is a hash of their IP address and browser that changes every day,
 * so the same person can't be followed from one day to the next.
 */

const EVENT_NAMES = ['call', 'whatsapp', 'email', 'donate', 'copy', 'outbound'];

function is_bot(string $ua): bool
{
    return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|facebookexternalhit|embedly|curl|wget|python|java\/|go-http|okhttp|monitor|uptime/i', $ua);
}

function visitor_id(string $ua): string
{
    $secret = setting('analytics_secret');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(16));
        save_setting('analytics_secret', $secret);
    }
    return substr(hash('sha256', $secret . date('Y-m-d') . client_ip() . $ua), 0, 16);
}

/** "/JudesCBO/about.html?x=1" → "/about". Returns null for anything that isn't a plain page path. */
function clean_page_path(string $url): ?string
{
    $path = strtolower(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
    $base = strtolower('/' . trim((string) parse_url((string) config('site_url'), PHP_URL_PATH), '/'));
    if ($base !== '/' && str_starts_with($path, $base . '/')) {
        $path = substr($path, strlen($base));
    }
    $path = preg_replace('/(\/index)?\.html$/', '', $path) ?? '';
    $path = '/' . trim($path, '/');
    if (strlen($path) > 190 || !preg_match('#^/[a-z0-9/_.\-]*$#', $path) || str_starts_with($path, '/admin')) {
        return null;
    }
    return $path;
}

/** Host name of an outside website that sent the visitor, or '' for direct visits and links within the site. */
function referrer_host(string $referrer): string
{
    $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
    $host = preg_replace('/^(www|m|l|lm|mobile)\./', '', $host) ?? '';
    $own = preg_replace('/^www\./', '', strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0])) ?? '';
    if ($host === '' || $host === $own || !preg_match('/^[a-z0-9.\-]{1,120}$/', $host)) return '';
    return $host;
}

/** Friendly name for where a visit came from. */
function source_label(string $referrer, string $source = ''): string
{
    $key = strtolower($source !== '' ? $source : $referrer);
    $known = [
        '/google\./' => 'Google', '/bing\.com/' => 'Bing', '/duckduckgo/' => 'DuckDuckGo', '/yahoo\./' => 'Yahoo',
        '/facebook|^fb(\.|$)/' => 'Facebook', '/instagram/' => 'Instagram', '/^t\.co$|twitter|^x\.com$|^x$/' => 'X (Twitter)',
        '/linkedin|lnkd\.in/' => 'LinkedIn', '/youtube|youtu\.be/' => 'YouTube', '/whatsapp|wa\.me/' => 'WhatsApp',
        '/tiktok/' => 'TikTok', '/chatgpt|openai/' => 'ChatGPT', '/gmail|mail\./' => 'Email',
    ];
    foreach ($known as $pattern => $label) {
        if (preg_match($pattern, $key)) return $label;
    }
    if ($key === '') return 'Direct / unknown';
    return $source !== '' ? ucfirst($source) : $key;
}

/** [browser, operating system] from the user agent. */
function parse_user_agent(string $ua): array
{
    $browser = match (true) {
        str_contains($ua, 'Edg/') => 'Edge',
        str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
        str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
        str_contains($ua, 'UCBrowser') => 'UC Browser',
        str_contains($ua, 'FBAN') || str_contains($ua, 'FBAV') => 'Facebook app',
        str_contains($ua, 'Instagram') => 'Instagram app',
        str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
        str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
        str_contains($ua, 'Safari/') => 'Safari',
        default => 'Other',
    };
    $os = match (true) {
        str_contains($ua, 'Android') => 'Android',
        (bool) preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS',
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Mac OS X') => 'macOS',
        str_contains($ua, 'CrOS') => 'ChromeOS',
        str_contains($ua, 'Linux') => 'Linux',
        default => 'Other',
    };
    return [$browser, $os];
}

function device_type(string $ua, int $screenWidth): string
{
    if (preg_match('/iPad|Tablet/i', $ua) || (str_contains($ua, 'Android') && !str_contains($ua, 'Mobile'))) return 'tablet';
    if (preg_match('/Mobi|iPhone|iPod/i', $ua)) return 'mobile';
    if ($screenWidth > 0 && $screenWidth < 768) return 'mobile';
    return 'desktop';
}

/** Page name for reports: the page's own title without the site name. */
function page_label(string $path, ?string $title): string
{
    if ($path === '/') return 'Home';
    $title = trim(explode(' | ', (string) $title)[0]);
    return $title !== '' && $title !== 'Judes CBO' ? $title : ucwords(str_replace(['-', '_'], ' ', ltrim($path, '/')));
}

/** Removes statistics older than the "keep for" setting. */
function purge_old_analytics(): void
{
    $months = max(1, (int) setting('analytics_keep_months', '13'));
    db()->exec("DELETE FROM page_views WHERE viewed_at < NOW() - INTERVAL $months MONTH");
    db()->exec("DELETE FROM site_events WHERE created_at < NOW() - INTERVAL $months MONTH");
}
