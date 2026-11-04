<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/images.php';
require_once __DIR__ . '/includes/publisher.php';
$user = require_login();

const ALBUMS = ['General', 'Community', 'Outreach', 'Youth Health', 'SGBV', 'Mental Health', 'Disability Inclusion', 'Events'];

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $album = in_array($_POST['album'] ?? '', ALBUMS, true) ? $_POST['album'] : 'General';
        $caption = post_str('caption', 160);
        $onHome = !empty($_POST['show_on_home']) ? 1 : 0;
        $next = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM gallery')->fetchColumn();
        $added = 0;
        $failed = [];
        foreach (uploaded_files('photos') as $file) {
            if ($file['error'] === UPLOAD_ERR_NO_FILE) continue;
            try {
                $img = process_image_upload($file, 'gallery');
                $alt = $caption ?: ucfirst(trim(preg_replace('/[_\-]+/', ' ', pathinfo($file['name'], PATHINFO_FILENAME)) ?? ''));
                db()->prepare('INSERT INTO gallery (image, thumb, caption, alt, album, show_on_home, sort_order, width, height, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$img['image'], $img['thumb'], $caption, mb_substr($alt, 0, 200), $album, $onHome, ++$next, $img['width'], $img['height'], $user['id']]);
                $added++;
            } catch (RuntimeException $e) {
                $failed[] = $file['name'] . ': ' . $e->getMessage();
            }
        }
        foreach ($failed as $f) flash('error', $f);
        if ($added) {
            log_activity('gallery.upload', "$added photo(s) to $album");
            publish_with_feedback("$added photo" . ($added === 1 ? '' : 's') . ' added to the gallery.');
        } elseif (!$failed) {
            flash('error', 'Choose at least one photo to upload.');
        }
        redirect('gallery');
    }

    if ($action === 'reorder') {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $stmt = db()->prepare('UPDATE gallery SET sort_order = ? WHERE id = ?');
        foreach ($ids as $i => $id) $stmt->execute([$i + 1, $id]);
        log_activity('gallery.reorder', count($ids) . ' photos');
        try { publish_site(); json_out(['ok' => true]); }
        catch (Throwable $e) { json_out(['ok' => false, 'error' => $e->getMessage()], 500); }
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $album = in_array($_POST['album'] ?? '', ALBUMS, true) ? $_POST['album'] : 'General';
        db()->prepare('UPDATE gallery SET caption = ?, alt = ?, album = ?, show_on_home = ? WHERE id = ?')
            ->execute([post_str('caption', 160), post_str('alt', 200), $album, !empty($_POST['show_on_home']) ? 1 : 0, $id]);
        log_activity('gallery.update', "Photo #$id");
        publish_with_feedback('Photo details saved.');
        redirect('gallery' . (isset($_GET['album']) ? '?album=' . urlencode($_GET['album']) : ''));
    }

    if ($action === 'bulk') {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $op = $_POST['op'] ?? '';
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            if ($op === 'delete') {
                $stmt = db()->prepare("SELECT image, thumb FROM gallery WHERE id IN ($in)");
                $stmt->execute($ids);
                foreach ($stmt as $row) { delete_upload($row['image']); delete_upload($row['thumb']); }
                db()->prepare("DELETE FROM gallery WHERE id IN ($in)")->execute($ids);
                log_activity('gallery.delete', count($ids) . ' photo(s)');
                publish_with_feedback(count($ids) . ' photo(s) deleted.');
            } elseif (in_array($op, ['home-on', 'home-off'], true)) {
                db()->prepare("UPDATE gallery SET show_on_home = ? WHERE id IN ($in)")->execute([$op === 'home-on' ? 1 : 0, ...$ids]);
                log_activity('gallery.homepage', count($ids) . ' photo(s) ' . ($op === 'home-on' ? 'added to' : 'removed from') . ' homepage');
                publish_with_feedback('Homepage photos updated.');
            } elseif (str_starts_with($op, 'album:') && in_array(substr($op, 6), ALBUMS, true)) {
                db()->prepare("UPDATE gallery SET album = ? WHERE id IN ($in)")->execute([substr($op, 6), ...$ids]);
                log_activity('gallery.album', count($ids) . ' photo(s) moved to ' . substr($op, 6));
                publish_with_feedback('Photos moved to ' . substr($op, 6) . '.');
            }
        } else {
            flash('error', 'Select at least one photo first.');
        }
        redirect('gallery');
    }
}

$album = in_array($_GET['album'] ?? '', ALBUMS, true) ? $_GET['album'] : '';
$stmt = db()->prepare('SELECT * FROM gallery' . ($album ? ' WHERE album = ?' : '') . ' ORDER BY sort_order, id');
$stmt->execute($album ? [$album] : []);
$photos = $stmt->fetchAll();
$albumCounts = db()->query('SELECT album, COUNT(*) c FROM gallery GROUP BY album')->fetchAll(PDO::FETCH_KEY_PAIR);
$homeCount = (int) db()->query('SELECT COUNT(*) FROM gallery WHERE show_on_home = 1')->fetchColumn();

render_header('Gallery');
?>
<section class="panel upload-panel" id="upload">
    <form method="post" enctype="multipart/form-data" id="upload-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload">
        <label class="dropzone" id="dropzone">
            <input type="file" name="photos[]" id="photos" accept="image/jpeg,image/png,image/webp,image/gif" multiple required>
            <span class="dropzone-text"><i class="fas fa-cloud-arrow-up"></i><strong><span class="hide-touch">Drop photos here, paste with Ctrl+V, or click to choose</span><span class="show-touch">Tap to choose photos</span></strong><small>JPG, PNG or WebP, up to <?= round(config('max_upload') / 1048576) ?> MB each. Photos are resized and optimised automatically.</small></span>
            <span class="upload-previews" id="upload-previews"></span>
        </label>
        <div class="upload-options">
            <label class="field">Album
                <select name="album"><?php foreach (ALBUMS as $a): ?><option><?= h($a) ?></option><?php endforeach; ?></select>
            </label>
            <label class="field">Caption <small>optional, used for all photos in this upload</small>
                <input type="text" name="caption" maxlength="160" placeholder="e.g. Youth forum in Oyugis">
            </label>
            <label class="check"><input type="checkbox" name="show_on_home" value="1"> Show on homepage</label>
            <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Upload</button>
        </div>
    </form>
</section>

<div class="toolbar">
    <div class="tabs tabs-scroll">
        <a href="gallery" class="<?= $album === '' ? 'active' : '' ?>">All <em><?= array_sum($albumCounts) ?></em></a>
        <?php foreach (ALBUMS as $a): if (empty($albumCounts[$a])) continue; ?>
            <a href="?album=<?= urlencode($a) ?>" class="<?= $album === $a ? 'active' : '' ?>"><?= h($a) ?> <em><?= (int) $albumCounts[$a] ?></em></a>
        <?php endforeach; ?>
    </div>
    <span class="muted"><i class="fas fa-house"></i> <?= $homeCount ?> on homepage (first 6 are shown)</span>
</div>

<?php if (!$photos): ?>
    <div class="panel empty-state"><i class="fas fa-images"></i><h3>No photos here yet</h3><p>Upload photos above. They appear on the website's Gallery page.</p></div>
<?php else: ?>
    <form method="post" id="bulk-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk">
        <div class="bulk-bar" id="bulk-bar" hidden>
            <span><strong id="bulk-count">0</strong> selected</span>
            <select name="op" id="bulk-op">
                <option value="home-on">Show on homepage</option>
                <option value="home-off">Remove from homepage</option>
                <?php foreach (ALBUMS as $a): ?><option value="album:<?= h($a) ?>">Move to <?= h($a) ?></option><?php endforeach; ?>
                <option value="delete">Delete</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
            <button type="button" class="btn btn-light btn-sm" id="bulk-clear">Clear</button>
        </div>
        <?php if (!$album): ?><p class="muted hint-line"><i class="fas fa-up-down-left-right"></i> Drag photos to change their order on the website.</p><?php endif; ?>
        <div class="photo-grid" id="photo-grid" data-sortable="<?= $album ? '0' : '1' ?>">
            <?php foreach ($photos as $p): ?>
                <figure class="photo-card" data-id="<?= (int) $p['id'] ?>">
                    <label class="photo-select"><input type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>"><span></span></label>
                    <?php if ($p['show_on_home']): ?><span class="photo-badge"><i class="fas fa-house"></i> Home</span><?php endif; ?>
                    <img src="../<?= h($p['thumb']) ?>" alt="<?= h($p['alt']) ?>" loading="lazy" draggable="false">
                    <figcaption>
                        <strong><?= h($p['caption'] ?: 'No caption') ?></strong>
                        <small><?= h($p['album']) ?></small>
                    </figcaption>
                    <button type="button" class="photo-edit" aria-label="Edit photo details"
                        data-id="<?= (int) $p['id'] ?>" data-caption="<?= h($p['caption']) ?>" data-alt="<?= h($p['alt']) ?>"
                        data-album="<?= h($p['album']) ?>" data-home="<?= (int) $p['show_on_home'] ?>" data-src="../<?= h($p['image']) ?>"><i class="fas fa-pen"></i></button>
                </figure>
            <?php endforeach; ?>
        </div>
    </form>
<?php endif; ?>

<dialog class="modal" id="photo-dialog">
    <form method="post" class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="pd-id">
        <header class="modal-head"><h3>Photo details</h3><button type="button" class="icon-btn" data-close aria-label="Close"><i class="fas fa-times"></i></button></header>
        <img id="pd-img" src="" alt="" class="modal-img">
        <label class="field">Caption <small>Shown on the gallery</small><input type="text" name="caption" id="pd-caption" maxlength="160"></label>
        <label class="field">Image description <small>For screen readers and search engines</small><input type="text" name="alt" id="pd-alt" maxlength="200"></label>
        <label class="field">Album<select name="album" id="pd-album"><?php foreach (ALBUMS as $a): ?><option><?= h($a) ?></option><?php endforeach; ?></select></label>
        <label class="check"><input type="checkbox" name="show_on_home" value="1" id="pd-home"> Show on homepage</label>
        <footer class="modal-foot"><button type="button" class="btn btn-light" data-close>Cancel</button><button type="submit" class="btn btn-primary">Save</button></footer>
    </form>
</dialog>
<?php render_footer(['assets/vendor/Sortable.min.js', 'assets/gallery.js']); ?>
