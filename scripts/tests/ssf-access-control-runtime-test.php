<?php
/** Isolated behavioural regression tests for the SSF access-control MU plugin. */
define('ABSPATH', __DIR__);

final class WP_User
{
    public int $ID;
    public array $caps;
    public array $roles;
    public string $display_name;
    public string $user_login;

    public function __construct(int $id, array $caps = array())
    {
        $this->ID = $id;
        $this->caps = $caps;
        $this->roles = ! empty($caps['manage_options']) ? array('administrator') : array('subscriber');
        $this->display_name = 'Test User ' . $id;
        $this->user_login = 'test' . $id;
    }
}
final class WP_Error { public string $code; public string $message; public function __construct(string $code, string $message) { $this->code = $code; $this->message = $message; } }
final class WP_Session_Tokens
{
    public static array $destroyed = array();
    private int $user_id;
    private function __construct(int $user_id) { $this->user_id = $user_id; }
    public static function get_instance(int $user_id): self { return new self($user_id); }
    public function destroy_all(): void { self::$destroyed[] = $this->user_id; }
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
function user_can($subject, string $cap): bool
{
    global $users;
    $user = $subject instanceof WP_User ? $subject : ($users[(int) $subject] ?? null);
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
SSF_Access_Control::save_groups(2, array('ansokningar', 'nyheter'), 1);
SSF_Access_Control::set_active(2, false, 1);
check(! SSF_Access_Control::is_active(2), 'Deactivation persists');
check(SSF_Access_Control::status(2) === SSF_Access_Control::STATUS_TERMINATED, 'Disconnected user has terminated status');
check(SSF_Access_Control::user_groups(2) === array(), 'Disconnect clears active groups');
check(count(SSF_Access_Control::previous_groups(2)) === 2, 'Disconnect preserves previous groups as read-only history');
check(WP_Session_Tokens::$destroyed === array(2), 'Disconnect revokes all sessions immediately');
check(! user_can(2, 'ssf_review_applications'), 'Inactive user has no SSF capability');
$users[2]->caps['edit_posts'] = true;
check(! user_can(2, 'edit_posts'), 'Inactive user cannot retain unrelated WordPress role capabilities');
$denied = SSF_Access_Control::block_inactive_login($users[2], 'test2', 'password');
check($denied instanceof WP_Error && str_contains($denied->message, 'avslutat'), 'Terminated password login is denied with friendly message');
SSF_Access_Control::reactivate(2, array('nyheter'), 1);
check(SSF_Access_Control::is_active(2), 'Reactivation persists');
check(user_can(2, 'edit_posts'), 'Reactivation restores original WordPress role capabilities');
check(SSF_Access_Control::user_groups(2) === array('nyheter'), 'Reactivation grants only explicitly selected groups');
check(! user_can(2, 'ssf_review_applications'), 'Old application permission is not silently restored');
SSF_Access_Control::set_active(1, false, 1);
check(SSF_Access_Control::is_active(1), 'Administrator cannot deactivate self');
SSF_Access_Control::mark_invited(3, 1);
check(SSF_Access_Control::status(3) === SSF_Access_Control::STATUS_INVITED, 'Invitation has explicit invited status');
check(! SSF_Access_Control::is_active(3), 'Invited account has no active access');
SSF_Access_Control::activate_invitation(3);
check(SSF_Access_Control::is_active(3), 'Invitation activation enables account');
check(count($options[SSF_Access_Control::AUDIT_OPTION]) === 6, 'Permission, lifecycle and invitation events audited');
check($options[SSF_Access_Control::AUDIT_OPTION][0]['actor_user_id'] === 1, 'Audit records actor');
check($options[SSF_Access_Control::AUDIT_OPTION][0]['target_user_id'] === 2, 'Audit records target');
check($options[SSF_Access_Control::AUDIT_OPTION][0]['actor_name'] === 'Test User 1', 'Audit snapshots actor name');
echo "PASS: access-control runtime behaviour and open-assignment filtering.\n";
