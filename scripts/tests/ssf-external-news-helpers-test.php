<?php
/** Isolated behavior tests for the canonical external-news invitation helpers. */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);

final class WP_Error
{
    public function __construct(private string $code, private string $message, private $data = null) {}
    public function get_error_message(): string { return $this->message; }
}

class WP_Post
{
    public function __construct(public int $ID, public string $post_status = 'draft', public string $post_type = 'post', public string $post_title = '') {}
}

final class SSF_Email_Template
{
    public static function send(string $recipient, string $subject, string $template, array $data): bool
    {
        $GLOBALS['ssf_test_mail'][] = compact('recipient', 'subject', 'template', 'data');
        return true;
    }
}

$GLOBALS['ssf_test_meta'] = array();
$GLOBALS['ssf_test_posts'] = array();
$GLOBALS['ssf_test_mail'] = array();
$GLOBALS['ssf_test_next_id'] = 100;

function add_action(...$args): void {}
function add_filter(...$args): void {}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_email(string $value): string { return filter_var(trim($value), FILTER_SANITIZE_EMAIL) ?: ''; }
function is_email(string $value): bool { return false !== filter_var($value, FILTER_VALIDATE_EMAIL); }
function current_time(string $type): string { return '2026-11-16 12:00:00'; }
function get_current_user_id(): int { return 7; }
function home_url(string $path = ''): string { return 'https://example.test' . $path; }
function wp_date(string $format, int $timestamp): string { return gmdate($format, $timestamp); }
function get_post(int $id): ?WP_Post { return $GLOBALS['ssf_test_posts'][$id] ?? null; }
function get_post_meta(int $id, string $key, bool $single = false) { return $GLOBALS['ssf_test_meta'][$id][$key] ?? ''; }
function update_post_meta(int $id, string $key, $value): bool { $GLOBALS['ssf_test_meta'][$id][$key] = $value; return true; }
function wp_insert_post(array $data, bool $wp_error = false)
{
    $id = $GLOBALS['ssf_test_next_id']++;
    $GLOBALS['ssf_test_posts'][$id] = new WP_Post($id, (string) $data['post_status'], (string) $data['post_type'], (string) $data['post_title']);
    return $id;
}
function get_posts(array $args): array
{
    $result = array();
    foreach ($GLOBALS['ssf_test_posts'] as $post) {
        if (isset($args['post_type']) && $post->post_type !== $args['post_type']) { continue; }
        if (isset($args['post_status']) && ! in_array($post->post_status, (array) $args['post_status'], true)) { continue; }
        if (isset($args['meta_key']) && ! array_key_exists($args['meta_key'], $GLOBALS['ssf_test_meta'][$post->ID] ?? array())) { continue; }
        if (isset($args['meta_value']) && get_post_meta($post->ID, (string) $args['meta_key'], true) !== $args['meta_value']) { continue; }
        $result[] = $post;
    }
    return array_slice($result, 0, (int) ($args['posts_per_page'] ?? count($result)));
}

require dirname(__DIR__, 2) . '/wp-content/plugins/ssf-site-customizations/includes/external-news-authors.php';

function assert_true(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$post = new WP_Post(10, 'draft', 'post', 'Testutkast');
$GLOBALS['ssf_test_posts'][10] = $post;
$old_token = ssf_external_news_set_token(10, time() + DAY_IN_SECONDS);
assert_true(1 === preg_match('/^[A-Za-z0-9_-]{43}$/', $old_token), 'token ska vara 32 bytes i Base64URL-format');
assert_true(hash('sha256', $old_token) === get_post_meta(10, SSF_EXTERNAL_NEWS_HASH, true), 'endast token-hash ska lagras');
assert_true(ssf_external_news_find($old_token) instanceof WP_Post, 'aktiv token ska hitta sitt utkast');

$new_token = ssf_external_news_regenerate_link(10);
assert_true(is_string($new_token) && $new_token !== $old_token, 'rotation ska skapa en ny token');
assert_true(null === ssf_external_news_find($old_token), 'rotation ska omedelbart ogiltigförklara föregående token');
assert_true(ssf_external_news_find($new_token) instanceof WP_Post, 'roterad token ska fungera');
assert_true(true === ssf_external_news_revoke_link(10), 'återkallelse ska lyckas');
assert_true(null === ssf_external_news_find($new_token), 'återkallad token ska vara ogiltig');
assert_true('Återkallad' === ssf_external_news_status($post), 'återkallat utkast ska ha svensk status');

$created = ssf_external_news_create_invitation('writer@example.test', 'Arbetsrubrik', time() + 30 * DAY_IN_SECONDS, 7);
assert_true(is_array($created) && isset($created['post_id']), 'create ska returnera post-id');
$created_id = (int) $created['post_id'];
$created_hash = (string) get_post_meta($created_id, SSF_EXTERNAL_NEWS_HASH, true);
assert_true(64 === strlen($created_hash), 'create ska lagra SHA-256-hash');
assert_true('writer@example.test' === get_post_meta($created_id, SSF_EXTERNAL_NEWS_RECIPIENT, true), 'create ska lagra mottagaren');
assert_true(1 === count($GLOBALS['ssf_test_mail']), 'create ska skicka ett inbjudningsmail');
$audit = (array) get_post_meta($created_id, SSF_EXTERNAL_NEWS_AUDIT, true);
assert_true(array('link_created', 'invitation_sent') === array_column($audit, 'event'), 'create ska audit-logga länk och utskick');
assert_true(! array_key_exists('_ssf_external_news_token', $GLOBALS['ssf_test_meta'][$created_id]), 'rå token får inte lagras som metadata');

$resent = ssf_external_news_resend_invitation($created_id);
assert_true(true === $resent, 'resend ska lyckas med lagrad mottagare');
assert_true($created_hash !== get_post_meta($created_id, SSF_EXTERNAL_NEWS_HASH, true), 'resend ska rotera token-hashen');
assert_true(2 === count($GLOBALS['ssf_test_mail']), 'resend ska skicka ett nytt mail');

echo "PASS: create, hash-only token, rotation, resend och revoke.\n";
