<?php
/**
 * Theme setup for SSF.
 *
 * @package SSF
 */

if (! defined('SSF_VERSION')) {
    define('SSF_VERSION', '0.3.3');
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
function ssf_render_news_article(WP_Post $post): void
{
    echo '<article id="post-' . esc_attr((string) $post->ID) . '" class="content-page content-page--single">';
    if (has_post_thumbnail($post)) { echo '<div class="single-featured-image">' . get_the_post_thumbnail($post, 'large') . '</div>'; }
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
    echo '<p class="entry-date">' . esc_html(get_the_date('', $post)) . ' · Publicerad av ' . esc_html($author_name) . '</p><h1>' . esc_html(get_the_title($post)) . '</h1><div class="entry-content">' . apply_filters('the_content', $post->post_content) . '</div></article>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
