<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/images.php';
require_once __DIR__ . '/includes/publisher.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'reorder') {
        $stmt = db()->prepare('UPDATE partners SET sort_order = ? WHERE id = ?');
        foreach (array_map('intval', (array) ($_POST['ids'] ?? [])) as $i => $partnerId) {
            $stmt->execute([$i + 1, $partnerId]);
        }
        log_activity('partner.reorder', 'Changed the order of partners');
        header('Content-Type: application/json');
        try { publish_site(); echo '{"ok":true}'; }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
        exit;
    }

    $partner = null;
    if ($id) {
        $stmt = db()->prepare('SELECT * FROM partners WHERE id = ?');
        $stmt->execute([$id]);
        $partner = $stmt->fetch() ?: null;
    }

    if ($action === 'delete' && $partner) {
        delete_upload($partner['logo']);
        db()->prepare('DELETE FROM partners WHERE id = ?')->execute([$id]);
        log_activity('partner.delete', $partner['name']);
        publish_with_feedback($partner['name'] . ' removed from the website.');
        redirect('partners');
    }

    if ($action === 'save') {
        $name = post_str('name', 120);
        $url = post_str('url', 255);
        if ($url !== '' && !preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $url)) {
            $url = '';
            flash('error', 'The website link was ignored. It should start with https://');
        }
        $visible = !empty($_POST['visible']) ? 1 : 0;
        if ($name === '') {
            flash('error', 'Enter the partner\'s name.');
            redirect('partners');
        }
        $logo = $partner['logo'] ?? null;
        if (!empty($_FILES['logo']['name'])) {
            try {
                $img = process_image_upload($_FILES['logo'], 'partners', 600, 300);
                delete_upload($logo);
                delete_upload($img['thumb']);
                $logo = $img['image'];
            } catch (RuntimeException $e) {
                flash('error', 'Logo: ' . $e->getMessage());
            }
        } elseif (!empty($_POST['remove_logo'])) {
            delete_upload($logo);
            $logo = null;
        }
        if ($partner) {
            db()->prepare('UPDATE partners SET name = ?, logo = ?, url = ?, visible = ? WHERE id = ?')
                ->execute([$name, $logo, $url, $visible, $id]);
            log_activity('partner.update', $name);
        } else {
            $next = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM partners')->fetchColumn();
            db()->prepare('INSERT INTO partners (name, logo, url, visible, sort_order) VALUES (?, ?, ?, ?, ?)')
                ->execute([$name, $logo, $url, $visible, $next]);
            log_activity('partner.create', $name);
        }
        publish_with_feedback($name . ' saved' . ($visible ? ' and shown on the website.' : ' (hidden from the website).'));
        redirect('partners');
    }
}

$partners = db()->query('SELECT * FROM partners ORDER BY sort_order, id')->fetchAll();
$shown = count(array_filter($partners, fn($p) => $p['visible']));

render_header('Partners');
?>
<div class="toolbar">
    <span class="muted"><?= $shown ?> shown on the homepage and About page</span>
    <a href="../about#partners" target="_blank" rel="noopener" class="btn btn-light push-right"><i class="fas fa-eye"></i> View page</a>
    <button type="button" class="btn btn-primary" data-partner-new><i class="fas fa-plus"></i> Add partner</button>
</div>

<?php if (!$partners): ?>
    <div class="panel empty-state"><i class="fas fa-handshake"></i><h3>No partners yet</h3><p>Add the organisations you work with. Their logos scroll across the homepage and appear on the About page.</p><button type="button" class="btn btn-primary" data-partner-new>Add the first partner</button></div>
<?php else: ?>
    <p class="muted hint-line"><i class="fas fa-up-down-left-right"></i> Drag partners to change their order on the website.<?php if ((int) setting('stat_partners', '4') !== $shown): ?> The homepage counter says <?= (int) setting('stat_partners', '4') ?> partners; <?php if (is_admin()): ?><a href="settings">change it in Settings</a><?php else: ?>an administrator can change it in Settings<?php endif; ?>.<?php endif; ?></p>
    <div class="partner-admin-grid" id="partner-grid">
        <?php foreach ($partners as $p): ?>
            <article class="partner-admin-card <?= $p['visible'] ? '' : 'is-hidden' ?>" data-id="<?= (int) $p['id'] ?>">
                <span class="drag-handle" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></span>
                <span class="partner-admin-logo"><?php if ($p['logo']): ?><img src="../<?= h($p['logo']) ?>" alt=""><?php else: ?><i class="fas fa-image"></i><?php endif; ?></span>
                <strong><?= h($p['name']) ?></strong>
                <small><?= $p['url'] ? h(preg_replace('#^https?://(www\.)?#', '', rtrim($p['url'], '/'))) : 'No website link' ?></small>
                <?php if (!$p['visible']): ?><span class="pill pill-closed">Hidden</span><?php endif; ?>
                <button type="button" class="btn btn-light btn-sm" data-partner='<?= h(json_encode(['id' => (int) $p['id'], 'name' => $p['name'], 'url' => $p['url'], 'visible' => (int) $p['visible'], 'logo' => $p['logo'] ? '../' . $p['logo'] : ''])) ?>'><i class="fas fa-pen"></i> Edit</button>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<dialog class="modal" id="partner-dialog">
    <form method="post" enctype="multipart/form-data" class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="p-id">
        <header class="modal-head"><h3 id="p-title">Add partner</h3><button type="button" class="icon-btn" data-close aria-label="Close"><i class="fas fa-times"></i></button></header>
        <div class="member-photo-row">
            <label class="member-photo-pick logo-pick">
                <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" id="p-logo-input">
                <img id="p-logo" src="" alt="" hidden><span id="p-logo-empty"><i class="fas fa-image"></i></span>
            </label>
            <div class="small muted">Click, drop or paste (Ctrl+V) the logo. A PNG with a transparent background looks best. Logos are shown in grey and turn to colour on hover.<label class="check" id="p-remove-wrap" hidden><input type="checkbox" name="remove_logo" value="1"> Remove logo</label></div>
        </div>
        <label class="field">Name<input type="text" name="name" id="p-name" maxlength="120" required placeholder="e.g. Homa Bay County"></label>
        <label class="field">Website <small>optional, the logo links here</small><input type="url" name="url" id="p-url" maxlength="255" placeholder="https://"></label>
        <label class="check"><input type="checkbox" name="visible" value="1" id="p-visible" checked> Show on the website</label>
        <footer class="modal-foot">
            <button type="submit" form="p-delete" class="btn btn-danger-ghost push-left" id="p-delete-btn" hidden><i class="fas fa-trash"></i> Remove</button>
            <button type="button" class="btn btn-light" data-close>Cancel</button><button type="submit" class="btn btn-primary">Save</button>
        </footer>
    </form>
    <form method="post" id="p-delete" data-confirm="Remove this partner from the website?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="p-delete-id"></form>
</dialog>
<?php render_footer(['https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js', 'assets/partners.js']); ?>
