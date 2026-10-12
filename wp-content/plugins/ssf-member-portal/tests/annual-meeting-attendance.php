<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
$GLOBALS['attendance_meta'] = array();
$GLOBALS['attendance_posts'] = array();
$GLOBALS['attendance_duplicate'] = false;
$GLOBALS['attendance_now'] = strtotime('2026-09-20 12:00:00 Europe/Stockholm');

class WP_Post {
    public int $ID; public int $post_parent; public string $post_type = 'ssf_meeting_registration'; public string $post_status = 'private';
    public function __construct(int $id, int $parent = 10) { $this->ID = $id; $this->post_parent = $parent; }
}
class WP_Error {
    private string $code; private string $message;
    public function __construct(string $code, string $message) { $this->code = $code; $this->message = $message; }
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}
function check($condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function get_post($id) { return $GLOBALS['attendance_posts'][$id] ?? null; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['attendance_meta'][$id][$key] ?? ''; }
function get_posts($args): array { return $GLOBALS['attendance_duplicate'] && '_ssf_am_email' === ($args['meta_key'] ?? '') ? array(new WP_Post(99)) : array(); }
function get_option($key, $default = false) { return 'ssf_member_portal_active_meeting_id' === $key ? 10 : $default; }
function wp_parse_args($args, $defaults): array { return array_merge($defaults, $args); }
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
(new ReflectionProperty($service, 'meetings'))->setValue($service, $module);
$validate = new ReflectionMethod($service, 'validate');
$meeting = array(
    'id' => 10, 'year' => 2026, 'start_at' => strtotime('2026-10-18'), 'end_at' => strtotime('2026-10-19'),
    'registration_visible' => 1, 'registration_open' => 1, 'registration_mode' => 'open', 'registration_mode_explicit' => 1,
    'registration_opens_at' => 0, 'registration_closes_at' => 0, 'modules' => array('day2' => 1, 'dinner' => 1),
    'program' => array(
        array('key' => 'annual_meeting', 'type' => 'annual_meeting', 'title' => 'Själva årsmötet', 'visible' => 1, 'requires_registration' => 1, 'capacity' => 0),
        array('key' => 'activity_1', 'type' => 'activity', 'title' => 'Studiebesök', 'visible' => 1, 'requires_registration' => 1, 'capacity' => 1),
        array('key' => 'dinner', 'type' => 'dinner', 'title' => 'Middag', 'visible' => 1, 'requires_registration' => 1, 'food' => 1, 'capacity' => 0),
    ),
    'food_options' => array(), 'questions' => array(), 'waitlist' => 0,
);
$person = static function (string $id, string $first, array $activities, array $extra = array()): array {
    return array_merge(array('id' => $id, 'first_name' => $first, 'last_name' => 'Test', 'email' => 'ada@example.test', 'relationship' => 'supporter', 'activities' => $activities), $extra);
};

$one = $validate->invoke($service, $meeting, array('participants' => array($person('p1', 'Ada', array('annual_meeting' => 1)))), 0);
check(! is_wp_error($one) && 1 === count($one['participants']) && 'yes' === $one['meeting_attendance'], 'One participant/one activity failed.');
$multi = $validate->invoke($service, $meeting, array('participants' => array($person('p1', 'Ada', array('annual_meeting' => 1, 'dinner' => 1), array('dietary' => array('vegan'), 'allergy_note' => 'Nötter')))), 0);
check(! is_wp_error($multi) && 'vegan' === $multi['participants'][0]['dietary'][0] && 'Nötter' === $multi['participants'][0]['allergy_note'], 'Multiple activities or dietary data failed.');
$two = $validate->invoke($service, $meeting, array('participants' => array($person('p1', 'Ada', array('annual_meeting' => 1)), $person('p2', 'Grace', array('dinner' => 1), array('email' => '', 'dietary' => array('vegetarian'))))), 0);
check(! is_wp_error($two) && 2 === count($two['participants']) && empty($two['participants'][1]['activities']['annual_meeting']), 'Two participants with different choices failed.');
$cleared = $validate->invoke($service, $meeting, array('participants' => array($person('p1', 'Ada', array('annual_meeting' => 1), array('dietary' => array('vegan'), 'dietary_other' => 'x', 'allergy_note' => 'x')))), 0);
check(! is_wp_error($cleared) && ! $cleared['participants'][0]['dietary'] && '' === $cleared['participants'][0]['allergy_note'], 'Food data was retained without a food activity.');
$full = $validate->invoke($service, $meeting, array('participants' => array($person('p1', 'Ada', array('activity_1' => 1)), $person('p2', 'Grace', array('activity_1' => 1), array('email' => '')))), 0);
check(is_wp_error($full) && 'annual_meeting_choice_full' === $full->get_error_code(), 'Per-person capacity was not enforced.');
$invalid = $validate->invoke($service, $meeting, array('participants' => array($person('p1', 'Ada', array('wrong_meeting' => 1)))), 0);
check(is_wp_error($invalid) && 'annual_meeting_invalid_activity' === $invalid->get_error_code(), 'Invalid activity was accepted.');
$GLOBALS['attendance_duplicate'] = true;
$duplicate = $validate->invoke($service, $meeting, array('participants' => array($person('p1', 'Ada', array('annual_meeting' => 1)))), 0);
check(is_wp_error($duplicate) && 'annual_meeting_duplicate' === $duplicate->get_error_code(), 'Duplicate primary email was accepted.');
$GLOBALS['attendance_duplicate'] = false;

$GLOBALS['attendance_posts'][20] = new WP_Post(20);
$GLOBALS['attendance_meta'][20] = array('_ssf_am_registration_id' => '2026-REG-0020', '_ssf_am_first_name' => 'Äldre', '_ssf_am_last_name' => 'Deltagare', '_ssf_am_email' => 'old@example.test', '_ssf_am_status' => 'registered', '_ssf_am_meeting_attendance' => 'yes', '_ssf_am_program' => array('dinner' => 1));
$legacy = $service->details(20, $meeting);
check(1 === $legacy['participant_count'] && ! empty($legacy['participants'][0]['activities']['annual_meeting']), 'Legacy registration was not projected as one participant.');
$GLOBALS['attendance_posts'][21] = new WP_Post(21);
$GLOBALS['attendance_meta'][21] = array('_ssf_am_registration_id' => '2026-REG-0021', '_ssf_am_status' => 'registered', '_ssf_am_participants' => $two['participants']);
$export = (new SSF\MemberPortal\Modules\AnnualMeetings\RegistrationExport())->data($meeting, array($GLOBALS['attendance_posts'][20], $GLOBALS['attendance_posts'][21]));
check(3 === count($export['rows']) && array('Deltagare', 'Aktiviteter', 'Specialkost') === array_column($export['sheets'], 'name'), 'Multi-sheet participant export failed.');
check(1 === count($export['sheets'][2]['rows']), 'Special diet export did not contain one person/activity row.');

$mailer = new SSF\MemberPortal\Modules\AnnualMeetings\RegistrationMailer();
$confirmation_data = (new ReflectionMethod($mailer, 'confirmation_data'))->invoke($mailer, $meeting, $service->details(21, $meeting), '', array(), '');
check(4 === count($confirmation_data['sections']), 'Confirmation email did not contain participant sections.');

echo "PASS: group registration validation, capacity, legacy projection, export and email.\n";
