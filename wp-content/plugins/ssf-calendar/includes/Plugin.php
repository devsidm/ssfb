<?php

namespace SSF\Calendar;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?Plugin $instance = null;

    private EventPostType $post_type;
    private EventRepository $events;

    public static function instance(): Plugin
    {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->post_type = new EventPostType();
        $this->events = new EventRepository();
        new Admin($this->events);
        new Frontend($this->events);
        add_action('init', array($this, 'register'));
    }

    public function register(): void
    {
        $this->post_type->register();
    }

    public static function activate(): void
    {
        $plugin = self::instance();
        $plugin->register();
        self::ensure_calendar_page();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function ensure_calendar_page(): int
    {
        $page = get_page_by_path('kalender');
        if (! $page) {
            $id = wp_insert_post(array(
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_name' => 'kalender',
                'post_title' => __('Kalender', 'ssf-calendar'),
                'post_content' => '[ssf_calendar]',
            ));
        } else {
            $id = $page->ID;
        }
        update_option('ssf_calendar_page_id', (int) $id, false);
        return (int) $id;
    }
}
