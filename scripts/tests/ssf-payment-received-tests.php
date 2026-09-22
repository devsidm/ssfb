<?php
/** Standalone contract tests for the internal payment status. */
define('ABSPATH', __DIR__);
$meta = array();
function get_post_meta($id, $key, $single = false) { global $meta; return $meta[$id][$key] ?? ('_ssf_application_history' === $key ? array() : ''); }
function update_post_meta($id, $key, $value) { global $meta; $meta[$id][$key] = $value; return true; }
function current_time($format) { return '2026-09-22 12:00:00'; }
function wp_date($format) { return '2026-09-22'; }
function get_current_user_id() { return 7; }
function is_admin() { return true; }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
function sanitize_textarea_field($value) { return (string) $value; }
require __DIR__ . '/../../wp-content/plugins/ssf-medlemsprocess/includes/class-ssf-medlemsprocess-application.php';
function check($condition, $message) { if (! $condition) { throw new RuntimeException($message); } }

$id = 10;
update_post_meta($id, '_ssf_process_status', 'under_review');
update_post_meta($id, '_ssf_membership_status', 'not_member');
check(! SSF_Medlemsprocess_Application::payment_received($id), 'Payment must default to false.');
check(SSF_Medlemsprocess_Application::set_payment_received($id, true, 'test'), 'Authorized handler could not mark payment received.');
check(SSF_Medlemsprocess_Application::payment_received($id), 'Received payment was not persisted.');
check(SSF_Medlemsprocess_Application::payment_received_date($id) === '2026-09-22', 'Received payment must default to today.');
$history_count = count((array) get_post_meta($id, '_ssf_application_history', true));
check(! SSF_Medlemsprocess_Application::set_payment_received($id, true, 'test'), 'Unchanged payment status must not create an update.');
check($history_count === count((array) get_post_meta($id, '_ssf_application_history', true)), 'Unchanged payment status created duplicate history.');
check(SSF_Medlemsprocess_Application::set_payment_received($id, true, 'test', '2026-09-21'), 'Manual payment date could not be saved.');
check(SSF_Medlemsprocess_Application::payment_received_date($id) === '2026-09-21', 'Manual payment date was not persisted.');
check(SSF_Medlemsprocess_Application::set_payment_received($id, false, 'test'), 'Authorized handler could not clear payment received.');
check(! SSF_Medlemsprocess_Application::payment_received($id), 'Cleared payment status was not persisted.');
check(SSF_Medlemsprocess_Application::payment_received_date($id) === '2026-09-21', 'Clearing payment silently deleted historical date.');
check(SSF_Medlemsprocess_Application::status($id) === 'under_review', 'Payment changed application workflow status.');
check(SSF_Medlemsprocess_Application::membership_status($id) === 'not_member', 'Payment changed membership status.');
$history = (array) get_post_meta($id, '_ssf_application_history', true);
check('Betalning mottagen – 2026-09-22.' === $history[0]['message'], 'Received payment history missing.');
check('Betaldatum ändrat till 2026-09-21.' === $history[1]['message'], 'Manual payment date history missing.');
check('Betalning markerad som ej mottagen.' === $history[2]['message'], 'Cleared payment history missing.');
echo "PASS: internal payment default, persistence, history, no duplicate and workflow isolation.\n";
