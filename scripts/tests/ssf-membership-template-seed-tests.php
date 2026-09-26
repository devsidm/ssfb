<?php
/** Focused no-write contract test for the persisted membership inspection template. */
define('ABSPATH', __DIR__ . '/');

$options = array();
function get_option(string $key, $default = false) { global $options; return $options[$key] ?? $default; }
function update_option(string $key, $value, bool $autoload = false): bool { global $options; $options[$key] = $value; return true; }

require __DIR__ . '/../../wp-content/plugins/ssf-medlemsprocess/includes/class-ssf-medlemsprocess-inspection.php';

function check_seed(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }

check_seed(SSF_Medlemsprocess_Inspection::seed_default_template(), 'The initial template seed did not write.');
$stored = get_option('ssf_membership_inspection_templates', array());
$key = 'membership-vessel-inspection@1.0';
check_seed(isset($stored[$key]), 'The template entity storage key is missing.');
check_seed('membership-vessel-inspection' === $stored[$key]['slug'], 'The template slug is missing.');
check_seed('1.0' === $stored[$key]['version'] && 'published' === $stored[$key]['status'], 'The seeded template is not published version 1.0.');
check_seed(! SSF_Medlemsprocess_Inspection::seed_default_template(), 'The seed is not idempotent.');
check_seed(1 === count(get_option('ssf_membership_inspection_templates', array())), 'The idempotent seed created a duplicate template.');

echo "PASS: membership inspection template seed is persisted and idempotent.\n";
