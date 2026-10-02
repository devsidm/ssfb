<?php
/** Focused, database-free tests for news URL safety, metadata and public fallback contracts. */
declare(strict_types=1);

define('ABSPATH', __DIR__);
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
check_news(false !== strpos($service, "'redirection' => 0") && false !== strpos($service, 'self::is_safe_url($url)'), 'each redirect is revalidated');
check_news(false !== strpos($service, 'catch (Throwable $error)') && false !== strpos($service, 'self::check_source((int) $source->ID)'), 'one broken source is isolated from the batch');
check_news(false !== strpos($service, "array('external_preview', 'ssf_image', 'none')"), 'all three image modes are constrained');
check_news(false !== strpos($access, "'ssf_news_edit'") && false !== strpos($access, "'ssf_news_publish'"), 'editor and publisher capabilities are distinct');
check_news(false !== strpos($renderer, "\$_GET['nyhetstyp']") && false !== strpos($renderer, 'aria-current="page"'), 'public filters have a server-rendered no-JS baseline');

echo "PASS: News access, suggestions, monitoring, URL safety, metadata, publishing and public fallback contracts.\n";
