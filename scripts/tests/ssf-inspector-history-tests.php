<?php
/** Regression test for malformed application history in the inspector portal. */

define('ABSPATH', __DIR__);

$history_fixture = array();
function get_post_meta($post_id, $key, $single = false)
{
    global $history_fixture;
    return $history_fixture;
}

require __DIR__ . '/../../wp-content/plugins/ssf-medlemsprocess/includes/class-ssf-medlemsprocess-inspector.php';

function check($condition, $message)
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$inspector = (new ReflectionClass('SSF_Medlemsprocess_Inspector'))->newInstanceWithoutConstructor();
$method = new ReflectionMethod($inspector, 'history_for_inspector');

$history_fixture = array(
    '',
    null,
    42,
    array('type' => 'private_note', 'message' => 'Hidden'),
    array('type' => 'private_note', 'public' => true, 'message' => 'Public'),
    array('type' => 'private_note', 'author' => 7, 'message' => 'Own'),
    array('type' => 'private_note', 'audience' => 'inspectors', 'message' => 'Inspectors'),
    array('type' => 'booking', 'message' => 'Booking'),
);
$result = $method->invoke($inspector, 421, 7);
check(array_column($result, 'message') === array('Public', 'Own', 'Inspectors', 'Booking'), 'Visible history changed or malformed entries were retained.');

$history_fixture = '';
check($method->invoke($inspector, 421, 7) === array(), 'Scalar history should be ignored without a fatal error.');

echo "PASS: Inspector history ignores malformed entries and preserves visibility.\n";
