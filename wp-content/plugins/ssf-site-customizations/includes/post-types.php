<?php
/**
 * Custom post types for SSF.
 *
 * @package SSF_Site
 */

if (! defined('ABSPATH')) {
    exit;
}

function ssf_site_register_post_types(): void
{
    register_post_type(
        'medlemsfartyg',
        array(
            'labels'       => array(
                'name'          => __('Medlemsfartyg', 'ssf-site'),
                'singular_name' => __('Medlemsfartyg', 'ssf-site'),
                'add_new_item'  => __('Lagg till medlemsfartyg', 'ssf-site'),
                'edit_item'     => __('Redigera medlemsfartyg', 'ssf-site'),
            ),
            'public'       => true,
            'has_archive'  => false,
            'menu_icon'    => 'dashicons-sos',
            'supports'     => array('title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'),
            'show_in_rest' => true,
            'rewrite'      => array('slug' => 'fartyg'),
        )
    );

    register_post_type(
        'ssf_ansokan',
        array(
            'labels'       => array(
                'name'          => __('Ansokningar', 'ssf-site'),
                'singular_name' => __('Ansokan', 'ssf-site'),
            ),
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-clipboard',
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'supports'     => array('title', 'editor', 'custom-fields'),
        )
    );

    register_post_type(
        'ssf_kontakt',
        array(
            'labels'       => array(
                'name'          => __('Kontaktmeddelanden', 'ssf-site'),
                'singular_name' => __('Kontaktmeddelande', 'ssf-site'),
            ),
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-email-alt',
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'supports'     => array('title', 'editor', 'custom-fields'),
        )
    );
}
add_action('init', 'ssf_site_register_post_types');
