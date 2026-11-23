<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/publisher.php';
$user = require_login();

$id = (int) ($_GET['edit'] ?? $_POST['id'] ?? 0);
$blank = [
    'id' => 0, 'title' => '', 'slug' => '', 'job_type' => 'Full-time', 'location' => 'Oyugis, Homa Bay County',
    'summary' => '', 'description' => '', 'requirements' => '', 'how_to_apply' => '',
    'apply_email' => '', 'apply_url' => '', 'deadline' => date('Y-m-d', strtotime('+21 days')), 'status' => 'published',
];
$job = $blank;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM vacancies WHERE id = ?');
    $stmt->execute([$id]);
    if ($row = $stmt->fetch()) {
        $job = array_merge($row, ['deadline' => (string) $row['deadline']]);
    } else {
        $id = 0;
    }
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete' && $id) {
        db()->prepare('DELETE FROM vacancies WHERE id = ?')->execute([$id]);
        log_activity('vacancy.delete', $job['title']);
        publish_with_feedback('Vacancy deleted and removed from the Careers page.');
        redirect('vacancies');
    }
    if ($action === 'close' && $id) {
        db()->prepare("UPDATE vacancies SET status = 'closed' WHERE id = ?")->execute([$id]);
        log_activity('vacancy.close', $job['title']);
        publish_with_feedback('Vacancy closed. It now shows under "Recently closed".');
        redirect('vacancies');
    }

    foreach (['title' => 200, 'location' => 200, 'summary' => 300, 'description' => 6000, 'requirements' => 4000, 'how_to_apply' => 2000, 'apply_email' => 190, 'apply_url' => 255, 'deadline' => 10] as $k => $max) {
        $job[$k] = post_str($k, $max);
    }
    $job['slug'] = slugify(post_str('slug', 120) ?: $job['title']);
    $job['job_type'] = in_array($_POST['job_type'] ?? '', VACANCY_TYPES, true) ? $_POST['job_type'] : 'Full-time';
    $job['status'] = in_array($_POST['status'] ?? '', ['draft', 'published', 'closed'], true) ? $_POST['status'] : 'published';

    if ($job['title'] === '') $errors[] = 'Give the position a title, e.g. "Community Health Volunteer".';
    if ($job['summary'] === '' && $job['description'] === '') $errors[] = 'Add a short summary or the role details.';
    if ($job['deadline'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $job['deadline'])) $errors[] = 'The closing date is not a valid date.';
    if ($job['apply_email'] !== '' && !filter_var($job['apply_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'The application email address is not valid.';
    if ($job['apply_url'] !== '' && !preg_match('#^https?://#i', $job['apply_url'])) $errors[] = 'The application link must start with https://';
    if ($job['apply_url'] !== '' && !filter_var($job['apply_url'], FILTER_VALIDATE_URL)) $errors[] = 'The application link is not a valid web address.';
    if ($job['status'] === 'published' && $job['deadline'] !== '' && $job['deadline'] < date('Y-m-d') && !$errors) {
        $errors[] = 'The closing date has passed. Choose a later date, or set the status to Closed.';
    }
    $dup = db()->prepare('SELECT COUNT(*) FROM vacancies WHERE slug = ? AND id <> ?');
    $dup->execute([$job['slug'], $id]);
    if ((int) $dup->fetchColumn() > 0) $job['slug'] .= '-' . date('Ymd');

    if (!$errors) {
        $fields = [$job['slug'], $job['title'], $job['job_type'], $job['location'], $job['summary'], $job['description'], $job['requirements'], $job['how_to_apply'], $job['apply_email'], $job['apply_url'], $job['deadline'] ?: null, $job['status']];
        if ($id) {
            db()->prepare('UPDATE vacancies SET slug = ?, title = ?, job_type = ?, location = ?, summary = ?, description = ?, requirements = ?, how_to_apply = ?, apply_email = ?, apply_url = ?, deadline = ?, status = ? WHERE id = ?')->execute([...$fields, $id]);
            log_activity('vacancy.update', $job['title']);
        } else {
            db()->prepare('INSERT INTO vacancies (slug, title, job_type, location, summary, description, requirements, how_to_apply, apply_email, apply_url, deadline, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$fields, $user['id']]);
            log_activity('vacancy.create', $job['title']);
        }
        publish_with_feedback(match ($job['status']) {
            'published' => 'Vacancy saved and posted on the Careers page.',
            'closed' => 'Vacancy saved as closed.',
            default => 'Draft saved. It is not on the website yet.',
        });
        redirect('vacancies');
    }
}

$rows = db()->query("SELECT * FROM vacancies ORDER BY FIELD(status, 'published', 'draft', 'closed'), COALESCE(deadline, '9999-12-31'), id DESC")->fetchAll();
$open = array_values(array_filter($rows, 'vacancy_is_open'));
$other = array_values(array_filter($rows, fn($v) => !vacancy_is_open($v)));
$showForm = $id || isset($_GET['new']) || $errors;
$orgEmail = setting('org_email', 'info@judescbo.co.ke');

render_header('Vacancies');

function vacancy_row(array $v): void
{
    $closes = vacancy_closes($v);
    $expired = $v['status'] === 'published' && !vacancy_is_open($v);
    ?>
    <article class="event-row panel">
        <div class="event-date"><?php if ($closes): ?><strong><?= date('j', $closes) ?></strong><span><?= date('M', $closes) ?></span><small><?= date('Y', $closes) ?></small><?php else: ?><strong><i class="fas fa-briefcase"></i></strong><small>No deadline</small><?php endif; ?></div>
        <div class="event-info">
            <div class="story-meta-row"><?= $expired ? status_pill('closed') : status_pill($v['status']) ?><span class="tag"><?= h($v['job_type']) ?></span></div>
            <h3><a href="?edit=<?= (int) $v['id'] ?>"><?= h($v['title']) ?></a></h3>
            <p class="muted small"><i class="far fa-clock"></i> <?= $closes ? ($expired || $v['status'] === 'closed' ? 'Closed ' : 'Closes ') . h(date('D j M Y', $closes)) : 'Open until filled' ?>
                <?php if ($v['location']): ?>· <i class="fas fa-location-dot"></i> <?= h($v['location']) ?><?php endif; ?></p>
        </div>
        <div class="row-actions">
            <?php if (vacancy_is_open($v)): ?><a href="../careers#job-<?= h($v['slug']) ?>" class="btn btn-light btn-sm" target="_blank" rel="noopener" title="View on website"><i class="fas fa-arrow-up-right-from-square"></i></a><?php endif; ?>
            <a href="?edit=<?= (int) $v['id'] ?>" class="btn btn-light btn-sm"><i class="fas fa-pen"></i> Edit</a>
            <?php if ($v['status'] === 'published'): ?>
            <form method="post" data-confirm="Close “<?= h($v['title']) ?>” to new applications?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><input type="hidden" name="action" value="close">
                <button class="btn btn-light btn-sm" title="Close to applications"><i class="fas fa-lock"></i> Close</button></form>
            <?php endif; ?>
            <form method="post" data-confirm="Delete “<?= h($v['title']) ?>”?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><input type="hidden" name="action" value="delete">
                <button class="btn btn-danger-ghost btn-sm" aria-label="Delete"><i class="fas fa-trash"></i></button></form>
        </div>
    </article>
    <?php
}
?>
<?php if ($showForm): ?>
    <a href="vacancies" class="back-link"><i class="fas fa-arrow-left"></i> All vacancies</a>
    <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= h($e) ?></div><?php endforeach; ?>
    <form method="post" class="editor-layout">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $id ?>">
        <div class="editor-main">
            <section class="panel">
                <label class="field">Position title<input type="text" name="title" value="<?= h($job['title']) ?>" maxlength="200" required placeholder="e.g. Community Health Volunteer"></label>
                <div class="form-grid">
                    <label class="field">Location<input type="text" name="location" value="<?= h($job['location']) ?>" maxlength="200" placeholder="e.g. Oyugis, Homa Bay County"></label>
                    <label class="field">Closing date <small>leave blank for "open until filled"</small><input type="date" name="deadline" value="<?= h($job['deadline']) ?>"></label>
                </div>
                <label class="field">Short summary <small>One or two sentences shown on the card</small><textarea name="summary" rows="2" maxlength="300"><?= h($job['summary']) ?></textarea></label>
                <label class="field">About the role <small>Duties and responsibilities. Blank lines start new paragraphs.</small><textarea name="description" rows="7" maxlength="6000"><?= h($job['description']) ?></textarea></label>
                <label class="field">Requirements <small>One per line, shown as a bulleted list</small><textarea name="requirements" rows="6" maxlength="4000" placeholder="Diploma in Community Health or related field&#10;Resident of Kasipul or Kabondo Kasipul&#10;Fluent in Dholuo and English"><?= h($job['requirements']) ?></textarea></label>
                <label class="field">How to apply <small>What to send and any instructions</small><textarea name="how_to_apply" rows="3" maxlength="2000" placeholder="Send your CV and a one-page cover letter. Only shortlisted candidates will be contacted."><?= h($job['how_to_apply']) ?></textarea></label>
            </section>
        </div>
        <aside class="editor-side">
            <section class="panel">
                <h3 class="panel-title">Publish</h3>
                <label class="field">Status<select name="status">
                    <option value="published" <?= $job['status'] === 'published' ? 'selected' : '' ?>>Open (on the website)</option>
                    <option value="draft" <?= $job['status'] === 'draft' ? 'selected' : '' ?>>Draft (hidden)</option>
                    <option value="closed" <?= $job['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                </select></label>
                <label class="field">Type<select name="job_type"><?php foreach (VACANCY_TYPES as $t): ?><option <?= $job['job_type'] === $t ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select></label>
                <label class="field">Web address<input type="text" name="slug" value="<?= h($job['slug']) ?>" maxlength="120" placeholder="created from the title"></label>
                <button class="btn btn-primary btn-block"><i class="fas fa-floppy-disk"></i> Save vacancy</button>
            </section>
            <section class="panel">
                <h3 class="panel-title">Applications go to</h3>
                <label class="field">Email<input type="email" name="apply_email" value="<?= h($job['apply_email']) ?>" maxlength="190" placeholder="<?= h($orgEmail) ?>"></label>
                <label class="field">or an online form link <small>optional, used instead of email</small><input type="url" name="apply_url" value="<?= h($job['apply_url']) ?>" maxlength="255" placeholder="https://forms.gle/…"></label>
                <p class="muted small">The "Apply now" button opens an email to this address with the position in the subject line.</p>
            </section>
        </aside>
    </form>
<?php else: ?>
    <div class="toolbar">
        <span class="muted"><?= count($open) ?> open · <?= count($other) ?> draft or closed</span>
        <a href="../careers" class="btn btn-light" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i> Careers page</a>
        <a href="?new=1" class="btn btn-primary push-right"><i class="fas fa-plus"></i> Post a vacancy</a>
    </div>
    <h3 class="section-label">Open</h3>
    <?php if (!$open): ?>
        <div class="panel empty-state"><i class="fas fa-briefcase"></i><h3>No open vacancies</h3><p>Post jobs, internships and volunteer roles. They appear on the Careers page and close automatically after the closing date.</p><a href="?new=1" class="btn btn-primary">Post a vacancy</a></div>
    <?php else: foreach ($open as $v) vacancy_row($v); endif; ?>
    <?php if ($other): ?>
        <h3 class="section-label">Drafts and closed</h3>
        <?php foreach ($other as $v) vacancy_row($v); ?>
    <?php endif; ?>
<?php endif; ?>
<?php render_footer(); ?>
