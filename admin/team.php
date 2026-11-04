<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/images.php';
require_once __DIR__ . '/includes/publisher.php';
require_login();

const TEAM_GROUPS = ['Leadership', 'Board', 'Staff', 'Volunteers'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'reorder') {
        $stmt = db()->prepare('UPDATE team_members SET sort_order = ? WHERE id = ?');
        foreach (array_map('intval', (array) ($_POST['ids'] ?? [])) as $i => $memberId) {
            $stmt->execute([$i + 1, $memberId]);
        }
        log_activity('team.reorder', 'Changed the order of the team');
        header('Content-Type: application/json');
        try { publish_site(); echo '{"ok":true}'; }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
        exit;
    }

    $member = null;
    if ($id) {
        $stmt = db()->prepare('SELECT * FROM team_members WHERE id = ?');
        $stmt->execute([$id]);
        $member = $stmt->fetch() ?: null;
    }

    if ($action === 'delete' && $member) {
        delete_upload($member['photo']);
        db()->prepare('DELETE FROM team_members WHERE id = ?')->execute([$id]);
        log_activity('team.delete', $member['name']);
        publish_with_feedback($member['name'] . ' removed from the team page.');
        redirect('team');
    }

    if ($action === 'save') {
        $name = post_str('name', 120);
        $role = post_str('role', 120);
        $group = in_array($_POST['team_group'] ?? '', TEAM_GROUPS, true) ? $_POST['team_group'] : 'Leadership';
        $bio = post_str('bio', 600);
        $linkedin = post_str('linkedin', 255);
        if ($linkedin !== '' && !preg_match('#^https://([a-z]{2,3}\.)?linkedin\.com/#i', $linkedin)) {
            $linkedin = '';
            flash('error', 'The LinkedIn link was ignored. It should start with https://www.linkedin.com/');
        }
        $visible = !empty($_POST['visible']) ? 1 : 0;
        if ($name === '') {
            flash('error', 'Enter a name.');
            redirect('team');
        }
        $photo = $member['photo'] ?? null;
        if (!empty($_FILES['photo']['name'])) {
            try {
                $img = process_image_upload($_FILES['photo'], 'team', 800, 400);
                delete_upload($photo);
                delete_upload($img['image']);
                $photo = $img['thumb']; // 400px is plenty for a profile photo
            } catch (RuntimeException $e) {
                flash('error', 'Photo: ' . $e->getMessage());
            }
        } elseif (!empty($_POST['remove_photo'])) {
            delete_upload($photo);
            $photo = null;
        }
        if ($member) {
            db()->prepare('UPDATE team_members SET name = ?, role = ?, team_group = ?, bio = ?, photo = ?, linkedin = ?, visible = ? WHERE id = ?')
                ->execute([$name, $role, $group, $bio, $photo, $linkedin, $visible, $id]);
            log_activity('team.update', $name);
        } else {
            $next = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM team_members')->fetchColumn();
            db()->prepare('INSERT INTO team_members (name, role, team_group, bio, photo, linkedin, visible, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$name, $role, $group, $bio, $photo, $linkedin, $visible, $next]);
            log_activity('team.create', $name);
        }
        publish_with_feedback($name . ' saved' . ($visible ? ' and shown on the team page.' : ' (hidden from the website).'));
        redirect('team');
    }
}

$members = db()->query('SELECT * FROM team_members ORDER BY FIELD(team_group, "Leadership", "Board", "Staff", "Volunteers"), sort_order, id')->fetchAll();
$byGroup = [];
foreach ($members as $m) $byGroup[$m['team_group']][] = $m;

render_header('Team');
?>
<div class="toolbar">
    <span class="muted"><?= count($members) ?> people · shown on the website's Team page</span>
    <a href="../team" target="_blank" rel="noopener" class="btn btn-light push-right"><i class="fas fa-eye"></i> View page</a>
    <button type="button" class="btn btn-primary" data-member-new><i class="fas fa-user-plus"></i> Add person</button>
</div>

<?php if (!$members): ?>
    <div class="panel empty-state"><i class="fas fa-people-group"></i><h3>No team members yet</h3><p>Add the people behind Judes CBO. Donors and partners like to see who they're supporting.</p><button type="button" class="btn btn-primary" data-member-new>Add the first person</button></div>
<?php endif; ?>

<?php foreach (TEAM_GROUPS as $group): if (empty($byGroup[$group])) continue; ?>
    <h3 class="section-label"><?= h($group) ?></h3>
    <div class="team-admin-grid" data-sortable-team>
        <?php foreach ($byGroup[$group] as $m): ?>
            <article class="team-admin-card <?= $m['visible'] ? '' : 'is-hidden' ?>" data-id="<?= (int) $m['id'] ?>">
                <span class="drag-handle" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></span>
                <?php if ($m['photo']): ?><img src="../<?= h($m['photo']) ?>" alt="" class="team-admin-photo">
                <?php else: ?><span class="team-admin-photo team-admin-initials"><?= h(mb_strtoupper(mb_substr($m['name'], 0, 1))) ?></span><?php endif; ?>
                <strong><?= h($m['name']) ?></strong>
                <small><?= h($m['role'] ?: '—') ?></small>
                <?php if (!$m['visible']): ?><span class="pill pill-closed">Hidden</span><?php endif; ?>
                <button type="button" class="btn btn-light btn-sm" data-member='<?= h(json_encode(['id' => (int) $m['id'], 'name' => $m['name'], 'role' => $m['role'], 'team_group' => $m['team_group'], 'bio' => $m['bio'], 'linkedin' => $m['linkedin'], 'visible' => (int) $m['visible'], 'photo' => $m['photo'] ? '../' . $m['photo'] : ''])) ?>'><i class="fas fa-pen"></i> Edit</button>
            </article>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<dialog class="modal" id="member-dialog">
    <form method="post" enctype="multipart/form-data" class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="m-id">
        <header class="modal-head"><h3 id="m-title">Add person</h3><button type="button" class="icon-btn" data-close aria-label="Close"><i class="fas fa-times"></i></button></header>
        <div class="member-photo-row">
            <label class="member-photo-pick">
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" id="m-photo-input">
                <img id="m-photo" src="" alt="" hidden><span id="m-photo-empty"><i class="fas fa-camera"></i></span>
            </label>
            <div class="small muted">Click, drop or paste (Ctrl+V) a photo. Square photos work best; faces are shown in a circle.<label class="check" id="m-remove-wrap" hidden><input type="checkbox" name="remove_photo" value="1"> Remove photo</label></div>
        </div>
        <div class="form-grid">
            <label class="field">Full name<input type="text" name="name" id="m-name" maxlength="120" required></label>
            <label class="field">Role<input type="text" name="role" id="m-role" maxlength="120" placeholder="e.g. Programs Coordinator"></label>
            <label class="field">Group<select name="team_group" id="m-group"><?php foreach (TEAM_GROUPS as $g): ?><option><?= h($g) ?></option><?php endforeach; ?></select></label>
            <label class="field">LinkedIn <small>optional</small><input type="url" name="linkedin" id="m-linkedin" maxlength="255" placeholder="https://www.linkedin.com/in/…"></label>
        </div>
        <label class="field">Short bio <small>1–3 sentences</small><textarea name="bio" id="m-bio" rows="3" maxlength="600"></textarea></label>
        <label class="check"><input type="checkbox" name="visible" value="1" id="m-visible" checked> Show on the website</label>
        <footer class="modal-foot">
            <button type="submit" form="m-delete" class="btn btn-danger-ghost push-left" id="m-delete-btn" hidden><i class="fas fa-trash"></i> Remove</button>
            <button type="button" class="btn btn-light" data-close>Cancel</button><button type="submit" class="btn btn-primary">Save</button>
        </footer>
    </form>
    <form method="post" id="m-delete" data-confirm="Remove this person from the team page?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="m-delete-id"></form>
</dialog>
<?php render_footer(['assets/vendor/Sortable.min.js', 'assets/team.js']); ?>
