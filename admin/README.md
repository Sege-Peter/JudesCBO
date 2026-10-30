# Judes CBO admin dashboard

A PHP + MySQL dashboard for managing the website's stories, gallery, messages, newsletter subscribers and donation records.

Content is stored in the database. Each time something is saved, the dashboard regenerates the affected static pages (`index.html`, `stories.html`, `gallery.html`, `blog-*.html`, `sitemap.xml`), so the public site stays fast plain HTML. Only the sections between `<!-- CMS:name -->` and `<!-- /CMS:name -->` markers are rewritten; don't remove those markers.

## Requirements

- PHP 8.1+ with `pdo_mysql` and `gd` (WebP support)
- MySQL or MariaDB
- The website folder must be writable by PHP (it writes the HTML pages and `/uploads`)

## Install on cPanel

1. Upload the whole site (including `admin/` and `uploads/`) to the domain's folder.
2. In cPanel, create a MySQL database and user, and give the user all privileges on it.
3. Create `admin/config.local.php`:

   ```php
   <?php
   return [
       'db_name' => 'cpaneluser_judes',
       'db_user' => 'cpaneluser_judes',
       'db_pass' => 'the-database-password',
       'site_url' => 'https://www.judescbo.org/',
       'secure_cookies' => true,
       'install_key' => 'a-long-random-secret',
   ];
   ```

4. Open `https://www.judescbo.org/admin/install.php?key=a-long-random-secret` and create the first administrator.
5. Log in at `/admin/login.php`, then open **Settings** and click **Rebuild all pages now** so links use the new address.
6. To collect messages in the dashboard, switch **Settings → Contact & newsletter forms** to **Built-in inbox**.

`install.php` never deletes data and locks itself once an administrator exists.

## Local (XAMPP)

The defaults in `config.php` work with XAMPP (`root`, no password, database `judescbo`). Enable `extension=gd` in `php.ini`, start Apache and MySQL, then open `http://localhost/JudesCBO/admin/install.php`.

## Notes

- GitHub Pages can't run PHP. `_config.yml` keeps `admin/` off GitHub Pages; the dashboard only works on PHP hosting.
- Uploaded photos are re-encoded as WebP (max 1600px) with a thumbnail; story covers also get a 1200×630 JPG for social sharing.
- `uploads/.htaccess` stops anything in the uploads folder from running as a script.
