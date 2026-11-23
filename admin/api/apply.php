<?php
require __DIR__ . '/common.php';
require_once dirname(__DIR__) . '/includes/publisher.php';
require_once dirname(__DIR__) . '/includes/applications.php';

// A real application with three attachments can exceed post_max_size; PHP then empties $_POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    api_reply(false, 'Your files are too large to send together. Please make them smaller (under 3 MB each) and try again.', 413);
}

api_guard('apply', 6, 60);

$one = fn(string $k, int $max) => clean_line((string) ($_POST[$k] ?? ''), $max);
$text = fn(string $k, int $max) => mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST[$k] ?? ''))), 0, $max);
$pick = fn(string $k, array $allowed) => in_array($_POST[$k] ?? '', $allowed, true) ? $_POST[$k] : '';

// The position must still be open and take applications on this website
$slug = $one('job', 120);
$stmt = db()->prepare('SELECT * FROM vacancies WHERE slug = ?');
$stmt->execute([$slug]);
$job = $stmt->fetch();
if (!$job || !vacancy_is_open($job) || $job['apply_url'] !== '') {
    api_reply(false, 'Sorry, this position is no longer taking applications. Please check the Careers page for open roles.', 410);
}

$d = [
    'gender' => $pick('gender', APP_GENDERS),
    'dob' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['dob'] ?? '')) ? $_POST['dob'] : '',
    'alt_phone' => $one('alt_phone', 40),
    'county' => $one('county', 80),
    'sub_county' => $one('sub_county', 120),
    'town' => $one('town', 120),
    'disability' => $pick('disability', APP_DISABILITY),
    'support_needs' => $text('support_needs', 1000),
    'highest_level' => $pick('highest_level', APP_LEVELS),
    'qualification' => $one('qualification', 200),
    'institution' => $one('institution', 200),
    'year_completed' => preg_match('/^(19|20)\d{2}$/', (string) ($_POST['year_completed'] ?? '')) ? $_POST['year_completed'] : '',
    'other_training' => $text('other_training', 2000),
    'current_role' => $one('current_role', 200),
    'years_experience' => $pick('years_experience', APP_EXPERIENCE),
    'experience' => $text('experience', 5000),
    'skills' => $text('skills', 1500),
    'languages' => implode(', ', array_merge(
        array_values(array_intersect(APP_LANGUAGES, (array) ($_POST['languages'] ?? []))),
        array_filter([$one('languages_other', 120)])
    )),
    'motivation' => $text('motivation', 4000),
    'available_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['available_from'] ?? '')) ? $_POST['available_from'] : '',
    'notice_period' => $one('notice_period', 80),
    'expected_pay' => $one('expected_pay', 60),
    'can_travel' => $pick('can_travel', ['Yes', 'No']),
    'heard_from' => $pick('heard_from', APP_HEARD),
    'ref1_name' => $one('ref1_name', 120), 'ref1_org' => $one('ref1_org', 200), 'ref1_phone' => $one('ref1_phone', 40), 'ref1_email' => $one('ref1_email', 190),
    'ref2_name' => $one('ref2_name', 120), 'ref2_org' => $one('ref2_org', 200), 'ref2_phone' => $one('ref2_phone', 40), 'ref2_email' => $one('ref2_email', 190),
];
$name = $one('full_name', 120);
$email = $one('email', 190);
$phone = $one('phone', 40);

$missing = [];
if ($name === '') $missing[] = 'full name';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $missing[] = 'a valid email address';
if (!preg_match('/^\+?[\d\s()-]{9,20}$/', $phone)) $missing[] = 'a valid phone number';
if ($d['county'] === '') $missing[] = 'county';
if ($d['highest_level'] === '') $missing[] = 'highest level of education';
if ($d['qualification'] === '') $missing[] = 'course or qualification';
if ($d['institution'] === '') $missing[] = 'institution';
if ($d['years_experience'] === '') $missing[] = 'years of experience';
if (mb_strlen($d['experience']) < 30) $missing[] = 'your relevant experience (a few sentences)';
if (mb_strlen($d['motivation']) < 30) $missing[] = 'why you want this role (a few sentences)';
if ($d['ref1_name'] === '' || $d['ref1_phone'] === '') $missing[] = "your first referee's name and phone";
if (empty($_POST['declaration']) || empty($_POST['consent'])) $missing[] = 'the declaration and consent boxes';
if ($missing) {
    api_reply(false, 'Please complete: ' . implode(', ', $missing) . '.', 422);
}
foreach (['ref1_email', 'ref2_email'] as $k) {
    if ($d[$k] !== '' && !filter_var($d[$k], FILTER_VALIDATE_EMAIL)) $d[$k] = '';
}

$dup = db()->prepare('SELECT COUNT(*) FROM job_applications WHERE vacancy_id = ? AND email = ?');
$dup->execute([$job['id'], $email]);
if ((int) $dup->fetchColumn() > 0) {
    api_reply(false, "You have already applied for this position with $email. We'll be in touch if you are shortlisted.", 409);
}

$uploads = [];
try {
    foreach (array_keys(APP_FILES) as $field) {
        if ($f = check_application_file($field)) $uploads[$field] = $f;
    }
} catch (RuntimeException $e) {
    api_reply(false, $e->getMessage(), 422);
}

// Save to get the reference number, then move the documents into private storage
db()->prepare('INSERT INTO job_applications (vacancy_id, vacancy_title, full_name, email, phone, county, highest_level, years_experience, details, files, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([$job['id'], $job['title'], $name, $email, $phone, $d['county'], $d['highest_level'], $d['years_experience'], json_encode($d, JSON_UNESCAPED_UNICODE), '{}', client_ip()]);
$id = (int) db()->lastInsertId();
$ref = application_ref($id);

$files = [];
try {
    foreach ($uploads as $field => $f) $files[$field] = store_application_file($field, $f, $id);
} catch (RuntimeException $e) {
    delete_application_files(['files' => json_encode($files)]);
    db()->prepare('DELETE FROM job_applications WHERE id = ?')->execute([$id]);
    api_reply(false, $e->getMessage(), 500);
}
db()->prepare('UPDATE job_applications SET ref_code = ?, files = ? WHERE id = ?')->execute([$ref, json_encode($files, JSON_UNESCAPED_UNICODE), $id]);

// Alert the hiring team
$site = rtrim((string) config('site_url'), '/');
$notify = $job['apply_email'] ?: setting('notify_email') ?: setting('org_email', '');
if ($notify !== '' && filter_var($notify, FILTER_VALIDATE_EMAIL)) {
    $body = "A new application has arrived on the Judes CBO website.\n\n"
        . "Position: {$job['title']}\nReference: $ref\n\n"
        . "Name: $name\nEmail: $email\nPhone: $phone\nCounty: {$d['county']}\n"
        . "Education: {$d['highest_level']}, {$d['qualification']} ({$d['institution']})\n"
        . "Experience: {$d['years_experience']}\n"
        . 'Documents: ' . ($files ? implode(', ', array_map(fn($f) => APP_FILES[$f][0], array_keys($files))) : 'none') . "\n\n"
        . "Review it in the dashboard: $site/admin/applications?id=$id\n";
    send_mail($notify, "New application: {$job['title']} ($ref)", $body, $email);
}

// Confirmation to the applicant
$closes = vacancy_closes($job);
send_mail($email, "We received your application ($ref)", "Dear $name,\n\n"
    . "Thank you for applying for the position of {$job['title']} at Judes CBO. We have received your application and documents.\n\n"
    . "Your reference number is $ref. Please quote it if you contact us about this application.\n\n"
    . ($closes ? 'Applications close on ' . date('l j F Y', $closes) . '. ' : '')
    . "Our team will review all applications after the closing date. Only shortlisted candidates will be contacted.\n\n"
    . "Please note: Judes CBO never asks for money at any stage of recruitment.\n\n"
    . "Kind regards,\nJudes CBO\n$site/careers\n");

http_response_code(200);
echo json_encode(['ok' => true, 'ref' => $ref, 'message' => "Thank you, $name! Your application for {$job['title']} has been received. Your reference number is $ref. We've sent a confirmation to $email."]);
