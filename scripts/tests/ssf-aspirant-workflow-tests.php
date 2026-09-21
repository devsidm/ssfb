<?php
/** Standalone contract tests for the membership and inspection state machines. */
define('ABSPATH', __DIR__);
$meta = array();
function get_post_meta($id, $key, $single = false) { global $meta; return $meta[$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { global $meta; $meta[$id][$key] = $value; return true; }
function delete_post_meta($id, $key) { global $meta; unset($meta[$id][$key]); return true; }
function current_time($format) { return '2026-10-18 12:00:00'; }
function get_current_user_id() { return 7; }
function is_admin() { return true; }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
function sanitize_textarea_field($value) { return (string) $value; }
function wp_timezone() { return new DateTimeZone('Europe/Stockholm'); }
require __DIR__ . '/../../wp-content/plugins/ssf-medlemsprocess/includes/class-ssf-medlemsprocess-application.php';

function check($condition, $message) { if (! $condition) { throw new RuntimeException($message); } }

$id = 1;
update_post_meta($id, '_ssf_process_status', 'under_review');
update_post_meta($id, '_ssf_membership_status', 'not_member');
check(SSF_Medlemsprocess_Application::inspection_status($id) === 'not_planned', 'Inspection must not precede approval.');
check(! SSF_Medlemsprocess_Application::set_inspection_status($id, 'planning'), 'Pre-approval inspection change was accepted.');
check(in_array('approved_aspirant', SSF_Medlemsprocess_Application::allowed_transitions('under_review'), true), 'Direct approval unavailable.');
check(! in_array('inspection_planned', SSF_Medlemsprocess_Application::allowed_transitions('under_review'), true), 'Legacy inspection remains a prerequisite.');
check(! SSF_Medlemsprocess_Application::transition($id, 'approved_aspirant', '', false, 'test', ''), 'Approval without decision date succeeded.');
check(SSF_Medlemsprocess_Application::transition($id, 'approved_aspirant', '', false, 'test', '2026-10-18'), 'Direct approval failed.');
check(SSF_Medlemsprocess_Application::status($id) === 'approved_aspirant', 'Application decision was not retained.');
check(SSF_Medlemsprocess_Application::membership_status($id) === 'aspirant', 'Membership was not set to aspirant.');
check(get_post_meta($id, '_ssf_decision_date') === '2026-10-18', 'DecisionDate mismatch.');
check(get_post_meta($id, '_ssf_aspirant_started_at') === '2026-10-18', 'AspirantStartDate mismatch.');
check(get_post_meta($id, '_ssf_aspirant_review_due_at') === '2027-10-18', 'AspirantReviewDate mismatch.');
foreach (array('planning', 'booked', 'completed', 'follow_up', 'final_review') as $step) {
    check(SSF_Medlemsprocess_Application::set_inspection_status($id, $step, 'test'), "Inspection step $step failed.");
    check(SSF_Medlemsprocess_Application::status($id) === 'approved_aspirant', "Inspection step $step changed ApplicationStatus.");
    check(SSF_Medlemsprocess_Application::membership_status($id) === 'aspirant', "Inspection step $step removed aspirant membership.");
}
check(SSF_Medlemsprocess_Application::membership_status($id) !== 'member_ship', 'Inspection automatically created full membership.');
check(! SSF_Medlemsprocess_Application::set_membership_status($id, 'member_ship', 'test'), 'Final membership skipped explicit follow-up.');
check(SSF_Medlemsprocess_Application::set_membership_status($id, 'follow_up', 'test'), 'Follow-up transition failed.');
check(SSF_Medlemsprocess_Application::set_membership_status($id, 'member_ship', 'test'), 'Explicit final membership transition failed.');
check(count((array) get_post_meta($id, '_ssf_application_history')) >= 9, 'Timeline events were lost.');

$rejected = 2;
update_post_meta($rejected, '_ssf_process_status', 'under_review');
update_post_meta($rejected, '_ssf_membership_status', 'not_member');
check(SSF_Medlemsprocess_Application::transition($rejected, 'rejected', '', false, 'test'), 'Rejection failed.');
check(SSF_Medlemsprocess_Application::membership_status($rejected) === 'not_member', 'Rejected case became aspirant.');

$legacy = 3;
update_post_meta($legacy, '_ssf_process_status', 'inspection_booked');
update_post_meta($legacy, '_ssf_membership_status', 'not_member');
check(SSF_Medlemsprocess_Application::status($legacy) === 'inspection_booked', 'Legacy status unreadable.');
check(SSF_Medlemsprocess_Application::inspection_status($legacy) === 'booked', 'Legacy inspection interpretation failed.');
check(get_post_meta($legacy, '_ssf_inspection_status') === '', 'Legacy case was silently rewritten.');
check(SSF_Medlemsprocess_Application::membership_status($legacy) === 'not_member', 'Legacy case became aspirant automatically.');
check(SSF_Medlemsprocess_Application::transition($legacy, 'approved_aspirant', '', false, 'test', '2026-10-18'), 'Explicit decision on legacy case failed.');
check(SSF_Medlemsprocess_Application::inspection_status($legacy) === 'booked', 'Explicit approval lost existing inspection booking.');
check(SSF_Medlemsprocess_Application::membership_status($legacy) === 'aspirant', 'Explicit legacy approval did not start aspirant year.');

$old_review = 4;
update_post_meta($old_review, '_ssf_process_status', 'awaiting_decision');
update_post_meta($old_review, '_ssf_membership_status', 'not_member');
check(SSF_Medlemsprocess_Application::status($old_review) === 'awaiting_decision', 'Old final-review case unreadable.');
check(SSF_Medlemsprocess_Application::membership_status($old_review) === 'not_member', 'Old final-review case became aspirant without a decision.');

echo "PASS: direct aspirant decision, dates, separate inspection, final decision, rejection, timeline and legacy state.\n";
