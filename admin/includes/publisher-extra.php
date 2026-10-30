<?php
declare(strict_types=1);

/* ---------- Events ---------- */

function event_paragraphs(string $text): string
{
    $out = [];
    foreach (preg_split('/\R{2,}/', trim($text)) ?: [] as $para) {
        if (trim($para) !== '') $out[] = '<p>' . nl2br(h(trim($para)), false) . '</p>';
    }
    return implode("\n", $out);
}

function event_end(array $e): int
{
    return $e['ends_at'] ? strtotime($e['ends_at']) : strtotime($e['starts_at']) + 2 * 3600;
}

function event_when(array $e): string
{
    $start = strtotime($e['starts_at']);
    $end = event_end($e);
    $text = date('D j M Y · g:i a', $start);
    if ($e['ends_at']) {
        $text .= date('Y-m-d', $start) === date('Y-m-d', $end) ? ' – ' . date('g:i a', $end) : ' – ' . date('D j M, g:i a', $end);
    }
    return $text;
}

function utc_stamp(int $ts): string
{
    return gmdate('Ymd\THis\Z', $ts);
}

function event_place(array $e): string
{
    return trim($e['venue'] . ($e['ward'] ? ', ' . $e['ward'] : '') . ', Homa Bay County, Kenya', ', ');
}

function event_google_link(array $e): string
{
    $details = trim($e['summary'] . "\n\n" . site_url('events'));
    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action' => 'TEMPLATE',
        'text' => $e['title'] . ' | Judes CBO',
        'dates' => utc_stamp(strtotime($e['starts_at'])) . '/' . utc_stamp(event_end($e)),
        'details' => $details,
        'location' => event_place($e),
        'ctz' => 'Africa/Nairobi',
    ]);
}

function ics_escape(string $text): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $text);
}

function ics_fold(string $line): string
{
    $out = '';
    while (strlen($line) > 74) {
        $cut = 74;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--; // don't split a UTF-8 character
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line;
}

function render_event_ics(array $e): string
{
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Judes CBO//Events//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:event-' . $e['id'] . '@judescbo',
        'DTSTAMP:' . utc_stamp(strtotime($e['updated_at'] ?? 'now')),
        'DTSTART:' . utc_stamp(strtotime($e['starts_at'])),
        'DTEND:' . utc_stamp(event_end($e)),
        'SUMMARY:' . ics_escape($e['title'] . ' | Judes CBO'),
        'LOCATION:' . ics_escape(event_place($e)),
        'DESCRIPTION:' . ics_escape(trim($e['summary'] . "\n\n" . $e['description'])),
        'URL:' . site_url('events'),
        'ORGANIZER;CN=Judes CBO:mailto:' . setting('org_email', 'info@judescbo.org'),
        'END:VEVENT', 'END:VCALENDAR',
    ];
    return implode("\r\n", array_map('ics_fold', $lines)) . "\r\n";
}

function render_event_card(array $e, bool $past = false): string
{
    $start = strtotime($e['starts_at']);
    $share = rawurlencode($e['title'] . ' – ' . date('D j M, g:i a', $start) . ' at ' . $e['venue'] . '. ' . site_url('events'));
    $image = $e['image'] ? '<img class="event-card-img" src="' . h($e['image']) . '" alt="' . h($e['image_alt'] ?: $e['title']) . '" loading="lazy">' : '';
    $more = trim($e['description']) !== ''
        ? '<details class="event-more"><summary>More details</summary>' . "\n" . event_paragraphs($e['description']) . "\n</details>"
        : '';
    $actions = $past ? '' : '<div class="event-actions">'
        . '<a href="calendar/' . h($e['slug']) . '.ics" class="event-btn event-btn-primary" download><i class="far fa-calendar-plus"></i> Add to calendar</a>'
        . '<a href="' . h(event_google_link($e)) . '" class="event-btn" target="_blank" rel="noopener"><i class="fab fa-google"></i> Google Calendar</a>'
        . '<a href="https://wa.me/?text=' . $share . '" class="event-btn" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Share</a>'
        . '</div>';
    return '<article class="event-card reveal' . ($e['image'] ? ' has-img' : '') . '" id="event-' . h($e['slug']) . '" data-ends="' . date('c', event_end($e)) . '">' . "\n"
        . '    <div class="event-card-date"><strong>' . date('j', $start) . '</strong><span>' . date('M', $start) . '</span><small>' . date('Y', $start) . "</small></div>\n"
        . '    <div class="event-card-body">' . "\n"
        . '        <div class="event-meta"><span class="story-tag">' . h($e['category']) . '</span><span><i class="far fa-clock"></i> ' . h(event_when($e)) . "</span></div>\n"
        . '        <h3>' . h($e['title']) . "</h3>\n"
        . '        <p class="event-where"><i class="fas fa-location-dot"></i> ' . h($e['venue'] . ($e['ward'] ? ', ' . $e['ward'] : '')) . "</p>\n"
        . ($e['summary'] !== '' ? '        <p>' . h($e['summary']) . "</p>\n" : '')
        . ($more ? '        ' . $more . "\n" : '')
        . ($actions ? '        ' . $actions . "\n" : '')
        . "    </div>\n"
        . ($image ? '    ' . $image . "\n" : '')
        . '</article>';
}

function render_events_schema(array $events, ?array $org): string
{
    $orgRef = ['@id' => $org['@id'] ?? site_url('#organization')];
    $items = [];
    foreach ($events as $e) {
        $items[] = [
            '@type' => 'Event',
            'name' => $e['title'],
            'description' => $e['summary'] ?: mb_substr($e['description'], 0, 300),
            'startDate' => date('c', strtotime($e['starts_at'])),
            'endDate' => date('c', event_end($e)),
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'location' => ['@type' => 'Place', 'name' => $e['venue'], 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $e['ward'] ?: 'Oyugis', 'addressRegion' => 'Homa Bay County', 'addressCountry' => 'KE']],
            'image' => site_url($e['image'] ?: 'images/brand/og-events.jpg'),
            'url' => site_url('events') . '#event-' . $e['slug'],
            'organizer' => $orgRef,
            'isAccessibleForFree' => true,
        ];
    }
    if (!$items) return '';
    $json = json_encode(['@context' => 'https://schema.org', '@graph' => $items], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    return '<script type="application/ld+json">' . $json . '</script>';
}

function publish_events(array $shell): array
{
    $changed = [];
    $all = db()->query("SELECT * FROM events WHERE status = 'published' ORDER BY starts_at")->fetchAll();
    $now = time();
    $upcoming = array_values(array_filter($all, fn($e) => event_end($e) >= $now));
    $past = array_reverse(array_values(array_filter($all, fn($e) => event_end($e) < $now)));

    // Calendar files for every published event; remove files for events that are gone
    if (!is_dir(site_path('calendar'))) mkdir(site_path('calendar'), 0755, true);
    $keep = [];
    foreach ($all as $e) {
        $file = 'calendar/' . $e['slug'] . '.ics';
        write_atomic(site_path($file), render_event_ics($e));
        $keep[$file] = true;
    }
    foreach (glob(site_path('calendar/*.ics')) ?: [] as $path) {
        if (!isset($keep['calendar/' . basename($path)])) @unlink($path);
    }

    $upcomingHtml = $upcoming
        ? implode("\n", array_map(fn($e) => render_event_card($e), $upcoming)) . "\n" . render_events_schema($upcoming, $shell['org'])
        : '<div class="events-empty"><i class="far fa-calendar"></i><p>No upcoming events right now. Follow us on WhatsApp or check back soon.</p></div>';
    $pastHtml = $past ? implode("\n", array_map(fn($e) => render_event_card($e, true), array_slice($past, 0, 12))) : '<p class="events-none">Past events will appear here.</p>';
    if (is_file(site_path('events.html'))) {
        $html = file_get_contents(site_path('events.html'));
        $html = replace_region($html, 'events-upcoming', indent_block($upcomingHtml, 16), 'events.html');
        $html = replace_region($html, 'events-past', indent_block($pastHtml, 16), 'events.html');
        write_atomic(site_path('events.html'), $html);
        $changed[] = 'events.html';
    }

    // Homepage strip: up to three upcoming events, nothing at all when there are none
    $home = '';
    if ($upcoming) {
        $cards = '';
        foreach (array_slice($upcoming, 0, 3) as $e) {
            $start = strtotime($e['starts_at']);
            $cards .= '            <a href="events#event-' . h($e['slug']) . '" class="home-event reveal">'
                . '<span class="home-event-date"><strong>' . date('j', $start) . '</strong>' . date('M', $start) . '</span>'
                . '<span class="home-event-info"><strong>' . h($e['title']) . '</strong><small><i class="far fa-clock"></i> ' . h(date('D, g:i a', $start)) . ' · <i class="fas fa-location-dot"></i> ' . h($e['venue']) . '</small></span>'
                . '<i class="fas fa-arrow-right"></i></a>' . "\n";
        }
        $home = '<section class="home-section home-events">' . "\n"
            . '    <div class="container">' . "\n"
            . '        <div class="section-head section-head--row reveal">' . "\n"
            . '            <div><span class="eyebrow">Join us</span><h2>Upcoming Events</h2></div>' . "\n"
            . '            <a href="events" class="text-link">All events <i class="fas fa-arrow-right"></i></a>' . "\n"
            . "        </div>\n"
            . '        <div class="home-events-list">' . "\n" . $cards . "        </div>\n"
            . "    </div>\n"
            . '</section>';
    }
    $index = file_get_contents(site_path('index.html'));
    if (str_contains($index, '<!-- CMS:home-events -->')) {
        write_atomic(site_path('index.html'), replace_region($index, 'home-events', $home === '' ? '' : indent_block($home, 4), 'index.html'));
        $changed[] = 'index.html';
    }
    return $changed;
}

/* ---------- Team ---------- */

function publish_team(): array
{
    if (!is_file(site_path('team.html'))) return [];
    $members = db()->query('SELECT * FROM team_members WHERE visible = 1 ORDER BY FIELD(team_group, "Leadership", "Board", "Staff", "Volunteers"), sort_order, id')->fetchAll();
    $groups = [];
    foreach ($members as $m) $groups[$m['team_group']][] = $m;
    $out = [];
    foreach ($groups as $group => $people) {
        $cards = [];
        foreach ($people as $m) {
            $photo = $m['photo']
                ? '<img src="' . h($m['photo']) . '" alt="' . h($m['name']) . '" loading="lazy">'
                : '<span class="team-initials">' . h(implode('', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice(preg_split('/\s+/', trim($m['name'])), 0, 2)))) . '</span>';
            $linkedin = $m['linkedin'] ? '<a href="' . h($m['linkedin']) . '" class="team-link" target="_blank" rel="noopener" aria-label="' . h($m['name']) . ' on LinkedIn"><i class="fab fa-linkedin-in"></i></a>' : '';
            $cards[] = '    <article class="team-card reveal">' . "\n"
                . '        <div class="team-photo">' . $photo . "</div>\n"
                . '        <h3>' . h($m['name']) . "</h3>\n"
                . ($m['role'] ? '        <p class="team-role">' . h($m['role']) . "</p>\n" : '')
                . ($m['bio'] ? '        <p class="team-bio">' . h($m['bio']) . "</p>\n" : '')
                . ($linkedin ? '        ' . $linkedin . "\n" : '')
                . '    </article>';
        }
        $out[] = '<div class="team-group">' . "\n" . '<h2 class="team-group-title reveal">' . h($group) . "</h2>\n"
            . '<div class="team-grid">' . "\n" . implode("\n", $cards) . "\n</div>\n</div>";
    }
    $inner = $out ? implode("\n", $out) : '<div class="events-empty"><i class="fas fa-people-group"></i><p>Our team profiles are coming soon.</p></div>';
    $html = file_get_contents(site_path('team.html'));
    write_atomic(site_path('team.html'), replace_region($html, 'team', indent_block($inner, 12), 'team.html'));
    return ['team.html'];
}
