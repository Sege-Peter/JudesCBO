<?php
declare(strict_types=1);

function nav_items(): array
{
    $items = [
        ['index', 'Dashboard', 'fa-gauge-high'],
        ['analytics', 'Analytics', 'fa-chart-line'],
        ['stories', 'Stories', 'fa-newspaper'],
        ['gallery', 'Gallery', 'fa-images'],
        ['events', 'Events', 'fa-calendar-days'],
        ['team', 'Team', 'fa-people-group'],
        ['partners', 'Partners', 'fa-handshake'],
        ['messages', 'Messages', 'fa-inbox'],
        ['mailbox', 'Mailbox', 'fa-envelope'],
        ['subscribers', 'Subscribers', 'fa-envelope-open-text'],
        ['donations', 'Donations', 'fa-hand-holding-dollar'],
    ];
    if (is_admin()) {
        $items[] = ['users', 'Users', 'fa-users-gear'];
        $items[] = ['settings', 'Settings', 'fa-sliders'];
        $items[] = ['activity', 'Activity log', 'fa-clock-rotate-left'];
    }
    if (is_developer()) {
        $items[] = ['system', 'System', 'fa-server'];
        $items[] = ['security', 'Security', 'fa-shield-halved'];
    }
    return $items;
}

function render_header(string $title, string $active = ''): void
{
    $user = current_user();
    $newMessages = (int) db()->query("SELECT COUNT(*) FROM messages WHERE status = 'new'")->fetchColumn();
    $page = basename($_SERVER['SCRIPT_NAME'], '.php');
    $active = $active ?: $page;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <meta name="session-idle" content="<?= session_idle_limit() ?>">
    <title><?= h($title) ?> | Judes CBO Admin</title>
    <link rel="icon" href="../jude-logo.png" type="image/png">
    <link rel="preload" href="../fonts/raleway-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../fonts/montserrat-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../css/fonts.css">
    <link rel="stylesheet" href="assets/admin.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/admin.css') ?>">
</head>
<body>
<div class="app">
    <aside class="sidebar" id="sidebar">
        <a href="index" class="brand">
            <span class="brand-logo"><img src="../images/jude-logo-192.webp" alt=""></span>
            <span><strong>JUDES CBO</strong><small>Admin dashboard</small></span>
        </a>
        <nav class="side-nav" aria-label="Dashboard">
            <?php foreach (nav_items() as [$href, $label, $icon]): ?>
                <a href="<?= h($href) ?>" class="<?= $href === $active ? 'active' : '' ?>">
                    <i class="fas <?= h($icon) ?>"></i><span><?= h($label) ?></span>
                    <?php if ($href === 'messages' && $newMessages > 0): ?><em class="badge"><?= $newMessages ?></em><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="side-footer">
            <a href="../index.html" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i><span>View website</span></a>
        </div>
    </aside>
    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>
    <div class="main">
        <header class="topbar">
            <button type="button" class="icon-btn menu-btn" id="menu-btn" aria-label="Open menu"><i class="fas fa-bars"></i></button>
            <h1 class="page-title"><?= h($title) ?></h1>
            <div class="topbar-actions">
                <a href="messages?status=new" class="icon-btn" aria-label="New messages" title="New messages">
                    <i class="fas fa-bell"></i><?php if ($newMessages > 0): ?><span class="dot"></span><?php endif; ?>
                </a>
                <div class="user-menu">
                    <button type="button" class="user-btn" id="user-btn" aria-expanded="false">
                        <span class="avatar"><?= h(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
                        <span class="user-meta"><strong><?= h($user['name']) ?></strong><small><?= h(role_label($user['role'])) ?></small></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="dropdown" id="user-dropdown">
                        <a href="profile"><i class="fas fa-user"></i> My profile</a>
                        <form method="post" action="logout" id="logout-form"><?= csrf_field() ?><button type="submit"><i class="fas fa-right-from-bracket"></i> Log out</button></form>
                    </div>
                </div>
            </div>
        </header>
        <main class="content">
            <?php foreach (take_flashes() as $f): ?>
                <div class="alert alert-<?= h($f['type']) ?>" role="status"><?= h($f['message']) ?><button type="button" class="alert-close" aria-label="Dismiss">&times;</button></div>
            <?php endforeach; ?>
    <?php
}

function render_footer(array $scripts = []): void
{
    ?>
        </main>
    </div>
</div>
<script src="assets/admin.js"></script>
<?php foreach ($scripts as $src): ?><script src="<?= h($src) ?>"></script><?php endforeach; ?>
</body>
</html>
    <?php
}

function status_pill(string $status): string
{
    return '<span class="pill pill-' . h($status) . '">' . h(ucfirst($status)) . '</span>';
}

function paginate(int $total, int $perPage, int $page, array $query): string
{
    $pages = (int) ceil($total / $perPage);
    if ($pages <= 1) return '';
    $html = '<nav class="pagination" aria-label="Pages">';
    for ($i = 1; $i <= $pages; $i++) {
        $query['page'] = $i;
        $html .= '<a href="?' . h(http_build_query($query)) . '" class="' . ($i === $page ? 'active' : '') . '">' . $i . '</a>';
    }
    return $html . '</nav>';
}
