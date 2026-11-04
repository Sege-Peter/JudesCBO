<?php
declare(strict_types=1);

/**
 * Turns database content into the public static pages.
 *
 * Pages keep their hand-written layout; only the regions wrapped in
 * <!-- CMS:name --> ... <!-- /CMS:name --> markers are rewritten. Story pages
 * (blog-<slug>.html) are generated in full from the Stories page's header and footer.
 */

require_once __DIR__ . '/publisher-extra.php';

const CATEGORY_ICONS = [
    'Youth Health' => 'fa-heartbeat',
    'Disability Inclusion' => 'fa-universal-access',
    'SGBV' => 'fa-shield-heart',
    'Mental Health' => 'fa-brain',
    'Community' => 'fa-people-group',
    'Events' => 'fa-calendar-days',
    'News' => 'fa-newspaper',
];

const ERROR_PAGES = ['400.html', '401.html', '403.html', '404.html', '500.html', '502.html', '503.html'];

const STATIC_PAGES = [
    'index.html' => ['1.0', 'monthly'], 'about.html' => ['0.8', 'yearly'], 'programs.html' => ['0.9', 'monthly'],
    'stories.html' => ['0.7', 'weekly'], 'gallery.html' => ['0.6', 'monthly'], 'donate.html' => ['0.9', 'monthly'],
    'contact.html' => ['0.6', 'yearly'], 'privacy.html' => ['0.3', 'yearly'], 'terms.html' => ['0.3', 'yearly'],
    'events.html' => ['0.8', 'weekly'], 'team.html' => ['0.6', 'monthly'],
];

function site_path(string $file): string
{
    return rtrim(config('site_root'), '/\\') . '/' . $file;
}

function site_url(string $path = ''): string
{
    return rtrim((string) config('site_url'), '/') . '/' . ltrim($path, '/');
}

function write_atomic(string $path, string $content): void
{
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not write ' . basename($path) . '. Check the folder permissions.');
    }
}

function replace_region(string $html, string $name, string $inner, string $file): string
{
    $pattern = '#(<!-- CMS:' . preg_quote($name, '#') . ' -->\n)(.*?)(\n\s*<!-- /CMS:' . preg_quote($name, '#') . ' -->)#s';
    if (!preg_match($pattern, $html)) {
        throw new RuntimeException("The '$name' section markers are missing from $file.");
    }
    return preg_replace_callback($pattern, fn($m) => $m[1] . $inner . $m[3], $html, 1);
}

function indent_block(string $html, int $spaces): string
{
    $pad = str_repeat(' ', $spaces);
    return implode("\n", array_map(fn($l) => $l === '' ? '' : $pad . $l, explode("\n", $html)));
}

function published_stories(): array
{
    return db()->query("SELECT * FROM stories WHERE status = 'published' ORDER BY published_at DESC, id DESC")->fetchAll();
}

function story_file(array $story): string
{
    return 'blog-' . $story['slug'] . '.html';
}

/** Clean link to a story (no .html); the server maps it to the file. */
function story_href(array $story): string
{
    return 'blog-' . $story['slug'];
}

function story_date(array $story): string
{
    return date('F j, Y', strtotime($story['published_at'] ?: 'today'));
}

/* ---------- Pieces of markup ---------- */

function render_story_card(array $s): string
{
    $file = h(story_href($s));
    $thumb = $s['cover_image']
        ? '<a href="' . $file . '" class="story-thumb"><img src="' . h($s['cover_image']) . '" alt="' . h($s['cover_alt'] ?: $s['title']) . '" loading="lazy"></a>'
        : '<a href="' . $file . '" class="story-thumb story-thumb--icon" aria-label="' . h($s['title']) . '"><i class="fas ' . h(CATEGORY_ICONS[$s['category']] ?? 'fa-newspaper') . '" aria-hidden="true"></i></a>';
    $iso = h($s['published_at']);
    $e = 'h';
    return <<<HTML
<article class="story-card reveal">
    $thumb
    <div class="story-body">
        <div class="story-meta"><span class="story-tag">{$e($s['category'])}</span><time datetime="$iso">{$e(story_date($s))}</time></div>
        <h3><a href="$file">{$e($s['title'])}</a></h3>
        <p>{$e($s['excerpt'])}</p>
        <a href="$file" class="story-link">Read story <i class="fas fa-arrow-right"></i></a>
    </div>
</article>
HTML;
}

function render_empty_stories(): string
{
    return '<p class="story-empty">New stories are coming soon.</p>';
}

function render_impact(): string
{
    $items = [
        ['fa-calendar-check', setting('stat_founded', '2018'), '2000', 'Year founded in Homa Bay County'],
        ['fa-map-location-dot', setting('stat_wards', '10'), '', 'Civic wards reached with youth health services'],
        ['fa-layer-group', setting('stat_programs', '4'), '', 'Core programs for women, youth and persons with disabilities'],
        ['fa-handshake', setting('stat_partners', '4'), '', 'Collaborating partners, from government to foundations'],
    ];
    $out = [];
    foreach ($items as [$icon, $value, $from, $label]) {
        $value = (string) (int) $value;
        $fromAttr = $from !== '' ? ' data-from="' . h($from) . '"' : '';
        $e = 'h';
        $out[] = <<<HTML
<div class="impact-item reveal">
    <i class="fas $icon"></i>
    <div class="impact-number"><span class="counter"$fromAttr data-to="$value">$value</span></div>
    <p>{$e($label)}</p>
</div>
HTML;
    }
    return implode("\n", $out);
}

function gallery_photos(bool $homeOnly = false): array
{
    $sql = 'SELECT * FROM gallery' . ($homeOnly ? ' WHERE show_on_home = 1' : '') . ' ORDER BY sort_order, id';
    if ($homeOnly) $sql .= ' LIMIT 6';
    return db()->query($sql)->fetchAll();
}

function render_home_gallery(): string
{
    $out = [];
    foreach (gallery_photos(true) as $i => $p) {
        $wide = in_array($i, [0, 4], true) ? ' gallery-item--wide' : '';
        $src = h($wide ? $p['image'] : $p['thumb']);
        $out[] = '<button type="button" class="gallery-item' . $wide . ' reveal" data-full="' . h($p['image']) . '"><img src="' . $src . '" alt="' . h($p['alt'] ?: $p['caption']) . '" loading="lazy"></button>';
    }
    return implode("\n", $out);
}

function render_gallery_page(): string
{
    $out = [];
    foreach (gallery_photos() as $p) {
        $caption = $p['caption'] !== '' ? '<span class="masonry-caption">' . h($p['caption']) . '</span>' : '';
        $size = $p['width'] ? ' width="' . (int) $p['width'] . '" height="' . (int) $p['height'] . '"' : '';
        $out[] = '<button type="button" class="gallery-item masonry-item reveal" data-full="' . h($p['image']) . '"><img src="' . h($p['thumb']) . '" alt="' . h($p['alt'] ?: $p['caption']) . '"' . $size . ' loading="lazy">' . $caption . '</button>';
    }
    return $out ? implode("\n", $out) : '<p class="story-empty">Photos are coming soon.</p>';
}

/* ---------- Story pages ---------- */

/**
 * Link-preview card for a story: the cover photo, the branded overlay
 * (admin/assets/og-story-overlay.png) and the story title, as a 1200x630 JPG.
 * Social networks don't reliably show WebP, so this is always a JPG.
 */
function story_share_image(array $s): string
{
    if (!$s['cover_image'] || !function_exists('imagejpeg')) {
        return 'images/brand/share-stories.jpg';
    }
    $src = site_path($s['cover_image']);
    if (!is_file($src)) {
        return 'images/brand/share-stories.jpg';
    }
    // A new file name whenever the cover or title changes, so apps that cache previews pick it up
    $relative = 'uploads/og/' . $s['slug'] . '-' . substr(md5($s['cover_image'] . '|' . $s['title'] . '|' . ($s['excerpt'] ?? '') . '|v3'), 0, 8) . '.jpg';
    $dest = site_path($relative);
    if (is_file($dest)) {
        return $relative;
    }
    $info = @getimagesize($src);
    $img = match ($info[2] ?? 0) {
        IMAGETYPE_WEBP => @imagecreatefromwebp($src),
        IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
        IMAGETYPE_PNG => @imagecreatefrompng($src),
        default => false,
    };
    if (!$img) {
        return 'images/brand/share-stories.jpg';
    }
    // Centre-crop the cover to 1200x630
    [$w, $h] = [imagesx($img), imagesy($img)];
    $ratio = 1200 / 630;
    [$cw, $ch] = $w / $h > $ratio ? [(int) ($h * $ratio), $h] : [$w, (int) ($w / $ratio)];
    $card = imagecreatetruecolor(1200, 630);
    imagecopyresampled($card, $img, 0, 0, (int) (($w - $cw) / 2), (int) (($h - $ch) / 2), 1200, 630, $cw, $ch);
    imagedestroy($img);

    $overlayPath = dirname(__DIR__) . '/assets/og-story-overlay.png';
    if (is_file($overlayPath) && ($overlay = @imagecreatefrompng($overlayPath))) {
        imagealphablending($card, true);
        imagecopy($card, $overlay, 0, 0, 0, 0, 1200, 630);
        imagedestroy($overlay);
    }

    // Title and summary in the brand fonts, placed like the page preview images (tools/build-og.py)
    $font = share_card_font();
    $body = share_card_font('body');
    if ($font && function_exists('imagettftext')) {
        $white = imagecolorallocate($card, 255, 255, 255);
        $soft = imagecolorallocatealpha($card, 255, 255, 255, 14);
        $size = mb_strlen($s['title']) <= 34 ? 46 : 40; // points (GD uses 96 dpi: 46pt is about 62px)
        $lines = wrap_for_image($s['title'], $font, $size, 780);
        if (count($lines) > 3) {
            $lines = array_slice($lines, 0, 3);
            $words = explode(' ', $lines[2]);
            if (count($words) > 1) array_pop($words); // end on a whole word
            $lines[2] = rtrim(implode(' ', $words), ' ,.:;-') . '…';
        }
        $px = $size * 96 / 72;
        $y = 262 + (int) round($px * 0.92);
        foreach ($lines as $line) {
            imagettftext($card, $size, 0, 64, $y, $white, $font, $line);
            $y += (int) round($px * 1.12);
        }
        $summary = trim((string) ($s['excerpt'] ?? ''));
        if ($body && $summary !== '' && count($lines) <= 2) {
            $sub = wrap_for_image($summary, $body, 20, 760);
            if (count($sub) > 2) {
                $sub = array_slice($sub, 0, 2);
                $words = explode(' ', $sub[1]);
                if (count($words) > 1) array_pop($words);
                $sub[1] = rtrim(implode(' ', $words), ' ,.:;-') . '…';
            }
            $y += 6;
            foreach ($sub as $line) {
                imagettftext($card, 20, 0, 64, $y, $soft, $body, $line);
                $y += 38;
            }
        }
    }

    if (!is_dir(dirname($dest))) {
        mkdir(dirname($dest), 0755, true);
    }
    foreach (glob(site_path('uploads/og/' . $s['slug'] . '-*.jpg')) ?: [] as $old) {
        @unlink($old);
    }
    imageinterlace($card, true);
    imagejpeg($card, $dest, 84);
    imagedestroy($card);
    return $relative;
}

/** Brand font for share cards (bundled in admin/assets/fonts), falling back to a system font. */
function share_card_font(string $kind = 'title'): ?string
{
    $bundled = dirname(__DIR__) . '/assets/fonts/' . ($kind === 'body' ? 'Raleway-SemiBold.ttf' : 'Montserrat-ExtraBold.ttf');
    $candidates = array_merge([$bundled], [
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu-sans-fonts/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        'C:/Windows/Fonts/segoeuib.ttf',
        'C:/Windows/Fonts/arialbd.ttf',
    ]);
    foreach ($candidates as $path) {
        if (@is_file($path)) return $path;
    }
    return null;
}

function wrap_for_image(string $text, string $font, float $size, int $maxWidth): array
{
    $lines = [];
    $line = '';
    foreach (preg_split('/\s+/', trim($text)) as $word) {
        $test = $line === '' ? $word : "$line $word";
        $box = imagettfbbox($size, 0, $font, $test);
        if ($box[2] - $box[0] > $maxWidth && $line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $test;
        }
    }
    if ($line !== '') $lines[] = $line;
    return $lines;
}

/** One block element per line so the generated pages stay readable. */
function tidy_story_html(string $html): string
{
    $html = preg_replace('#(</(?:p|h2|h3|h4|ul|ol|blockquote|figure)>|<(?:ul|ol)>|</li>|<hr>)\s*(?=<)#i', "$1\n", $html) ?? $html;
    return preg_replace('#\n(<li>)#i', "\n    $1", $html) ?? $html;
}

function page_shell(): array
{
    $html = file_get_contents(site_path('stories.html'));
    $start = strpos($html, '    <!-- Header -->');
    $bannerAt = strpos($html, '    <!-- Page Banner -->');
    $footerAt = strpos($html, '    <!-- Footer -->');
    if ($start === false || $bannerAt === false || $footerAt === false) {
        throw new RuntimeException('stories.html is missing its Header, Page Banner or Footer markers.');
    }
    $org = null;
    if (preg_match('#<script type="application/ld\+json">\s*(\{.*?\})\s*</script>#s', $html, $m)) {
        foreach (json_decode($m[1], true)['@graph'] ?? [] as $node) {
            if (($node['@type'] ?? '') === 'NGO') $org = $node;
        }
    }
    return [
        'header' => substr($html, $start, $bannerAt - $start),
        'footer' => substr($html, $footerAt),
        'org' => $org,
    ];
}

function render_story_page(array $s, ?array $next, array $shell): string
{
    $url = site_url(story_href($s));
    $title = $s['title'] . ' | Judes CBO';
    $desc = $s['meta_description'] ?: mb_substr(strip_tags($s['excerpt']), 0, 160);
    $image = site_url(story_share_image($s));
    $imageAlt = $s['cover_alt'] ?: 'Judes CBO community members in Homa Bay County';
    $orgId = $shell['org']['@id'] ?? site_url('#organization');

    $graph = array_values(array_filter([
        $shell['org'],
        ['@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => site_url()],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Stories', 'item' => site_url('stories')],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $s['title'], 'item' => $url],
        ]],
        ['@type' => 'WebPage', '@id' => $url . '#webpage', 'url' => $url, 'name' => $title, 'description' => $desc,
            'isPartOf' => ['@id' => site_url('#website')], 'about' => ['@id' => $orgId], 'inLanguage' => 'en',
            'breadcrumb' => ['@id' => $url . '#breadcrumb']],
        ['@type' => 'BlogPosting', '@id' => $url . '#article', 'headline' => $s['title'], 'description' => $desc,
            'image' => $image, 'datePublished' => $s['published_at'], 'dateModified' => date('Y-m-d', strtotime($s['updated_at'])),
            'articleSection' => $s['category'], 'inLanguage' => 'en',
            'author' => ['@id' => $orgId], 'publisher' => ['@id' => $orgId], 'mainEntityOfPage' => ['@id' => $url . '#webpage']],
    ]));
    $jsonLd = json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    $jsonLd = indent_block($jsonLd, 4);

    $figure = $s['cover_image']
        ? "            <figure class=\"article-figure\">\n                <img src=\"" . h($s['cover_image']) . '" alt="' . h($imageAlt) . "\">\n            </figure>\n"
        : '';
    $shareText = rawurlencode($s['title']);
    $shareUrl = rawurlencode($url);
    $nextHtml = $next
        ? "            <div class=\"article-next\">\n                <span>Read next</span>\n                <a href=\"" . h(story_href($next)) . '"><strong>' . h($next['title']) . "</strong> <i class=\"fas fa-arrow-right\"></i></a>\n            </div>\n"
        : '';
    $body = indent_block(tidy_story_html($s['body_html']), 12);

    $e = 'h';
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$e($title)}</title>
    <meta name="description" content="{$e($desc)}">
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
    <meta name="geo.region" content="KE-08">
    <meta name="geo.placename" content="Oyugis, Homa Bay County, Kenya">
    <meta name="geo.position" content="-0.510;34.733">
    <meta name="ICBM" content="-0.510, 34.733">
    <meta name="author" content="Judes Community Based Organization">
    <link rel="canonical" href="{$e($url)}">
    <!-- Open Graph / social sharing -->
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="Judes CBO">
    <meta property="og:locale" content="en_KE">
    <meta property="og:title" content="{$e($title)}">
    <meta property="og:description" content="{$e($desc)}">
    <meta property="og:url" content="{$e($url)}">
    <meta property="og:image" content="{$e($image)}">
    <meta property="og:image:secure_url" content="{$e($image)}">
    <meta property="og:image:type" content="image/jpeg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{$e($imageAlt)}">
    <meta property="article:published_time" content="{$e($s['published_at'])}">
    <meta property="article:section" content="{$e($s['category'])}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{$e($title)}">
    <meta name="twitter:description" content="{$e($desc)}">
    <meta name="twitter:image" content="{$e($image)}">
    <meta name="twitter:image:alt" content="{$e($imageAlt)}">
    <!-- Icons and theme -->
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" href="images/icons/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="images/icons/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <meta name="theme-color" content="#1a365d">
    <!-- Fonts, icons and styles -->
    <link rel="preload" href="fonts/raleway-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="fonts/montserrat-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="css/fonts.css?v=20261104b">
    <link rel="stylesheet" href="css/style.css?v=20261104b">
    <!-- Structured data -->
    <script type="application/ld+json">
$jsonLd
    </script>
    <script>document.documentElement.classList.add('js');</script>
</head>
<body>
{$shell['header']}    <!-- Article -->
    <section class="article-hero">
        <div class="container article-narrow">
            <a href="stories" class="article-back"><i class="fas fa-arrow-left"></i> All stories</a>
            <span class="story-tag">{$e($s['category'])}</span>
            <h1>{$e($s['title'])}</h1>
            <div class="article-meta"><i class="far fa-calendar"></i> <time datetime="{$e($s['published_at'])}">{$e(story_date($s))}</time> &middot; Judes CBO</div>
        </div>
    </section>
    <article class="article">
        <div class="container article-narrow">
$figure            <div class="article-content">
$body
            </div>
            <div class="article-share">
                <span>Share this story</span>
                <a href="https://wa.me/?text=$shareText%20$shareUrl" target="_blank" rel="noopener" aria-label="Share on WhatsApp"><i class="fab fa-whatsapp"></i></a>
                <a href="https://www.facebook.com/sharer/sharer.php?u=$shareUrl" target="_blank" rel="noopener" aria-label="Share on Facebook"><i class="fab fa-facebook-f"></i></a>
                <a href="https://x.com/intent/post?text=$shareText&amp;url=$shareUrl" target="_blank" rel="noopener" aria-label="Share on X"><i class="fab fa-x-twitter"></i></a>
            </div>
            <div class="article-cta">
                <div>
                    <h3>Support this work</h3>
                    <p>Your support helps us reach more young people and families in Homa Bay County.</p>
                </div>
                <div class="article-cta-actions">
                    <a href="donate" class="header-donate" aria-label="Donate"><i class="fas fa-heart"></i><span>Donate</span></a>
                    <a href="contact" class="footer-chip">Get involved</a>
                </div>
            </div>
$nextHtml        </div>
    </article>
{$shell['footer']}
HTML;
}

/* ---------- Sitemap and form endpoints ---------- */

function render_sitemap(array $stories): string
{
    $today = date('Y-m-d');
    $rows = [];
    foreach (STATIC_PAGES as $file => [$priority, $freq]) {
        $loc = $file === 'index.html' ? site_url() : site_url(substr($file, 0, -5));
        $rows[] = "  <url>\n    <loc>" . h($loc) . "</loc>\n    <lastmod>$today</lastmod>\n    <changefreq>$freq</changefreq>\n    <priority>$priority</priority>\n  </url>";
    }
    foreach ($stories as $s) {
        $mod = date('Y-m-d', strtotime($s['updated_at']));
        $rows[] = "  <url>\n    <loc>" . h(site_url(story_href($s))) . "</loc>\n    <lastmod>$mod</lastmod>\n    <changefreq>yearly</changefreq>\n    <priority>0.6</priority>\n  </url>";
    }
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n" . implode("\n", $rows) . "\n</urlset>\n";
}

function apply_form_endpoints(string $html): string
{
    $builtin = setting('form_mode', 'formspree') === 'builtin';
    $contact = $builtin ? 'admin/api/contact.php' : setting('formspree_url', 'https://formspree.io/f/mjkelgrq');
    $subscribe = $builtin ? 'admin/api/subscribe.php' : setting('formspree_url', 'https://formspree.io/f/mjkelgrq');
    $html = preg_replace('#(<form id="contact-form"[^>]*\baction=")[^"]*(")#', '${1}' . h($contact) . '${2}', $html) ?? $html;
    return preg_replace('#(<form id="subscribe-form"[^>]*\baction=")[^"]*(")#', '${1}' . h($subscribe) . '${2}', $html) ?? $html;
}

/* ---------- Publish everything ---------- */

function publish_site(): array
{
    $changed = [];
    $stories = published_stories();
    $shell = page_shell();

    // Story pages: write current ones, remove pages for stories that are no longer published
    $keep = [];
    foreach ($stories as $i => $s) {
        $next = $stories[$i + 1] ?? ($i > 0 ? $stories[0] : null);
        $file = story_file($s);
        write_atomic(site_path($file), render_story_page($s, $next, $shell));
        $keep[$file] = true;
        $changed[] = $file;
    }
    foreach (db()->query('SELECT slug FROM stories')->fetchAll() as $row) {
        $file = 'blog-' . $row['slug'] . '.html';
        if (!isset($keep[$file]) && is_file(site_path($file))) {
            unlink(site_path($file));
            $changed[] = "$file (removed)";
        }
    }
    foreach (json_decode(setting('retired_story_files', '[]'), true) ?: [] as $file) {
        if (!isset($keep[$file]) && preg_match('/^blog-[a-z0-9-]+\.html$/', $file) && is_file(site_path($file))) {
            unlink(site_path($file));
            $changed[] = "$file (removed)";
        }
    }
    save_setting('retired_story_files', '[]');

    // Managed regions
    $cards = $stories ? implode("\n", array_map('render_story_card', $stories)) : render_empty_stories();
    $homeCards = $stories ? implode("\n", array_map('render_story_card', array_slice($stories, 0, 3))) : render_empty_stories();
    $regions = [
        'index.html' => [
            'home-impact' => [render_impact(), 12],
            'home-gallery' => [render_home_gallery(), 16],
            'home-stories' => [$homeCards, 16],
        ],
        'stories.html' => ['stories' => [$cards, 16]],
        'gallery.html' => ['gallery' => [render_gallery_page(), 16]],
    ];
    foreach ($regions as $file => $parts) {
        $html = file_get_contents(site_path($file));
        foreach ($parts as $name => [$inner, $indent]) {
            $html = replace_region($html, $name, indent_block($inner, $indent), $file);
        }
        write_atomic(site_path($file), $html);
        $changed[] = $file;
    }

    $changed = array_merge($changed, publish_events($shell), publish_team(), publish_partners(), publish_giving());

    // Form endpoints on every page
    foreach (glob(site_path('*.html')) as $path) {
        $html = file_get_contents($path);
        $updated = apply_form_endpoints($html);
        if ($updated !== $html) {
            write_atomic($path, $updated);
            $changed[] = basename($path);
        }
    }

    write_atomic(site_path('sitemap.xml'), render_sitemap($stories));
    $changed[] = 'sitemap.xml';

    // Error pages load styles through <base href>, so keep it on the current address
    foreach (ERROR_PAGES as $file) {
        if (!is_file(site_path($file))) continue;
        $html = file_get_contents(site_path($file));
        $updated = preg_replace('#<base href="[^"]*">#', '<base href="' . h(site_url()) . '">', $html, 1);
        if ($updated !== null && $updated !== $html) {
            write_atomic(site_path($file), $updated);
            $changed[] = $file;
        }
    }
    write_atomic(site_path('robots.txt'), "User-agent: *
Allow: /
Disallow: /admin/

Sitemap: " . site_url('sitemap.xml') . "
");
    $changed[] = 'robots.txt';

    save_setting('last_published', date('Y-m-d H:i:s'));
    return array_values(array_unique($changed));
}

/** Publishes and reports the result as a flash message instead of throwing. */
function publish_with_feedback(string $successMessage): void
{
    try {
        publish_site();
        flash('success', $successMessage);
    } catch (Throwable $e) {
        flash('error', 'Saved, but the website pages could not be updated: ' . $e->getMessage());
    }
}
