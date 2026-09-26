<?php
/** Targeted contracts for the integrated membership inspection. No WordPress writes. */
define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../../wp-content/plugins/ssf-medlemsprocess/includes/class-ssf-medlemsprocess-inspection.php';

$failures = array();
function check(bool $condition, string $message): void { global $failures; if (! $condition) { $failures[] = $message; } }

$template = SSF_Medlemsprocess_Inspection::official_template();
$questions = SSF_Medlemsprocess_Inspection::questions($template);
check('Medlemsprövning av fartyg' === $template['name'], 'Official template name changed.');
check('1.0' === $template['version'] && 'published' === $template['status'], 'Official template must be published version 1.0.');
check('Fysisk inspektion ombord' === $template['type'], 'Inspection type changed.');
check(array_keys($questions) === array(1, 2, 3, 4, 5, 6, '7a', '7b', '7c', 8, 9, 10), 'Questions 1–10/7A–7C are not exact or ordered.');

$expected = array(
    '1' => array('Ja, segelfartyg', 'Ja, segelfartyg med hjälpmaskin', 'Delvis, rigg/segel saknas men återställningsplan finns', 'Nej', 'Går ej att bedöma'),
    '4' => array('Ja, båda måtten är uppfyllda och styrkta', 'Ja, enligt uppgift men dokumentation saknas', 'Nej, ett av måtten saknas', 'Nej, båda måtten saknas', 'Går ej att bedöma'),
    '7b' => array('Ja, stämmer väl', 'Ja, med rimliga förändringar', 'Rigg saknas men återställningsplan finns', 'Delvis, oklart eller ändrat', 'Nej', 'Går ej att bedöma'),
    '10' => array('Godkänn som medlemsfartyg', 'Godkänn efter mindre komplettering', 'Skicka till särskild prövning', 'Avvakta – underlaget är för svagt', 'Avslå som medlemsfartyg', 'Föreslå stödmedlemskap i stället'),
);
foreach ($expected as $id => $options) { check($questions[$id]['options'] === $options, 'Options changed for question ' . $id . '.'); }
check($questions['4']['context'] === array('main_deck_length', 'beam'), 'Question 4 lost dimensions.');
check($questions['7a']['context'] === array('hull_type', 'material', 'build_year', 'build_place'), 'Question 7A lost hull context.');
check($questions['7b']['context'] === array('original_rig', 'current_rig', 'masts', 'sail_area'), 'Question 7B lost rig context.');
check(in_array('Ja, annat', $questions['9']['comment_required_for'], true), 'Question 9 other must require a comment.');
check(str_contains($template['disclaimer'], 'inte myndighetsbesiktning'), 'Applicant disclaimer is missing.');

$root = __DIR__ . '/../../wp-content/plugins/ssf-medlemsprocess/';
$model = file_get_contents($root . 'includes/class-ssf-medlemsprocess-inspection.php');
$inspector = file_get_contents($root . 'includes/class-ssf-medlemsprocess-inspector.php');
$application = file_get_contents($root . 'includes/class-ssf-medlemsprocess-application.php');
$public = file_get_contents($root . 'includes/class-ssf-medlemsprocess-public.php');
$ui = file_get_contents($root . 'templates/inspector-portal.php');
$js = file_get_contents($root . 'assets/js/ssf-inspector-portal.js');
check(str_contains($application, "'completed' => array('follow_up', 'final_review')"), 'Follow-up is still mandatory.');
check(str_contains($application, "'booked' => array('in_progress', 'completed')") && str_contains($application, "'in_progress' => array('completed')"), 'Working inspection state is missing.');
check(str_contains($model, "'application_snapshot' => self::application_snapshot"), 'Application read-only snapshot is missing.');
check(str_contains($model, 'duplicate_template') && str_contains($model, 'save_draft_template') && str_contains($model, 'publish_template'), 'Immutable template version workflow is missing.');
check(str_contains($model, "\$record['final_snapshot'] = self::snapshot"), 'Immutable final snapshot is missing.');
check(str_contains($model, "'co_inspector_user_id'") && str_contains($model, 'report_hash'), 'Two-inspector confirmation contract is missing.');
check(str_contains($model, "if (empty(\$record['is_test']))"), 'Real side effects are not guarded from test mode.');
check(str_contains($model, "'production' === wp_get_environment_type()"), 'Production test-tool guard is missing.');
check(str_contains($inspector, 'SSF_Access_Control::is_active') && str_contains($inspector, 'can_read($inspection_id'), 'Object-level authorization is missing.');
check(str_contains($inspector, 'find_by_token($token)') && str_contains($public, 'inspection_protocol'), 'Secure applicant protocol integration is missing.');
check(str_contains($ui, 'capture="environment"') && str_contains($ui, 'data-ssf-question'), 'Question-linked mobile camera UI is missing.');
check(str_contains($js, 'indexedDB.open') && str_contains($js, 'operation_id') && str_contains($js, 'canvas.toBlob'), 'Offline/idempotent optimized photo queue is missing.');
check(! str_contains($model, 'set_membership_status'), 'Inspector recommendation must not decide membership.');

if ($failures) { fwrite(STDERR, "FAIL: " . implode("\nFAIL: ", $failures) . "\n"); exit(1); }
echo "PASS: integrated membership inspection template, snapshots, access, photos, two-inspector and test-mode contracts.\n";
