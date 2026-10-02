<?php
/** Focused, database-free tests for news URL safety, metadata and public fallback contracts. */
declare(strict_types=1);

define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
$news_posts = $news_meta = $news_scheduled = array();
$news_next_id = 100;
$news_response = array('code' => 200, 'type' => 'text/html', 'body' => '<title>Segelfartyg</title>');
class WP_Error { public function __construct(public string $code, public string $message, public array $data = array()) {} public function get_error_message(): string { return $this->message; } }
class WP_User { public int $ID = 10; public array $roles = array(); }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function current_time(string $format): string { return '2026-10-02 09:00:00'; }
function get_current_user_id(): int { return 10; }
function absint($value): int { return abs((int) $value); }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function wp_strip_all_tags(string $value): string { return strip_tags($value); }
function post_type_exists(string $type): bool { return true; }
function get_the_title($id): string { global $news_posts; return $news_posts[$id]->post_title ?? ''; }
function get_post($id) { global $news_posts; return $news_posts[$id] ?? null; }
function get_post_meta($id, $key, $single = false) { global $news_meta; return $news_meta[$id][$key] ?? ''; }
function update_post_meta($id, $key, $value): void { global $news_meta; $news_meta[$id][$key] = $value; }
function wp_insert_post(array $args, bool $error = false): int { global $news_posts, $news_next_id; $id = ++$news_next_id; $news_posts[$id] = (object) array_merge($args, array('ID' => $id)); return $id; }
function get_posts(array $args): array {
    global $news_posts, $news_meta;
    $posts = array_filter($news_posts, static function ($post) use ($args, $news_meta): bool {
        return $post->post_type === ($args['post_type'] ?? 'post')
            && (! isset($args['meta_key']) || ($news_meta[$post->ID][$args['meta_key']] ?? '') === $args['meta_value']);
    });
    if (($args['numberposts'] ?? -1) > 0) $posts = array_slice($posts, 0, $args['numberposts']);
    return array_values('ids' === ($args['fields'] ?? '') ? array_map(static fn($post): int => $post->ID, $posts) : $posts);
}
function do_action(string $name, ...$args): void {}
function wp_next_scheduled(string $hook, array $args = array()): bool { global $news_scheduled; return isset($news_scheduled[$hook . json_encode($args)]); }
function wp_schedule_event(int $time, string $frequency, string $hook): void { global $news_scheduled; $news_scheduled[$hook . '[]'] = array($frequency, $time); }
function wp_schedule_single_event(int $time, string $hook, array $args): void { global $news_scheduled; $news_scheduled[$hook . json_encode($args)] = array('once', $time); }
function wp_safe_remote_get(string $url, array $args) { global $news_response; if ($news_response instanceof Throwable) throw $news_response; return $news_response; }
function wp_remote_retrieve_response_code(array $response): int { return $response['code']; }
function wp_remote_retrieve_header(array $response, string $name): string { return 'location' === $name ? ($response['location'] ?? '') : ($response['type'] ?? ''); }
function wp_remote_retrieve_body(array $response): string { return $response['body']; }
function add_action(string $name, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
function add_filter(string $name, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
function wp_parse_url(string $url, int $component = -1) { return parse_url($url, $component); }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_textarea_field(string $value): string { return trim(strip_tags($value)); }
function esc_url_raw(string $value): string { return filter_var($value, FILTER_VALIDATE_URL) ? $value : ''; }
function apply_filters(string $name, $value) { return $value; }
function home_url(string $path = ''): string { return 'https://example.test' . $path; }
function wp_http_validate_url(string $url): string|false { return filter_var($url, FILTER_VALIDATE_URL) ? $url : false; }

require __DIR__ . '/../../wp-content/plugins/ssf-arbetsyta/includes/class-ssf-news-service.php';

function check_news(bool $condition, string $description): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
}

$normalized = SSF_News_Service::normalize_url('HTTPS://Example.COM:443/story/?utm_source=test&b=2&a=1#part');
check_news('https://example.com/story/?a=1&b=2' === $normalized, 'canonical URL is stable and tracking-free');
check_news('' === SSF_News_Service::normalize_url('file:///etc/passwd'), 'unsafe scheme is rejected');
check_news(! SSF_News_Service::is_safe_url('http://localhost/story'), 'localhost is blocked');
check_news(! SSF_News_Service::is_safe_url('http://127.0.0.1/story'), 'loopback is blocked');
check_news(! SSF_News_Service::is_safe_url('http://10.20.30.40/story'), 'private IPv4 is blocked');
check_news(! SSF_News_Service::is_safe_url('http://169.254.169.254/latest/meta-data'), 'metadata endpoint is blocked');
check_news(! SSF_News_Service::is_safe_url('ftp://example.com/story'), 'non-http scheme is blocked');

$html = '<!doctype html><html><head><title>Fallback title</title><meta property="og:title" content="Originalrubrik"><meta property="og:description" content="Kort beskrivning"><meta property="og:image" content="/image.jpg"><meta property="og:site_name" content="Båtliv"><meta property="article:published_time" content="2026-09-28T10:00:00+02:00"><link rel="canonical" href="https://example.com/story?utm_campaign=x"></head></html>';
$meta = SSF_News_Service::parse_metadata($html, 'https://example.com/news/story');
check_news('Originalrubrik' === $meta['title'], 'Open Graph title parsed');
check_news('Kort beskrivning' === $meta['description'], 'Open Graph description parsed');
check_news('https://example.com/image.jpg' === $meta['image'], 'relative Open Graph image resolved');
check_news('https://example.com/story' === $meta['canonical_url'], 'canonical URL normalized');
check_news('Båtliv' === $meta['site_name'], 'source name parsed');
check_news('2026-09-28 08:00:00' === $meta['published_at'], 'published date normalized to UTC');

$missing = SSF_News_Service::parse_metadata('<html><head><title>Only a title</title></head><body><p>broken', 'https://example.com/a');
check_news('Only a title' === $missing['title'] && '' === $missing['image'], 'malformed HTML and missing OG degrade safely');

check_news(SSF_News_Service::safe_fetch('http://127.0.0.1') instanceof WP_Error, 'private fetch fails before transport');
$news_response = array('code' => 302, 'location' => 'http://10.0.0.1/private', 'body' => '');
check_news(SSF_News_Service::safe_fetch('https://93.184.216.34/') instanceof WP_Error, 'redirect to private address fails before second request');
$news_response = array('code' => 200, 'type' => 'image/png', 'body' => 'x');
check_news(SSF_News_Service::safe_fetch('https://93.184.216.34/') instanceof WP_Error, 'unexpected content type is rejected');
$news_response = array('code' => 200, 'type' => 'text/html', 'body' => str_repeat('x', 101));
check_news(SSF_News_Service::safe_fetch('https://93.184.216.34/', 100) instanceof WP_Error, 'response limit is enforced');
$news_response = new WP_Error('timeout', 'Timeout');
check_news(SSF_News_Service::safe_fetch('https://93.184.216.34/') instanceof WP_Error, 'transport timeout propagates');

$data = array('url' => 'https://93.184.216.34/story?utm_source=x', 'canonical_url' => 'https://93.184.216.34/story', 'title' => 'Segelfartyg och kulturarv', 'origin' => 'editor_manual');
$suggestion = SSF_News_Service::create_suggestion($data);
check_news(is_int($suggestion) && 'editor_manual' === get_post_meta($suggestion, '_ssf_suggestion_origin', true), 'manual URL creates canonical suggestion');
$data['origin'] = 'monitoring';
check_news(is_wp_error(SSF_News_Service::create_suggestion($data)), 'monitoring deduplicates manual canonical URL');
update_post_meta($suggestion, '_ssf_suggestion_status', 'dismissed');
$data['origin'] = 'member_tip';
check_news(is_wp_error(SSF_News_Service::create_suggestion($data)), 'dismissed URL cannot return as new member tip');
check_news('dismissed' === get_post_meta($suggestion, '_ssf_suggestion_status', true) && 3 === count(get_post_meta($suggestion, '_ssf_suggestion_origins', true)), 'dismiss preserves status and arrival history');
$data['url'] = $data['canonical_url'] = 'https://93.184.216.34/another';
check_news(is_int(SSF_News_Service::create_suggestion($data)), 'different member tip uses same suggestion model');

SSF_News_Service::ensure_schedule();
check_news('twicedaily' === $news_scheduled[SSF_News_Service::CRON_HOOK . '[]'][0], 'twice daily schedule is registered');
$source = wp_insert_post(array('post_type' => SSF_News_Service::SOURCE_TYPE, 'post_title' => 'A'));
update_post_meta($source, '_ssf_source_active', '0');
check_news(is_wp_error(SSF_News_Service::check_source($source)), 'inactive source is not fetched');
update_post_meta($source, '_ssf_source_active', '1');
update_post_meta($source, '_ssf_source_feed_url', 'https://93.184.216.34/feed');
$news_response = new RuntimeException('Source failed');
SSF_News_Service::background_source($source);
check_news(str_starts_with(get_post_meta($source, '_ssf_source_last_result', true), 'Error'), 'broken source becomes an error without crashing background work');
$source2 = wp_insert_post(array('post_type' => SSF_News_Service::SOURCE_TYPE, 'post_title' => 'B'));
update_post_meta($source2, '_ssf_source_active', '1');
SSF_News_Service::run_monitoring();
check_news(isset($news_scheduled['ssf_news_check_source[' . $source . ']']) && isset($news_scheduled['ssf_news_check_source[' . $source2 . ']']), 'batch queues both sources independently');

$renderer = file_get_contents(__DIR__ . '/../../wp-content/plugins/ssf-site-customizations/includes/shortcodes.php');
$script = file_get_contents(__DIR__ . '/../../wp-content/plugins/ssf-site-customizations/assets/js/ssf-site.js');
$service = file_get_contents(__DIR__ . '/../../wp-content/plugins/ssf-arbetsyta/includes/class-ssf-news-service.php');
$access = file_get_contents(__DIR__ . '/../../wp-content/mu-plugins/ssf-access-control.php');
check_news(false !== strpos($renderer, 'referrerpolicy="no-referrer"'), 'external previews use no-referrer');
check_news(false !== strpos($renderer, 'loading="lazy"'), 'external previews lazy-load');
check_news(false !== strpos($script, "image.addEventListener('error', fallback)"), 'broken external images have a text-card fallback');
check_news(false !== strpos($renderer, 'Läs hos '), 'external CTA names the source');
check_news(false !== strpos($service, "'twicedaily', self::CRON_HOOK"), 'monitoring is scheduled twice daily');
check_news(false !== strpos($service, "'editor_manual'") && false !== strpos($service, "'member_tip'") && false !== strpos($service, "'monitoring'"), 'all origins share the canonical suggestion model');
check_news(false !== strpos($service, "'_ssf_suggestion_status', 'dismissed'"), 'dismissed suggestions retain their dedup record');
check_news(false !== strpos($service, "'ssf_news_error'") && false !== strpos($service, "'ssf_news_notice'"), 'workspace notices use namespaced query parameters');
check_news(false !== strpos($service, 'Förslaget är redan behandlat och kan inte konverteras igen.'), 'handled suggestions cannot be converted from a stale direct link');
check_news(false !== strpos($service, "'redirection' => 0") && false !== strpos($service, 'self::is_safe_url($url)'), 'each redirect is revalidated');
check_news(false !== strpos($service, "array('external_preview', 'ssf_image', 'none')"), 'all three image modes are constrained');
check_news(false !== strpos($access, "'ssf_news_edit'") && false !== strpos($access, "'ssf_news_publish'"), 'editor and publisher capabilities are distinct');
check_news(false !== strpos($renderer, "\$_GET['nyhetstyp']") && false !== strpos($renderer, 'aria-current="page"'), 'public filters have a server-rendered no-JS baseline');

echo "PASS: News access, suggestions, monitoring, URL safety, metadata, publishing and public fallback contracts.\n";
