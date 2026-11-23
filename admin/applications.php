<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/publisher.php';
require_once __DIR__ . '/includes/applications.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM job_applications WHERE id = ?');
    $stmt->execute([$id]);
    $app = $stmt->fetch();
    if (!$app) redirect('applications');
    $action = $_POST['action'] ?? '';
    if ($action === 'status' && in_array($_POST['status'] ?? '', APP_STATUSES, true)) {
        db()->prepare('UPDATE job_applications SET status = ? WHERE id = ?')->execute([$_POST['status'], $id]);
        log_activity('application.status', "{$app['ref_code']} {$app['full_name']} marked {$_POST['status']}");
        flash('success', $app['full_name'] . ' marked as ' . $_POST['status'] . '.');
    } elseif ($action === 'rating') {
        db()->prepare('UPDATE job_applications SET rating = ? WHERE id = ?')->execute([max(0, min(5, (int) ($_POST['rating'] ?? 0))), $id]);
        flash('success', 'Rating saved.');
    } elseif ($action === 'note') {
        db()->prepare('UPDATE job_applications SET internal_note = ? WHERE id = ?')->execute([post_str('note', 4000), $id]);
        log_activity('application.note', "{$app['ref_code']} {$app['full_name']}");
        flash('success', 'Note saved.');
    } elseif ($action === 'delete') {
        delete_application_files($app);
        db()->prepare('DELETE FROM job_applications WHERE id = ?')->execute([$id]);
        log_activity('application.delete', "{$app['ref_code']} {$app['full_name']} ({$app['vacancy_title']})");
        flash('success', 'Application and its documents deleted.');
        redirect('applications' . (!empty($_POST['back']) ? '?' . (string) $_POST['back'] : ''));
    }
    redirect('applications?id=' . $id . (!empty($_POST['back']) ? '&' . (string) $_POST['back'] : ''));
}

$vacancy = (int) ($_GET['vacancy'] ?? 0);
$status = in_array($_GET['status'] ?? '', APP_STATUSES, true) ? $_GET['status'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($vacancy) { $where[] = 'vacancy_id = ?'; $params[] = $vacancy; }
if ($status) { $where[] = 'status = ?'; $params[] = $status; }
if ($q !== '') { $where[] = '(full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR ref_code LIKE ? OR details LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$query = array_filter(['vacancy' => $vacancy ?: null, 'status' => $status, 'q' => $q]);

if (isset($_GET['export'])) {
    $stmt = db()->prepare("SELECT * FROM job_applications$whereSql ORDER BY created_at DESC");
    $stmt->execute($params);
    $header = ['Reference', 'Received', 'Position', 'Status', 'Rating', 'Name', 'Email', 'Phone'];
    foreach (APP_SECTIONS as $section => $fields) {
        foreach ($fields as $label) $header[] = str_starts_with($section, 'Referee') ? "$section: $label" : $label;
    }
    $header[] = 'Documents';
    $header[] = 'Internal note';
    $rows = [];
    foreach ($stmt as $a) {
        $d = json_decode($a['details'] ?: '{}', true) ?: [];
        $row = [$a['ref_code'], $a['created_at'], $a['vacancy_title'], $a['status'], $a['rating'] ?: '', $a['full_name'], $a['email'], $a['phone']];
        foreach (APP_SECTIONS as $fields) {
            foreach (array_keys($fields) as $k) $row[] = $d[$k] ?? '';
        }
        $row[] = implode(', ', array_map(fn($f) => APP_FILES[$f][0], array_keys(json_decode($a['files'] ?: '{}', true) ?: [])));
        $row[] = (string) $a['internal_note'];
        $rows[] = $row;
    }
    log_activity('application.export', 'Exported ' . count($rows) . ' applications');
    send_csv('judescbo-applications-' . date('Y-m-d') . '.csv', $header, $rows);
}

// One application in full
$current = null;
if (!empty($_GET['id'])) {
    $one = db()->prepare('SELECT * FROM job_applications WHERE id = ?');
    $one->execute([(int) $_GET['id']]);
    $current = $one->fetch() ?: null;
    if ($current && $current['status'] === 'new') {
        db()->prepare("UPDATE job_applications SET status = 'reviewed' WHERE id = ?")->execute([$current['id']]);
        $current['status'] = 'reviewed';
    }
}

$perPage = 30;
$page = max(1, (int) ($_GET['page'] ?? 1));
$count = db()->prepare("SELECT COUNT(*) FROM job_applications$whereSql");
$count->execute($params);
$total = (int) $count->fetchColumn();
$stmt = db()->prepare("SELECT id, ref_code, vacancy_title, full_name, email, phone, county, highest_level, years_experience, status, rating, files, created_at FROM job_applications$whereSql ORDER BY created_at DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$list = $stmt->fetchAll();

$countSql = 'SELECT status, COUNT(*) c FROM job_applications' . ($vacancy ? ' WHERE vacancy_id = ' . $vacancy : '') . ' GROUP BY status';
$counts = db()->query($countSql)->fetchAll(PDO::FETCH_KEY_PAIR);
$vacancies = db()->query('SELECT v.id, v.title, v.status, COUNT(a.id) n FROM vacancies v LEFT JOIN job_applications a ON a.vacancy_id = v.id GROUP BY v.id ORDER BY v.created_at DESC')->fetchAll();

render_header('Applications');

function app_stars(int $rating): string
{
    return $rating ? '<span class="stars" title="' . $rating . ' of 5">' . str_repeat('★', $rating) . '<span class="stars-off">' . str_repeat('★', 5 - $rating) . '</span></span>' : '';
}

if ($current):
    $d = json_decode($current['details'] ?: '{}', true) ?: [];
    $files = json_decode($current['files'] ?: '{}', true) ?: [];
    $back = http_build_query(array_merge($query, $page > 1 ? ['page' => $page] : []));
    $wa = preg_replace('/\D+/', '', $current['phone']);
    if (str_starts_with($wa, '0')) $wa = '254' . substr($wa, 1);
?>
    <a href="applications<?= $back ? '?' . h($back) : '' ?>" class="back-link"><i class="fas fa-arrow-left"></i> All applications</a>
    <div class="editor-layout">
        <div class="editor-main">
            <section class="panel">
                <header class="message-head">
                    <span class="avatar"><?= h(mb_strtoupper(mb_substr($current['full_name'], 0, 1))) ?></span>
                    <div>
                        <h2><?= h($current['full_name']) ?></h2>
                        <p><a href="mailto:<?= h($current['email']) ?>"><?= h($current['email']) ?></a> · <a href="tel:<?= h($current['phone']) ?>"><?= h($current['phone']) ?></a></p>
                    </div>
                    <?= status_pill($current['status']) ?>
                </header>
                <div class="message-meta">
                    <span><i class="fas fa-briefcase"></i> <?= h($current['vacancy_title']) ?></span>
                    <span><i class="fas fa-hashtag"></i> <?= h($current['ref_code']) ?></span>
                    <span><i class="far fa-clock"></i> <?= h(date('D j M Y, g:i a', strtotime($current['created_at']))) ?></span>
                </div>
                <div class="app-actions">
                    <a href="mailto:<?= h($current['email']) ?>?subject=<?= rawurlencode('Your application for ' . $current['vacancy_title'] . ' (' . $current['ref_code'] . ')') ?>" class="btn btn-light btn-sm"><i class="fas fa-envelope"></i> Email</a>
                    <a href="tel:<?= h($current['phone']) ?>" class="btn btn-light btn-sm"><i class="fas fa-phone"></i> Call</a>
                    <?php if (strlen($wa) >= 11): ?><a href="https://wa.me/<?= h($wa) ?>" class="btn btn-light btn-sm" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> WhatsApp</a><?php endif; ?>
                </div>
            </section>

            <section class="panel">
                <h3 class="panel-title"><i class="fas fa-paperclip"></i> Documents</h3>
                <?php if (!$files): ?><p class="muted">No documents were attached.</p><?php endif; ?>
                <div class="app-files">
                    <?php foreach ($files as $slot => $f): ?>
                        <a class="app-file" href="application-file?id=<?= (int) $current['id'] ?>&amp;f=<?= h($slot) ?>" target="_blank" rel="noopener">
                            <i class="fas <?= str_ends_with($f['name'], '.pdf') ? 'fa-file-pdf' : (preg_match('/\.(jpe?g|png)$/i', $f['name']) ? 'fa-file-image' : 'fa-file-word') ?>"></i>
                            <span><strong><?= h(APP_FILES[$slot][0] ?? $slot) ?></strong><small><?= h($f['name']) ?> · <?= h(format_bytes((int) $f['size'])) ?></small></span>
                            <i class="fas fa-download"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php foreach (APP_SECTIONS as $section => $fields):
                $rows = array_filter(array_map(fn($k) => [$fields[$k], (string) ($d[$k] ?? '')], array_keys($fields)), fn($r) => $r[1] !== '');
                if (!$rows) continue; ?>
                <section class="panel">
                    <h3 class="panel-title"><?= h($section) ?></h3>
                    <dl class="app-dl">
                        <?php foreach ($rows as [$label, $value]):
                            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) $value = date('j M Y', strtotime($value)); ?>
                            <div class="<?= mb_strlen($value) > 80 || str_contains($value, "\n") ? 'wide' : '' ?>"><dt><?= h($label) ?></dt><dd><?= nl2br(h($value)) ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                </section>
            <?php endforeach; ?>
        </div>
        <aside class="editor-side">
            <section class="panel">
                <h3 class="panel-title">Stage</h3>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $current['id'] ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="back" value="<?= h($back) ?>">
                    <label class="field">Status<select name="status" onchange="this.form.submit()">
                        <?php foreach (APP_STATUSES as $s): ?><option value="<?= $s ?>" <?= $current['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
                    </select></label>
                    <noscript><button class="btn btn-primary btn-sm">Save</button></noscript>
                </form>
                <form method="post" class="rating-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $current['id'] ?>"><input type="hidden" name="action" value="rating"><input type="hidden" name="back" value="<?= h($back) ?>">
                    <span class="field-label">Rating</span>
                    <div class="rating-buttons">
                        <?php for ($i = 1; $i <= 5; $i++): ?><button name="rating" value="<?= $i === (int) $current['rating'] ? 0 : $i ?>" class="star <?= $i <= (int) $current['rating'] ? 'on' : '' ?>" aria-label="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>" title="<?= $i === (int) $current['rating'] ? 'Clear rating' : "$i of 5" ?>">★</button><?php endfor; ?>
                    </div>
                </form>
            </section>
            <section class="panel">
                <h3 class="panel-title">Internal note</h3>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $current['id'] ?>"><input type="hidden" name="action" value="note"><input type="hidden" name="back" value="<?= h($back) ?>">
                    <label class="field"><span class="sr-only">Note</span><textarea name="note" rows="5" maxlength="4000" placeholder="Interview notes, scores, follow-ups. Only staff see this."><?= h((string) $current['internal_note']) ?></textarea></label>
                    <button class="btn btn-primary btn-sm btn-block"><i class="fas fa-floppy-disk"></i> Save note</button>
                </form>
            </section>
            <section class="panel">
                <form method="post" data-confirm="Delete this application and its documents permanently? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $current['id'] ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="back" value="<?= h($back) ?>">
                    <button class="btn btn-danger-ghost btn-sm btn-block"><i class="fas fa-trash"></i> Delete application</button>
                </form>
            </section>
        </aside>
    </div>
<?php else: ?>
    <div class="toolbar">
        <div class="tabs tabs-scroll">
            <?php $base = array_diff_key($query, ['status' => 1]); ?>
            <a href="?<?= h(http_build_query($base)) ?>" class="<?= $status === '' ? 'active' : '' ?>">All <em><?= array_sum($counts) ?></em></a>
            <?php foreach (APP_STATUSES as $s): ?>
                <a href="?<?= h(http_build_query(array_merge($base, ['status' => $s]))) ?>" class="<?= $status === $s ? 'active' : '' ?>"><?= ucfirst($s) ?> <em><?= (int) ($counts[$s] ?? 0) ?></em></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="toolbar">
        <form class="search" method="get">
            <?php if ($status): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
            <select name="vacancy" onchange="this.form.submit()" aria-label="Filter by position">
                <option value="">All positions</option>
                <?php foreach ($vacancies as $v): ?><option value="<?= (int) $v['id'] ?>" <?= $vacancy === (int) $v['id'] ? 'selected' : '' ?>><?= h($v['title']) ?> (<?= (int) $v['n'] ?>)</option><?php endforeach; ?>
            </select>
            <i class="fas fa-magnifying-glass"></i><input type="search" name="q" value="<?= h($q) ?>" placeholder="Search name, email, phone, reference or answers">
        </form>
        <a href="?<?= h(http_build_query(array_merge($query, ['export' => 1]))) ?>" class="btn btn-light"><i class="fas fa-file-csv"></i> Export</a>
    </div>

    <?php if (!$list): ?>
        <div class="panel empty-state"><i class="fas fa-user-tie"></i><h3><?= $where ? 'No applications match these filters' : 'No applications yet' ?></h3>
            <p><?= $where ? 'Try another position or status.' : 'When people apply through the Careers page, their applications and CVs appear here.' ?></p>
            <a href="vacancies" class="btn btn-primary">Manage vacancies</a></div>
    <?php else: ?>
        <section class="panel table-wrap">
            <table class="table">
                <thead><tr><th>Applicant</th><th>Position</th><th>Education</th><th>Experience</th><th>Received</th><th>Stage</th></tr></thead>
                <tbody>
                <?php foreach ($list as $a): $link = '?' . h(http_build_query(array_merge($query, ['id' => $a['id']], $page > 1 ? ['page' => $page] : []))); ?>
                    <tr class="<?= $a['status'] === 'new' ? 'row-new' : '' ?>">
                        <td><a href="<?= $link ?>" class="app-name"><strong><?= h($a['full_name']) ?></strong></a><br><small class="muted"><?= h($a['ref_code']) ?> · <?= h($a['county']) ?></small> <?= app_stars((int) $a['rating']) ?></td>
                        <td><?= h($a['vacancy_title']) ?></td>
                        <td><?= h($a['highest_level']) ?></td>
                        <td class="num"><?= h($a['years_experience']) ?></td>
                        <td class="num" title="<?= h($a['created_at']) ?>"><?= h(time_ago($a['created_at'])) ?></td>
                        <td><?= status_pill($a['status']) ?><?= str_contains($a['files'], '"cv"') ? '' : ' <i class="fas fa-triangle-exclamation" title="No CV attached"></i>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?= paginate($total, $perPage, $page, $query) ?>
        </section>
        <p class="muted small"><i class="fas fa-lock"></i> CVs and certificates are stored outside the public website and can only be opened by signed-in staff.</p>
    <?php endif; ?>
<?php endif; ?>
<?php render_footer(); ?>
