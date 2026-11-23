<?php
declare(strict_types=1);

/**
 * Online job applications: shared field lists, file storage and display helpers.
 * Used by the public endpoint (api/apply.php), the dashboard (applications.php)
 * and the publisher (apply.html job list).
 *
 * Uploaded documents live outside the website folder (private_dir/applications)
 * and are only served to signed-in staff through application-file.php.
 */

require_once __DIR__ . '/mailer.php';

const APP_STATUSES = ['new', 'reviewed', 'shortlisted', 'interview', 'offered', 'hired', 'rejected'];
const APP_LEVELS = ['KCSE / Secondary', 'Certificate', 'Diploma', "Bachelor's degree", "Master's degree", 'Doctorate (PhD)', 'Other'];
const APP_EXPERIENCE = ['None', 'Less than 1 year', '1–2 years', '3–5 years', '6–10 years', 'Over 10 years'];
const APP_LANGUAGES = ['English', 'Kiswahili', 'Dholuo', 'Kenyan Sign Language'];
const APP_GENDERS = ['Female', 'Male', 'Prefer not to say'];
const APP_DISABILITY = ['No', 'Yes', 'Prefer not to say'];
const APP_HEARD = ['Judes CBO website', 'Facebook', 'WhatsApp', 'X (Twitter)', 'LinkedIn', 'A friend or colleague', 'Community notice board', 'Other'];

/** Upload slots: form field => [label, required, max bytes, allowed extensions] */
const APP_FILES = [
    'cv' => ['CV / résumé', true, 3 * 1024 * 1024, ['pdf', 'doc', 'docx']],
    'cover_letter' => ['Cover letter', false, 3 * 1024 * 1024, ['pdf', 'doc', 'docx']],
    'certificates' => ['Certificates', false, 5 * 1024 * 1024, ['pdf', 'jpg', 'jpeg', 'png']],
];

const APP_MIME = [
    'pdf' => ['application/pdf'],
    'doc' => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream', 'application/CDFV2'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
];

/** Sections and fields shown on the dashboard and in the CSV export, in order. */
const APP_SECTIONS = [
    'Personal details' => [
        'gender' => 'Gender', 'dob' => 'Date of birth', 'alt_phone' => 'Other phone',
        'county' => 'County', 'sub_county' => 'Sub-county / ward', 'town' => 'Town / village',
        'disability' => 'Has a disability', 'support_needs' => 'Support needed during recruitment',
    ],
    'Education' => [
        'highest_level' => 'Highest level', 'qualification' => 'Course / qualification', 'institution' => 'Institution',
        'year_completed' => 'Year completed', 'other_training' => 'Other training and registrations',
    ],
    'Experience' => [
        'current_role' => 'Current role and organization', 'years_experience' => 'Relevant experience',
        'experience' => 'Relevant experience and achievements', 'skills' => 'Key skills', 'languages' => 'Languages',
    ],
    'Motivation' => ['motivation' => 'Why this role'],
    'Availability' => [
        'available_from' => 'Available from', 'notice_period' => 'Notice period', 'expected_pay' => 'Expected monthly pay (KES)',
        'can_travel' => 'Can travel within Homa Bay County', 'heard_from' => 'Heard about the role from',
    ],
    'Referee 1' => ['ref1_name' => 'Name', 'ref1_org' => 'Organization and relationship', 'ref1_phone' => 'Phone', 'ref1_email' => 'Email'],
    'Referee 2' => ['ref2_name' => 'Name', 'ref2_org' => 'Organization and relationship', 'ref2_phone' => 'Phone', 'ref2_email' => 'Email'],
];

function applications_dir(): string
{
    $dir = mail_private_dir() . '/applications';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

/** Vacancies that accept applications through the website right now. */
function vacancies_accepting(): array
{
    $rows = db()->query("SELECT * FROM vacancies WHERE status = 'published' AND apply_url = '' ORDER BY COALESCE(deadline, '9999-12-31'), title")->fetchAll();
    return array_values(array_filter($rows, 'vacancy_is_open'));
}

/**
 * Checks one uploaded document before anything is saved. Returns the upload with its
 * extension and type, null when the optional slot was left empty, or throws a message for the applicant.
 */
function check_application_file(string $field): ?array
{
    [$label, $required, $max, $allowed] = APP_FILES[$field];
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || ($f['name'] ?? '') === '') {
        if ($required) throw new RuntimeException("Please attach your $label.");
        return null;
    }
    if (is_array($f['name'])) throw new RuntimeException("Attach one file for your $label.");
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > $max) {
        throw new RuntimeException("Your $label is too large. The limit is " . round($max / 1048576) . ' MB.');
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        throw new RuntimeException("Your $label could not be uploaded. Please try again.");
    }
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException("Your $label must be a " . strtoupper(implode(', ', $allowed)) . ' file.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    if (!in_array($mime, APP_MIME[$ext], true)) {
        throw new RuntimeException("Your $label doesn't look like a real .$ext file.");
    }
    return $f + ['ext' => $ext, 'mime' => $mime];
}

/** Moves a checked upload into private storage and returns its record. */
function store_application_file(string $field, array $f, int $appId): array
{
    $sub = date('Y');
    $dir = applications_dir() . '/' . $sub;
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $stored = $sub . '/' . $appId . '-' . $field . '-' . bin2hex(random_bytes(8)) . '.' . $f['ext'];
    if (!move_uploaded_file($f['tmp_name'], applications_dir() . '/' . $stored)) {
        throw new RuntimeException('Your ' . APP_FILES[$field][0] . ' could not be saved. Please try again.');
    }
    @chmod(applications_dir() . '/' . $stored, 0600);
    $name = preg_replace('/[^\w.\- ()]+/u', '_', (string) $f['name']) ?: ($field . '.' . $f['ext']);
    return ['name' => mb_substr($name, 0, 120), 'path' => $stored, 'size' => (int) $f['size'], 'mime' => $f['mime']];
}

function delete_application_files(array $app): void
{
    foreach (json_decode($app['files'] ?: '{}', true) ?: [] as $file) {
        $path = applications_dir() . '/' . ($file['path'] ?? '');
        if (!empty($file['path']) && !str_contains($file['path'], '..') && is_file($path)) @unlink($path);
    }
}

function application_ref(int $id): string
{
    return 'JC' . date('y') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

function format_bytes(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}
