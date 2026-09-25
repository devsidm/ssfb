<?php
/** Isolated behavioural regression tests for the SSF access-control MU plugin. */
define('ABSPATH', __DIR__);

final class WP_User
{
    public int $ID;
    public array $caps;

    public function __construct(int $id, array $caps = array())
    {
        $this->ID = $id;
        $this->caps = $caps;
    }
}

$users = array(
    1 => new WP_User(1, array('manage_options' => true)),
    2 => new WP_User(2),
    3 => new WP_User(3),
);
$meta = array();
$options = array();
$cases = array();

function add_filter(...$args): void {}
function add_action(...$args): void {}
function get_role(string $role) { return null; }
function sanitize_key($value): string { return strtolower(preg_replace('/[^a-z0-9_-]/', '', (string) $value)); }
function get_user_meta(int $id, string $key, bool $single = true) { global $meta; return $meta[$id][$key] ?? ''; }
function update_user_meta(int $id, string $key, $value): void { global $meta; $meta[$id][$key] = $value; }
function get_option(string $key, $default = false) { global $options; return $options[$key] ?? $default; }
function update_option(string $key, $value, bool $autoload = false): void { global $options; $options[$key] = $value; }
function user_can(int $id, string $cap): bool
{
    global $users;
    $user = $users[$id] ?? null;
    if (! $user) { return false; }
    $allcaps = SSF_Access_Control::grant_capabilities($user->caps, array($cap), array(), $user);
    return ! empty($allcaps[$cap]);
}
function current_user_can(string $cap): bool { return user_can(1, $cap); }
function get_users(array $args): array { global $users; return array_keys($users); }
function get_userdata(int $id) { global $users; return $users[$id] ?? false; }
function post_type_exists(string $type): bool { return 'ssf_application' === $type; }
function get_posts(array $args): array
{
    global $cases;
    return array_values(array_filter($cases, static fn ($case): bool => $case->assigned === (int) $args['meta_value']));
}
function get_post_meta(int $id, string $key, bool $single = true)
{
    global $cases;
    foreach ($cases as $case) { if ($case->ID === $id) { return $case->meta[$key] ?? ''; } }
    return '';
}
function check(bool $condition, string $message): void
{
    if (! $condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

require __DIR__ . '/../../wp-content/mu-plugins/ssf-access-control.php';
require __DIR__ . '/../../wp-content/mu-plugins/ssf-user-admin.php';
class SSF_Medlemsprocess_Application { public const POST_TYPE = 'ssf_application'; }
require __DIR__ . '/../../wp-content/plugins/ssf-medlemsprocess/includes/class-ssf-medlemsprocess-admin.php';
$admin = (new ReflectionClass('SSF_Medlemsprocess_Admin'))->newInstanceWithoutConstructor();
$handler_ids = (new ReflectionClass('SSF_Medlemsprocess_Admin'))->getMethod('handler_user_ids');

check(! SSF_Access_Control::can_handle_membership(2), 'User without group cannot handle applications');
check(SSF_Access_Control::can_handle_membership(1), 'Administrator can handle applications');
SSF_Access_Control::save_groups(2, array('ansokningar'), 1);
check(SSF_Access_Control::can_handle_membership(2), 'Applications group grants handler capability');
check(user_can(2, 'ssf_decide_applications'), 'Applications group grants decision capability');
check(in_array(2, $handler_ids->invoke($admin, 0), true), 'Applications user appears in handler dropdown');
check(in_array(1, $handler_ids->invoke($admin, 0), true), 'Administrator appears in handler dropdown');
check(! SSF_Access_Control::can_handle_membership(3), 'Unprivileged user remains ineligible');
check(! in_array(3, $handler_ids->invoke($admin, 0), true), 'Unprivileged user is not an eligible handler');
check(count($options[SSF_Access_Control::AUDIT_OPTION]) === 1, 'Group change audited once');
SSF_Access_Control::save_groups(2, array('ansokningar'), 1);
check(count($options[SSF_Access_Control::AUDIT_OPTION]) === 1, 'Unchanged group save does not duplicate audit');
SSF_Access_Control::save_groups(2, array(), 1);
check(! SSF_Access_Control::can_handle_membership(2), 'Removing group removes future eligibility');
check(! in_array(2, $handler_ids->invoke($admin, 0), true), 'Former handler is not eligible for new assignment');
check(in_array(2, $handler_ids->invoke($admin, 2), true), 'Historical handler remains visible on existing case');

$cases = array(
    (object) array('ID' => 11, 'assigned' => 2, 'meta' => array('_ssf_process_status' => 'review')),
    (object) array('ID' => 12, 'assigned' => 2, 'meta' => array('_ssf_process_status' => 'archived')),
    (object) array('ID' => 13, 'assigned' => 3, 'meta' => array('_ssf_process_status' => 'review')),
);
check(count(SSF_User_Admin::open_assignments(2)) === 1, 'Only active assigned case blocks deactivation');
check(count(SSF_User_Admin::open_assignments(3)) === 1, 'Other user assignment remains separate');
SSF_Access_Control::set_active(2, false, 1);
check(! SSF_Access_Control::is_active(2), 'Deactivation persists');
check(! user_can(2, 'ssf_review_applications'), 'Inactive user has no SSF capability');
$users[2]->caps['edit_posts'] = true;
check(! user_can(2, 'edit_posts'), 'Inactive user cannot retain unrelated WordPress role capabilities');
SSF_Access_Control::set_active(2, true, 1);
check(SSF_Access_Control::is_active(2), 'Reactivation persists');
check(user_can(2, 'edit_posts'), 'Reactivation restores original WordPress role capabilities');
SSF_Access_Control::set_active(1, false, 1);
check(SSF_Access_Control::is_active(1), 'Administrator cannot deactivate self');
check(count($options[SSF_Access_Control::AUDIT_OPTION]) === 4, 'Group, deactivation and reactivation events audited');
check($options[SSF_Access_Control::AUDIT_OPTION][0]['actor_user_id'] === 1, 'Audit records actor');
check($options[SSF_Access_Control::AUDIT_OPTION][0]['target_user_id'] === 2, 'Audit records target');
echo "PASS: access-control runtime behaviour and open-assignment filtering.\n";
