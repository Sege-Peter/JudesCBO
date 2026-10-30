<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/publisher.php';
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
    } elseif ($section === 'giving') {
        $digits = fn(string $key): string => preg_replace('/\D+/', '', (string) ($_POST[$key] ?? ''));
        $phone = $digits('mpesa_phone');
        if (preg_match('/^254(\d{9})$/', $phone, $m)) $phone = '0' . $m[1];
        $values = [
            'mpesa_paybill' => $digits('mpesa_paybill'),
            'mpesa_account' => post_str('mpesa_account', 40),
            'mpesa_till' => $digits('mpesa_till'),
            'mpesa_phone' => $phone,
            'mpesa_name' => post_str('mpesa_name', 80),
            'bank_name' => post_str('bank_name', 80),
            'bank_branch' => post_str('bank_branch', 80),
            'bank_account_name' => post_str('bank_account_name', 120),
            'bank_account_number' => post_str('bank_account_number', 40),
            'bank_swift' => strtoupper(preg_replace('/\s+/', '', post_str('bank_swift', 20))),
            'give_online_url' => post_str('give_online_url', 255),
            'give_online_label' => post_str('give_online_label', 40),
        ];
        $errors = [];
        if ($values['mpesa_paybill'] !== '' && !preg_match('/^\d{5,7}$/', $values['mpesa_paybill'])) $errors[] = 'A Paybill business number has 5 to 7 digits.';
        if ($values['mpesa_till'] !== '' && !preg_match('/^\d{5,8}$/', $values['mpesa_till'])) $errors[] = 'A till number has 5 to 8 digits.';
        if ($values['mpesa_phone'] !== '' && !preg_match('/^0[17]\d{8}$/', $values['mpesa_phone'])) $errors[] = 'Enter the M-Pesa phone number like 0712 345 678.';
        if ($values['bank_swift'] !== '' && !preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $values['bank_swift'])) $errors[] = 'A SWIFT code has 8 or 11 letters and numbers.';
        if ($values['give_online_url'] !== '' && !preg_match('#^https://[^\s]+$#i', $values['give_online_url'])) $errors[] = 'The online giving link must start with https://';
        if ($errors) {
            foreach ($errors as $e) flash('error', $e);
            redirect('settings.php#giving');
        }
        foreach ($values as $key => $value) save_setting($key, $value);
        log_activity('settings.giving', 'Donation details updated');
        publish_with_feedback('Donation details updated on the Donate page.');
        redirect('settings.php#giving');
    } elseif ($section === 'security') {
        save_setting('require_2fa_admins', !empty($_POST['require_2fa_admins']) ? '1' : '0');
        log_activity('settings.security', 'Two-step login for administrators: ' . (!empty($_POST['require_2fa_admins']) ? 'required' : 'optional'));
        flash('success', 'Security settings saved.');
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

    <section class="panel span-2" id="giving">
        <h3 class="panel-title"><i class="fas fa-hand-holding-heart"></i> Ways to give</h3>
        <p class="muted">Shown on the website's Donate page. Fill in what you have and leave the rest blank. If more than one M-Pesa option is filled in, donors can pick between them.</p>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="section" value="giving">
            <h4 class="form-subhead"><span class="mpesa-chip">M-PESA</span> Lipa na M-Pesa</h4>
            <div class="form-grid">
                <label class="field">Paybill business number<input type="text" name="mpesa_paybill" value="<?= h(setting('mpesa_paybill')) ?>" inputmode="numeric" maxlength="10" placeholder="e.g. 247247"></label>
                <label class="field">Paybill account number <small>leave blank to let donors use their name</small><input type="text" name="mpesa_account" value="<?= h(setting('mpesa_account')) ?>" maxlength="40" placeholder="e.g. DONATION"></label>
                <label class="field">Till number <small>Buy Goods</small><input type="text" name="mpesa_till" value="<?= h(setting('mpesa_till')) ?>" inputmode="numeric" maxlength="10" placeholder="e.g. 123456"></label>
                <label class="field">Send Money phone number<input type="tel" name="mpesa_phone" value="<?= h(format_phone_ke(setting('mpesa_phone', '0712109310'))) ?>" maxlength="16" placeholder="0712 345 678"></label>
                <label class="field span-2">Name M-Pesa shows donors <small>so they can check before entering their PIN</small><input type="text" name="mpesa_name" value="<?= h(setting('mpesa_name')) ?>" maxlength="80" placeholder="e.g. JUDES COMMUNITY BASED ORGANISATION"></label>
            </div>
            <h4 class="form-subhead"><i class="fas fa-building-columns"></i> Bank transfer <small>leave the account number blank to show "email us" instead</small></h4>
            <div class="form-grid">
                <label class="field">Bank<input type="text" name="bank_name" value="<?= h(setting('bank_name')) ?>" maxlength="80" placeholder="e.g. Equity Bank"></label>
                <label class="field">Branch<input type="text" name="bank_branch" value="<?= h(setting('bank_branch')) ?>" maxlength="80" placeholder="e.g. Oyugis"></label>
                <label class="field">Account name<input type="text" name="bank_account_name" value="<?= h(setting('bank_account_name')) ?>" maxlength="120"></label>
                <label class="field">Account number<input type="text" name="bank_account_number" value="<?= h(setting('bank_account_number')) ?>" maxlength="40"></label>
                <label class="field">SWIFT code <small>for transfers from abroad</small><input type="text" name="bank_swift" value="<?= h(setting('bank_swift')) ?>" maxlength="20" placeholder="e.g. EQBLKENA"></label>
            </div>
            <h4 class="form-subhead"><i class="fas fa-globe-africa"></i> Give from abroad <small>optional online giving link, e.g. PayPal, GlobalGiving or a card donation page</small></h4>
            <div class="form-grid">
                <label class="field">Link<input type="url" name="give_online_url" value="<?= h(setting('give_online_url')) ?>" maxlength="255" placeholder="https://"></label>
                <label class="field">Button text<input type="text" name="give_online_label" value="<?= h(setting('give_online_label')) ?>" maxlength="40" placeholder="Give online"></label>
            </div>
            <div class="form-actions"><button class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save and update website</button>
                <a href="../donate#give" target="_blank" rel="noopener" class="btn btn-light"><i class="fas fa-eye"></i> View Donate page</a></div>
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
        <h3 class="panel-title"><i class="fas fa-shield-halved"></i> Security</h3>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="section" value="security">
            <label class="check"><input type="checkbox" name="require_2fa_admins" value="1" <?= setting('require_2fa_admins', '1') === '1' ? 'checked' : '' ?>> Require two-step login for administrators and System admins</label>
            <p class="muted small">Administrators without it are taken to My profile to set it up before they can use the dashboard. Editors can turn it on for themselves.</p>
            <button class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save</button>
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
