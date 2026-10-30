<?php
/**
 * Judes CBO admin configuration.
 *
 * Put real server values in config.local.php (same keys, not committed to git).
 * The defaults below suit a local XAMPP install.
 */
$config = [
    'db_host' => '127.0.0.1',
    'db_name' => 'judescbo',
    'db_user' => 'root',
    'db_pass' => '',
    // Public address of the website, with a trailing slash. Used for canonical URLs, share links and the sitemap.
    'site_url' => 'https://sege-peter.github.io/JudesCBO/',
    // Folder that holds the public website files (one level above /admin).
    'site_root' => dirname(__DIR__),
    // Largest image upload accepted, in bytes.
    'max_upload' => 12 * 1024 * 1024,
    // Set to true once the site is served over HTTPS so the session cookie is HTTPS-only.
    'secure_cookies' => false,
    // Required to run install.php on a live server: open install.php?key=<this value>. Not needed on localhost.
    'install_key' => '',
    // Address of the dashboard, used in password-reset emails, e.g. https://www.judescbo.org/admin/
    'admin_url' => '',
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_merge($config, require $local);
}

return $config;
