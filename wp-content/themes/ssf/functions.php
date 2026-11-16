<?php
/**
 * Theme setup for SSF.
 *
 * @package SSF
 */

if (! defined('SSF_VERSION')) {
    define('SSF_VERSION', '0.3.4');
}

function ssf_setup(): void
{
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('responsive-embeds');
    add_theme_support('editor-styles');
    add_theme_support('html5', array('search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script'));

    register_nav_menus(
        array(
            'primary' => __('Primary menu', 'ssf'),
            'footer'  => __('Footer menu', 'ssf'),
        )
    );

    add_editor_style('style.css');
}
add_action('after_setup_theme', 'ssf_setup');

function ssf_enqueue_assets(): void
{
    wp_enqueue_style('ssf-style', get_stylesheet_uri(), array(), SSF_VERSION);
    wp_enqueue_script('ssf-theme', get_template_directory_uri() . '/assets/theme.js', array(), SSF_VERSION, true);
}
add_action('wp_enqueue_scripts', 'ssf_enqueue_assets');

/** Shared ordinary-news rendering used by the public template and private previews. */
function ssf_news_featured_figure(WP_Post $post): string
{
    $attachment_id = (int) get_post_thumbnail_id($post->ID);
    if (! $attachment_id) {
        return '';
    }
    $image = wp_get_attachment_image($attachment_id, 'large', false, array(
        'sizes' => '(max-width: 720px) calc(100vw - 32px), (max-width: 900px) calc(100vw - 48px), 860px',
        'decoding' => 'async',
    ));
    if (! $image) {
        return '';
    }
    $attachment = get_post($attachment_id);
    $caption = $attachment instanceof WP_Post ? trim((string) $attachment->post_excerpt) : '';
    return '<figure class="single-featured-image">' . $image . ($caption ? '<figcaption>' . wp_kses_post($caption) . '</figcaption>' : '') . '</figure>';
}

function ssf_news_gallery(WP_Post $post): string
{
    if (! function_exists('ssf_news_gallery_ids')) {
        return '';
    }
    $featured_id = (int) get_post_thumbnail_id($post->ID);
    $ids = array_values(array_filter(ssf_news_gallery_ids($post->ID), static fn(int $id): bool => $id !== $featured_id));
    if (! $ids) {
        return '';
    }
    $single = 1 === count($ids);
    $image_size = $single ? 'large' : 'medium_large';
    $sizes = $single
        ? '(max-width: 720px) calc(100vw - 32px), (max-width: 900px) calc(100vw - 48px), 860px'
        : '(max-width: 720px) calc(100vw - 32px), 410px';
    $html = '<section class="ssf-news-gallery" aria-label="Fler bilder">';
    foreach ($ids as $attachment_id) {
        $image = wp_get_attachment_image($attachment_id, $image_size, false, array(
            'sizes' => $sizes,
            'loading' => 'lazy',
            'decoding' => 'async',
        ));
        if (! $image) {
            continue;
        }
        $attachment = get_post($attachment_id);
        $caption = $attachment instanceof WP_Post ? trim((string) $attachment->post_excerpt) : '';
        $html .= '<figure>' . $image . ($caption ? '<figcaption>' . wp_kses_post($caption) . '</figcaption>' : '') . '</figure>';
    }
    return $html . '</section>';
}

function ssf_render_news_article(WP_Post $post): void
{
    echo '<article id="post-' . esc_attr((string) $post->ID) . '" class="content-page content-page--single">';
    echo ssf_news_featured_figure($post); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    $author = get_userdata((int) $post->post_author);
    $historical = (array) get_post_meta($post->ID, '_ssf_historical_author', true);
    $author_name = (string) ($historical['display_name'] ?? ($author ? $author->display_name : 'Tidigare användare'));
    $type = (string) get_post_meta($post->ID, '_ssf_news_type', true);
    if ('media' === $type) {
        $source = sanitize_text_field((string) get_post_meta($post->ID, '_ssf_news_source_name', true));
        $external = esc_url_raw((string) get_post_meta($post->ID, '_ssf_news_external_url', true));
        $original_date = (string) get_post_meta($post->ID, '_ssf_news_original_date', true);
        $display_date = $original_date && strtotime($original_date) ? wp_date(get_option('date_format'), strtotime($original_date)) : get_the_date('', $post);
        $summary = trim((string) $post->post_excerpt);
        echo '<p class="ssf-news-type">I medierna</p><p class="entry-date">' . esc_html($source . ' · ' . $display_date . ' · Publicerad av ' . $author_name) . '</p><h1>' . esc_html(get_the_title($post)) . '</h1>';
        if ('' !== $summary) { echo '<div class="entry-content"><p>' . esc_html($summary) . '</p></div>'; }
        if ($external) { echo '<p><a class="ssf-button" target="_blank" rel="noopener noreferrer" href="' . esc_url($external) . '">Läs hos ' . esc_html($source ?: wp_parse_url($external, PHP_URL_HOST)) . ' ↗</a></p>'; }
        echo '</article>';
        return;
    }
    echo '<p class="entry-date">' . esc_html(get_the_date('', $post)) . ' · Publicerad av ' . esc_html($author_name) . '</p><h1>' . esc_html(get_the_title($post)) . '</h1><div class="entry-content">' . apply_filters('the_content', $post->post_content) . '</div>' . ssf_news_gallery($post) . '</article>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
