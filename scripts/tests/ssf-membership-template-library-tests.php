<?php
/** Targeted no-WordPress tests for template versioning, validation and eligibility. */
define('ABSPATH', __DIR__ . '/');
$options = array(); $caps = array('manage_options' => true);
function get_option(string $key, $default = false) { global $options; return $options[$key] ?? $default; }
function update_option(string $key, $value, bool $autoload = false): bool { global $options; $changed = ($options[$key] ?? null) !== $value; $options[$key] = $value; return $changed; }
function current_user_can(string $capability): bool { global $caps; return ! empty($caps[$capability]); }
function current_time(string $type): string { static $tick = 0; return '2026-09-26 12:00:' . str_pad((string) $tick++, 2, '0', STR_PAD_LEFT); }
function wp_parse_args(array $value, array $defaults): array { return array_merge($defaults, $value); }
function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $value)); }
function absint($value): int { return abs((int) $value); }
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value): string { return trim(strip_tags((string) $value)); }
function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000001'; }
function get_posts(array $args): array { return array(); }

require __DIR__ . '/../../wp-content/plugins/ssf-medlemsprocess/includes/class-ssf-medlemsprocess-inspection-template.php';
require __DIR__ . '/../../wp-content/plugins/ssf-medlemsprocess/includes/class-ssf-medlemsprocess-inspection.php';

$failures = array();
function verify_template(bool $condition, string $message): void { global $failures; if (! $condition) { $failures[] = $message; } }

SSF_Medlemsprocess_Inspection::seed_default_template();
$official = SSF_Medlemsprocess_Inspection_Template::default_for_type('physical_membership_inspection');
verify_template('membership-vessel-inspection@1.0' === ($official['id'] ?? ''), 'Official 1.0 was not resolved as default.');
verify_template(SSF_Medlemsprocess_Inspection_Template::duplicate($official['id'], '1.1'), 'Published 1.0 could not be duplicated.');
$draft = SSF_Medlemsprocess_Inspection_Template::all()['membership-vessel-inspection@1.1'] ?? array();
verify_template('draft' === ($draft['status'] ?? '') && empty($draft['is_default']), 'Duplicate is not a non-default draft.');

$question_index = null;
foreach ($draft['blocks'] as $index => $block) { if ('question' === $block['type'] && '7b' === $block['question_id']) { $question_index = $index; break; } }
verify_template(null !== $question_index, 'Question 7B is missing from canonical blocks.');
$draft['blocks'][$question_index]['help'] = 'Verifiera riggen ombord.';
$draft['blocks'][$question_index]['comment_rule'] = 'always';
$draft['blocks'][$question_index]['photo_rule'] = 'required';
$result = SSF_Medlemsprocess_Inspection_Template::save_draft($draft['id'], $draft, $draft['updated_at']);
verify_template(! empty($result['ok']), 'Draft save failed.');
verify_template(! empty(SSF_Medlemsprocess_Inspection_Template::publish($draft['id'])['ok']), 'Valid draft did not publish.');

$published = SSF_Medlemsprocess_Inspection_Template::all()[$draft['id']];
$published['name'] = 'Tampered';
$immutable = SSF_Medlemsprocess_Inspection_Template::save_draft($draft['id'], $published, $published['updated_at']);
verify_template(empty($immutable['ok']), 'Published template was mutable through the draft endpoint.');
verify_template(empty(SSF_Medlemsprocess_Inspection_Template::resolve_eligible('missing@9.9', 'physical_membership_inspection')), 'Crafted unknown template did not fail closed.');

$stored = get_option('ssf_membership_inspection_templates', array());
$stored[$draft['id']]['status'] = 'archived'; update_option('ssf_membership_inspection_templates', $stored, false);
verify_template(empty(SSF_Medlemsprocess_Inspection_Template::resolve_eligible($draft['id'], 'physical_membership_inspection')), 'Archived template remained eligible.');

if ($failures) { fwrite(STDERR, "FAIL: " . implode("\nFAIL: ", $failures) . "\n"); exit(1); }
echo "PASS: versioned inspection template library contracts.\n";
