<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
define('SSF_MEMBER_PORTAL_PATH', dirname(__DIR__) . '/');

$GLOBALS['meeting_meta'] = array();
$GLOBALS['meeting_options'] = array('ssf_member_portal_active_meeting_id' => 10);
$GLOBALS['now'] = strtotime('2026-09-20 12:00:00 Europe/Stockholm');

class WP_Post {
    public int $ID;
    public string $post_type = 'ssf_annual_meeting';
    public string $post_status = 'publish';
    public string $post_title = 'SSF:s årsmöteshelg 2026';
    public string $post_content = '';
    public function __construct(int $id) { $this->ID = $id; }
}
$GLOBALS['meeting_posts'] = array(10 => new WP_Post(10));

function check($condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function get_post($id) { return $GLOBALS['meeting_posts'][$id] ?? null; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['meeting_meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value): void { $GLOBALS['meeting_meta'][$id][$key] = $value; }
function get_option($key, $default = false) { return $GLOBALS['meeting_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null): void { $GLOBALS['meeting_options'][$key] = $value; }
function delete_option($key): void { unset($GLOBALS['meeting_options'][$key]); }
function get_posts($args): array { return array(); }
function wp_parse_args($args, $defaults): array { return array_merge($defaults, $args); }
function wp_unslash($value) { return $value; }
function absint($value): int { return abs((int) $value); }
function sanitize_key($value): string { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) $value)); }
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value): string { return sanitize_text_field($value); }
function wp_kses_post($value): string { return (string) $value; }
function esc_url_raw($value): string { return (string) $value; }
function current_user_can(...$args): bool { return true; }
function wp_verify_nonce($nonce, $action): bool { return 'valid' === $nonce; }
function wp_nonce_field(...$args): void {}
function wp_editor(...$args): void {}
function wp_timezone(): DateTimeZone { return new DateTimeZone('Europe/Stockholm'); }
function wp_timezone_string(): string { return 'Europe/Stockholm'; }
function current_datetime(): DateTimeImmutable { return (new DateTimeImmutable('@' . $GLOBALS['now']))->setTimezone(wp_timezone()); }
function wp_date($format, $timestamp, $timezone = null): string { return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone ?: wp_timezone())->format($format); }
function __($text, $domain = ''): string { return $text; }
function esc_html__($text, $domain = ''): string { return $text; }
function esc_html_e($text, $domain = ''): void { echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_attr_e($text, $domain = ''): void { esc_html_e($text); }
function esc_html($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text): string { return esc_html($text); }
function esc_textarea($text): string { return esc_html($text); }
function esc_url($text): string { return esc_html($text); }
function checked($actual, $expected = true): void { if ($actual == $expected) echo 'checked="checked"'; }
function selected($actual, $expected): void { if ($actual == $expected) echo 'selected="selected"'; }
function disabled($actual, $expected = true): void { if ($actual == $expected) echo 'disabled="disabled"'; }
function add_query_arg($args, $url): string { return $url; }
function home_url($path): string { return 'https://ssfb.se/dev' . $path; }
function admin_url($path): string { return 'https://ssfb.se/dev/wp-admin/' . $path; }
function has_post_thumbnail($post): bool { return false; }
function do_action(...$args): void {}
function wpautop($text): string { return '<p>' . $text . '</p>'; }
function get_the_title($post): string { return is_object($post) ? $post->post_title : 'Årsmöte'; }

require_once __DIR__ . '/../includes/Modules/AnnualMeetings/Module.php';
require_once __DIR__ . '/../includes/Modules/AnnualMeetings/RegistrationPostType.php';
require_once __DIR__ . '/../includes/Modules/AnnualMeetings/RegistrationService.php';
require_once __DIR__ . '/../includes/Modules/AnnualMeetings/CalendarService.php';
require_once __DIR__ . '/../includes/Modules/AnnualMeetings/Frontend.php';
require_once __DIR__ . '/../includes/Modules/AnnualMeetings/Editor.php';

$module = (new ReflectionClass(SSF\MemberPortal\Modules\AnnualMeetings\Module::class))->newInstanceWithoutConstructor();
$service = (new ReflectionClass(SSF\MemberPortal\Modules\AnnualMeetings\RegistrationService::class))->newInstanceWithoutConstructor();
$calendar = new SSF\MemberPortal\Modules\AnnualMeetings\CalendarService($module);
$frontend = (new ReflectionClass(SSF\MemberPortal\Modules\AnnualMeetings\Frontend::class))->newInstanceWithoutConstructor();
$editor = new SSF\MemberPortal\Modules\AnnualMeetings\Editor($module, $service);
foreach (array(array($service, 'meetings', $module), array($frontend, 'meetings', $module), array($frontend, 'registrations', $service)) as $property) {
    $field = new ReflectionProperty($property[0], $property[1]);
    $field->setValue($property[0], $property[2]);
}

$start = strtotime('2026-10-18 00:00:00 Europe/Stockholm');
update_post_meta(10, '_ssf_am_year', 2026);
update_post_meta(10, '_ssf_am_start_at', $start);
update_post_meta(10, '_ssf_am_end_at', $start + DAY_IN_SECONDS);
update_post_meta(10, '_ssf_am_duration_days', 1);
update_post_meta(10, '_ssf_am_location', 'Simrishamn');
update_post_meta(10, '_ssf_am_modules', array('meeting' => 1, 'day2' => 1, 'calendar' => 1));
$program = array(array('key' => 'annual_meeting', 'type' => 'annual_meeting', 'title' => 'Själva årsmötet', 'day' => 1, 'start' => '10:00', 'end' => '12:00', 'requires_registration' => 1, 'visible' => 1));
update_post_meta(10, '_ssf_am_program', $program);

$render = static function (array $meeting, array $state) use ($module, $service, $frontend, $calendar): string {
    $meeting_post = get_post(10);
    $registration_state = $state;
    $choices = $state['choices'];
    $choice_states = $state['choice_states'];
    $calendar = array();
    $motion = array('state' => 'closed');
    $page_title = 'SSF:s årsmöteshelg 2026';
    $location_summary = 'Simrishamn';
    $location_address = '';
    $maps_url = '';
    $contact_url = 'https://ssfb.se/dev/kontakta-oss/';
    $include = function () use ($meeting, $meeting_post, $registration_state, $choices, $choice_states, $calendar, $motion, $page_title, $location_summary, $location_address, $maps_url, $contact_url): void {
        include SSF_MEMBER_PORTAL_PATH . 'templates/annual-meetings/meeting.php';
    };
    ob_start();
    $include->bindTo($frontend, get_class($frontend))();
    return ob_get_clean();
};

$meeting = $module->data(10);
check($meeting['registration_mode'] === 'closed' && ! $meeting['registration_mode_explicit'], 'Legacy disabled mode was not preserved.');
$new_post = new WP_Post(11);
$new_post->post_status = 'auto-draft';
$GLOBALS['meeting_posts'][11] = $new_post;
check($module->data(11)['registration_mode'] === 'hidden', 'New meeting did not default to hidden registration.');
$legacy_state = $service->registration_state($meeting, get_post(10));
check($legacy_state['status'] === 'disabled', 'Legacy disabled state changed.');
update_post_meta(10, '_ssf_am_registration_open', 1);
$meeting = $module->data(10);
check($meeting['registration_mode'] === 'open' && ! $meeting['registration_mode_explicit'], 'Legacy open mode was not preserved.');
check($service->registration_state($meeting, get_post(10))['can_register'], 'Legacy open registration stopped working.');

$_POST = array(
    'ssf_member_portal_meeting_nonce' => 'valid',
    'ssf_meeting_year' => '2026',
    'ssf_meeting_start_date' => '2026-10-18',
    'ssf_meeting_location' => 'Simrishamn',
    'ssf_meeting_modules' => array('meeting' => 1, 'day2' => 1, 'calendar' => 1),
    'ssf_meeting_program' => $program,
    'ssf_meeting_active' => '1',
    'ssf_meeting_registration_mode' => 'hidden',
);
$module->save(10, get_post(10));
$meeting = $module->data(10);
check($meeting['registration_mode'] === 'hidden' && $meeting['registration_mode_explicit'] && ! $meeting['registration_open'], 'Hidden mode did not persist.');
$hidden_state = $service->registration_state($meeting, get_post(10));
check(! $hidden_state['can_register'], 'Hidden mode allowed registration.');
check($frontend->registration_shortcode() === '', 'Direct registration page exposed hidden registration content.');
$hidden_html = $render($meeting, $hidden_state);
check(str_contains($hidden_html, 'Simrishamn') && str_contains($hidden_html, 'Själva årsmötet'), 'Hidden mode removed meeting information.');
foreach (array('ssf-am-registration', 'Gå till anmälan', 'Anmälan är inte', 'Anmälan är stängd', 'Anmälan krävs', 'Anmäl gärna', 'ssf-am-activity__status') as $forbidden) {
    check(! str_contains($hidden_html, $forbidden), 'Hidden mode leaked registration UI: ' . $forbidden);
}
check(! str_contains($calendar->event_data($meeting)['description'], 'Anmäl'), 'Hidden mode leaked registration into calendar text.');

$_POST['ssf_meeting_registration_mode'] = 'open';
$module->save(10, get_post(10));
$meeting = $module->data(10);
$open_state = $service->registration_state($meeting, get_post(10));
check($meeting['registration_mode'] === 'open' && $meeting['registration_open'] && $open_state['can_register'], 'Open mode did not persist or enable registration.');
$open_html = $render($meeting, $open_state);
check(str_contains($open_html, 'ssf-am-registration') && str_contains($open_html, 'Gå till anmälan'), 'Open mode omitted the existing registration action.');
$form_html = $frontend->registration_shortcode();
check(str_contains($form_html, 'ssf-am-form') && str_contains($form_html, 'ssf_member_portal_submit_meeting_registration'), 'Open mode did not render the existing registration form.');

$_POST['ssf_meeting_registration_mode'] = 'closed';
$_POST['ssf_meeting_registration_closes_on'] = '2026-10-17';
$module->save(10, get_post(10));
$meeting = $module->data(10);
$closed_state = $service->registration_state($meeting, get_post(10));
check($meeting['registration_mode'] === 'closed' && ! $meeting['registration_open'] && ! $closed_state['can_register'], 'Closed mode did not block registration.');
check($closed_state['message'] === 'Anmälan är stängd.', 'Manual close displayed a misleading future deadline.');
check($closed_state['choice_states']['annual_meeting']['message'] === 'Anmälan är stängd.', 'Program choice displayed a misleading future deadline.');
$GLOBALS['meeting_options']['ssf_member_portal_active_meeting_id'] = 11;
check($service->registration_state($meeting, get_post(10))['status'] === 'closed', 'Closed historical meeting lost its closed status.');
$GLOBALS['meeting_options']['ssf_member_portal_active_meeting_id'] = 10;
$closed_html = $render($meeting, $closed_state);
check(str_contains($closed_html, 'Anmälan är stängd') && ! str_contains($closed_html, 'Gå till anmälan'), 'Closed mode did not show its status correctly.');
$closed_form_html = $frontend->registration_shortcode();
check(! str_contains($closed_form_html, 'ssf-am-form') && str_contains($closed_form_html, 'Anmälan är stängd'), 'Closed mode exposed the form.');
ob_start();
$editor->render(get_post(10));
$admin_html = ob_get_clean();
check(str_contains($admin_html, 'name="ssf_meeting_registration_mode" value="hidden"') && str_contains($admin_html, 'name="ssf_meeting_registration_mode" value="open"') && str_contains($admin_html, 'name="ssf_meeting_registration_mode" value="closed" checked="checked"'), 'Admin did not render all three modes or restore the selected one.');

echo "PASS: legacy, hidden, open, closed, persistence, frontend and calendar.\n";
