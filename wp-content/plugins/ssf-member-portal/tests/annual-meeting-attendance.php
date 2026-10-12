<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['attendance_meta'] = array();
$GLOBALS['attendance_posts'] = array();
$GLOBALS['attendance_now'] = strtotime('2026-09-20 12:00:00 Europe/Stockholm');

class WP_Post {
    public int $ID;
    public int $post_parent;
    public string $post_type = 'ssf_meeting_registration';
    public string $post_status = 'private';
    public function __construct(int $id, int $parent = 10) { $this->ID = $id; $this->post_parent = $parent; }
}

class WP_Error {
    private string $code;
    private string $message;
    public function __construct(string $code, string $message) { $this->code = $code; $this->message = $message; }
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}

function check($condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function get_post($id) { return $GLOBALS['attendance_posts'][$id] ?? null; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['attendance_meta'][$id][$key] ?? ''; }
function get_posts($args): array { return array(); }
function get_option($key, $default = false) { return 'ssf_member_portal_active_meeting_id' === $key ? 10 : $default; }
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value): string { return sanitize_text_field($value); }
function sanitize_email($value): string { return filter_var((string) $value, FILTER_SANITIZE_EMAIL); }
function sanitize_key($value): string { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) $value)); }
function is_email($value): bool { return false !== filter_var($value, FILTER_VALIDATE_EMAIL); }
function current_datetime(): DateTimeImmutable { return (new DateTimeImmutable('@' . $GLOBALS['attendance_now']))->setTimezone(wp_timezone()); }
function wp_timezone(): DateTimeZone { return new DateTimeZone('Europe/Stockholm'); }
function wp_date($format, $timestamp, $timezone = null): string { return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone ?: wp_timezone())->format($format); }
function wp_strip_all_tags($value): string { return strip_tags((string) $value); }
function wp_trim_words($value, $count, $more = null): string { return (string) $value; }
function get_the_title($id): string { return 'SSF:s årsmöte 2026'; }
function __($text, $domain = ''): string { return $text; }
function add_action(...$args): void {}

require_once __DIR__ . '/../includes/Modules/AnnualMeetings/Module.php';
require_once __DIR__ . '/../includes/Modules/AnnualMeetings/RegistrationPostType.php';
require_once __DIR__ . '/../includes/Modules/AnnualMeetings/RegistrationService.php';
require_once __DIR__ . '/../includes/Modules/AnnualMeetings/RegistrationExport.php';
require_once __DIR__ . '/../includes/Modules/AnnualMeetings/RegistrationMailer.php';
require_once __DIR__ . '/../includes/Modules/AnnualMeetings/Admin.php';

$module = (new ReflectionClass(SSF\MemberPortal\Modules\AnnualMeetings\Module::class))->newInstanceWithoutConstructor();
$service = (new ReflectionClass(SSF\MemberPortal\Modules\AnnualMeetings\RegistrationService::class))->newInstanceWithoutConstructor();
$field = new ReflectionProperty($service, 'meetings');
$field->setValue($service, $module);
$validate = new ReflectionMethod($service, 'validate');

$meeting = array(
    'id' => 10,
    'year' => 2026,
    'start_at' => strtotime('2026-10-18 00:00:00 Europe/Stockholm'),
    'end_at' => strtotime('2026-10-19 00:00:00 Europe/Stockholm'),
    'registration_visible' => 1,
    'registration_open' => 1,
    'registration_mode' => 'open',
    'registration_mode_explicit' => 1,
    'registration_opens_at' => 0,
    'registration_closes_at' => 0,
    'modules' => array('day2' => 1, 'dinner' => 0),
    'program' => array(
        array('key' => 'annual_meeting', 'type' => 'annual_meeting', 'title' => 'Själva årsmötet', 'visible' => 1, 'requires_registration' => 1, 'date' => '2026-10-18', 'start' => '10:00'),
        array('key' => 'activity_1', 'type' => 'activity', 'title' => 'Studiebesök', 'visible' => 1, 'requires_registration' => 1, 'optional' => 1, 'date' => '2026-10-18', 'start' => '14:00'),
    ),
    'food_options' => array(),
    'questions' => array(),
    'waitlist' => 0,
);

$input = array('first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'relationship' => 'supporter');
$yes = $validate->invoke($service, $meeting, $input + array('meeting_attendance' => 'yes'), 0);
check(! is_wp_error($yes) && 'yes' === $yes['meeting_attendance'] && array() === $yes['program'], 'Meeting attendance Yes did not validate independently.');

$no = $validate->invoke($service, $meeting, $input + array('meeting_attendance' => 'no', 'program' => array('activity_1' => 1)), 0);
check(! is_wp_error($no) && 'no' === $no['meeting_attendance'] && ! empty($no['program']['activity_1']), 'Meeting attendance No did not validate with a separate weekend activity.');

$missing = $validate->invoke($service, $meeting, $input, 0);
check(is_wp_error($missing) && 'annual_meeting_attendance_required' === $missing->get_error_code(), 'Missing meeting attendance was not rejected server-side.');
$invalid = $validate->invoke($service, $meeting, $input + array('meeting_attendance' => 'maybe'), 0);
check(is_wp_error($invalid) && 'annual_meeting_attendance_required' === $invalid->get_error_code(), 'A non-canonical meeting attendance value was accepted.');

$GLOBALS['attendance_posts'][20] = new WP_Post(20);
$GLOBALS['attendance_posts'][21] = new WP_Post(21);
$GLOBALS['attendance_posts'][22] = new WP_Post(22);
$GLOBALS['attendance_posts'][23] = new WP_Post(23);
$GLOBALS['attendance_meta'][20] = array(
    '_ssf_am_first_name' => 'Äldre',
    '_ssf_am_last_name' => 'Deltagare',
    '_ssf_am_status' => 'registered',
);
$GLOBALS['attendance_meta'][21] = array('_ssf_am_meeting_attendance' => 'yes', '_ssf_am_status' => 'registered');
$GLOBALS['attendance_meta'][22] = array('_ssf_am_meeting_attendance' => 'no', '_ssf_am_status' => 'registered');
$GLOBALS['attendance_meta'][23] = array('_ssf_am_meeting_attendance' => 'yes', '_ssf_am_status' => 'cancelled');
$legacy = $service->details(20, $meeting);
check('' === $legacy['meeting_attendance'] && 'Ej angivet' === $legacy['meeting_attendance_label'], 'A legacy registration was not shown as Ej angivet.');

$export = (new SSF\MemberPortal\Modules\AnnualMeetings\RegistrationExport())->data($meeting, array($GLOBALS['attendance_posts'][20], $GLOBALS['attendance_posts'][21], $GLOBALS['attendance_posts'][22]));
$attendance_column = array_search('Årsmöte', $export['headers'], true);
check(false !== $attendance_column && 'Ej angivet' === $export['rows'][0][$attendance_column], 'Export did not include the legacy attendance value.');
check('Ja' === $export['rows'][1][$attendance_column] && 'Nej' === $export['rows'][2][$attendance_column], 'Export did not map explicit Yes/No attendance values.');

$mailer = new SSF\MemberPortal\Modules\AnnualMeetings\RegistrationMailer();
$confirmation_data = new ReflectionMethod($mailer, 'confirmation_data');
$legacy_mail = $confirmation_data->invoke($mailer, $meeting, $legacy, '', array(), '');
$mail_rows = $legacy_mail['sections'][1]['rows'];
$attendance_rows = array_values(array_filter($mail_rows, static fn (array $row): bool => 'Årsmöte' === $row['label']));
check(1 === count($attendance_rows) && 'Ej angivet' === $attendance_rows[0]['value'], 'Confirmation email did not include meeting attendance.');

$admin = (new ReflectionClass(SSF\MemberPortal\Modules\AnnualMeetings\Admin::class))->newInstanceWithoutConstructor();
foreach (array(array('meetings', $module), array('registrations', $service)) as $property) {
    $admin_field = new ReflectionProperty($admin, $property[0]);
    $admin_field->setValue($admin, $property[1]);
}
$columns = $admin->columns(array('cb' => 'cb'));
check(isset($columns['ssf_am_attendance']), 'Admin participant list does not expose meeting attendance.');
$statistics = new ReflectionMethod($admin, 'statistics');
$stats = $statistics->invoke($admin, $meeting, array_values($GLOBALS['attendance_posts']));
check(1 === $stats['meeting_attendees'], 'Admin attendance count did not count only explicit Yes on active registrations.');

echo "PASS: annual-meeting attendance validation, legacy value, admin, export and email.\n";
