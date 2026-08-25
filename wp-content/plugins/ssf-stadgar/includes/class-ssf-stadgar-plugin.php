<?php
/**
 * Main plugin bootstrap.
 *
 * @package SSF_Stadgar
 */

if (! defined('ABSPATH')) {
    exit;
}

require_once SSF_STADGAR_PATH . 'includes/class-ssf-stadgar-document.php';
require_once SSF_STADGAR_PATH . 'includes/class-ssf-stadgar-extractor.php';
require_once SSF_STADGAR_PATH . 'includes/class-ssf-stadgar-admin.php';
require_once SSF_STADGAR_PATH . 'includes/class-ssf-stadgar-public.php';

class SSF_Stadgar_Plugin
{
    private static ?SSF_Stadgar_Plugin $instance = null;

    public SSF_Stadgar_Document $documents;
    public SSF_Stadgar_Extractor $extractor;
    public SSF_Stadgar_Admin $admin;
    public SSF_Stadgar_Public $public;

    private function __construct()
    {
        $this->documents = new SSF_Stadgar_Document();
        $this->extractor = new SSF_Stadgar_Extractor($this->documents);
        $this->admin = new SSF_Stadgar_Admin($this->documents, $this->extractor);
        $this->public = new SSF_Stadgar_Public($this->documents);

        add_action('init', array($this, 'register_shortcodes'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public static function instance(): SSF_Stadgar_Plugin
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        $plugin = self::instance();
        $plugin->documents->register();
        self::ensure_stadgar_page();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function ensure_stadgar_page(): void
    {
        $page = get_page_by_path('stadgar');
        if ($page instanceof WP_Post) {
            return;
        }

        wp_insert_post(
            array(
                'post_title'   => 'Stadgar',
                'post_name'    => 'stadgar',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_content' => '[ssf_stadgar]',
            )
        );
    }

    public function register_shortcodes(): void
    {
        add_shortcode('ssf_stadgar', array($this->public, 'render'));
    }

    public function enqueue_assets(): void
    {
        if (! is_page('stadgar')) {
            return;
        }

        wp_enqueue_style(
            'ssf-stadgar',
            SSF_STADGAR_URL . 'assets/css/ssf-stadgar.css',
            array(),
            SSF_STADGAR_VERSION
        );
    }
}
