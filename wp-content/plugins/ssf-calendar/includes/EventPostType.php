<?php

namespace SSF\Calendar;

if (! defined('ABSPATH')) {
    exit;
}

final class EventPostType
{
    public const POST_TYPE = 'ssf_event';

    public function register(): void
    {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => __('Kalender', 'ssf-calendar'),
                'singular_name' => __('Event', 'ssf-calendar'),
                'add_new' => __('Lägg till event', 'ssf-calendar'),
                'add_new_item' => __('Lägg till event', 'ssf-calendar'),
                'edit_item' => __('Redigera event', 'ssf-calendar'),
                'all_items' => __('Alla event', 'ssf-calendar'),
                'search_items' => __('Sök event', 'ssf-calendar'),
            ),
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => false,
            'menu_icon' => 'dashicons-calendar-alt',
            'has_archive' => false,
            'rewrite' => array('slug' => 'kalender', 'with_front' => false),
            'show_in_rest' => true,
            'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ));

        foreach (array('start_date', 'start_time', 'end_date', 'end_time', 'location', 'event_url', 'event_type', 'event_source', 'event_source_id') as $field) {
            register_post_meta(self::POST_TYPE, '_ssf_calendar_' . $field, array(
                'type' => 'string',
                'single' => true,
                'show_in_rest' => true,
                'sanitize_callback' => 'event_url' === $field ? 'esc_url_raw' : 'sanitize_text_field',
                'auth_callback' => static function (bool $allowed, string $meta_key, int $post_id): bool {
                    return current_user_can('edit_post', $post_id);
                },
            ));
        }
    }
}
