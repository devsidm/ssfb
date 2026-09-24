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
function delete_post_meta($id, $key): void { unset($GLOBALS['meeting_meta'][$id][$key]); }
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
function apply_filters($hook, $value) { return $value; }
function wpautop($text): string { return '<p>' . $text . '</p>'; }
function get_the_title($post): string { return is_object($post) ? $post->post_title : 'Årsmöte'; }
function get_post_mime_type($id): string { return 123 === (int) $id ? 'application/pdf' : ''; }
function wp_get_attachment_url($id): string { return 'https://ssfb.se/dev/document-' . $id . '.pdf'; }
function get_attached_file($id): string { return 'document-' . $id . '.pdf'; }

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
$GLOBALS['meeting_posts'][10]->post_content = 'Varmt välkommen';
$program = array(
    array('key' => 'annual_meeting', 'type' => 'annual_meeting', 'title' => 'Själva årsmötet', 'day' => 1, 'start' => '10:00', 'end' => '12:00', 'requires_registration' => 1, 'visible' => 1),
    array('key' => 'dinner', 'type' => 'dinner', 'title' => 'Middag', 'day' => 1, 'start' => '18:00', 'end' => '20:00', 'requires_registration' => 1, 'visible' => 1),
);
update_post_meta(10, '_ssf_am_program', $program);
update_post_meta(10, '_ssf_am_invitation', array('title' => 'Kallelse', 'text' => 'Kallelsetext', 'visible' => 1, 'pdf_id' => 0));
update_post_meta(10, '_ssf_am_documents', array(array('attachment_id' => 123, 'title' => 'Handling 1', 'type' => 'agenda', 'visible' => 1, 'order' => 0)));

$render = static function (array $meeting, array $state) use ($module, $service, $frontend, $calendar): string {
    $meeting_post = get_post(10);
    $registration_state = $state;
    $choices = $state['choices'];
    $choice_states = $state['choice_states'];
    $calendar = ! empty($meeting['modules']['calendar']) ? array('outlook' => '#outlook', 'google' => '#google', 'apple' => '#apple', 'ics' => '#ics') : array();
    $motion = array('state' => 'closed');
    $page_title = 'SSF:s årsmöteshelg 2026';
    $location_summary = 'Simrishamn';
    $location_address = 'Kajen 1';
    $maps_url = 'https://maps.example.test/';
    $contact_url = 'https://ssfb.se/dev/kontakta-oss/';
    $include = function () use ($meeting, $meeting_post, $registration_state, $choices, $choice_states, $calendar, $motion, $page_title, $location_summary, $location_address, $maps_url, $contact_url): void {
        include SSF_MEMBER_PORTAL_PATH . 'templates/annual-meetings/meeting.php';
    };
    ob_start();
    $include->bindTo($frontend, get_class($frontend))();
    return ob_get_clean();
};

$meeting = $module->data(10);
check($meeting['registration_mode'] === 'closed' && $meeting['registration_visible'] && ! $meeting['registration_mode_explicit'], 'Legacy disabled registration was not preserved.');
check($meeting['modules']['dinner'], 'Legacy program dinner disappeared before an explicit visibility save.');
$new_post = new WP_Post(11);
$new_post->post_status = 'auto-draft';
$GLOBALS['meeting_posts'][11] = $new_post;
check(! $module->data(11)['registration_visible'] && ! $module->data(11)['modules']['motions'] && ! $module->data(11)['modules']['contact'], 'New meeting did not default to a quiet preview.');
$legacy_state = $service->registration_state($meeting, get_post(10));
check($legacy_state['status'] === 'disabled', 'Legacy disabled state changed.');
update_post_meta(10, '_ssf_am_registration_mode', 'hidden');
check(! $module->data(10)['registration_visible'], 'Previously saved hidden mode became visible.');
delete_post_meta(10, '_ssf_am_registration_mode');
update_post_meta(10, '_ssf_am_registration_open', 1);
$meeting = $module->data(10);
check($meeting['registration_mode'] === 'open' && ! $meeting['registration_mode_explicit'], 'Legacy open mode was not preserved.');
check($service->registration_state($meeting, get_post(10))['can_register'], 'Legacy open registration stopped working.');

$_POST = array(
    'ssf_member_portal_meeting_nonce' => 'valid',
    'ssf_meeting_year' => '2026',
    'ssf_meeting_start_date' => '2026-10-18',
    'ssf_meeting_location' => 'Simrishamn',
    'ssf_meeting_modules' => array('meeting' => 1, 'calendar' => 1),
    'ssf_meeting_invitation' => array('title' => 'Kallelse', 'text' => 'Kallelsetext'),
    'ssf_meeting_documents' => array(array('attachment_id' => 123, 'title' => 'Handling 1', 'type' => 'agenda', 'visible' => 1)),
    'ssf_meeting_program' => $program,
    'ssf_meeting_active' => '1',
    'ssf_meeting_registration_status' => 'open',
    'ssf_meeting_advance_notice' => array('visible' => 1, 'title' => 'Mer information kommer', 'text' => 'Vi publicerar mer information om årsmöteshelgen här löpande.'),
);
$module->save(10, get_post(10));
$meeting = $module->data(10);
check($meeting['registration_mode'] === 'open' && ! $meeting['registration_visible'] && ! $meeting['registration_open'], 'Registration visibility and status were not kept separate.');
check(! $meeting['modules']['day2'] && ! $meeting['modules']['dinner'] && ! $meeting['modules']['motions'] && ! $meeting['modules']['documents'] && ! $meeting['modules']['invitation'], 'Hidden component flags did not persist.');
$_POST['ssf_meeting_modules']['dinner'] = 1;
$module->save(10, get_post(10));
$meeting = $module->data(10);
check(! array_filter($module->registration_choices($meeting), static fn (array $choice): bool => 'dinner' === ($choice['key'] ?? '')), 'Dinner remained registrable while the program was hidden.');
unset($_POST['ssf_meeting_modules']['dinner']);
$module->save(10, get_post(10));
$meeting = $module->data(10);
$hidden_state = $service->registration_state($meeting, get_post(10));
check(! $hidden_state['can_register'], 'Hidden mode allowed registration.');
check($frontend->registration_shortcode() === '', 'Direct registration page exposed hidden registration content.');
$hidden_html = $render($meeting, $hidden_state);
foreach (array('Simrishamn', 'Kajen 1', 'Vägbeskrivning', 'Lägg till i kalender', 'Varmt välkommen') as $required) {
    check(str_contains($hidden_html, $required), 'Hidden components removed core meeting information: ' . $required);
}
foreach (array('ssf-am-registration', 'Gå till anmälan', 'Anmälan är inte', 'Anmälan är stängd', 'Avstängd', 'Anmälan krävs', 'Anmäl gärna', 'ssf-am-program', 'Kallelsetext', 'ssf-am-motion-row', 'ssf-am-document-grid', 'Handling 1', 'ssf-am-contact', 'href="#ssf-am-') as $forbidden) {
    check(! str_contains($hidden_html, $forbidden), 'Hidden component leaked UI: ' . $forbidden);
}
check(substr_count($hidden_html, '<h2>Mer information kommer</h2>') === 1, 'The shared advance notice was missing or duplicated.');
check(! str_contains($calendar->event_data($meeting)['description'], 'Anmäl'), 'Hidden mode leaked registration into calendar text.');

$notice_text = $meeting['advance_notice']['text'];
$_POST['ssf_meeting_advance_notice']['visible'] = 0;
$module->save(10, get_post(10));
$meeting = $module->data(10);
check($meeting['advance_notice']['text'] === $notice_text, 'Turning off the advance notice deleted its text.');
check(! str_contains($render($meeting, $service->registration_state($meeting, get_post(10))), 'Mer information kommer'), 'Disabled advance notice was still rendered.');
$_POST['ssf_meeting_advance_notice']['visible'] = 1;
$_POST['ssf_meeting_advance_notice']['title'] = 'Snart klart';
$_POST['ssf_meeting_advance_notice']['text'] = 'Vi återkommer med detaljer.';
$_POST['ssf_meeting_modules']['day2'] = 1;
$module->save(10, get_post(10));
$meeting = $module->data(10);
$program_state = $service->registration_state($meeting, get_post(10));
$program_html = $render($meeting, $program_state);
check(str_contains($program_html, 'Själva årsmötet') && ! str_contains($program_html, '<h4>Middag</h4>'), 'Program/dinner visibility was not independent.');
check(! isset($program_state['choice_states']['dinner']), 'Hidden dinner remained in the registration choices.');
check(substr_count($program_html, '<h2>Snart klart</h2>') === 1 && str_contains($program_html, 'Vi återkommer med detaljer.'), 'Editable advance notice did not persist.');
$_POST['ssf_meeting_modules']['dinner'] = 1;
$module->save(10, get_post(10));
$meeting = $module->data(10);
check(str_contains($render($meeting, $service->registration_state($meeting, get_post(10))), '<h4>Middag</h4>'), 'Dinner did not reappear when enabled.');
check(isset($service->registration_state($meeting, get_post(10))['choice_states']['dinner']), 'Visible dinner did not return to the registration choices.');

$_POST['ssf_meeting_modules']['invitation'] = 1;
$_POST['ssf_meeting_modules']['motions'] = 1;
$_POST['ssf_meeting_modules']['documents'] = 1;
$_POST['ssf_meeting_modules']['contact'] = 1;
$_POST['ssf_meeting_registration_visible'] = '1';
$module->save(10, get_post(10));
$meeting = $module->data(10);
$open_state = $service->registration_state($meeting, get_post(10));
check($meeting['registration_mode'] === 'open' && $meeting['registration_visible'] && $meeting['registration_open'] && $open_state['can_register'], 'Visible open registration did not persist or enable registration.');
$open_html = $render($meeting, $open_state);
foreach (array('ssf-am-registration', 'Gå till anmälan', 'Kallelsetext', 'ssf-am-motion-row', 'Handling 1', 'ssf-am-contact') as $required) {
    check(str_contains($open_html, $required), 'Visible component did not reappear: ' . $required);
}
$form_html = $frontend->registration_shortcode();
check(str_contains($form_html, 'ssf-am-form') && str_contains($form_html, 'ssf_member_portal_submit_meeting_registration'), 'Open mode did not render the existing registration form.');

$_POST['ssf_meeting_registration_status'] = 'closed';
$_POST['ssf_meeting_registration_closes_on'] = '2026-10-17';
$module->save(10, get_post(10));
$meeting = $module->data(10);
$closed_state = $service->registration_state($meeting, get_post(10));
check($meeting['registration_mode'] === 'closed' && $meeting['registration_visible'] && ! $meeting['registration_open'] && ! $closed_state['can_register'], 'Visible closed mode did not block registration.');
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
check(str_contains($admin_html, 'name="ssf_meeting_registration_visible" value="1" checked="checked"') && str_contains($admin_html, 'name="ssf_meeting_registration_status" value="open"') && str_contains($admin_html, 'name="ssf_meeting_registration_status" value="closed" checked="checked"'), 'Admin did not separate visibility from registration status.');
check(str_contains($admin_html, 'name="ssf_meeting_advance_notice[visible]"') && str_contains($admin_html, 'name="ssf_meeting_modules[dinner]"') && str_contains($admin_html, 'name="ssf_meeting_modules[contact]"'), 'Admin did not expose component visibility controls.');

update_post_meta(10, '_ssf_am_modules', array('meeting' => 1, 'day2' => 0, 'dinner' => 0, 'calendar' => 1));
$meeting = $module->data(10);
check($meeting['registration_visible'] && ! $module->registration_visible($meeting), 'Registration without choices was still publicly visible.');
$no_choices_state = $service->registration_state($meeting, get_post(10));
$no_choices_html = $render($meeting, $no_choices_state);
check(! str_contains($no_choices_html, 'id="ssf-am-registration"') && ! str_contains($no_choices_html, 'Anmälan är stängd'), 'Registration CTA or status remained without any registration choice.');
check($frontend->registration_shortcode() === '', 'Registration form remained visible without any registration choice.');

echo "PASS: independent visibility, advance notice, registration, legacy data and frontend.\n";
