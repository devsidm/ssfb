<?php
/**
 * Theme setup for SSF.
 *
 * @package SSF
 */

if (! defined('SSF_VERSION')) {
    define('SSF_VERSION', '0.1.1');
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
