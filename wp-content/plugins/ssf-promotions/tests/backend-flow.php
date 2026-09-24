<?php

define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
$GLOBALS['posts'] = array();
$GLOBALS['meta'] = array();
$GLOBALS['options'] = array();
$GLOBALS['clock'] = strtotime('2026-09-20 07:00:00 Europe/Stockholm');

class WP_Post {
    public int $ID;
    public string $post_type;
    public string $post_status;
    public string $post_title;
    public string $post_excerpt = '';
    public function __construct(int $id, string $type, string $status, string $title) {
        $this->ID = $id; $this->post_type = $type; $this->post_status = $status; $this->post_title = $title;
    }
}
class WP_Query {
    public array $posts = array();
    public function __construct(array $args) {
        $now = $GLOBALS['clock'];
        foreach ($GLOBALS['posts'] as $post) {
            if ($post->post_type !== $args['post_type'] || 'publish' !== $post->post_status) continue;
            $meta = $GLOBALS['meta'][$post->ID] ?? array();
            if (! empty($meta['_ssf_promotion_archived'])) continue;
            if (! empty($meta['_ssf_promotion_start']) && $meta['_ssf_promotion_start'] > $now) continue;
            if (! empty($meta['_ssf_promotion_end']) && $meta['_ssf_promotion_end'] < $now) continue;
            $this->posts[] = $post->ID;
        }
    }
}
function check($condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function add_action(...$args): void {}
function add_filter(...$args): void {}
function apply_filters($tag, $value) { return $value; }
function wp_parse_args($args, $defaults): array { return array_merge($defaults, $args); }
function wp_unique_id($prefix): string { return $prefix . '1'; }
function wp_strip_all_tags($value): string { return strip_tags($value); }
function esc_url($value): string { return esc_url_raw($value); }
function sanitize_key($value): string { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)); }
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
function wp_unslash($value) { return $value; }
function absint($value): int { return abs((int) $value); }
function get_post(int $id) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_meta(int $id, string $key, bool $single = true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta(int $id, string $key, $value): void { $GLOBALS['meta'][$id][$key] = $value; }
function metadata_exists($type, int $id, string $key): bool { return array_key_exists($key, $GLOBALS['meta'][$id] ?? array()); }
function delete_post_meta(int $id, string $key): void { unset($GLOBALS['meta'][$id][$key]); }
function get_permalink($post): string { return 'https://ssfb.se/' . $post->post_title . '/'; }
function current_user_can($cap): bool { return (bool) ($GLOBALS['authorized'] ?? true); }
function wp_verify_nonce($nonce, $action): bool { return 'valid' === $nonce; }
function esc_url_raw($value): string { return filter_var((string) $value, FILTER_VALIDATE_URL) ? (string) $value : ''; }
function current_datetime(): DateTimeImmutable { return (new DateTimeImmutable('@' . $GLOBALS['clock']))->setTimezone(wp_timezone()); }
function wp_timezone(): DateTimeZone { return new DateTimeZone('Europe/Stockholm'); }
function wp_date($format, $timestamp, $timezone): string { return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format($format); }
function get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null): void { $GLOBALS['options'][$key] = $value; }
function set_transient(...$args): void {}
function get_current_user_id(): int { return 1; }
function get_pages($args): array { return array_values(array_filter($GLOBALS['posts'], static fn($post) => 'page' === $post->post_type && 'publish' === $post->post_status)); }
function wp_nonce_field(...$args): void {}
function esc_html_e($value): void { echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_attr_e($value): void { echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value): string { return esc_html($value); }
function esc_textarea($value): string { return esc_html($value); }
function checked($actual, $expected = true): void { if ($actual == $expected) echo 'checked="checked"'; }
function selected($actual, $expected): void { if ($actual == $expected) echo 'selected="selected"'; }
function __($value): string { return $value; }
function post_type_exists($type): bool { return true; }
function get_the_title($post): string { return $post->post_title; }

require_once __DIR__ . '/../includes/class-ssf-promotion-relations.php';
require_once __DIR__ . '/../includes/class-ssf-promotion-repository.php';
require_once __DIR__ . '/../includes/class-ssf-promotion-renderer.php';
class SSF_Promotions { public const CAPABILITY = 'manage_ssf_promotions'; }
require_once __DIR__ . '/../includes/class-ssf-promotion-admin.php';

$GLOBALS['posts'][10] = new WP_Post(10, 'page', 'publish', 'arsmote');
$GLOBALS['posts'][20] = new WP_Post(20, 'ssf_promotion', 'publish', 'Motion');
$relations = new SSF_Promotion_Relations();
$repository = new SSF_Promotion_Repository($relations);
$renderer = new SSF_Promotion_Renderer($repository);
$admin = new SSF_Promotion_Admin($repository, $relations, $renderer);

$_POST = array('ssf_promotion_nonce' => 'valid', 'ssf_promotion_link_mode' => 'page', 'ssf_promotion_page_id' => '10', 'ssf_promotion_cta_text' => 'Lämna motion', 'ssf_promotion_start' => '2026-09-20T08:00', 'ssf_promotion_end' => '2026-09-25T23:59');
$admin->save(20, $GLOBALS['posts'][20]);
$data = $repository->data(20);
check(get_post_meta(20, '_ssf_promotion_locations') === array('home'), 'New promotion did not default to homepage placement.');
check($data['url'] === 'https://ssfb.se/arsmote/' && $data['cta_text'] === 'Lämna motion', 'Page link or link text was not saved.');
check($data['start'] === strtotime('2026-09-20 08:00:00 Europe/Stockholm'), 'Start time ignores site timezone.');
check($data['end'] === strtotime('2026-09-25 23:59:00 Europe/Stockholm'), 'End time ignores site timezone.');
check($repository->active_ids() === array(), 'Promotion appeared before its start time.');
$GLOBALS['clock'] = strtotime('2026-09-20 08:00:00 Europe/Stockholm');
check($repository->active_ids() === array(20), 'Promotion did not appear at its start time.');
$GLOBALS['clock'] = strtotime('2026-09-25 23:59:01 Europe/Stockholm');
check($repository->active_ids() === array(), 'Promotion remained visible after its end time.');

$_POST['ssf_promotion_link_mode'] = 'url';
$_POST['ssf_promotion_url'] = 'https://ssfb.se/dev/arsmote/?meeting=204#ssf-am-motions';
$admin->save(20, $GLOBALS['posts'][20]);
check($repository->data(20)['url'] === $_POST['ssf_promotion_url'], 'Custom URL lost its query or anchor.');
$_POST['ssf_promotion_link_mode'] = 'none';
$admin->save(20, $GLOBALS['posts'][20]);
check($repository->data(20)['url'] === '', 'No-link mode still rendered a destination.');
check(get_post_meta(20, '_ssf_promotion_cta_text') === 'Lämna motion', 'No-link mode discarded saved link text.');
$GLOBALS['clock'] = strtotime('2026-09-21 12:00:00 Europe/Stockholm');
check(! str_contains($renderer->render(), 'ssf-promotion__action'), 'No-link promotion rendered a CTA.');

$GLOBALS['authorized'] = false;
$_POST['ssf_promotion_link_mode'] = 'page';
$admin->save(20, $GLOBALS['posts'][20]);
check($repository->data(20)['url'] === '', 'Unauthorized save changed the link.');
$GLOBALS['authorized'] = true;
$GLOBALS['posts'][21] = new WP_Post(21, 'ssf_promotion', 'publish', 'Old');
update_post_meta(21, '_ssf_promotion_related_type', 'post');
update_post_meta(21, '_ssf_promotion_related_id', 10);
update_post_meta(21, '_ssf_promotion_type', 'motions');
update_post_meta(21, '_ssf_promotion_priority', 80);
update_post_meta(21, '_ssf_promotion_locations', array('annual'));
check($repository->data(21)['url'] === 'https://ssfb.se/arsmote/', 'Legacy page relation lost its link.');
ob_start(); $admin->render_fields($GLOBALS['posts'][21]); $html = ob_get_clean();
check(str_contains($html, 'name="ssf_promotion_page_id"') && str_contains($html, 'value="10" selected="selected"'), 'Legacy page was not selected in the editor.');
check(str_contains($html, 'data-link-field="page"') && str_contains($html, 'data-link-field="url" hidden') && str_contains($html, 'data-link-field="cta"'), 'Link fields are not conditionally rendered.');
check(! str_contains($html, 'name="ssf_promotion_priority"') && ! str_contains($html, 'name="ssf_promotion_related_type"'), 'Legacy controls remain in the editor.');
$_POST['ssf_promotion_link_mode'] = 'page';
$admin->save(21, $GLOBALS['posts'][21]);
check(get_post_meta(21, '_ssf_promotion_type') === 'motions' && get_post_meta(21, '_ssf_promotion_priority') === 80 && get_post_meta(21, '_ssf_promotion_locations') === array('annual'), 'Legacy visual or placement metadata changed on save.');
echo "PASS: promotion links, legacy data, admin fields and site-time visibility.\n";
