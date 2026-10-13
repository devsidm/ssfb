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
function update_post_meta($id, $key, $value): void { $GLOBALS['attendance_meta'][$id][$key] = $value; }
function get_posts($args): array { return $GLOBALS['attendance_duplicate'] && '_ssf_am_email' === ($args['meta_key'] ?? '') ? array(new WP_Post(99)) : array(); }
function get_option($key, $default = false) { return 'ssf_member_portal_active_meeting_id' === $key ? 10 : $default; }
function wp_parse_args($args, $defaults): array { return array_merge($defaults, $args); }
function absint($value): int { return abs((int) $value); }
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
$without_vessel = $validate->invoke($service, $meeting, array('participants' => array($person('p1', 'Johan', array('annual_meeting' => 1), array('relationship' => 'representative', 'vessels' => array())))), 0);
check(! is_wp_error($without_vessel) && ! $without_vessel['participants'][0]['vessels'], 'Optional vessel field was incorrectly required for a representative.');
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

$question_meeting = $meeting;
$question_meeting['program'][1]['capacity'] = 0;
$question_meeting['program'][1]['questions'] = array(array('key' => 'group_note', 'library_key' => 'group_note', 'title' => 'Gemensam kommentar', 'type' => 'text', 'options' => array(), 'required' => 1, 'scope' => 'registration'));
$question_meeting['program'][2]['questions'] = array(
    array('key' => 'dietary_preferences', 'library_key' => 'dietary_preferences', 'title' => 'Kost / matpreferenser', 'type' => 'multiple', 'options' => array('Vegetarisk', 'Glutenfri'), 'required' => 1, 'scope' => 'participant'),
    array('key' => 'kitchen_notes', 'library_key' => 'kitchen_notes', 'title' => 'Allergier', 'type' => 'textarea', 'options' => array(), 'required' => 0, 'scope' => 'participant'),
);
$question_input = array(
    'participants' => array($person('p1', 'Ada', array('dinner' => 1)), $person('p2', 'Grace', array('dinner' => 1), array('email' => ''))),
    'activity_answers' => array('dinner' => array(
        'dietary_preferences' => array('participants' => array('p1' => array('Vegetarisk'), 'p2' => array('Glutenfri'))),
        'kitchen_notes' => array('participants' => array('p1' => '', 'p2' => 'Separat bröd')),
    )),
);
$question_result = $validate->invoke($service, $question_meeting, $question_input, 0);
check(! is_wp_error($question_result) && 'vegetarian' === $question_result['participants'][0]['dietary'][0] && 'gluten_free' === $question_result['participants'][1]['dietary'][0], 'Per-participant activity answers were not kept separate.');
check('Separat bröd' === $question_result['participants'][1]['allergy_note'], 'Activity answer was not projected to legacy kitchen notes.');
$missing_answer = $question_input; unset($missing_answer['activity_answers']['dinner']['dietary_preferences']['participants']['p2']);
$missing_result = $validate->invoke($service, $question_meeting, $missing_answer, 0);
check(is_wp_error($missing_result) && 'annual_meeting_activity_question_required' === $missing_result->get_error_code(), 'Required per-participant answer was not enforced.');
$not_attending = array('participants' => array($person('p1', 'Ada', array('annual_meeting' => 1)), $person('p2', 'Grace', array('dinner' => 1), array('email' => ''))), 'activity_answers' => array('dinner' => array('dietary_preferences' => array('participants' => array('p2' => array('Glutenfri'))))));
check(! is_wp_error($validate->invoke($service, $question_meeting, $not_attending, 0)), 'Question was incorrectly required for a participant outside the activity.');
$registration_question = array('participants' => array($person('p1', 'Ada', array('activity_1' => 1)), $person('p2', 'Grace', array('activity_1' => 1), array('email' => ''))), 'activity_answers' => array('activity_1' => array('group_note' => array('registration' => 'Sitt med Vega'))));
$registration_result = $validate->invoke($service, $question_meeting, $registration_question, 0);
check(! is_wp_error($registration_result) && 'Sitt med Vega' === $registration_result['activity_answers']['activity_1']['group_note']['registration'], 'Per-registration answer was not stored once.');
$GLOBALS['attendance_posts'][22] = new WP_Post(22);
$GLOBALS['attendance_meta'][22] = array('_ssf_am_registration_id' => '2026-REG-0022', '_ssf_am_status' => 'registered', '_ssf_am_first_name' => 'Ada', '_ssf_am_last_name' => 'Test', '_ssf_am_email' => 'ada@example.test', '_ssf_am_participants' => $question_result['participants'], '_ssf_am_activity_answers' => $question_result['activity_answers']);
$question_details = $service->details(22, $question_meeting);
check('Vegetarisk' === $question_details['participants'][0]['activity_answers'][0]['answer'] && 'Glutenfri' === $question_details['participants'][1]['activity_answers'][0]['answer'], 'Admin/confirmation projection mixed participant answers.');
$question_export = (new SSF\MemberPortal\Modules\AnnualMeetings\RegistrationExport())->data($question_meeting, array($GLOBALS['attendance_posts'][22]));
check(4 === count($question_export['sheets'][2]['rows']) && 'Vegetarisk' === $question_export['sheets'][2]['rows'][0][3] && 'Glutenfri' === $question_export['sheets'][2]['rows'][1][3], 'Activity answers were not exported per participant.');
$question_mail = (new ReflectionMethod(new SSF\MemberPortal\Modules\AnnualMeetings\RegistrationMailer(), 'confirmation_data'))->invoke(new SSF\MemberPortal\Modules\AnnualMeetings\RegistrationMailer(), $question_meeting, $question_details, '', array(), '');
check(str_contains(json_encode($question_mail, JSON_UNESCAPED_UNICODE), 'Vegetarisk') && str_contains(json_encode($question_mail, JSON_UNESCAPED_UNICODE), 'Glutenfri'), 'Confirmation email omitted activity answers.');

$GLOBALS['attendance_posts'][20] = new WP_Post(20);
$GLOBALS['attendance_meta'][20] = array('_ssf_am_registration_id' => '2026-REG-0020', '_ssf_am_first_name' => 'Äldre', '_ssf_am_last_name' => 'Deltagare', '_ssf_am_email' => 'old@example.test', '_ssf_am_status' => 'registered', '_ssf_am_meeting_attendance' => 'yes', '_ssf_am_program' => array('dinner' => 1));
$legacy = $service->details(20, $meeting);
check(1 === $legacy['participant_count'] && ! empty($legacy['participants'][0]['activities']['annual_meeting']), 'Legacy registration was not projected as one participant.');
$GLOBALS['attendance_posts'][21] = new WP_Post(21);
$GLOBALS['attendance_meta'][21] = array('_ssf_am_registration_id' => '2026-REG-0021', '_ssf_am_status' => 'registered', '_ssf_am_participants' => $two['participants']);
$export = (new SSF\MemberPortal\Modules\AnnualMeetings\RegistrationExport())->data($meeting, array($GLOBALS['attendance_posts'][20], $GLOBALS['attendance_posts'][21]));
check(3 === count($export['rows']) && array('Deltagare', 'Aktiviteter', 'Aktivitetsfrågor', 'Specialkost') === array_column($export['sheets'], 'name'), 'Multi-sheet participant export failed.');
check(1 === count($export['sheets'][3]['rows']), 'Special diet export did not contain one person/activity row.');

$GLOBALS['attendance_posts'][10] = new WP_Post(10, 0);
$GLOBALS['attendance_meta'][10] = array(
    '_ssf_am_start_at' => strtotime('2026-10-18 00:00:00 Europe/Stockholm'), '_ssf_am_end_at' => strtotime('2026-10-20 00:00:00 Europe/Stockholm'), '_ssf_am_duration_days' => 2,
    '_ssf_am_modules' => array('meeting' => 1, 'day2' => 1, 'dinner' => 1), '_ssf_am_program' => array(),
    '_ssf_am_dinner' => array('title' => 'Middag', 'location' => 'Hotell Svea', 'start_at' => strtotime('2026-10-18 18:00:00 Europe/Stockholm'), 'end_at' => strtotime('2026-10-18 21:00:00 Europe/Stockholm'), 'opens_at' => strtotime('2026-09-01 00:00:00 Europe/Stockholm'), 'deadline' => strtotime('2026-10-10 23:59:00 Europe/Stockholm'), 'capacity' => 30, 'price' => '395 kr', 'description' => 'Gemensam middag', 'food_enabled' => 1, 'manual_open' => 1),
    '_ssf_am_food_options' => array('Vegetarisk', 'Glutenfri'),
);
$migrated_once = $module->data(10);
$migrated_twice = $module->data(10);
$dinners_once = array_values(array_filter($migrated_once['program'], static fn (array $activity): bool => 'dinner' === ($activity['key'] ?? '')));
$dinners_twice = array_values(array_filter($migrated_twice['program'], static fn (array $activity): bool => 'dinner' === ($activity['key'] ?? '')));
check(1 === count($dinners_once) && 1 === count($dinners_twice), 'Legacy dinner migration created duplicates.');
check('Hotell Svea' === $dinners_once[0]['location'] && 30 === $dinners_once[0]['capacity'] && '395 kr' === $dinners_once[0]['price'] && ! empty($dinners_once[0]['manual_open']), 'Legacy dinner fields were not mapped to the activity.');
check(array('dietary_preferences', 'kitchen_notes') === array_column($dinners_once[0]['questions'], 'key'), 'Legacy food fields were not mapped to activity questions.');
check($module->migrate_activity_model(10) && ! $module->migrate_activity_model(10), 'Persisted activity migration was not idempotent.');

$mailer = new SSF\MemberPortal\Modules\AnnualMeetings\RegistrationMailer();
$confirmation_data = (new ReflectionMethod($mailer, 'confirmation_data'))->invoke($mailer, $meeting, $service->details(21, $meeting), '', array(), '');
check(4 === count($confirmation_data['sections']), 'Confirmation email did not contain participant sections.');

echo "PASS: group registration validation, capacity, legacy projection, export and email.\n";
