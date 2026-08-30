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
    $membership_menu = class_exists('SSF_Admin_Navigation') ? SSF_Admin_Navigation::MEMBERSHIP : true;
    $communication_menu = class_exists('SSF_Admin_Navigation') ? SSF_Admin_Navigation::COMMUNICATION : true;

    register_post_type(
        'ssf_ansokan',
        array(
            'labels'       => array(
                'name'          => __('Äldre ansökningar', 'ssf-site'),
                'singular_name' => __('Ansökan', 'ssf-site'),
                'menu_name'     => __('Äldre ansökningar', 'ssf-site'),
            ),
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => $membership_menu,
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
            'show_in_menu' => $communication_menu,
            'menu_icon'    => 'dashicons-email-alt',
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'supports'     => array('title', 'editor', 'custom-fields'),
        )
    );
}
add_action('init', 'ssf_site_register_post_types');
