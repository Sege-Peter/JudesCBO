<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/images.php';
require __DIR__ . '/includes/publisher.php';
$user = require_login();

const EVENT_CATEGORIES = ['Outreach', 'Community dialogue', 'Training', 'Health camp', 'Awareness campaign', 'Meeting', 'Fundraiser', 'Other'];

$id = (int) ($_GET['edit'] ?? $_POST['id'] ?? 0);
$blank = [
    'id' => 0, 'title' => '', 'slug' => '', 'summary' => '', 'description' => '', 'category' => 'Outreach',
    'start_date' => date('Y-m-d', strtotime('+7 days')), 'start_time' => '09:00', 'end_date' => '', 'end_time' => '13:00',
    'venue' => '', 'ward' => '', 'image' => null, 'image_alt' => '', 'status' => 'published',
];
$event = $blank;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$id]);
    if ($row = $stmt->fetch()) {
        $event = array_merge($row, [
            'start_date' => date('Y-m-d', strtotime($row['starts_at'])), 'start_time' => date('H:i', strtotime($row['starts_at'])),
            'end_date' => $row['ends_at'] ? date('Y-m-d', strtotime($row['ends_at'])) : '', 'end_time' => $row['ends_at'] ? date('H:i', strtotime($row['ends_at'])) : '',
        ]);
    } else {
        $id = 0;
    }
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete' && $id) {
        delete_upload($event['image']);
        db()->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
        log_activity('event.delete', $event['title']);
        publish_with_feedback('Event deleted and removed from the website.');
        redirect('events.php');
    }

    $old = $event;
    foreach (['title' => 200, 'summary' => 300, 'description' => 4000, 'venue' => 200, 'ward' => 100, 'image_alt' => 200, 'start_date' => 10, 'start_time' => 5, 'end_date' => 10, 'end_time' => 5] as $k => $max) {
        $event[$k] = post_str($k, $max);
    }
    $event['slug'] = slugify(post_str('slug', 120) ?: $event['title']);
    $event['category'] = in_array($_POST['category'] ?? '', EVENT_CATEGORIES, true) ? $_POST['category'] : 'Other';
    $event['status'] = ($_POST['status'] ?? '') === 'draft' ? 'draft' : 'published';

    if ($event['title'] === '') $errors[] = 'Give the event a title.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $event['start_date'])) $errors[] = 'Choose the start date.';
    if (!preg_match('/^\d{2}:\d{2}$/', $event['start_time'])) $event['start_time'] = '09:00';
    $startsAt = $event['start_date'] . ' ' . $event['start_time'] . ':00';
    $endsAt = null;
    if (preg_match('/^\d{2}:\d{2}$/', $event['end_time'])) {
        $endDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $event['end_date']) ? $event['end_date'] : $event['start_date'];
        $endsAt = $endDate . ' ' . $event['end_time'] . ':00';
        if (strtotime($endsAt) <= strtotime($startsAt)) $errors[] = 'The event must end after it starts.';
    }
    if ($event['venue'] === '') $errors[] = 'Add the venue, e.g. "Oyugis Social Hall".';
    $dup = db()->prepare('SELECT COUNT(*) FROM events WHERE slug = ? AND id <> ?');
    $dup->execute([$event['slug'], $id]);
    if ((int) $dup->fetchColumn() > 0) $event['slug'] .= '-' . date('Ymd', strtotime($startsAt));

    if (!$errors && !empty($_FILES['image']['name'])) {
        try {
            $img = process_image_upload($_FILES['image'], 'events', 1400, 700);
            delete_upload($event['image']);
            delete_upload($img['thumb']);
            $event['image'] = $img['image'];
        } catch (RuntimeException $e) {
            $errors[] = 'Image: ' . $e->getMessage();
        }
    }
    if (!$errors && !empty($_POST['remove_image'])) {
        delete_upload($event['image']);
        $event['image'] = null;
    }

    if (!$errors) {
        $fields = [$event['slug'], $event['title'], $event['summary'], $event['description'], $startsAt, $endsAt, $event['venue'], $event['ward'], $event['category'], $event['image'], $event['image_alt'] ?: $event['title'], $event['status']];
        if ($id) {
            db()->prepare('UPDATE events SET slug = ?, title = ?, summary = ?, description = ?, starts_at = ?, ends_at = ?, venue = ?, ward = ?, category = ?, image = ?, image_alt = ?, status = ? WHERE id = ?')->execute([...$fields, $id]);
            log_activity('event.update', $event['title']);
        } else {
            db()->prepare('INSERT INTO events (slug, title, summary, description, starts_at, ends_at, venue, ward, category, image, image_alt, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$fields, $user['id']]);
            $id = (int) db()->lastInsertId();
            log_activity('event.create', $event['title']);
        }
        if (($old['slug'] ?? '') && $old['slug'] !== $event['slug']) {
            @unlink(site_path('calendar/' . $old['slug'] . '.ics'));
        }
        publish_with_feedback($event['status'] === 'published' ? 'Event saved and published.' : 'Draft saved. It is not on the website yet.');
        redirect('events.php');
    }
}

$upcoming = db()->query('SELECT * FROM events WHERE COALESCE(ends_at, starts_at) >= NOW() ORDER BY starts_at')->fetchAll();
$past = db()->query('SELECT * FROM events WHERE COALESCE(ends_at, starts_at) < NOW() ORDER BY starts_at DESC LIMIT 30')->fetchAll();
$showForm = $id || isset($_GET['new']) || $errors;

render_header('Events');

function event_row(array $e): void
{
    $d = strtotime($e['starts_at']);
    ?>
    <article class="event-row panel">
        <div class="event-date"><strong><?= date('j', $d) ?></strong><span><?= date('M', $d) ?></span><small><?= date('Y', $d) ?></small></div>
        <div class="event-info">
            <div class="story-meta-row"><?= status_pill($e['status']) ?><span class="tag"><?= h($e['category']) ?></span></div>
            <h3><a href="?edit=<?= (int) $e['id'] ?>"><?= h($e['title']) ?></a></h3>
            <p class="muted small"><i class="far fa-clock"></i> <?= h(date('D j M, g:i a', $d)) ?><?= $e['ends_at'] ? ' – ' . h(date(date('Y-m-d', strtotime($e['ends_at'])) === date('Y-m-d', $d) ? 'g:i a' : 'D j M, g:i a', strtotime($e['ends_at']))) : '' ?>
                · <i class="fas fa-location-dot"></i> <?= h($e['venue']) ?><?= $e['ward'] ? ', ' . h($e['ward']) : '' ?></p>
        </div>
        <div class="row-actions">
            <a href="?edit=<?= (int) $e['id'] ?>" class="btn btn-light btn-sm"><i class="fas fa-pen"></i> Edit</a>
            <form method="post" data-confirm="Delete “<?= h($e['title']) ?>”?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="action" value="delete">
                <button class="btn btn-danger-ghost btn-sm"><i class="fas fa-trash"></i></button></form>
        </div>
    </article>
    <?php
}
?>
<?php if ($showForm): ?>
    <a href="events.php" class="back-link"><i class="fas fa-arrow-left"></i> All events</a>
    <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= h($e) ?></div><?php endforeach; ?>
    <form method="post" enctype="multipart/form-data" class="editor-layout">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $id ?>">
        <div class="editor-main">
            <section class="panel">
                <label class="field">Event title<input type="text" name="title" value="<?= h($event['title']) ?>" maxlength="200" required placeholder="e.g. Youth health outreach in Kanyamwa"></label>
                <div class="form-grid">
                    <label class="field">Starts<input type="date" name="start_date" value="<?= h($event['start_date']) ?>" required></label>
                    <label class="field">Start time<input type="time" name="start_time" value="<?= h($event['start_time']) ?>" required></label>
                    <label class="field">Ends <small>leave blank if same day</small><input type="date" name="end_date" value="<?= h($event['end_date']) ?>"></label>
                    <label class="field">End time<input type="time" name="end_time" value="<?= h($event['end_time']) ?>"></label>
                    <label class="field">Venue<input type="text" name="venue" value="<?= h($event['venue']) ?>" maxlength="200" required placeholder="e.g. Oyugis Social Hall"></label>
                    <label class="field">Ward / area<input type="text" name="ward" value="<?= h($event['ward']) ?>" maxlength="100" placeholder="e.g. Kasipul"></label>
                </div>
                <label class="field">Short summary <small>Shown on event cards</small><textarea name="summary" rows="2" maxlength="300"><?= h($event['summary']) ?></textarea></label>
                <label class="field">Details <small>What happens, who it's for, what to bring. Blank lines start new paragraphs.</small><textarea name="description" rows="7" maxlength="4000"><?= h($event['description']) ?></textarea></label>
            </section>
        </div>
        <aside class="editor-side">
            <section class="panel">
                <h3 class="panel-title">Publish</h3>
                <label class="field">Status<select name="status"><option value="published" <?= $event['status'] === 'published' ? 'selected' : '' ?>>Published</option><option value="draft" <?= $event['status'] === 'draft' ? 'selected' : '' ?>>Draft (hidden)</option></select></label>
                <label class="field">Type<select name="category"><?php foreach (EVENT_CATEGORIES as $c): ?><option <?= $event['category'] === $c ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select></label>
                <label class="field">Web address<input type="text" name="slug" value="<?= h($event['slug']) ?>" maxlength="120" placeholder="created from the title"></label>
                <button class="btn btn-primary btn-block"><i class="fas fa-floppy-disk"></i> Save event</button>
            </section>
            <section class="panel">
                <h3 class="panel-title">Image <small class="muted">optional</small></h3>
                <label class="dropzone dropzone-sm" id="cover-drop">
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp" id="cover-input">
                    <img id="cover-preview" src="<?= $event['image'] ? '../' . h($event['image']) : '' ?>" alt="" <?= $event['image'] ? '' : 'hidden' ?>>
                    <span class="dropzone-text" <?= $event['image'] ? 'hidden' : '' ?>><i class="fas fa-image"></i> Choose, drop or paste a photo or poster</span>
                </label>
                <?php if ($event['image']): ?><label class="check"><input type="checkbox" name="remove_image" value="1"> Remove image</label><?php endif; ?>
                <label class="field">Image description<input type="text" name="image_alt" value="<?= h($event['image_alt']) ?>" maxlength="200"></label>
            </section>
        </aside>
    </form>
<?php else: ?>
    <div class="toolbar">
        <span class="muted"><?= count($upcoming) ?> upcoming · <?= count($past) ?> past</span>
        <a href="?new=1" class="btn btn-primary push-right"><i class="fas fa-plus"></i> New event</a>
    </div>
    <h3 class="section-label">Upcoming</h3>
    <?php if (!$upcoming): ?>
        <div class="panel empty-state"><i class="fas fa-calendar-plus"></i><h3>No upcoming events</h3><p>Add outreaches, trainings and community meetings. They appear on the Events page and the homepage.</p><a href="?new=1" class="btn btn-primary">Add an event</a></div>
    <?php else: foreach ($upcoming as $e) event_row($e); endif; ?>
    <?php if ($past): ?>
        <h3 class="section-label">Past</h3>
        <?php foreach ($past as $e) event_row($e); ?>
    <?php endif; ?>
<?php endif; ?>
<?php render_footer($showForm ? ['assets/cover-preview.js'] : []); ?>
