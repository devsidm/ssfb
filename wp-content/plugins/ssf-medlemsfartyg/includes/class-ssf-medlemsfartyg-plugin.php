<?php
/**
 * Main plugin loader.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Medlemsfartyg_Plugin
{
    private static ?SSF_Medlemsfartyg_Plugin $instance = null;

    public SSF_Medlemsfartyg_CPT $cpt;
    public SSF_Medlemsfartyg_Meta $meta;
    public SSF_Medlemsfartyg_Roles $roles;
    public SSF_Medlemsfartyg_Admin $admin;
    public SSF_Medlemsfartyg_Owner_Dashboard $owner_dashboard;
    public SSF_Medlemsfartyg_Shortcodes $shortcodes;
    public SSF_Medlemsfartyg_Templates $templates;
    public SSF_Medlemsfartyg_Notifications $notifications;
    public SSF_Medlemsfartyg_Export $export;

    public static function instance(): SSF_Medlemsfartyg_Plugin
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->load_files();
        $this->cpt = new SSF_Medlemsfartyg_CPT();
        $this->meta = new SSF_Medlemsfartyg_Meta();
        $this->roles = new SSF_Medlemsfartyg_Roles();
        $this->admin = new SSF_Medlemsfartyg_Admin();
        $this->owner_dashboard = new SSF_Medlemsfartyg_Owner_Dashboard();
        $this->shortcodes = new SSF_Medlemsfartyg_Shortcodes();
        $this->templates = new SSF_Medlemsfartyg_Templates();
        $this->notifications = new SSF_Medlemsfartyg_Notifications();
        $this->export = new SSF_Medlemsfartyg_Export();

        add_action('init', array($this, 'register_image_sizes'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    private function load_files(): void
    {
        $files = array(
            'includes/class-ssf-medlemsfartyg-cpt.php',
            'includes/class-ssf-medlemsfartyg-meta.php',
            'includes/class-ssf-medlemsfartyg-roles.php',
            'includes/class-ssf-medlemsfartyg-admin.php',
            'includes/class-ssf-medlemsfartyg-owner-dashboard.php',
            'includes/class-ssf-medlemsfartyg-shortcodes.php',
            'includes/class-ssf-medlemsfartyg-templates.php',
            'includes/class-ssf-medlemsfartyg-notifications.php',
            'includes/class-ssf-medlemsfartyg-export.php',
        );

        foreach ($files as $file) {
            require_once SSF_MEDLEMSFARTYG_PATH . $file;
        }
    }

    public function register_image_sizes(): void
    {
        add_image_size('ssf_ship_card', 640, 420, true);
        add_image_size('ssf_ship_hero', 1600, 700, true);
        add_image_size('ssf_ship_gallery_thumb', 240, 160, true);
    }

    public function enqueue_frontend_assets(): void
    {
        if (! is_singular('medlemsfartyg') && ! is_post_type_archive('medlemsfartyg') && ! $this->page_has_shortcodes()) {
            return;
        }

        wp_enqueue_style('ssf-medlemsfartyg', SSF_MEDLEMSFARTYG_URL . 'assets/css/ssf-medlemsfartyg.css', array(), SSF_MEDLEMSFARTYG_VERSION);
        wp_enqueue_script('ssf-medlemsfartyg', SSF_MEDLEMSFARTYG_URL . 'assets/js/ssf-medlemsfartyg.js', array(), SSF_MEDLEMSFARTYG_VERSION, true);
    }

    public function enqueue_admin_assets(string $hook): void
    {
        $screen = get_current_screen();
        if (! $screen || ('medlemsfartyg' !== $screen->post_type && false === strpos($hook, 'ssf-medlemsfartyg'))) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style('ssf-medlemsfartyg-admin', SSF_MEDLEMSFARTYG_URL . 'assets/css/ssf-medlemsfartyg-admin.css', array(), SSF_MEDLEMSFARTYG_VERSION);
        wp_enqueue_script('ssf-medlemsfartyg-admin', SSF_MEDLEMSFARTYG_URL . 'assets/js/ssf-medlemsfartyg-admin.js', array('jquery'), SSF_MEDLEMSFARTYG_VERSION, true);
    }

    private function page_has_shortcodes(): bool
    {
        if (! is_singular()) {
            return false;
        }

        $post = get_post();
        if (! $post) {
            return false;
        }

        return has_shortcode($post->post_content, 'ssf_medlemsfartyg')
            || has_shortcode($post->post_content, 'ssf_utvalt_fartyg')
            || has_shortcode($post->post_content, 'ssf_fartyg_grid');
    }

    public static function settings(): array
    {
        $defaults = array(
            'admin_email' => get_option('admin_email'),
            'require_review' => 'yes',
            'default_status' => 'draft',
            'per_page' => 12,
            'public_contact_default' => 'no',
            'enable_filters' => 'yes',
            'enable_map' => 'no',
            'primary_color' => '#3163B7',
            'archive_slug' => 'medlemsfartyg',
        );

        return wp_parse_args((array) get_option('ssf_medlemsfartyg_settings', array()), $defaults);
    }

    public static function activate(): void
    {
        require_once SSF_MEDLEMSFARTYG_PATH . 'includes/class-ssf-medlemsfartyg-cpt.php';
        require_once SSF_MEDLEMSFARTYG_PATH . 'includes/class-ssf-medlemsfartyg-roles.php';

        (new SSF_Medlemsfartyg_CPT())->register();
        SSF_Medlemsfartyg_Roles::add_role();

        if (! get_option('ssf_medlemsfartyg_settings')) {
            update_option('ssf_medlemsfartyg_settings', self::settings());
        }

        self::maybe_create_archive_page();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    private static function maybe_create_archive_page(): void
    {
        $existing = get_page_by_path('medlemsfartyg');
        if ($existing) {
            return;
        }

        wp_insert_post(
            array(
                'post_title' => 'Medlemsfartyg',
                'post_name' => 'medlemsfartyg',
                'post_type' => 'page',
                'post_status' => 'draft',
                'post_content' => '<!-- wp:shortcode -->[ssf_medlemsfartyg]<!-- /wp:shortcode -->',
            )
        );
    }
}
