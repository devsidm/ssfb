<?php

declare(strict_types=1);

const SSF_SITE_NEWSLETTER_POST_TYPE = 'ssf_newsletter';
const SSF_SITE_NEWSLETTER_SERIES_TAXONOMY = 'ssf_newsletter_series';

$newsletter_query = array();

function check(bool $condition, string $description): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
}

function get_header(): void {}
function get_footer(): void {}
function ssf_site_newsletter_settings(): array { return array('archive_intro' => 'Arkiv'); }
function sanitize_text_field($value): string { return trim((string) $value); }
function sanitize_key($value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)); }
function wp_unslash($value) { return $value; }
function ssf_site_is_valid_newsletter_year(string $year): bool { return '2026' === $year; }
function get_terms(array $args): array { return array((object) array('slug' => 'fordevind', 'name' => 'Fördevind')); }
function term_exists(string $term, string $taxonomy): bool { return 'fordevind' === $term && SSF_SITE_NEWSLETTER_SERIES_TAXONOMY === $taxonomy; }
function ssf_site_get_latest_newsletter() { return null; }
function ssf_site_get_newsletters(string $year = '', int $exclude_id = 0, string $series = '', bool $published_only = true): array
{
    global $newsletter_query;
    $newsletter_query = compact('year', 'exclude_id', 'series', 'published_only');
    return array();
}
function ssf_site_get_newsletter_years(): array { return array('2026'); }
function ssf_site_group_newsletters_by_year(array $newsletters): array { return array(); }
function get_post_type_archive_link(string $post_type): string { return 'https://example.test/nyhetsbrev/'; }
function add_query_arg($args, $value = null, $url = ''): string
{
    if (! is_array($args)) {
        $args = array($args => $value);
    } else {
        $url = $value;
    }
    return rtrim((string) $url, '?') . '?' . http_build_query($args);
}
function esc_url($value): string { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_attr($value): string { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_html_e(string $value, string $domain = ''): void { echo esc_html($value); }
function esc_attr_e(string $value, string $domain = ''): void { echo esc_attr($value); }
function __(string $value, string $domain = ''): string { return $value; }

$_GET['ar'] = '2026';
$_GET['serie'] = 'fordevind';

ob_start();
require dirname(__DIR__, 2) . '/wp-content/themes/ssf/archive-ssf_newsletter.php';
$html = (string) ob_get_clean();

check('2026' === $newsletter_query['year'], 'Public archive forwards selected year.');
check('fordevind' === $newsletter_query['series'], 'Public archive forwards selected series.');
check(str_contains($html, 'serie=fordevind'), 'Public archive renders series filter link.');
check(str_contains($html, 'ar=2026'), 'Public archive renders year filter link.');

$newsletter_source = file_get_contents(dirname(__DIR__, 2) . '/wp-content/plugins/ssf-site-customizations/includes/newsletters.php');
$workspace_source = file_get_contents(dirname(__DIR__, 2) . '/wp-content/plugins/ssf-arbetsyta/includes/class-ssf-workspace-services.php');
check(false !== strpos($newsletter_source, 'function ssf_site_render_newsletter_workspace_editor'), 'Dedicated Workspace renderer exists.');
check(false !== strpos($workspace_source, "return ssf_site_render_newsletter_workspace_editor('ny' === \$tail ? 0 : (int) \$tail);"), 'Workspace routes directly to its renderer.');
check(false !== strpos($newsletter_source, ': (string) get_post_meta($post_id, SSF_SITE_NEWSLETTER_SERIES_META, true)'), 'Legacy series metadata remains readable.');
check(false !== strpos($newsletter_source, 'function ssf_site_render_newsletter_editor_page'), 'wp-admin fallback remains available.');
check(false !== strpos($newsletter_source, "current_user_can('edit_ssf_newsletters')"), 'Newsletter edit capability remains enforced.');
check(false !== strpos($newsletter_source, "current_user_can('publish_ssf_newsletters')"), 'Newsletter publish capability remains enforced.');

echo "PASS: newsletter Workspace routing, permissions, legacy compatibility, admin fallback and public filters.\n";
