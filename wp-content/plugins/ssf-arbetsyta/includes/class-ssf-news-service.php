<?php
/** News workflow, external-article suggestions and source monitoring for SSF Arbetsyta. */
if (! defined('ABSPATH')) {
    exit;
}

final class SSF_News_Service
{
    public const SUGGESTION_TYPE = 'ssf_news_suggestion';
    public const SOURCE_TYPE = 'ssf_news_source';
    public const CRON_HOOK = 'ssf_news_monitor_sources';
    public const META_TYPE = '_ssf_news_type';
    public const META_EXTERNAL_URL = '_ssf_news_external_url';
    public const META_SOURCE = '_ssf_news_source_name';
    public const META_IMAGE_MODE = '_ssf_news_image_mode';
    public const META_EXTERNAL_IMAGE = '_ssf_news_external_image';
    public const META_SHIPS = '_ssf_news_ship_ids';
    public const META_FEATURED = '_ssf_news_featured';
    public const HOME_SETTINGS = 'ssf_news_home_settings';
    public const TAXONOMY = 'ssf_news_topic';

    public static function boot(): void
    {
        add_action('init', array(__CLASS__, 'register_data'), 8);
        add_action('init', array(__CLASS__, 'ensure_schedule'), 40);
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_monitoring'));
        add_action('ssf_news_check_source', array(__CLASS__, 'background_source'));
        add_filter('user_has_cap', array(__CLASS__, 'member_tip_capability'), 20, 4);
        foreach (array(
            'ssf_news_manual' => 'handle_manual',
            'ssf_news_tip' => 'handle_tip',
            'ssf_news_suggestion' => 'handle_suggestion',
            'ssf_news_external_save' => 'handle_external_save',
            'ssf_news_home_settings' => 'handle_home_settings',
            'ssf_news_source_save' => 'handle_source_save',
            'ssf_news_source_action' => 'handle_source_action',
        ) as $action => $method) {
            add_action('admin_post_' . $action, array(__CLASS__, $method));
        }
    }

    public static function activate(): void
    {
        self::register_data();
        self::ensure_schedule();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function register_data(): void
    {
        register_post_type(self::SUGGESTION_TYPE, array(
            'labels' => array('name' => 'Artikelförslag', 'singular_name' => 'Artikelförslag'),
            'public' => false, 'show_ui' => false, 'supports' => array('title', 'excerpt', 'custom-fields'),
            'capability_type' => 'post', 'map_meta_cap' => true,
        ));
        register_post_type(self::SOURCE_TYPE, array(
            'labels' => array('name' => 'Bevakningskällor', 'singular_name' => 'Bevakningskälla'),
            'public' => false, 'show_ui' => false, 'supports' => array('title', 'custom-fields'),
            'capability_type' => 'post', 'map_meta_cap' => true,
        ));
        register_taxonomy(self::TAXONOMY, 'post', array(
            'labels' => array('name' => 'Nyhetsämnen', 'singular_name' => 'Nyhetsämne'),
            'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'hierarchical' => false,
        ));
    }

    public static function ensure_schedule(): void
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_HOOK);
        }
    }

    public static function member_tip_capability(array $allcaps, array $caps, array $args, WP_User $user): array
    {
        $active = class_exists('SSF_Access_Control') && SSF_Access_Control::is_active((int) $user->ID);
        if ($active && in_array('ssf_news_tip', $caps, true)
            && (in_array('ssf_fartygsombud', (array) $user->roles, true) || self::user_ship_ids((int) $user->ID))) {
            $allcaps['ssf_news_tip'] = true;
        }
        return $allcaps;
    }

    public static function can(string $capability): bool
    {
        return current_user_can($capability) || current_user_can('manage_options');
    }

    public static function sanitize_home_settings(array $settings): array
    {
        $integer = static function (string $key, int $default, int $maximum) use ($settings): int {
            $value = (int) ($settings[$key] ?? $default);
            return $value >= 1 && $value <= $maximum ? $value : $default;
        };
        $show_media = (string) ($settings['show_media'] ?? '1');
        $show_media = in_array($show_media, array('0', '1'), true) ? $show_media : '1';
        return array(
            'ssf_count' => $integer('ssf_count', 6, 6),
            'ssf_columns' => $integer('ssf_columns', 3, 3),
            'show_media' => $show_media,
            'media_count' => $integer('media_count', 6, 6),
            'media_columns' => $integer('media_columns', 3, 3),
        );
    }

    private static function home_settings(): array
    {
        $settings = get_option(self::HOME_SETTINGS, array());
        return self::sanitize_home_settings(is_array($settings) ? $settings : array());
    }

    public static function user_ship_ids(int $user_id): array
    {
        if ($user_id < 1 || ! post_type_exists('medlemsfartyg')) {
            return array();
        }
        // The canonical owner field can contain either serialized integers or strings.
        return array_values(array_map(static fn($ship): int => (int) $ship->ID, array_filter(
            get_posts(array('post_type' => 'medlemsfartyg', 'post_status' => 'any', 'numberposts' => -1)),
            static fn($ship): bool => in_array($user_id, array_map('intval', (array) get_post_meta($ship->ID, '_ssf_ship_owner_users', true)), true)
        )));
    }

    public static function normalize_url(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $parts = wp_parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (! in_array($scheme, array('http', 'https'), true) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        if (('http' === $scheme && ':80' === $port) || ('https' === $scheme && ':443' === $port)) {
            $port = '';
        }
        $path = isset($parts['path']) && '' !== $parts['path'] ? preg_replace('#/+#', '/', (string) $parts['path']) : '/';
        $query = array();
        if (! empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            foreach (array_keys($query) as $key) {
                if (preg_match('/^(utm_|fbclid$|gclid$|mc_)/i', (string) $key)) {
                    unset($query[$key]);
                }
            }
            ksort($query);
        }
        return $scheme . '://' . $host . $port . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }

    public static function is_safe_url(string $url): bool
    {
        $normalized = self::normalize_url($url);
        if (! $normalized) {
            return false;
        }
        $host = (string) wp_parse_url($normalized, PHP_URL_HOST);
        if (in_array($host, array('localhost', 'localhost.localdomain'), true) || str_ends_with($host, '.local')) {
            return false;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : (array) gethostbynamel($host);
        if (! $ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return function_exists('wp_http_validate_url') ? (bool) wp_http_validate_url($normalized) : true;
    }

    /** Safe, bounded fetch with every redirect revalidated. */
    public static function safe_fetch(string $url, int $max_bytes = 1048576)
    {
        $url = self::normalize_url($url);
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            if (! self::is_safe_url($url)) {
                return new WP_Error('unsafe_url', 'URL:en pekar mot en otillåten nätverksadress.');
            }
            $response = wp_safe_remote_get($url, array(
                'timeout' => 8, 'redirection' => 0, 'limit_response_size' => $max_bytes + 1,
                'reject_unsafe_urls' => true,
                'headers' => array('Accept' => 'text/html,application/xhtml+xml,application/rss+xml,application/atom+xml;q=0.9'),
                'user-agent' => 'SSF News Monitor/1.0; ' . home_url('/'),
            ));
            if (is_wp_error($response)) {
                return $response;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if (in_array($code, array(301, 302, 303, 307, 308), true)) {
                $location = (string) wp_remote_retrieve_header($response, 'location');
                if (! $location || $redirects >= 3) {
                    return new WP_Error('redirect', 'För många eller ogiltiga omdirigeringar.');
                }
                $url = self::resolve_url($url, $location);
                continue;
            }
            if ($code < 200 || $code >= 300) {
                return new WP_Error('http_status', 'Källan svarade med HTTP ' . $code . '.');
            }
            $type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
            if ($type && ! preg_match('#(text/html|application/xhtml\+xml|application/(rss|atom)\+xml|text/xml|application/xml)#', $type)) {
                return new WP_Error('content_type', 'Källan returnerade inte HTML eller RSS/Atom.');
            }
            $body = (string) wp_remote_retrieve_body($response);
            if (strlen($body) > $max_bytes) {
                return new WP_Error('response_size', 'Källans svar överskrider tillåten storlek.');
            }
            return array('url' => $url, 'body' => $body, 'content_type' => $type);
        }
        return new WP_Error('fetch_failed', 'Källan kunde inte hämtas.');
    }

    private static function resolve_url(string $base, string $target): string
    {
        $target = trim($target);
        if (! $target || preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) && ! preg_match('#^https?://#i', $target)) {
            return '';
        }
        if (preg_match('#^https?://#i', $target)) {
            return $target;
        }
        $parts = wp_parse_url($base);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $root = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($target, '//')) {
            return $parts['scheme'] . ':' . $target;
        }
        if (str_starts_with($target, '/')) {
            return $root . $target;
        }
        $directory = preg_replace('#/[^/]*$#', '/', (string) ($parts['path'] ?? '/'));
        return $root . $directory . $target;
    }

    public static function parse_metadata(string $html, string $url): array
    {
        $data = array('url' => self::normalize_url($url), 'canonical_url' => '', 'title' => '', 'description' => '', 'image' => '', 'site_name' => '', 'published_at' => '');
        if ('' === trim($html)) {
            return $data;
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return $data;
        }
        $xpath = new DOMXPath($doc);
        $meta = array();
        foreach ($xpath->query('//meta[@property or @name]') ?: array() as $node) {
            $key = strtolower(trim($node->getAttribute('property') ?: $node->getAttribute('name')));
            if ($key && ! isset($meta[$key])) {
                $meta[$key] = trim($node->getAttribute('content'));
            }
        }
        $canonical = $xpath->query('//link[contains(concat(" ", translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), " "), " canonical ")]/@href');
        $title_nodes = $xpath->query('//title');
        $data['title'] = sanitize_text_field((string) ($meta['og:title'] ?? ($title_nodes && $title_nodes->length ? $title_nodes->item(0)->textContent : '')));
        $data['description'] = sanitize_textarea_field((string) ($meta['og:description'] ?? $meta['description'] ?? ''));
        $data['site_name'] = sanitize_text_field((string) ($meta['og:site_name'] ?? ''));
        $image = trim((string) ($meta['og:image'] ?? ''));
        $data['image'] = $image ? esc_url_raw(self::resolve_url($url, $image)) : '';
        $canonical_url = $canonical && $canonical->length ? self::resolve_url($url, trim($canonical->item(0)->nodeValue)) : $url;
        $data['canonical_url'] = self::is_safe_url($canonical_url) ? self::normalize_url($canonical_url) : $data['url'];
        $date = (string) ($meta['article:published_time'] ?? $meta['datepublished'] ?? $meta['date'] ?? '');
        if ($date && strtotime($date)) {
            $data['published_at'] = gmdate('Y-m-d H:i:s', strtotime($date));
        }
        return apply_filters('ssf_news_metadata', $data, $html, $url);
    }

    public static function fetch_metadata(string $url)
    {
        $response = self::safe_fetch($url);
        if (is_wp_error($response)) {
            return $response;
        }
        return self::parse_metadata($response['body'], $response['url']);
    }

    public static function create_suggestion(array $data)
    {
        $canonical = self::normalize_url((string) ($data['canonical_url'] ?? $data['url'] ?? ''));
        if (! $canonical) {
            return new WP_Error('invalid_url', 'Ange en giltig publik http- eller https-URL.');
        }
        $hash = hash('sha256', $canonical);
        $existing = get_posts(array(
            'post_type' => self::SUGGESTION_TYPE, 'post_status' => 'private', 'numberposts' => 1, 'fields' => 'ids',
            'meta_key' => '_ssf_suggestion_dedup', 'meta_value' => $hash,
        ));
        if ($existing) {
            $id = (int) $existing[0];
            $history = (array) get_post_meta($id, '_ssf_suggestion_origins', true);
            $history[] = array('origin' => sanitize_key((string) ($data['origin'] ?? 'monitoring')), 'at' => current_time('mysql'), 'user_id' => get_current_user_id());
            update_post_meta($id, '_ssf_suggestion_origins', array_slice($history, -20));
            return new WP_Error('duplicate', 'Artikeln finns redan i kön.', array('id' => $id, 'status' => get_post_meta($id, '_ssf_suggestion_status', true)));
        }
        $title = sanitize_text_field((string) ($data['title'] ?? $canonical));
        $id = wp_insert_post(array(
            'post_type' => self::SUGGESTION_TYPE, 'post_status' => 'private', 'post_title' => $title ?: $canonical,
            'post_excerpt' => sanitize_textarea_field((string) ($data['description'] ?? '')),
            'post_author' => get_current_user_id(),
        ), true);
        if (is_wp_error($id)) {
            return $id;
        }
        $fields = array(
            '_ssf_suggestion_url' => self::normalize_url((string) ($data['url'] ?? $canonical)),
            '_ssf_suggestion_canonical' => $canonical,
            '_ssf_suggestion_dedup' => $hash,
            '_ssf_suggestion_source' => sanitize_text_field((string) (! empty($data['site_name']) ? $data['site_name'] : ($data['source'] ?? wp_parse_url($canonical, PHP_URL_HOST)))),
            '_ssf_suggestion_original_date' => ! empty($data['published_at']) && strtotime((string) $data['published_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $data['published_at'])) : '',
            '_ssf_suggestion_image' => esc_url_raw((string) ($data['image'] ?? '')),
            '_ssf_suggestion_origin' => sanitize_key((string) ($data['origin'] ?? 'monitoring')),
            '_ssf_suggestion_status' => 'new',
            '_ssf_suggestion_source_id' => absint($data['source_id'] ?? 0),
            '_ssf_suggestion_priority' => empty($data['priority']) ? '0' : '1',
            '_ssf_suggestion_comment' => sanitize_textarea_field((string) ($data['comment'] ?? '')),
            '_ssf_suggestion_user_id' => absint($data['user_id'] ?? get_current_user_id()),
        );
        $matches = self::match_relevance($title . ' ' . (string) ($data['description'] ?? ''));
        $fields['_ssf_suggestion_ships'] = array_values(array_unique(array_merge($matches['ships'], array_map('intval', (array) ($data['ship_ids'] ?? array())))));
        $fields['_ssf_suggestion_topics'] = $matches['topics'];
        $extra_keywords = array_values(array_filter(array_map('sanitize_text_field', (array) ($data['matched_keywords'] ?? array()))));
        $fields['_ssf_suggestion_keywords'] = array_values(array_unique(array_merge($matches['keywords'], $extra_keywords)));
        $fields['_ssf_suggestion_match_reason'] = implode(', ', array_filter(array($matches['reason'], implode(', ', $extra_keywords))));
        foreach ($fields as $key => $value) {
            update_post_meta((int) $id, $key, $value);
        }
        update_post_meta((int) $id, '_ssf_suggestion_origins', array(array('origin' => $fields['_ssf_suggestion_origin'], 'at' => current_time('mysql'), 'user_id' => $fields['_ssf_suggestion_user_id'])));
        do_action('ssf_news_suggestion_created', (int) $id, $data);
        return (int) $id;
    }

    public static function match_relevance(string $text): array
    {
        $plain = wp_strip_all_tags($text);
        $has_mb = function_exists('mb_strtolower') && function_exists('mb_strpos') && function_exists('mb_strlen');
        $lower = static fn(string $value): string => $has_mb ? mb_strtolower($value) : strtolower($value);
        $contains = static fn(string $value, string $term): bool => false !== ($has_mb ? mb_strpos($value, $term) : strpos($value, $term));
        $length = static fn(string $value): int => $has_mb ? mb_strlen($value) : strlen($value);
        $haystack = $lower($plain);
        $topics = array('segelfartyg', 'skutor', 'traditionsfartyg', 'kulturarv', 'restaurering', 'race', 'regattor', 'evenemang', 'ungdom', 'utbildning', 'sjöfartshistoria', 'hamnfrågor', 'myndighet');
        $matched_topics = array_values(array_filter($topics, static fn(string $term): bool => $contains($haystack, $term)));
        $ships = array();
        if (post_type_exists('medlemsfartyg')) {
            foreach (get_posts(array('post_type' => 'medlemsfartyg', 'post_status' => 'publish', 'numberposts' => -1)) as $ship) {
                if ($length($ship->post_title) > 2 && $contains($haystack, $lower($ship->post_title))) {
                    $ships[] = (int) $ship->ID;
                }
            }
        }
        return apply_filters('ssf_news_relevance_match', array(
            'ships' => $ships, 'topics' => $matched_topics, 'keywords' => $matched_topics,
            'reason' => implode(', ', array_merge($matched_topics, array_map('get_the_title', $ships))),
        ), $text);
    }

    private static function post_meta(int $id, string $key): string
    {
        return (string) get_post_meta($id, $key, true);
    }

    private static function redirect(string $path, string $notice = '', string $error = ''): void
    {
        $url = SSF_Workspace::url($path);
        if ($notice) {
            $url = add_query_arg('ssf_news_notice', $notice, $url);
        }
        if ($error) {
            $url = add_query_arg('ssf_news_error', $error, $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    private static function require_cap(string $capability): void
    {
        if (! SSF_Workspace::active_user() || ! self::can($capability)) {
            wp_die('Du saknar behörighet.', '', array('response' => 403));
        }
    }

    private static function notice_html(): string
    {
        $html = '';
        if (! empty($_GET['ssf_news_notice'])) {
            $html .= '<p class="ssf-workspace-confirmation" role="status">' . esc_html(sanitize_text_field(wp_unslash($_GET['ssf_news_notice']))) . '</p>';
        }
        if (! empty($_GET['ssf_news_error'])) {
            $html .= '<p class="ssf-workspace-error" role="alert">' . esc_html(sanitize_text_field(wp_unslash($_GET['ssf_news_error']))) . '</p>';
        }
        return $html;
    }

    public static function render(string $tail)
    {
        $parts = '' === $tail ? array() : explode('/', trim($tail, '/'));
        $section = $parts[0] ?? '';
        if ('' === $section) {
            return self::dashboard();
        }
        if ('egna' === $section) {
            return self::own_news(isset($parts[1]) ? (int) $parts[1] : 0);
        }
        if ('medierna' === $section) {
            return self::media_news(isset($parts[1]) ? (int) $parts[1] : 0);
        }
        if ('forslag' === $section) {
            return self::suggestions(isset($parts[1]) ? (int) $parts[1] : 0);
        }
        if ('ny' === $section) {
            self::require_cap('ssf_news_edit');
            return self::own_editor(null);
        }
        if ('extern' === $section) {
            return self::manual_form();
        }
        if ('bevakning' === $section) {
            return self::sources(isset($parts[1]) ? (int) $parts[1] : 0);
        }
        if ('tips' === $section) {
            return self::tip_form();
        }
        return new WP_Error('not_found', 'Sidan kunde inte hittas.', array('status' => 404));
    }

    private static function tabs(string $active = ''): string
    {
        $tabs = array('' => 'Översikt', 'egna' => 'Egna nyheter', 'medierna' => 'I medierna', 'forslag' => 'Artikelförslag', 'bevakning' => 'Omvärldsbevakning');
        $html = '<nav class="ssf-workspace-tabs ssf-news-tabs" aria-label="Nyheters delar">';
        foreach ($tabs as $path => $label) {
            if ('bevakning' === $path && ! self::can('ssf_news_sources_manage')
                || 'forslag' === $path && ! self::can('ssf_news_suggestions_manage')) {
                continue;
            }
            $html .= '<a' . ($active === $path ? ' aria-current="page"' : '') . ' href="' . esc_url(SSF_Workspace::url('nyheter' . ($path ? '/' . $path : ''))) . '">' . esc_html($label) . '</a>';
        }
        return $html . '</nav>';
    }

    private static function count_suggestions(string $status = 'new'): int
    {
        $query = new WP_Query(array('post_type' => self::SUGGESTION_TYPE, 'post_status' => 'private', 'posts_per_page' => 1, 'meta_key' => '_ssf_suggestion_status', 'meta_value' => $status));
        return (int) $query->found_posts;
    }

    public static function dashboard(): string
    {
        $new = self::count_suggestions();
        $drafts = wp_count_posts('post')->draft ?? 0;
        $future = wp_count_posts('post')->future ?? 0;
        $html = self::notice_html() . '<div class="ssf-news-heading"><div><h1>Nyheter</h1><p>Det här behöver du göra nu.</p></div><div class="ssf-workspace-form-actions"><a class="ssf-workspace-button" href="' . esc_url(SSF_Workspace::url('nyheter/ny')) . '">+ Ny artikel</a><a class="ssf-workspace-button ssf-workspace-button--secondary" href="' . esc_url(SSF_Workspace::url('nyheter/extern')) . '">+ Lägg till extern artikel</a></div></div>' . self::tabs('');
        $html .= '<div class="ssf-news-stats"><a href="' . esc_url(SSF_Workspace::url('nyheter/forslag')) . '"><strong>' . esc_html((string) $new) . '</strong><span>nya artikelförslag</span></a><a href="' . esc_url(SSF_Workspace::url('nyheter/egna')) . '"><strong>' . esc_html((string) $drafts) . '</strong><span>utkast</span></a><a href="' . esc_url(SSF_Workspace::url('nyheter/egna')) . '"><strong>' . esc_html((string) $future) . '</strong><span>schemalagda</span></a></div>';
        $recent = get_posts(array('post_type' => array('post', self::SUGGESTION_TYPE), 'post_status' => array('draft', 'pending', 'future', 'publish', 'private'), 'numberposts' => 6, 'orderby' => 'modified', 'order' => 'DESC'));
        $html .= '<h2>Senaste aktivitet</h2><ul class="ssf-workspace-list">';
        foreach ($recent as $item) {
            $suggestion = self::SUGGESTION_TYPE === $item->post_type;
            $url = $suggestion ? SSF_Workspace::url('nyheter/forslag/' . $item->ID) : SSF_Workspace::url(('media' === self::post_meta($item->ID, self::META_TYPE) ? 'nyheter/medierna/' : 'nyheter/egna/') . $item->ID);
            $item_status = $suggestion ? 'Artikelförslag · ' . self::status_label($item->post_status) : self::status_label($item->post_status);
            $html .= '<li><div><strong>' . esc_html($item->post_title) . '</strong><span>' . esc_html($item_status) . ' · ' . esc_html(get_the_modified_date('j F Y H:i', $item)) . '</span></div><a href="' . esc_url($url) . '">Öppna</a></li>';
        }
        $html .= '</ul>';
        if (! self::can('ssf_news_edit')) {
            return $html;
        }
        $settings = self::home_settings();
        $html .= '<section class="ssf-workspace-panel"><h2>Startsidans nyheter</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ssf-workspace-form"><input type="hidden" name="action" value="ssf_news_home_settings">' . wp_nonce_field('ssf_news_home_settings', '_wpnonce', true, false);
        $html .= '<fieldset><legend>Nyheter från SSF</legend><label for="ssf-home-count">Antal artiklar</label><input id="ssf-home-count" name="ssf_count" type="number" min="1" max="6" value="' . esc_attr((string) $settings['ssf_count']) . '"><label for="ssf-home-columns">Kolumner på desktop</label><select id="ssf-home-columns" name="ssf_columns">' . self::column_options((int) $settings['ssf_columns']) . '</select></fieldset>';
        $html .= '<fieldset><legend>I medierna</legend><label class="ssf-workspace-check"><input type="checkbox" name="show_media" value="1"' . checked('1', $settings['show_media'], false) . '> Visa ”I medierna” på startsidan</label><label for="ssf-media-count">Antal artiklar</label><input id="ssf-media-count" name="media_count" type="number" min="1" max="6" value="' . esc_attr((string) $settings['media_count']) . '"><label for="ssf-media-columns">Kolumner på desktop</label><select id="ssf-media-columns" name="media_columns">' . self::column_options((int) $settings['media_columns']) . '</select></fieldset>';
        return $html . '<button class="ssf-workspace-button" type="submit">Spara</button></form></section>';
    }

    private static function column_options(int $selected_columns): string
    {
        $html = '';
        foreach (array(1, 2, 3) as $columns) {
            $html .= '<option value="' . $columns . '"' . selected($selected_columns, $columns, false) . '>' . $columns . '</option>';
        }
        return $html;
    }

    public static function tasks(int $user_id): array
    {
        if (! user_can($user_id, 'ssf_news_suggestions_manage') && ! user_can($user_id, 'manage_options')) {
            return array();
        }
        $count = self::count_suggestions();
        return $count ? array(array(
            'id' => 'news-suggestions', 'title' => $count . ' nya artikelförslag att granska',
            'description' => 'Omvärldsbevakning, manuella fynd och medlemstips', 'priority' => 'high',
            'updated_at' => current_time('mysql'), 'url' => SSF_Workspace::url('nyheter/forslag'),
        )) : array();
    }

    public static function badge(): int
    {
        return self::count_suggestions();
    }

    private static function status_label(string $status): string
    {
        return array('draft' => 'Utkast', 'pending' => 'Väntar på granskning', 'future' => 'Schemalagd', 'publish' => 'Publicerad', 'private' => 'Endast internt')[$status] ?? $status;
    }

    private static function own_news(int $id): string
    {
        self::require_cap('ssf_news_edit');
        if ($id) {
            $post = get_post($id);
            if (! $post || 'post' !== $post->post_type || 'media' === self::post_meta($id, self::META_TYPE) || ! current_user_can('edit_post', $id)) {
                return '<p>Artikeln kunde inte hittas.</p>';
            }
            return self::own_editor($post);
        }
        if (isset($_GET['new'])) {
            return self::own_editor(null);
        }
        $posts = get_posts(array('post_type' => 'post', 'post_status' => array('draft', 'pending', 'future', 'publish'), 'numberposts' => 80, 'orderby' => 'modified', 'order' => 'DESC'));
        $html = '<h1>Egna nyheter</h1>' . self::tabs('egna') . '<p><a class="ssf-workspace-button" href="' . esc_url(add_query_arg('new', '1', SSF_Workspace::url('nyheter/egna'))) . '">+ Ny artikel</a></p><ul class="ssf-workspace-list">';
        foreach ($posts as $post) {
            if ('media' === self::post_meta($post->ID, self::META_TYPE) || ! current_user_can('edit_post', $post->ID)) {
                continue;
            }
            $type = 'ship' === self::post_meta($post->ID, self::META_TYPE) ? 'Från medlemsfartygen' : 'SSF-nyhet';
            $html .= '<li><div><strong>' . esc_html($post->post_title) . '</strong><span>' . esc_html($type . ' · ' . self::status_label($post->post_status)) . '</span></div><a href="' . esc_url(SSF_Workspace::url('nyheter/egna/' . $post->ID)) . '">Öppna</a></li>';
        }
        return $html . '</ul>';
    }

    private static function own_editor(?WP_Post $post): string
    {
        if ($post && isset($_GET['preview'])) {
            return self::preview($post, 'egna');
        }
        $id = $post ? (int) $post->ID : 0;
        $type = $post ? self::post_meta($id, self::META_TYPE) : 'ssf';
        $featured = $post && '1' === self::post_meta($id, self::META_FEATURED);
        $html = '<h1>' . ($post ? 'Redigera nyhet' : 'Ny artikel') . '</h1><p><a href="' . esc_url(SSF_Workspace::url('nyheter/egna')) . '">← Egna nyheter</a></p>' . self::notice_html();
        $html .= '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="ssf-workspace-form"><input type="hidden" name="action" value="ssf_news_external_save"><input type="hidden" name="mode" value="own"><input type="hidden" name="post_id" value="' . esc_attr((string) $id) . '">' . wp_nonce_field('ssf_news_save_' . $id, '_wpnonce', true, false)
            . '<label for="ssf-title">Rubrik</label><input id="ssf-title" name="title" required maxlength="200" value="' . esc_attr($post ? $post->post_title : '') . '"><label for="ssf-excerpt">Ingress</label><textarea id="ssf-excerpt" name="summary" rows="4">' . esc_textarea($post ? $post->post_excerpt : '') . '</textarea><label for="ssf-content">Artikeltext</label><textarea id="ssf-content" name="content" rows="16">' . esc_textarea($post ? $post->post_content : '') . '</textarea><label for="ssf-type">Typ</label><select id="ssf-type" name="news_type"><option value="ssf"' . selected($type, 'ssf', false) . '>SSF-nyhet</option><option value="ship"' . selected($type, 'ship', false) . '>Från medlemsfartygen</option></select><label class="ssf-workspace-check"><input type="checkbox" name="featured_news" value="1"' . checked($featured, true, false) . '> Utvald på nyhetssidan</label><p class="ssf-workspace-help">Den utvalda nyheten visas större i filtret Alla när den är publicerad.</p><label for="ssf-image">Utvald bild (valfri)</label><input id="ssf-image" name="featured_image" type="file" accept="image/jpeg,image/png,image/webp"><div class="ssf-workspace-form-actions"><button class="ssf-workspace-button" name="intent" value="draft">Spara utkast</button>';
        if (self::can('ssf_news_publish')) {
            $html .= '<button class="ssf-workspace-button" name="intent" value="publish">Publicera</button>';
            if ($post && 'publish' === $post->post_status) {
                $html .= '<button class="ssf-workspace-button ssf-workspace-button--danger" name="intent" value="unpublish">Avpublicera</button>';
            }
        }
        $html .= '<button class="ssf-workspace-button ssf-workspace-button--secondary" name="intent" value="preview">Spara och förhandsgranska</button>';
        if ($post) {
            $html .= '<a target="_blank" rel="noopener" href="' . esc_url(get_preview_post_link($post)) . '">Förhandsgranska ↗</a>';
        }
        return $html . '</div></form>';
    }

    private static function media_news(int $id): string
    {
        self::require_cap('ssf_news_edit');
        if ($id) {
            $post = get_post($id);
            if (! $post || 'post' !== $post->post_type || 'media' !== self::post_meta($id, self::META_TYPE) || ! current_user_can('edit_post', $id)) {
                return '<p>Artikeln kunde inte hittas.</p>';
            }
            return self::external_editor($post);
        }
        $posts = get_posts(array('post_type' => 'post', 'post_status' => array('draft', 'pending', 'future', 'publish'), 'numberposts' => 80, 'meta_key' => self::META_TYPE, 'meta_value' => 'media', 'orderby' => 'modified', 'order' => 'DESC'));
        $html = '<h1>I medierna</h1>' . self::tabs('medierna') . '<p><a class="ssf-workspace-button" href="' . esc_url(SSF_Workspace::url('nyheter/extern')) . '">+ Lägg till extern artikel</a></p><ul class="ssf-workspace-list">';
        foreach ($posts as $post) {
            $html .= '<li><div><strong>' . esc_html($post->post_title) . '</strong><span>' . esc_html(self::post_meta($post->ID, self::META_SOURCE) . ' · ' . self::status_label($post->post_status)) . '</span></div><a href="' . esc_url(SSF_Workspace::url('nyheter/medierna/' . $post->ID)) . '">Öppna</a></li>';
        }
        return $html . '</ul>';
    }

    private static function manual_form(): string
    {
        self::require_cap('ssf_news_suggestions_manage');
        return '<h1>Lägg till extern artikel</h1><p><a href="' . esc_url(SSF_Workspace::url('nyheter/forslag')) . '">← Artikelförslag</a></p>' . self::notice_html() . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ssf-workspace-form"><input type="hidden" name="action" value="ssf_news_manual">' . wp_nonce_field('ssf_news_manual', '_wpnonce', true, false) . '<label for="ssf-external-url">Artikelns URL</label><input id="ssf-external-url" name="url" type="url" inputmode="url" required placeholder="https://…"><p class="ssf-workspace-help">Vi hämtar bara säker metadata: rubrik, kort beskrivning, canonical URL, datum och Open Graph-bild. Artikeltexten kopieras aldrig.</p><button class="ssf-workspace-button" type="submit">Hämta och skapa förslag</button></form>';
    }

    private static function tip_form(): string
    {
        if (! self::can('ssf_news_tip')) {
            return '<p>Tipsfunktionen är tillgänglig för registrerade fartygsombud.</p>';
        }
        return '<h1>Tipsa om artikel</h1>' . self::notice_html() . '<p>Tipsa SSF:s webbredaktör om en publicerad artikel. Endast redaktionen kan publicera tipset.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ssf-workspace-form"><input type="hidden" name="action" value="ssf_news_tip">' . wp_nonce_field('ssf_news_tip', '_wpnonce', true, false) . '<label for="ssf-tip-url">Artikelns URL</label><input id="ssf-tip-url" name="url" type="url" inputmode="url" required><label for="ssf-tip-comment">Kommentar (valfri)</label><textarea id="ssf-tip-comment" name="comment" rows="4" maxlength="1000"></textarea><button class="ssf-workspace-button" type="submit">Skicka tips</button></form>';
    }

    private static function suggestion_origin(string $origin): string
    {
        return array('monitoring' => 'Omvärldsbevakning', 'member_tip' => 'Medlemstips', 'editor_manual' => 'Manuellt hittad')[$origin] ?? $origin;
    }

    private static function suggestions(int $id): string
    {
        self::require_cap('ssf_news_suggestions_manage');
        if ($id) {
            $post = get_post($id);
            if (! $post || self::SUGGESTION_TYPE !== $post->post_type) {
                return '<p>Förslaget kunde inte hittas.</p>';
            }
            return self::suggestion_detail($post);
        }
        $filter = sanitize_key((string) ($_GET['origin'] ?? 'all'));
        $args = array('post_type' => self::SUGGESTION_TYPE, 'post_status' => 'private', 'numberposts' => 100, 'orderby' => array('meta_value_num' => 'DESC', 'date' => 'DESC'), 'meta_key' => '_ssf_suggestion_priority');
        if (in_array($filter, array('monitoring', 'member_tip', 'editor_manual'), true)) {
            $args['meta_query'] = array(array('key' => '_ssf_suggestion_origin', 'value' => $filter));
        }
        $posts = get_posts($args);
        $html = '<h1>Artikelförslag</h1>' . self::tabs('forslag') . self::notice_html() . '<div class="ssf-workspace-form-actions"><a class="ssf-workspace-button" href="' . esc_url(SSF_Workspace::url('nyheter/extern')) . '">+ Lägg till extern artikel</a></div><nav class="ssf-news-filters" aria-label="Filtrera artikelförslag">';
        foreach (array('all' => 'Alla', 'monitoring' => 'Omvärldsbevakning', 'member_tip' => 'Medlemstips', 'editor_manual' => 'Manuellt hittade') as $key => $label) {
            $html .= '<a' . ($filter === $key ? ' aria-current="page"' : '') . ' href="' . esc_url(add_query_arg('origin', $key, SSF_Workspace::url('nyheter/forslag'))) . '">' . esc_html($label) . '</a>';
        }
        $html .= '</nav><div class="ssf-suggestion-grid">';
        $visible = 0;
        foreach ($posts as $post) {
            $status = self::post_meta($post->ID, '_ssf_suggestion_status');
            if (! in_array($status, array('new', 'reviewing'), true)) {
                continue;
            }
            $visible++;
            $source = self::post_meta($post->ID, '_ssf_suggestion_source');
            $origin = self::suggestion_origin(self::post_meta($post->ID, '_ssf_suggestion_origin'));
            $topics = (array) get_post_meta($post->ID, '_ssf_suggestion_topics', true);
            $ships = array_filter(array_map('get_the_title', (array) get_post_meta($post->ID, '_ssf_suggestion_ships', true)));
            $html .= '<article class="ssf-suggestion-card"><div class="ssf-suggestion-card__meta"><span>' . esc_html($origin) . '</span><span>Status: ' . esc_html(self::status_label($post->post_status)) . '</span>' . ('1' === self::post_meta($post->ID, '_ssf_suggestion_priority') ? '<span>★ Prioriterad källa</span>' : '') . '</div><h2>' . esc_html($post->post_title) . '</h2><p><strong>' . esc_html($source) . '</strong>' . (self::post_meta($post->ID, '_ssf_suggestion_original_date') ? ' · ' . esc_html(wp_date('j F Y', strtotime(self::post_meta($post->ID, '_ssf_suggestion_original_date')))) : '') . '</p>';
            if ($post->post_excerpt) {
                $html .= '<p>' . esc_html(wp_trim_words($post->post_excerpt, 32)) . '</p>';
            }
            if ($ships || $topics) {
                $html .= '<p class="ssf-news-tags">' . esc_html(implode(' · ', array_merge($ships, $topics))) . '</p>';
            }
            $html .= '<div class="ssf-workspace-form-actions"><a target="_blank" rel="noopener noreferrer" href="' . esc_url(self::post_meta($post->ID, '_ssf_suggestion_canonical')) . '">Läs original ↗</a><a class="ssf-workspace-button" href="' . esc_url(SSF_Workspace::url('nyheter/forslag/' . $post->ID)) . '">Granska</a></div></article>';
        }
        return $html . ($visible ? '' : '<p class="ssf-workspace-empty">Inga nya förslag i det här filtret.</p>') . '</div>';
    }

    private static function suggestion_detail(WP_Post $post): string
    {
        $id = (int) $post->ID;
        $source_id = (int) get_post_meta($id, '_ssf_suggestion_source_id', true);
        $allow_preview = $source_id && '1' === self::post_meta($source_id, '_ssf_source_allow_preview');
        $html = '<h1>Granska artikelförslag</h1><p><a href="' . esc_url(SSF_Workspace::url('nyheter/forslag')) . '">← Artikelförslag</a></p>' . self::notice_html() . '<article class="ssf-workspace-panel"><p class="ssf-news-kicker">' . esc_html(self::suggestion_origin(self::post_meta($id, '_ssf_suggestion_origin'))) . '</p><p><span class="ssf-status">Status: ' . esc_html(self::status_label($post->post_status)) . '</span></p><h2>' . esc_html($post->post_title) . '</h2><p><strong>Källa:</strong> ' . esc_html(self::post_meta($id, '_ssf_suggestion_source')) . '</p><p>' . esc_html($post->post_excerpt) . '</p><p><a target="_blank" rel="noopener noreferrer" href="' . esc_url(self::post_meta($id, '_ssf_suggestion_canonical')) . '">Läs original ↗</a></p></article>';
        if (self::post_meta($id, '_ssf_suggestion_comment')) {
            $html .= '<section class="ssf-workspace-panel"><h2>Kommentar från tipsaren</h2><p>' . esc_html(self::post_meta($id, '_ssf_suggestion_comment')) . '</p></section>';
        }
        if ('new' !== self::post_meta($id, '_ssf_suggestion_status')) {
            return $html . '<p class="ssf-workspace-confirmation" role="status">Förslaget är redan behandlat och kan inte konverteras igen.</p>';
        }
        $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ssf-workspace-form"><input type="hidden" name="action" value="ssf_news_suggestion"><input type="hidden" name="suggestion_id" value="' . esc_attr((string) $id) . '">' . wp_nonce_field('ssf_news_suggestion_' . $id, '_wpnonce', true, false) . '<div class="ssf-workspace-form-actions"><button class="ssf-workspace-button" name="intent" value="convert">Skapa I medierna</button><button class="ssf-workspace-button ssf-workspace-button--danger" name="intent" value="dismiss">Inte relevant</button></div>';
        if (self::post_meta($id, '_ssf_suggestion_image') && ! $allow_preview) {
            $html .= '<p class="ssf-workspace-help">Källan tillåter inte extern bildförhandsvisning. Textkort används tills detta aktiveras på källan.</p>';
        }
        return $html . '</form>';
    }

    private static function external_editor(WP_Post $post): string
    {
        if (isset($_GET['preview'])) {
            return self::preview($post, 'medierna');
        }
        $id = (int) $post->ID;
        $mode = self::post_meta($id, self::META_IMAGE_MODE) ?: 'none';
        $source_id = (int) get_post_meta($id, '_ssf_news_source_id', true);
        $preview_allowed = $source_id && '1' === self::post_meta($source_id, '_ssf_source_allow_preview');
        $ship_ids = array_map('intval', (array) get_post_meta($id, self::META_SHIPS, true));
        $topics = wp_get_object_terms($id, self::TAXONOMY, array('fields' => 'names'));
        $featured = '1' === self::post_meta($id, self::META_FEATURED);
        $html = '<h1>I medierna</h1><p><a href="' . esc_url(SSF_Workspace::url('nyheter/medierna')) . '">← I medierna</a></p>' . self::notice_html() . '<section class="ssf-workspace-panel"><p class="ssf-news-kicker">Originalartikel</p><h2>' . esc_html(self::post_meta($id, '_ssf_news_original_title') ?: $post->post_title) . '</h2><p><strong>' . esc_html(self::post_meta($id, self::META_SOURCE)) . '</strong>' . (self::post_meta($id, '_ssf_news_original_date') ? ' · ' . esc_html(wp_date('j F Y', strtotime(self::post_meta($id, '_ssf_news_original_date')))) : '') . '</p><a target="_blank" rel="noopener noreferrer" href="' . esc_url(self::post_meta($id, self::META_EXTERNAL_URL)) . '">Läs original ↗</a></section>';
        $html .= '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="ssf-workspace-form"><input type="hidden" name="action" value="ssf_news_external_save"><input type="hidden" name="mode" value="external"><input type="hidden" name="post_id" value="' . esc_attr((string) $id) . '">' . wp_nonce_field('ssf_news_save_' . $id, '_wpnonce', true, false) . '<label for="ssf-title">SSF-rubrik</label><input id="ssf-title" name="title" required maxlength="200" value="' . esc_attr($post->post_title) . '"><label for="ssf-summary">Kort information (frivillig)</label><textarea id="ssf-summary" name="summary" maxlength="600" rows="5" aria-describedby="ssf-summary-help">' . esc_textarea($post->post_excerpt) . '</textarea><p id="ssf-summary-help" class="ssf-workspace-help">Frivillig. Använd om du vill förklara varför artikeln är intressant.</p>';
        $html .= self::ship_select($ship_ids) . '<label for="ssf-topics">Ämnen (kommaseparerade)</label><input id="ssf-topics" name="topics" value="' . esc_attr(is_wp_error($topics) ? '' : implode(', ', $topics)) . '"><label class="ssf-workspace-check"><input type="checkbox" name="featured_news" value="1"' . checked($featured, true, false) . '> Utvald på nyhetssidan</label><p class="ssf-workspace-help">Den utvalda nyheten visas större i filtret Alla när den är publicerad.</p><fieldset><legend>Bildläge</legend><label class="ssf-workspace-check"><input type="radio" name="image_mode" value="external_preview"' . checked($mode, 'external_preview', false) . disabled(!$preview_allowed, true, false) . '> Extern Open Graph-förhandsvisning</label>' . ($preview_allowed ? '' : '<p class="ssf-workspace-help">Extern förhandsvisning måste tillåtas på en matchande bevakningskälla.</p>') . '<label class="ssf-workspace-check"><input type="radio" name="image_mode" value="ssf_image"' . checked($mode, 'ssf_image', false) . '> SSF:s egen / licensierad bild</label><label class="ssf-workspace-check"><input type="radio" name="image_mode" value="none"' . checked($mode, 'none', false) . '> Ingen bild</label></fieldset><label for="ssf-image">SSF/licensierad bild</label><input id="ssf-image" name="featured_image" type="file" accept="image/jpeg,image/png,image/webp"><label for="ssf-photographer">Fotograf</label><input id="ssf-photographer" name="photographer" value="' . esc_attr(self::post_meta($id, '_ssf_news_photographer')) . '"><label for="ssf-rights-source">Bildkälla</label><input id="ssf-rights-source" name="rights_source" value="' . esc_attr(self::post_meta($id, '_ssf_news_rights_source')) . '"><label for="ssf-rights">Rättighetsnotering</label><input id="ssf-rights" name="rights_note" value="' . esc_attr(self::post_meta($id, '_ssf_news_rights_note')) . '"><div class="ssf-workspace-form-actions"><button class="ssf-workspace-button" name="intent" value="draft">Spara utkast</button>';
        if (self::can('ssf_news_publish')) {
            $html .= '<button class="ssf-workspace-button" name="intent" value="publish">Publicera</button>';
            if ('publish' === $post->post_status) {
                $html .= '<button class="ssf-workspace-button ssf-workspace-button--danger" name="intent" value="unpublish">Avpublicera</button>';
            }
        }
        return $html . '<button class="ssf-workspace-button ssf-workspace-button--secondary" name="intent" value="preview">Spara och förhandsgranska</button></div></form>';
    }

    private static function preview(WP_Post $post, string $section): string
    {
        if (defined('SSF_SITE_URL')) {
            wp_enqueue_style('ssf-news-preview', SSF_SITE_URL . 'assets/css/ssf-site.css', array(), SSF_WORKSPACE_VERSION);
            wp_enqueue_script('ssf-news-preview', SSF_SITE_URL . 'assets/js/ssf-site.js', array(), SSF_WORKSPACE_VERSION, true);
        }
        $url = SSF_Workspace::url('nyheter/' . $section . '/' . $post->ID);
        $html = '<h1>Förhandsgranska</h1><p>Så här visas det sparade innehållet. Utkastet är endast synligt för redaktionen.</p><p><a class="ssf-workspace-button" href="' . esc_url($url) . '">← Till redigering</a></p>';
        if (function_exists('ssf_site_render_news_card')) {
            $html .= '<div class="ssf-news-preview-card">' . ssf_site_render_news_card($post, add_query_arg('preview', '1', $url)) . '</div>';
        }
        if ('egna' === $section && function_exists('ssf_render_news_article')) {
            ob_start();
            ssf_render_news_article($post);
            $html .= (string) ob_get_clean();
        }
        return $html;
    }

    private static function ship_select(array $selected): string
    {
        $html = '<fieldset><legend>Relaterade medlemsfartyg</legend><div class="ssf-news-ship-list">';
        foreach (get_posts(array('post_type' => 'medlemsfartyg', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC')) as $ship) {
            $html .= '<label class="ssf-workspace-check"><input type="checkbox" name="ship_ids[]" value="' . esc_attr((string) $ship->ID) . '"' . checked(in_array((int) $ship->ID, $selected, true), true, false) . '> ' . esc_html($ship->post_title) . '</label>';
        }
        return $html . '</div></fieldset>';
    }

    private static function sources(int $id): string
    {
        self::require_cap('ssf_news_sources_manage');
        if ($id || isset($_GET['new'])) {
            return self::source_editor($id);
        }
        $sources = get_posts(array('post_type' => self::SOURCE_TYPE, 'post_status' => 'private', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        $html = '<h1>Omvärldsbevakning</h1>' . self::tabs('bevakning') . self::notice_html() . '<p>Aktiva källor kontrolleras i bakgrunden ungefär två gånger per dygn. En publik sidvisning väntar aldrig på en extern källa.</p><p><a class="ssf-workspace-button" href="' . esc_url(add_query_arg('new', '1', SSF_Workspace::url('nyheter/bevakning'))) . '">+ Lägg till källa</a></p><div class="ssf-source-list">';
        foreach ($sources as $source) {
            $active = '1' === self::post_meta($source->ID, '_ssf_source_active');
            $priority = '1' === self::post_meta($source->ID, '_ssf_source_priority');
            $html .= '<article class="ssf-workspace-panel"><div class="ssf-source-heading"><h2>' . esc_html($source->post_title) . '</h2><div><span class="ssf-status">' . ($active ? 'Aktiv' : 'Pausad') . '</span>' . ($priority ? '<span class="ssf-status">★ Prioriterad</span>' : '') . '</div></div><dl><dt>Metod</dt><dd>' . esc_html(self::post_meta($source->ID, '_ssf_source_feed_url') ? 'RSS/Atom' : 'Konfigurerad sida') . '</dd><dt>Senaste kontroll</dt><dd>' . esc_html(self::post_meta($source->ID, '_ssf_source_last_checked') ?: 'Aldrig') . '</dd><dt>Senaste resultat</dt><dd>' . esc_html(self::post_meta($source->ID, '_ssf_source_last_result') ?: 'Inte kontrollerad') . '</dd><dt>Felstatus</dt><dd>' . esc_html(self::post_meta($source->ID, '_ssf_source_error') ?: 'OK') . '</dd></dl><div class="ssf-workspace-form-actions"><a href="' . esc_url(SSF_Workspace::url('nyheter/bevakning/' . $source->ID)) . '">Redigera</a><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_news_source_action"><input type="hidden" name="source_id" value="' . esc_attr((string) $source->ID) . '">' . wp_nonce_field('ssf_news_source_action_' . $source->ID, '_wpnonce', true, false) . '<button class="ssf-workspace-button" name="intent" value="check">Kontrollera nu</button><button class="ssf-workspace-button ssf-workspace-button--secondary" name="intent" value="toggle">' . ($active ? 'Pausa' : 'Aktivera') . '</button></form></div></article>';
        }
        return $html . ($sources ? '' : '<p class="ssf-workspace-empty">Inga bevakningskällor har lagts till.</p>') . '</div>';
    }

    private static function source_editor(int $id): string
    {
        $source = $id ? get_post($id) : null;
        if ($id && (! $source || self::SOURCE_TYPE !== $source->post_type)) {
            return '<p>Källan kunde inte hittas.</p>';
        }
        return '<h1>' . ($source ? 'Redigera källa' : 'Lägg till källa') . '</h1><p><a href="' . esc_url(SSF_Workspace::url('nyheter/bevakning')) . '">← Omvärldsbevakning</a></p>' . self::notice_html() . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ssf-workspace-form"><input type="hidden" name="action" value="ssf_news_source_save"><input type="hidden" name="source_id" value="' . esc_attr((string) $id) . '">' . wp_nonce_field('ssf_news_source_save_' . $id, '_wpnonce', true, false) . '<label for="ssf-source-name">Källans namn</label><input id="ssf-source-name" name="name" required value="' . esc_attr($source ? $source->post_title : '') . '"><label for="ssf-base-url">Webbplats</label><input id="ssf-base-url" name="base_url" type="url" required value="' . esc_attr($source ? self::post_meta($id, '_ssf_source_base_url') : '') . '"><label for="ssf-feed-url">RSS/Atom (valfri, prioriteras)</label><input id="ssf-feed-url" name="feed_url" type="url" value="' . esc_attr($source ? self::post_meta($id, '_ssf_source_feed_url') : '') . '"><label for="ssf-discovery-url">Konfigurerad artikel-/indexsida (valfri)</label><input id="ssf-discovery-url" name="discovery_url" type="url" value="' . esc_attr($source ? self::post_meta($id, '_ssf_source_discovery_url') : '') . '"><label for="ssf-keywords">Extra nyckelord/ämnen (kommaseparerade)</label><input id="ssf-keywords" name="keywords" value="' . esc_attr($source ? self::post_meta($id, '_ssf_source_keywords') : '') . '"><label class="ssf-workspace-check"><input type="checkbox" name="active" value="1"' . checked(!$source || '1' === self::post_meta($id, '_ssf_source_active'), true, false) . '> Aktiv</label><label class="ssf-workspace-check"><input type="checkbox" name="priority" value="1"' . checked($source && '1' === self::post_meta($id, '_ssf_source_priority'), true, false) . '> ★ Prioriterad</label><label class="ssf-workspace-check"><input type="checkbox" name="allow_preview" value="1"' . checked($source && '1' === self::post_meta($id, '_ssf_source_allow_preview'), true, false) . '> Tillåt extern Open Graph-bild</label><p class="ssf-workspace-help">Extern bild innebär att besökarens webbläsare kontaktar källans bildserver. Bilden kopieras eller cachas inte av SSF.</p><button class="ssf-workspace-button" type="submit">Spara källa</button></form>';
    }

    public static function handle_home_settings(): void
    {
        self::require_cap('ssf_news_edit');
        check_admin_referer('ssf_news_home_settings');
        $settings = self::sanitize_home_settings(array(
            'ssf_count' => wp_unslash($_POST['ssf_count'] ?? ''),
            'ssf_columns' => wp_unslash($_POST['ssf_columns'] ?? ''),
            'show_media' => ! empty($_POST['show_media']) ? '1' : '0',
            'media_count' => wp_unslash($_POST['media_count'] ?? ''),
            'media_columns' => wp_unslash($_POST['media_columns'] ?? ''),
        ));
        update_option(self::HOME_SETTINGS, $settings, false);
        self::redirect('nyheter', 'Inställningarna för startsidans nyheter sparades.');
    }

    public static function handle_manual(): void
    {
        self::require_cap('ssf_news_suggestions_manage');
        check_admin_referer('ssf_news_manual');
        $url = esc_url_raw((string) wp_unslash($_POST['url'] ?? ''));
        $data = self::fetch_metadata($url);
        if (is_wp_error($data)) {
            self::redirect('nyheter/extern', '', $data->get_error_message());
        }
        $data['origin'] = 'editor_manual';
        $data['source_id'] = self::source_for_url((string) ($data['canonical_url'] ?? $url));
        $data['priority'] = $data['source_id'] && '1' === self::post_meta((int) $data['source_id'], '_ssf_source_priority');
        $id = self::create_suggestion($data);
        if (is_wp_error($id)) {
            self::redirect('nyheter/forslag', '', $id->get_error_message());
        }
        self::redirect('nyheter/forslag/' . $id, 'Metadata hämtades och förslaget skapades.');
    }

    public static function handle_tip(): void
    {
        self::require_cap('ssf_news_tip');
        check_admin_referer('ssf_news_tip');
        $url = esc_url_raw((string) wp_unslash($_POST['url'] ?? ''));
        if (! self::is_safe_url($url)) {
            self::redirect('tipsa-om-artikel', '', 'Ange en giltig publik artikel-URL.');
        }
        $data = self::fetch_metadata($url);
        if (is_wp_error($data)) {
            $data = array('url' => $url, 'canonical_url' => $url, 'title' => $url);
        }
        $data['origin'] = 'member_tip';
        $data['source_id'] = self::source_for_url((string) ($data['canonical_url'] ?? $url));
        $data['priority'] = $data['source_id'] && '1' === self::post_meta((int) $data['source_id'], '_ssf_source_priority');
        $data['comment'] = sanitize_textarea_field((string) wp_unslash($_POST['comment'] ?? ''));
        $data['user_id'] = get_current_user_id();
        $data['ship_ids'] = self::user_ship_ids(get_current_user_id());
        $result = self::create_suggestion($data);
        if (is_wp_error($result)) {
            self::redirect('tipsa-om-artikel', '', $result->get_error_message());
        }
        self::redirect('tipsa-om-artikel', 'Tack! Tipset har skickats till webbredaktionen.');
    }

    public static function handle_suggestion(): void
    {
        self::require_cap('ssf_news_suggestions_manage');
        $id = absint($_POST['suggestion_id'] ?? 0);
        check_admin_referer('ssf_news_suggestion_' . $id);
        $suggestion = get_post($id);
        if (! $suggestion || self::SUGGESTION_TYPE !== $suggestion->post_type) {
            wp_die('Förslaget kunde inte hittas.', '', array('response' => 404));
        }
        $intent = sanitize_key((string) ($_POST['intent'] ?? ''));
        $converted = (int) get_post_meta($id, '_ssf_suggestion_post_id', true);
        if ($converted && 'post' === get_post_type($converted)) {
            self::redirect('nyheter/medierna/' . $converted, 'Förslaget har redan ett utkast.');
        }
        if ('new' !== self::post_meta($id, '_ssf_suggestion_status')) {
            self::redirect('nyheter/forslag', '', 'Förslaget är redan behandlat.');
        }
        if ('dismiss' === $intent) {
            update_post_meta($id, '_ssf_suggestion_status', 'dismissed');
            self::redirect('nyheter/forslag', 'Förslaget markerades som inte relevant.');
        }
        if ('convert' !== $intent || ! self::can('ssf_news_edit')) {
            wp_die('Ogiltig åtgärd.', '', array('response' => 400));
        }
        $post_id = wp_insert_post(array(
            'post_type' => 'post', 'post_status' => 'draft', 'post_title' => $suggestion->post_title,
            'post_excerpt' => '', 'post_author' => get_current_user_id(),
        ), true);
        if (is_wp_error($post_id)) {
            self::redirect('nyheter/forslag/' . $id, '', 'Utkastet kunde inte skapas.');
        }
        $source_id = (int) get_post_meta($id, '_ssf_suggestion_source_id', true);
        $allow = $source_id && '1' === self::post_meta($source_id, '_ssf_source_allow_preview');
        $meta = array(
            self::META_TYPE => 'media', self::META_EXTERNAL_URL => self::post_meta($id, '_ssf_suggestion_canonical'),
            '_ssf_news_original_url' => self::post_meta($id, '_ssf_suggestion_url'),
            '_ssf_news_canonical_url' => self::post_meta($id, '_ssf_suggestion_canonical'),
            '_ssf_news_origin' => self::post_meta($id, '_ssf_suggestion_origin'),
            self::META_SOURCE => self::post_meta($id, '_ssf_suggestion_source'), '_ssf_news_original_title' => $suggestion->post_title,
            '_ssf_news_original_date' => self::post_meta($id, '_ssf_suggestion_original_date'),
            self::META_EXTERNAL_IMAGE => self::post_meta($id, '_ssf_suggestion_image'),
            self::META_IMAGE_MODE => ($allow && self::post_meta($id, '_ssf_suggestion_image')) ? 'external_preview' : 'none',
            self::META_SHIPS => (array) get_post_meta($id, '_ssf_suggestion_ships', true), '_ssf_news_suggestion_id' => $id,
            '_ssf_news_source_id' => $source_id,
            '_ssf_news_external_preview_allowed' => $allow ? '1' : '0',
        );
        foreach ($meta as $key => $value) {
            update_post_meta((int) $post_id, $key, $value);
        }
        wp_set_object_terms((int) $post_id, (array) get_post_meta($id, '_ssf_suggestion_topics', true), self::TAXONOMY, false);
        update_post_meta($id, '_ssf_suggestion_status', 'converted');
        update_post_meta($id, '_ssf_suggestion_post_id', (int) $post_id);
        self::redirect('nyheter/medierna/' . $post_id, 'Utkastet skapades från artikelförslaget.');
    }

    public static function handle_external_save(): void
    {
        self::require_cap('ssf_news_edit');
        $id = absint($_POST['post_id'] ?? 0);
        check_admin_referer('ssf_news_save_' . $id);
        $post = $id ? get_post($id) : null;
        if ($id && (! $post || 'post' !== $post->post_type || ! current_user_can('edit_post', $id))) {
            wp_die('Du saknar åtkomst.', '', array('response' => 403));
        }
        $mode = sanitize_key((string) ($_POST['mode'] ?? 'own'));
        $intent = sanitize_key((string) ($_POST['intent'] ?? 'draft'));
        if (! in_array($mode, array('own', 'external'), true)
            || ('external' === $mode && (! $post || 'media' !== self::post_meta($id, self::META_TYPE)))
            || ('own' === $mode && $post && 'media' === self::post_meta($id, self::META_TYPE))) {
            wp_die('Ogiltig artikeltyp.', '', array('response' => 400));
        }
        if (in_array($intent, array('publish', 'unpublish'), true) && ! self::can('ssf_news_publish')
            || ($post && in_array($post->post_status, array('publish', 'future'), true) && ! self::can('ssf_news_publish'))) {
            wp_die('Publiceringsbehörighet krävs för denna åtgärd.', '', array('response' => 403));
        }
        $status = 'draft';
        if ('publish' === $intent && self::can('ssf_news_publish')) {
            $status = 'publish';
        } elseif ('unpublish' === $intent && self::can('ssf_news_publish')) {
            $status = 'draft';
        }
        $args = array(
            'post_type' => 'post', 'post_status' => $status,
            'post_title' => sanitize_text_field((string) wp_unslash($_POST['title'] ?? '')),
            'post_excerpt' => sanitize_textarea_field((string) wp_unslash($_POST['summary'] ?? '')),
        );
        if (! $args['post_title']) {
            wp_die('Ange en rubrik.', '', array('response' => 400));
        }
        if ('external' === $mode && mb_strlen($args['post_excerpt']) > 600) {
            wp_die('Kort information får vara högst 600 tecken.', '', array('response' => 400));
        }
        if ('own' === $mode) {
            $args['post_content'] = wp_kses_post((string) wp_unslash($_POST['content'] ?? ''));
        }
        if ($id) {
            $args['ID'] = $id;
            $result = wp_update_post($args, true);
        } else {
            $args['post_author'] = get_current_user_id();
            $result = wp_insert_post($args, true);
        }
        if (is_wp_error($result) || ! $result) {
            wp_die('Artikeln kunde inte sparas.', '', array('response' => 500));
        }
        $id = (int) $result;
        if ('external' === $mode) {
            update_post_meta($id, self::META_TYPE, 'media');
            $image_mode = sanitize_key((string) ($_POST['image_mode'] ?? 'none'));
            if (! in_array($image_mode, array('external_preview', 'ssf_image', 'none'), true)) {
                $image_mode = 'none';
            }
            $source_id = (int) get_post_meta($id, '_ssf_news_source_id', true);
            if ('external_preview' === $image_mode && (! $source_id || '1' !== self::post_meta($source_id, '_ssf_source_allow_preview'))) {
                $image_mode = 'none';
            }
            update_post_meta($id, self::META_IMAGE_MODE, $image_mode);
            update_post_meta($id, self::META_SHIPS, array_map('intval', (array) ($_POST['ship_ids'] ?? array())));
            foreach (array('photographer', 'rights_source', 'rights_note') as $field) {
                update_post_meta($id, '_ssf_news_' . $field, sanitize_text_field((string) wp_unslash($_POST[$field] ?? '')));
            }
            $topics = array_filter(array_map('trim', explode(',', sanitize_text_field((string) wp_unslash($_POST['topics'] ?? '')))));
            wp_set_object_terms($id, $topics, self::TAXONOMY, false);
        } else {
            $type = sanitize_key((string) ($_POST['news_type'] ?? 'ssf'));
            update_post_meta($id, self::META_TYPE, 'ship' === $type ? 'ship' : 'ssf');
        }
        self::set_featured($id, ! empty($_POST['featured_news']));
        self::handle_image_upload($id);
        $path = 'external' === $mode ? 'nyheter/medierna/' . $id : 'nyheter/egna/' . $id;
        if ('preview' === $intent) {
            wp_safe_redirect(add_query_arg('preview', '1', SSF_Workspace::url($path)));
            exit;
        }
        self::redirect($path, 'Artikeln sparades som ' . strtolower(self::status_label($status)) . '.');
    }

    private static function set_featured(int $post_id, bool $featured): void
    {
        if (! $featured) {
            delete_post_meta($post_id, self::META_FEATURED);
            return;
        }
        $featured_ids = get_posts(array(
            'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1,
            'meta_key' => self::META_FEATURED, 'meta_value' => '1', 'fields' => 'ids',
        ));
        foreach ($featured_ids as $featured_id) {
            if ((int) $featured_id !== $post_id) {
                delete_post_meta((int) $featured_id, self::META_FEATURED);
            }
        }
        update_post_meta($post_id, self::META_FEATURED, '1');
    }

    private static function handle_image_upload(int $post_id): void
    {
        if (empty($_FILES['featured_image']['name'])) {
            return;
        }
        if (! current_user_can('upload_files')) {
            return;
        }
        $file = $_FILES['featured_image'];
        $checked = wp_check_filetype_and_ext((string) ($file['tmp_name'] ?? ''), (string) ($file['name'] ?? ''));
        if (! in_array($checked['type'] ?? '', array('image/jpeg', 'image/png', 'image/webp'), true)) {
            wp_die('Bilden måste vara JPEG, PNG eller WebP.', '', array('response' => 400));
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $attachment = media_handle_upload('featured_image', $post_id);
        if (! is_wp_error($attachment)) {
            set_post_thumbnail($post_id, (int) $attachment);
        }
    }

    public static function handle_source_save(): void
    {
        self::require_cap('ssf_news_sources_manage');
        $id = absint($_POST['source_id'] ?? 0);
        check_admin_referer('ssf_news_source_save_' . $id);
        if ($id && self::SOURCE_TYPE !== get_post_type($id)) {
            wp_die('Källan kunde inte hittas.', '', array('response' => 404));
        }
        $urls = array();
        foreach (array('base_url', 'feed_url', 'discovery_url') as $field) {
            $urls[$field] = esc_url_raw((string) wp_unslash($_POST[$field] ?? ''));
            if ($urls[$field] && ! self::is_safe_url($urls[$field])) {
                self::redirect('nyheter/bevakning' . ($id ? '/' . $id : ''), '', 'Källans URL är inte en tillåten publik adress.');
            }
        }
        if (! $urls['base_url']) {
            self::redirect('nyheter/bevakning' . ($id ? '/' . $id : ''), '', 'Webbplats saknas.');
        }
        $args = array('post_type' => self::SOURCE_TYPE, 'post_status' => 'private', 'post_title' => sanitize_text_field((string) wp_unslash($_POST['name'] ?? '')));
        if ($id) {
            $args['ID'] = $id;
            $result = wp_update_post($args, true);
        } else {
            $result = wp_insert_post($args, true);
        }
        if (is_wp_error($result)) {
            wp_die('Källan kunde inte sparas.', '', array('response' => 500));
        }
        $id = (int) $result;
        foreach ($urls as $key => $value) {
            update_post_meta($id, '_ssf_source_' . $key, $value);
        }
        update_post_meta($id, '_ssf_source_keywords', sanitize_text_field((string) wp_unslash($_POST['keywords'] ?? '')));
        foreach (array('active', 'priority', 'allow_preview') as $key) {
            update_post_meta($id, '_ssf_source_' . $key, isset($_POST[$key]) ? '1' : '0');
        }
        self::redirect('nyheter/bevakning', 'Källan sparades.');
    }

    private static function source_for_url(string $url): int
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (! $host) {
            return 0;
        }
        foreach (get_posts(array('post_type' => self::SOURCE_TYPE, 'post_status' => 'private', 'numberposts' => -1)) as $source) {
            $source_host = strtolower((string) wp_parse_url(self::post_meta((int) $source->ID, '_ssf_source_base_url'), PHP_URL_HOST));
            if ($source_host && $source_host === $host) {
                return (int) $source->ID;
            }
        }
        return 0;
    }

    public static function handle_source_action(): void
    {
        self::require_cap('ssf_news_sources_manage');
        $id = absint($_POST['source_id'] ?? 0);
        check_admin_referer('ssf_news_source_action_' . $id);
        $source = get_post($id);
        if (! $source || self::SOURCE_TYPE !== $source->post_type) {
            wp_die('Källan kunde inte hittas.', '', array('response' => 404));
        }
        if ('toggle' === sanitize_key((string) ($_POST['intent'] ?? ''))) {
            update_post_meta($id, '_ssf_source_active', '1' === self::post_meta($id, '_ssf_source_active') ? '0' : '1');
            self::redirect('nyheter/bevakning', 'Källans status uppdaterades.');
        }
        if ('1' !== self::post_meta($id, '_ssf_source_active')) {
            self::redirect('nyheter/bevakning', '', 'Källan är pausad. Aktivera den först.');
        }
        self::queue_source($id);
        self::redirect('nyheter/bevakning', 'Kontrollen har startats i bakgrunden. Uppdatera sidan för att se resultatet.');
    }

    public static function run_monitoring(): void
    {
        $sources = get_posts(array('post_type' => self::SOURCE_TYPE, 'post_status' => 'private', 'numberposts' => -1, 'meta_key' => '_ssf_source_active', 'meta_value' => '1'));
        foreach ($sources as $source) {
            self::queue_source((int) $source->ID);
        }
    }

    private static function queue_source(int $id): void
    {
        if (! wp_next_scheduled('ssf_news_check_source', array($id))) {
            wp_schedule_single_event(time() + 1, 'ssf_news_check_source', array($id));
        }
        update_post_meta($id, '_ssf_source_last_result', 'Kontroll köad');
    }

    public static function background_source(int $id): void
    {
        try {
            self::check_source($id);
        } catch (Throwable $error) {
            self::source_result($id, 0, 'Error', 'Källan kunde inte behandlas.');
        }
    }

    private static function source_result(int $id, int $count, string $status, string $error = ''): void
    {
        update_post_meta($id, '_ssf_source_last_checked', current_time('mysql'));
        update_post_meta($id, '_ssf_source_last_result', $status . ': ' . $count . ' nya förslag');
        update_post_meta($id, '_ssf_source_error', sanitize_text_field($error));
    }

    public static function check_source(int $id)
    {
        $source = get_post($id);
        if (! $source || self::SOURCE_TYPE !== $source->post_type) {
            return new WP_Error('source_missing', 'Källan kunde inte hittas.');
        }
        if ('1' !== self::post_meta($id, '_ssf_source_active')) {
            return new WP_Error('source_paused', 'Källan är pausad. Aktivera den först.');
        }
        $feed = self::post_meta($id, '_ssf_source_feed_url');
        $discovery = self::post_meta($id, '_ssf_source_discovery_url');
        $url = $feed ?: $discovery;
        if (! $url) {
            $error = new WP_Error('source_url', 'Ange RSS/Atom eller en konfigurerad indexsida.');
            self::source_result($id, 0, 'Warning', $error->get_error_message());
            return $error;
        }
        $response = self::safe_fetch($url, 2097152);
        if (is_wp_error($response)) {
            self::source_result($id, 0, 'Error', $response->get_error_message());
            return $response;
        }
        $items = $feed ? self::feed_items($response['body'], $response['url']) : self::discovery_items($response['body'], $response['url']);
        $created = 0;
        foreach (array_slice($items, 0, 25) as $item) {
            if (empty($item['url']) || ! self::is_safe_url($item['url'])) {
                continue;
            }
            // Always resolve canonical + OG, including complete RSS entries. Feed text is never article content.
            $metadata = self::fetch_metadata($item['url']);
            if (! is_wp_error($metadata)) {
                $item = array_merge($item, array_filter($metadata));
            }
            $candidate_text = mb_strtolower((string) ($item['title'] ?? '') . ' ' . (string) ($item['description'] ?? ''));
            $custom_keywords = array_filter(array_map('trim', explode(',', self::post_meta($id, '_ssf_source_keywords'))));
            $custom_matches = array_values(array_filter($custom_keywords, static fn(string $keyword): bool => '' !== $keyword && false !== mb_strpos($candidate_text, mb_strtolower($keyword))));
            $relevance = self::match_relevance($candidate_text);
            if (! $relevance['ships'] && ! $relevance['topics'] && ! $custom_matches) {
                continue;
            }
            $item['origin'] = 'monitoring';
            $item['source'] = $source->post_title;
            $item['site_name'] = $source->post_title;
            $item['source_id'] = $id;
            $item['priority'] = '1' === self::post_meta($id, '_ssf_source_priority');
            $item['matched_keywords'] = $custom_matches;
            $result = self::create_suggestion($item);
            if (! is_wp_error($result)) {
                $created++;
            }
        }
        self::source_result($id, $created, 'OK');
        return $created;
    }

    private static function feed_items(string $xml, string $base): array
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            return array();
        }
        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $feed) {
            return array();
        }
        $items = array();
        $nodes = isset($feed->channel->item) ? $feed->channel->item : $feed->entry;
        foreach ($nodes as $entry) {
            $link = (string) $entry->link;
            if (! $link && isset($entry->link['href'])) {
                $link = (string) $entry->link['href'];
            }
            $items[] = array(
                'url' => self::resolve_url($base, $link), 'canonical_url' => self::resolve_url($base, $link),
                'title' => sanitize_text_field((string) $entry->title),
                'description' => sanitize_textarea_field(wp_strip_all_tags((string) ($entry->description ?: $entry->summary))),
                'published_at' => sanitize_text_field((string) ($entry->pubDate ?: $entry->published ?: $entry->updated)),
            );
        }
        return $items;
    }

    private static function discovery_items(string $html, string $base): array
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return array();
        }
        $items = array();
        foreach ($doc->getElementsByTagName('a') as $link) {
            $href = trim($link->getAttribute('href'));
            $title = sanitize_text_field(trim($link->textContent));
            if (! $href || mb_strlen($title) < 12) {
                continue;
            }
            $url = self::resolve_url($base, $href);
            if (wp_parse_url($url, PHP_URL_HOST) !== wp_parse_url($base, PHP_URL_HOST)) {
                continue;
            }
            $items[$url] = array('url' => $url, 'canonical_url' => $url, 'title' => $title);
        }
        return array_values($items);
    }
}
