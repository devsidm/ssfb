<?php
/** Isolated historical-reference test for permanent user deletion preparation. */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

final class WP_User
{
    public function __construct(public int $ID, public string $display_name, public string $user_login = '') {}
}
final class WP_Post
{
    public function __construct(public int $ID, public string $post_type, public int $post_author) {}
}
final class SSF_Access_Control
{
    public const AUDIT_OPTION = 'ssf_microsoft_login_permission_audit';
    public const MANAGE_USERS = 'ssf_manage_users';
    public const STATUS_TERMINATED = 'terminated';
    public static function can_manage_users(): bool { return true; }
    public static function status(int $id): string { return 'active'; }
    public static function status_label(int $id): string { return 'Aktiv'; }
    public static function is_active(int $id): bool { return true; }
}

$posts = array(
    101 => new WP_Post(101, 'ssf_application', 2),
    201 => new WP_Post(201, 'ssf_membership_insp', 1),
    301 => new WP_Post(301, 'post', 2),
);
$meta = array(
    101 => array(
        '_ssf_application_history' => array(array('author' => 2, 'actor_if_known' => 2, 'message' => 'Handlagt')),
        '_ssf_assigned_user' => 2,
        '_ssf_inspector_ids' => array(),
    ),
    201 => array(
        '_ssf_membership_inspection' => array(
            'lead_inspector_user_id' => 2,
            'co_inspector_user_id' => 0,
            'audit' => array(array('user_id' => 2, 'type' => 'inspection_created')),
            'confirmations' => array(),
            'photos' => array(array('created_by' => 2, 'id' => 'photo-1')),
        ),
    ),
);
$options = array(
    SSF_Access_Control::AUDIT_OPTION => array(array('target_user_id' => 2, 'actor_user_id' => 1, 'event' => 'permissions_changed')),
);

function add_action(...$args): void {}
function absint($value): int { return abs((int) $value); }
function sanitize_text_field($value): string { return trim((string) $value); }
function post_type_exists(string $type): bool { return in_array($type, array('ssf_application', 'ssf_membership_insp'), true); }
function get_post($id) { global $posts; return $posts[(int) $id] ?? null; }
function get_posts(array $args): array
{
    global $posts;
    $types = (array) ($args['post_type'] ?? 'post');
    $matches = array_filter($posts, static function (WP_Post $post) use ($args, $types): bool {
        return in_array($post->post_type, $types, true)
            && (! isset($args['author']) || $post->post_author === (int) $args['author']);
    });
    return 'ids' === ($args['fields'] ?? '') ? array_keys($matches) : array_values($matches);
}
function get_post_meta(int $id, string $key, bool $single = true) { global $meta; return $meta[$id][$key] ?? ''; }
function update_post_meta(int $id, string $key, $value): void { global $meta; $meta[$id][$key] = $value; }
function get_option(string $key, $default = false) { global $options; return $options[$key] ?? $default; }
function update_option(string $key, $value, bool $autoload = false): void { global $options; $options[$key] = $value; }

function check(bool $condition, string $message): void
{
    if (! $condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

require __DIR__ . '/../../wp-content/mu-plugins/ssf-user-admin.php';

$before_ids = array_keys($posts);
$summary = SSF_User_Admin::reference_summary(2);
check($summary === array('applications' => 1, 'inspections' => 1, 'news' => 1, 'history_events' => 3), 'Reference summary is inaccurate');

$method = (new ReflectionClass('SSF_User_Admin'))->getMethod('snapshot_historical_references');
$method->invoke(null, 2, 'Test Testsson');

check(array_keys($posts) === $before_ids, 'Snapshot preparation removed domain records');
check(($meta[301]['_ssf_historical_author']['display_name'] ?? '') === 'Test Testsson', 'News author snapshot missing');
check(($meta[101]['_ssf_application_history'][0]['actor_name'] ?? '') === 'Test Testsson', 'Application history actor snapshot missing');
check(($meta[201]['_ssf_membership_inspection']['actor_snapshots']['2']['display_name'] ?? '') === 'Test Testsson', 'Inspector snapshot missing');
check(($meta[201]['_ssf_membership_inspection']['photos'][0]['creator_name'] ?? '') === 'Test Testsson', 'Photo creator snapshot missing');
check(($options[SSF_Access_Control::AUDIT_OPTION][0]['target_name'] ?? '') === 'Test Testsson', 'Access audit snapshot missing');

echo "PASS: permanent-delete preparation preserves domain records and snapshots historical actors.\n";
