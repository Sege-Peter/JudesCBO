<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/publisher.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $section = $_POST['section'] ?? '';
    if ($section === 'stats') {
        foreach (['stat_founded', 'stat_wards', 'stat_programs', 'stat_partners'] as $key) {
            save_setting($key, (string) max(0, (int) ($_POST[$key] ?? 0)));
        }
        log_activity('settings.stats', 'Homepage numbers updated');
        publish_with_feedback('Homepage numbers updated on the website.');
    } elseif ($section === 'forms') {
        $mode = ($_POST['form_mode'] ?? '') === 'builtin' ? 'builtin' : 'formspree';
        $url = post_str('formspree_url', 200);
        if ($mode === 'formspree' && !preg_match('#^https://formspree\.io/f/[A-Za-z0-9]+$#', $url)) {
            flash('error', 'Enter a Formspree address like https://formspree.io/f/abc123.');
            redirect('settings.php#forms');
        }
        $notify = post_str('notify_email', 190);
        if ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid notification email, or leave it blank.');
            redirect('settings.php#forms');
        }
        save_setting('form_mode', $mode);
        save_setting('formspree_url', $url);
        save_setting('notify_email', $notify);
        log_activity('settings.forms', 'Form handling: ' . $mode);
        publish_with_feedback($mode === 'builtin' ? 'Website forms now send to the built-in inbox.' : 'Website forms now send to Formspree.');
    } elseif ($section === 'publish') {
        try {
            $files = publish_site();
            log_activity('site.publish', count($files) . ' files');
            flash('success', 'Website updated: ' . count($files) . ' files refreshed.');
        } catch (Throwable $e) {
            flash('error', 'The website could not be updated: ' . $e->getMessage());
        }
    }
    redirect('settings.php');
}

$builtin = setting('form_mode', 'formspree') === 'builtin';
render_header('Settings');
?>
<div class="settings-grid">
    <section class="panel">
        <h3 class="panel-title"><i class="fas fa-chart-column"></i> Homepage numbers</h3>
        <p class="muted">The four counters under the homepage photo.</p>
        <form method="post" class="form-grid">
            <?= csrf_field() ?><input type="hidden" name="section" value="stats">
            <label class="field">Year founded<input type="number" name="stat_founded" value="<?= h(setting('stat_founded', '2018')) ?>" min="1900" max="2100"></label>
            <label class="field">Civic wards reached<input type="number" name="stat_wards" value="<?= h(setting('stat_wards', '10')) ?>" min="0"></label>
            <label class="field">Core programs<input type="number" name="stat_programs" value="<?= h(setting('stat_programs', '4')) ?>" min="0"></label>
            <label class="field">Partners<input type="number" name="stat_partners" value="<?= h(setting('stat_partners', '4')) ?>" min="0"></label>
            <button class="btn btn-primary span-2"><i class="fas fa-floppy-disk"></i> Save and update website</button>
        </form>
    </section>

    <section class="panel" id="forms">
        <h3 class="panel-title"><i class="fas fa-inbox"></i> Contact &amp; newsletter forms</h3>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="section" value="forms">
            <label class="radio-card <?= !$builtin ? 'checked' : '' ?>">
                <input type="radio" name="form_mode" value="formspree" <?= !$builtin ? 'checked' : '' ?>>
                <span><strong>Formspree</strong><small>Works on any hosting, including GitHub Pages. Messages arrive by email from Formspree, not in this dashboard.</small></span>
            </label>
            <label class="radio-card <?= $builtin ? 'checked' : '' ?>">
                <input type="radio" name="form_mode" value="builtin" <?= $builtin ? 'checked' : '' ?>>
                <span><strong>Built-in inbox</strong><small>Messages and newsletter signups are saved here. Requires the website to be on PHP hosting (cPanel), not GitHub Pages.</small></span>
            </label>
            <label class="field">Formspree address<input type="url" name="formspree_url" value="<?= h(setting('formspree_url', 'https://formspree.io/f/mjkelgrq')) ?>"></label>
            <label class="field">Email me about new messages <small>Built-in inbox only. Leave blank to turn off.</small><input type="email" name="notify_email" value="<?= h(setting('notify_email')) ?>" placeholder="you@example.com"></label>
            <button class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save and update website</button>
        </form>
    </section>

    <section class="panel">
        <h3 class="panel-title"><i class="fas fa-rotate"></i> Update website</h3>
        <p class="muted">Pages update automatically whenever you save. Use this if a page looks out of date, for example after restoring a backup.</p>
        <p class="small">Last updated: <strong><?= setting('last_published') ? h(date('j M Y, g:i a', strtotime(setting('last_published')))) : 'never' ?></strong></p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="section" value="publish">
            <button class="btn btn-light"><i class="fas fa-rotate"></i> Rebuild all pages now</button></form>
    </section>

    <section class="panel">
        <h3 class="panel-title"><i class="fas fa-server"></i> System</h3>
        <ul class="sys-list">
            <li><span>Website address</span><strong><?= h(config('site_url')) ?></strong></li>
            <li><span>PHP version</span><strong><?= h(PHP_VERSION) ?></strong></li>
            <li><span>Image processing</span><strong><?= function_exists('imagewebp') ? '<i class="fas fa-check text-green"></i> Available' : '<i class="fas fa-xmark text-red"></i> Missing (enable GD)' ?></strong></li>
            <li><span>Uploads folder writable</span><strong><?= is_writable(config('site_root') . '/uploads') || (!is_dir(config('site_root') . '/uploads') && is_writable(config('site_root'))) ? '<i class="fas fa-check text-green"></i> Yes' : '<i class="fas fa-xmark text-red"></i> No' ?></strong></li>
            <li><span>Max upload size</span><strong><?= h(ini_get('upload_max_filesize')) ?></strong></li>
        </ul>
        <p class="muted small">The website address is set in <code>admin/config.local.php</code>.</p>
    </section>
</div>
<?php render_footer(); ?>
