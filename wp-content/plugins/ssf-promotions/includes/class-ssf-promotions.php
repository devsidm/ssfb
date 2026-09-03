<?php

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Promotions
{
    public const CAPABILITY = 'manage_ssf_promotions';

    private static ?self $instance = null;
    private SSF_Promotion_Repository $repository;
    private SSF_Promotion_Renderer $renderer;
    private SSF_Promotion_Admin $admin;

    private function __construct()
    {
        $relations = new SSF_Promotion_Relations();
        $this->repository = new SSF_Promotion_Repository($relations);
        $this->renderer = new SSF_Promotion_Renderer($this->repository);
        $this->admin = new SSF_Promotion_Admin($this->repository, $relations, $this->renderer);

        add_action('init', array($this, 'register_post_type'), 9);
        add_action('init', array($this, 'ensure_capability'), 20);
        add_action('init', array($this, 'register_block'), 30);
        add_shortcode('ssf_promotions', array($this, 'shortcode'));
        add_action('ssf_home_after_hero', array($this, 'render_home'));
        add_action('ssf_annual_meeting_after_header', array($this, 'render_annual'), 10, 2);
        add_filter('the_content', array($this, 'prepend_global_promotions'), 99);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_assets'));
        add_action('save_post_' . SSF_Promotion_Repository::POST_TYPE, array($this, 'invalidate_cache'));
        add_action('before_delete_post', array($this, 'maybe_invalidate_deleted_post'));
        add_action('trashed_post', array($this, 'maybe_invalidate_deleted_post'));
        add_action('untrashed_post', array($this, 'maybe_invalidate_deleted_post'));
    }

    public static function instance(): self
    {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate(): void
    {
        self::add_capability();
        self::instance()->register_post_type();
        flush_rewrite_rules(false);
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules(false);
    }

    public function register_post_type(): void
    {
        register_post_type(SSF_Promotion_Repository::POST_TYPE, array(
            'labels' => array(
                'name' => __('Aktuellt', 'ssf-promotions'),
                'singular_name' => __('Budskap', 'ssf-promotions'),
                'add_new' => __('Lägg till budskap', 'ssf-promotions'),
                'add_new_item' => __('Lägg till budskap', 'ssf-promotions'),
                'edit_item' => __('Redigera budskap', 'ssf-promotions'),
                'new_item' => __('Nytt budskap', 'ssf-promotions'),
                'view_items' => __('Visa budskap', 'ssf-promotions'),
                'search_items' => __('Sök budskap', 'ssf-promotions'),
                'not_found' => __('Inga budskap hittades.', 'ssf-promotions'),
                'all_items' => __('Alla budskap', 'ssf-promotions'),
                'menu_name' => __('Aktuellt', 'ssf-promotions'),
            ),
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'supports' => array('title', 'excerpt', 'revisions'),
            'map_meta_cap' => false,
            'capabilities' => array(
                'edit_post' => self::CAPABILITY,
                'read_post' => self::CAPABILITY,
                'delete_post' => self::CAPABILITY,
                'edit_posts' => self::CAPABILITY,
                'edit_others_posts' => self::CAPABILITY,
                'publish_posts' => self::CAPABILITY,
                'read_private_posts' => self::CAPABILITY,
                'delete_posts' => self::CAPABILITY,
                'delete_private_posts' => self::CAPABILITY,
                'delete_published_posts' => self::CAPABILITY,
                'delete_others_posts' => self::CAPABILITY,
                'edit_private_posts' => self::CAPABILITY,
                'edit_published_posts' => self::CAPABILITY,
                'create_posts' => self::CAPABILITY,
            ),
        ));
    }

    public function ensure_capability(): void
    {
        self::add_capability();
    }

    private static function add_capability(): void
    {
        $administrator = get_role('administrator');
        if ($administrator && ! $administrator->has_cap(self::CAPABILITY)) {
            $administrator->add_cap(self::CAPABILITY);
        }
    }

    public static function render_current(array $args = array()): string
    {
        return self::instance()->renderer->render($args);
    }

    public function render_home(): void
    {
        echo $this->renderer->render(array('location' => 'home', 'max' => 3)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function render_annual(WP_Post $meeting_post, array $meeting): void
    {
        echo $this->renderer->render(array('location' => 'annual', 'max' => 3)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function prepend_global_promotions(string $content): string
    {
        if (is_admin() || is_front_page() || ! is_singular() || ! in_the_loop() || ! is_main_query()) {
            return $content;
        }
        $annual_page_id = (int) get_option('ssf_member_portal_annual_meeting_page_id', 0);
        if ($annual_page_id && is_page($annual_page_id)) {
            return $content;
        }
        return $this->renderer->render(array('location' => 'all', 'max' => 3)) . $content;
    }

    public function shortcode(array $attributes = array()): string
    {
        $attributes = shortcode_atts(array(
            'location' => 'home',
            'max' => 3,
            'type' => '',
            'layout' => 'auto',
        ), $attributes, 'ssf_promotions');
        return $this->renderer->render($attributes);
    }

    public function register_block(): void
    {
        wp_register_script(
            'ssf-promotions-block-editor',
            SSF_PROMOTIONS_URL . 'assets/js/block.js',
            array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'),
            SSF_PROMOTIONS_VERSION,
            true
        );
        register_block_type('ssf/current-promotions', array(
            'api_version' => 2,
            'editor_script' => 'ssf-promotions-block-editor',
            'render_callback' => array($this, 'render_block'),
            'attributes' => array(
                'max' => array('type' => 'number', 'default' => 3),
                'type' => array('type' => 'string', 'default' => ''),
                'layout' => array('type' => 'string', 'default' => 'auto'),
                'location' => array('type' => 'string', 'default' => 'home'),
            ),
        ));
    }

    public function render_block(array $attributes): string
    {
        return $this->renderer->render($attributes);
    }

    public function enqueue_public_assets(): void
    {
        if (class_exists('SSF_Feature_Manager')) {
            $registered = ! method_exists('SSF_Feature_Manager', 'get_registry') || isset(SSF_Feature_Manager::get_registry()['promotions']);
            if ($registered && ! SSF_Feature_Manager::can_access('promotions')) {
                return;
            }
        }
        wp_enqueue_style('ssf-promotions', SSF_PROMOTIONS_URL . 'assets/css/promotions.css', array(), SSF_PROMOTIONS_VERSION);
    }

    public function invalidate_cache(): void
    {
        $this->repository->invalidate_cache();
    }

    public function maybe_invalidate_deleted_post(int $post_id): void
    {
        if (SSF_Promotion_Repository::POST_TYPE === get_post_type($post_id)) {
            $this->repository->invalidate_cache();
        }
    }
}
