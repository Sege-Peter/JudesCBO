<?php
/**
 * One-time setup: creates the database tables, seeds the existing website content
 * and creates the first administrator. It never deletes data, and it locks itself
 * once an administrator exists.
 */
declare(strict_types=1);

$CONFIG = require __DIR__ . '/config.php';
require __DIR__ . '/includes/passwords.php';

function h(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

$errors = [];
$done = false;

// On a live server, setup needs a secret key so nobody else can create the first admin account
$host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
$isLocal = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
if (!$isLocal) {
    $key = (string) ($CONFIG['install_key'] ?? '');
    if (strlen($key) < 16) {
        http_response_code(403);
        exit('Setup is locked. Add an install_key of at least 16 characters to admin/config.local.php, then open install.php?key=YOUR_KEY.');
    }
    if (!hash_equals($key, (string) ($_GET['key'] ?? $_POST['key'] ?? ''))) {
        http_response_code(403);
        exit('Setup is locked. Open install.php?key=YOUR_KEY using the install_key from admin/config.local.php.');
    }
}

try {
    // Create the database if this account is allowed to (on cPanel, create it in the panel first)
    try {
        $server = new PDO(sprintf('mysql:host=%s;charset=utf8mb4', $CONFIG['db_host']), $CONFIG['db_user'], $CONFIG['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $CONFIG['db_name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    } catch (PDOException $e) {
        // Fine if the database already exists and this user can't create databases
    }
    $pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $CONFIG['db_host'], $CONFIG['db_name']), $CONFIG['db_user'], $CONFIG['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/includes/schema.sql')))) as $sql) {
        $pdo->exec($sql);
    }
} catch (PDOException $e) {
    exit('Could not connect to the database. Check the settings in admin/config.php (or config.local.php). Error: ' . h($e->getMessage()));
}

$hasAdmin = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
if ($hasAdmin) {
    http_response_code(403);
    exit('Setup is already complete. <a href="login.php">Go to the login page</a>.');
}

/* ---------- Seed existing website content (only into empty tables) ---------- */

$defaults = [
    'stat_founded' => '2018',
    'stat_wards' => '10',
    'stat_programs' => '4',
    'stat_partners' => '4',
    'form_mode' => 'formspree',
    'formspree_url' => 'https://formspree.io/f/mjkelgrq',
    'notify_email' => '',
    'org_email' => 'info@judescbo.co.ke',
];
$insertSetting = $pdo->prepare('INSERT IGNORE INTO settings (name, value) VALUES (?, ?)');
foreach ($defaults as $k => $v) {
    $insertSetting->execute([$k, $v]);
}

if ((int) $pdo->query('SELECT COUNT(*) FROM stories')->fetchColumn() === 0) {
    $stories = [
        [
            'youth-health', 'Youth and Adolescent Health Programme', 'Youth Health',
            'Our Youth and Adolescent Health Programme focuses on SRHR, mental health, mentorship, and peer networks, working with Tiko Africa across 10 civic wards in Homa Bay County.',
            'How Judes CBO and Tiko Africa bring SRHR education, contraceptives, HIV testing and mental health support to young people across 10 wards in Homa Bay County.',
            '<p>At Judes CBO, we are committed to creating a future where young people can make informed choices about their health, education, and wellbeing. Our Youth and Adolescent Health Programme focuses on Sexual and Reproductive Health and Rights (SRHR) and mental health, with a strong emphasis on empowering adolescent girls and young women (AGYW).</p>'
            . '<h2>What the programme offers</h2><ul><li>Comprehensive sexuality education tailored for adolescents and youth.</li><li>Access to youth-friendly reproductive health services in partnership with local facilities.</li><li>Mentorship and peer-support networks to guide young people in making better life choices.</li><li>Mental health awareness and counseling support for resilience and wellbeing.</li></ul>'
            . '<h2>Working with Tiko Africa</h2><p>We are currently partnering with Tiko Africa (Triggerise) in Homa Bay County, working across 10 civic wards to provide contraceptives, HIV testing, and mental health support.</p>'
            . '<blockquote>Together, we are building a healthier, brighter future where no girl is left behind because of unplanned pregnancies or lack of support.</blockquote>',
            'images/hero-7.webp', 'Young people gathered at a Tiko health outreach tent in Homa Bay County', '2023-08-20',
        ],
        [
            'disability-inclusion', 'Breaking Barriers: Disability Inclusion', 'Disability Inclusion',
            'Our initiative supports neurodiverse individuals and persons with Cerebral Palsy through inclusive education, advocacy, and empowerment.',
            'Judes CBO supports neurodiverse people and persons with Cerebral Palsy in Homa Bay County through inclusive education, vocational training and awareness.',
            '<p>Judes CBO is committed to supporting neurodiverse individuals and persons with Cerebral Palsy through inclusive education, vocational training, and community awareness programs.</p>'
            . '<h2>Our focus</h2><p>Our focus is on removing stigma, promoting dignity, and ensuring that every person, regardless of ability, can actively contribute to society and access equal opportunities.</p>'
            . '<blockquote>By breaking barriers, we are paving the way for a more inclusive and compassionate community.</blockquote>',
            null, '', '2023-03-12',
        ],
    ];
    $stmt = $pdo->prepare("INSERT INTO stories (slug, title, category, excerpt, meta_description, body_html, cover_image, cover_alt, status, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'published', ?)");
    foreach ($stories as $s) {
        $stmt->execute($s);
    }
}

if ((int) $pdo->query('SELECT COUNT(*) FROM gallery')->fetchColumn() === 0) {
    $photos = [
        ['images/hero-4.webp', 'Community gathering', 'A large community gathering under the trees', 'Community', 1],
        ['images/hero-7.webp', 'Youth health outreach with Tiko', 'Young people at a Tiko health outreach tent', 'Youth Health', 1],
        ['images/hero-1.webp', 'Families we support', 'Children and caregivers supported by Judes CBO', 'Community', 0],
        ['images/hero-2.webp', 'Community dialogue', 'A community dialogue session outdoors', 'Community', 1],
        ['images/hero-5.webp', 'Outreach event', 'An outreach event with tents and a crowd', 'Outreach', 1],
        ['images/hero-3.webp', 'Youth forum', 'Young people seated at a community forum', 'Youth Health', 1],
        ['images/hero-6.webp', 'Community outreach', 'Young people at a community outreach', 'Outreach', 1],
    ];
    $stmt = $pdo->prepare('INSERT INTO gallery (image, thumb, caption, alt, album, show_on_home, sort_order, width, height) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($photos as $i => $p) {
        $size = @getimagesize($CONFIG['site_root'] . '/' . $p[0]) ?: [0, 0];
        $stmt->execute([$p[0], $p[0], $p[1], $p[2], $p[3], $p[4], $i + 1, $size[0], $size[1]]);
    }
}

/* ---------- First administrator ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');

    if ($name === '') $errors[] = 'Enter your name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $username)) $errors[] = 'Username must be 3 to 60 letters, numbers, dots, dashes or underscores.';
    if ($problem = password_problem($password, [$name, $username, $email])) $errors[] = $problem;
    if ($password !== $confirm) $errors[] = 'The two passwords do not match.';

    if (!$errors) {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, username, password_hash, role) VALUES (?, ?, ?, ?, 'admin')");
        $stmt->execute([$name, $email, $username, password_hash($password, PASSWORD_DEFAULT)]);
        $pdo->prepare("INSERT INTO activity_log (user_id, action, details, ip) VALUES (?, 'install', 'Setup completed', ?)")
            ->execute([$pdo->lastInsertId(), substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45)]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Set up | Judes CBO Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700&family=Raleway:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <img src="../jude-logo.png" alt="Judes CBO" class="auth-logo">
        <?php if ($done): ?>
            <h1>All set!</h1>
            <p class="auth-sub">Your administrator account is ready and the existing stories and photos have been imported.</p>
            <a href="login.php" class="btn btn-primary btn-block">Go to login</a>
        <?php else: ?>
            <h1>Set up the dashboard</h1>
            <p class="auth-sub">The database is ready. Create the first administrator account.</p>
            <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= h($e) ?></div><?php endforeach; ?>
            <form method="post" class="form-stack">
                <label>Full name<input type="text" name="name" value="<?= h($_POST['name'] ?? '') ?>" required></label>
                <label>Email<input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required></label>
                <label>Username<input type="text" name="username" value="<?= h($_POST['username'] ?? '') ?>" required autocomplete="username"></label>
                <label>Password <small>(at least 10 characters)</small><input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
                <label>Confirm password<input type="password" name="confirm" required minlength="10" autocomplete="new-password"></label>
                <button type="submit" class="btn btn-primary btn-block">Create administrator</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
