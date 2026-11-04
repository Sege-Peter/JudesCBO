<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/images.php';
require __DIR__ . '/includes/sanitize.php';
require_once __DIR__ . '/includes/publisher.php';
$user = require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$story = [
    'id' => 0, 'title' => '', 'slug' => '', 'category' => 'News', 'excerpt' => '', 'meta_description' => '',
    'body_html' => '', 'cover_image' => null, 'cover_alt' => '', 'status' => 'draft', 'published_at' => date('Y-m-d'),
];
if ($id) {
    $stmt = db()->prepare('SELECT * FROM stories WHERE id = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash('error', 'That story no longer exists.');
        redirect('stories');
    }
    $story = $found;
}
$errors = [];

// Images dropped into the story text are uploaded here (called from the editor)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'inline-image') {
    require_csrf();
    header('Content-Type: application/json');
    try {
        $img = process_image_upload($_FILES['image'] ?? [], 'stories', 1400, 600);
        echo json_encode(['ok' => true, 'url' => $img['image']]);
    } catch (RuntimeException $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $old = $story;
    $story['title'] = trim(preg_replace('/\s+/', ' ', post_str('title', 200)) ?? '');
    $story['slug'] = slugify(post_str('slug', 120) ?: $story['title']);
    $story['category'] = array_key_exists($_POST['category'] ?? '', CATEGORY_ICONS) ? $_POST['category'] : 'News';
    $story['excerpt'] = post_str('excerpt', 400);
    $story['meta_description'] = post_str('meta_description', 170);
    $story['body_html'] = sanitize_html((string) ($_POST['body_html'] ?? ''));
    $story['cover_alt'] = post_str('cover_alt', 200);
    $story['status'] = ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft';
    $date = post_str('published_at', 10);
    $story['published_at'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');

    if ($story['title'] === '') $errors[] = 'Give the story a title.';
    if ($story['excerpt'] === '') $errors[] = 'Add a short summary. It appears on the story cards.';
    if (trim(strip_tags($story['body_html'])) === '') $errors[] = 'Write the story text.';
    $stmt = db()->prepare('SELECT COUNT(*) FROM stories WHERE slug = ? AND id <> ?');
    $stmt->execute([$story['slug'], $id]);
    if ((int) $stmt->fetchColumn() > 0) $errors[] = 'Another story already uses this web address. Change the address (slug).';

    if (!$errors && !empty($_FILES['cover']['name'])) {
        try {
            $img = process_image_upload($_FILES['cover'], 'stories', 1600, 800);
            delete_upload($story['cover_image']);
            $story['cover_image'] = $img['image'];
            delete_upload($img['thumb']);
        } catch (RuntimeException $e) {
            $errors[] = 'Cover image: ' . $e->getMessage();
        }
    }
    if (!$errors && !empty($_POST['remove_cover'])) {
        delete_upload($story['cover_image']);
        $story['cover_image'] = null;
    }
    if ($story['cover_image'] && $story['cover_alt'] === '') {
        $story['cover_alt'] = $story['title'];
    }

    if (!$errors) {
        $fields = [$story['slug'], $story['title'], $story['category'], $story['excerpt'], $story['meta_description'], $story['body_html'], $story['cover_image'], $story['cover_alt'], $story['status'], $story['published_at']];
        if ($id) {
            db()->prepare('UPDATE stories SET slug = ?, title = ?, category = ?, excerpt = ?, meta_description = ?, body_html = ?, cover_image = ?, cover_alt = ?, status = ?, published_at = ? WHERE id = ?')
                ->execute([...$fields, $id]);
            if ($old['slug'] !== $story['slug']) {
                $retired = json_decode(setting('retired_story_files', '[]'), true) ?: [];
                $retired[] = 'blog-' . $old['slug'] . '.html';
                save_setting('retired_story_files', json_encode(array_values(array_unique($retired))));
            }
            log_activity('story.update', $story['title']);
        } else {
            db()->prepare('INSERT INTO stories (slug, title, category, excerpt, meta_description, body_html, cover_image, cover_alt, status, published_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([...$fields, $user['id']]);
            $id = (int) db()->lastInsertId();
            log_activity('story.create', $story['title']);
        }
        publish_with_feedback($story['status'] === 'published' ? 'Story saved and published to the website.' : 'Draft saved. It is not on the website yet.');
        redirect('story-edit?id=' . $id);
    }
}

$site = rtrim((string) config('site_url'), '/');
$host = preg_replace('#^https?://#', '', $site);
$isPublished = $story['status'] === 'published';
render_header($id ? 'Edit story' : 'New story', 'stories');
?>
<div class="se-top">
    <a href="stories" class="back-link"><i class="fas fa-arrow-left"></i> All stories</a>
    <span class="se-status <?= $isPublished ? 'is-live' : 'is-draft' ?>"><i class="fas <?= $isPublished ? 'fa-circle-check' : 'fa-pen-ruler' ?>"></i> <?= $isPublished ? 'Published' : 'Draft' ?></span>
    <?php if (!empty($story['updated_at'])): ?><span class="muted small">Last saved <?= h(time_ago($story['updated_at'])) ?></span><?php endif; ?>
</div>
<?php foreach ($errors as $e): ?><div class="alert alert-error"><?= h($e) ?></div><?php endforeach; ?>
<div class="alert alert-info se-restore" id="se-restore" hidden><i class="fas fa-clock-rotate-left"></i><span>You have unsaved changes from <strong id="se-restore-time"></strong>.</span>
    <button type="button" class="btn btn-light btn-sm" id="se-restore-yes">Restore them</button><button type="button" class="link" id="se-restore-no">Discard</button></div>

<form method="post" enctype="multipart/form-data" class="editor-layout se-layout" id="story-form" data-key="story-<?= (int) $id ?>" data-saved="<?= h((string) ($story['updated_at'] ?? '')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <input type="hidden" name="status" value="<?= h($story['status']) ?>">
    <div class="editor-main">
        <section class="panel se-write">
            <label class="se-title-wrap">
                <span class="sr-only">Title</span>
                <textarea name="title" id="title" rows="1" maxlength="200" required placeholder="Story title"><?= h($story['title']) ?></textarea>
            </label>
            <div class="se-meta-row">
                <span class="se-counter" id="title-count" data-max="60"></span>
                <label class="se-slug"><i class="fas fa-link"></i><span><?= h($host) ?>/blog-</span><input type="text" name="slug" id="slug" value="<?= h($story['slug']) ?>" maxlength="120" placeholder="created-from-the-title" aria-label="Web address"></label>
            </div>
            <label class="field se-excerpt">Summary <small>Shown on story cards and used when the story is shared (1–2 sentences)</small>
                <textarea name="excerpt" id="excerpt" rows="2" maxlength="400" required placeholder="What's this story about, in a sentence or two?"><?= h($story['excerpt']) ?></textarea>
            </label>
            <div class="field se-body">
                <span class="field-label">Story <small class="se-stats" id="se-stats"></small></span>
                <?php /* Stored paths are relative to the site root; point them up a level for the preview here */ ?>
                <div id="editor" class="rich-editor"><?= preg_replace('#src="(uploads|images)/#', 'src="../$1/', $story['body_html']) ?></div>
                <input type="hidden" name="body_html" id="body_html">
                <small class="hint"><i class="fas fa-lightbulb"></i> Use headings to break up long stories. Add photos with the image button, or drag or paste them in. They're resized automatically. Press <kbd>Ctrl</kbd>+<kbd>S</kbd> to save.</small>
            </div>
        </section>

        <section class="panel">
            <h3 class="panel-title"><i class="fab fa-google"></i> Search &amp; sharing</h3>
            <label class="field">Search description <small><span id="meta-count">0</span>/160 characters. Leave blank to use the summary.</small>
                <textarea name="meta_description" id="meta_description" rows="2" maxlength="170"><?= h($story['meta_description']) ?></textarea>
            </label>
            <div class="se-previews">
                <div class="se-google" aria-label="Google preview">
                    <span class="se-prev-label">Google</span>
                    <div class="se-g-site"><span class="se-g-fav"><img src="../images/icons/favicon-32.png" alt=""></span><span><strong>Judes CBO</strong><small><?= h($host) ?> › <span id="g-slug"></span></small></span></div>
                    <div class="se-g-title" id="g-title"></div>
                    <div class="se-g-desc"><span id="g-date"></span><span id="g-desc"></span></div>
                </div>
                <div class="se-social" aria-label="Facebook and WhatsApp preview">
                    <span class="se-prev-label">Facebook / WhatsApp</span>
                    <div class="se-s-card">
                        <div class="se-s-img" id="s-img" style="<?= $story['cover_image'] ? "background-image:url('../" . h($story['cover_image']) . "')" : '' ?>"><?php if (!$story['cover_image']): ?><span><i class="fas fa-image"></i> Add a cover image</span><?php endif; ?></div>
                        <div class="se-s-body"><small><?= h(strtoupper($host)) ?></small><strong id="s-title"></strong><span id="s-desc"></span></div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <aside class="editor-side se-side">
        <section class="panel">
            <h3 class="panel-title"><i class="fas fa-paper-plane"></i> Publish</h3>
            <div class="se-actions">
                <?php if ($isPublished): ?>
                    <button type="submit" name="status" value="published" class="btn btn-primary btn-block"><i class="fas fa-rotate"></i> Update story</button>
                    <a href="../blog-<?= h($story['slug']) ?>" target="_blank" rel="noopener" class="btn btn-light btn-block"><i class="fas fa-arrow-up-right-from-square"></i> View on website</a>
                    <button type="submit" name="status" value="draft" class="link se-unpublish" data-confirm-click="Take this story off the website? It stays here as a draft."><i class="fas fa-eye-slash"></i> Unpublish (back to draft)</button>
                <?php else: ?>
                    <button type="submit" name="status" value="published" class="btn btn-primary btn-block"><i class="fas fa-paper-plane"></i> Publish</button>
                    <button type="submit" name="status" value="draft" class="btn btn-light btn-block"><i class="fas fa-floppy-disk"></i> Save draft</button>
                <?php endif; ?>
            </div>
            <label class="field">Date<input type="date" name="published_at" value="<?= h($story['published_at']) ?>"></label>
            <label class="field">Category
                <select name="category">
                    <?php foreach (array_keys(CATEGORY_ICONS) as $cat): ?>
                        <option <?= $story['category'] === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </section>
        <section class="panel">
            <h3 class="panel-title"><i class="fas fa-image"></i> Cover image</h3>
            <label class="dropzone dropzone-sm" id="cover-drop">
                <input type="file" name="cover" accept="image/jpeg,image/png,image/webp" id="cover-input">
                <img id="cover-preview" src="<?= $story['cover_image'] ? '../' . h($story['cover_image']) : '' ?>" alt="" <?= $story['cover_image'] ? '' : 'hidden' ?>>
                <span class="dropzone-text" <?= $story['cover_image'] ? 'hidden' : '' ?>><i class="fas fa-cloud-arrow-up"></i> Choose, drop or paste a photo<small>Landscape works best, at least 1200px wide</small></span>
            </label>
            <?php if ($story['cover_image']): ?>
                <label class="check"><input type="checkbox" name="remove_cover" value="1"> Remove cover image</label>
            <?php endif; ?>
            <label class="field">Image description <small>For screen readers and search engines</small>
                <input type="text" name="cover_alt" value="<?= h($story['cover_alt']) ?>" maxlength="200" placeholder="e.g. Young people at a health outreach">
            </label>
        </section>
        <section class="panel se-tips">
            <h3 class="panel-title"><i class="fas fa-list-check"></i> Before publishing</h3>
            <ul id="se-checklist">
                <li data-check="title">Title under 60 characters</li>
                <li data-check="excerpt">Summary written</li>
                <li data-check="cover">Cover image added</li>
                <li data-check="length">At least 150 words</li>
                <li data-check="headings">Uses a heading</li>
            </ul>
        </section>
    </aside>
</form>

<link rel="stylesheet" href="assets/vendor/quill/quill.snow.css">
<?php render_footer(['assets/vendor/quill/quill.js', 'assets/story-editor.js?v=' . (int) @filemtime(__DIR__ . '/assets/story-editor.js')]); ?>
