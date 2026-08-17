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
    public SSF_Medlemsfartyg_Tokens $tokens;
    public SSF_Medlemsfartyg_Submissions $submissions;
    public SSF_Medlemsfartyg_Public_Form $public_form;

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
        $this->tokens = new SSF_Medlemsfartyg_Tokens();
        $this->submissions = new SSF_Medlemsfartyg_Submissions();
        $this->public_form = new SSF_Medlemsfartyg_Public_Form();

        add_action('init', array($this, 'register_image_sizes'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
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
            'includes/class-ssf-medlemsfartyg-tokens.php',
            'includes/class-ssf-medlemsfartyg-submissions.php',
            'includes/class-ssf-medlemsfartyg-public-form.php',
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

    public function register_rest_routes(): void
    {
        register_rest_route(
            'ssf-medlemsfartyg/v1',
            '/upsert',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'rest_upsert_ship'),
                'permission_callback' => static function (): bool {
                    return current_user_can('manage_options');
                },
            )
        );
    }

    public function rest_upsert_ship(WP_REST_Request $request): WP_REST_Response
    {
        $slug = sanitize_title((string) $request->get_param('slug'));
        $title = sanitize_text_field((string) $request->get_param('title'));
        if (! $slug || ! $title) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Slug och titel krävs.'), 400);
        }

        $existing = get_page_by_path($slug, OBJECT, 'medlemsfartyg');
        $post_data = array(
            'post_type' => 'medlemsfartyg',
            'post_name' => $slug,
            'post_title' => $title,
            'post_status' => sanitize_key((string) ($request->get_param('status') ?: 'publish')),
            'post_excerpt' => sanitize_textarea_field((string) $request->get_param('excerpt')),
            'post_content' => wp_kses_post((string) $request->get_param('content')),
        );

        if ($existing) {
            $post_data['ID'] = $existing->ID;
            $post_id = wp_update_post($post_data, true);
        } else {
            $post_id = wp_insert_post($post_data, true);
        }

        if (is_wp_error($post_id)) {
            return new WP_REST_Response(array('success' => false, 'message' => $post_id->get_error_message()), 500);
        }

        $taxonomies = (array) $request->get_param('taxonomies');
        foreach ($taxonomies as $taxonomy => $terms) {
            if (! taxonomy_exists($taxonomy)) {
                continue;
            }
            $term_ids = array();
            foreach ((array) $terms as $term_name) {
                $term_name = sanitize_text_field((string) $term_name);
                if (! $term_name) {
                    continue;
                }
                $term = term_exists($term_name, $taxonomy);
                if (! $term) {
                    $term = wp_insert_term($term_name, $taxonomy);
                }
                if (! is_wp_error($term)) {
                    $term_ids[] = (int) (is_array($term) ? $term['term_id'] : $term);
                }
            }
            wp_set_object_terms($post_id, $term_ids, $taxonomy);
        }

        $meta = (array) $request->get_param('meta');
        SSF_Medlemsfartyg_Meta::save_fields_from_request((int) $post_id, $meta, true);
        update_post_meta((int) $post_id, '_ssf_review_status', 'Publicerad');

        return new WP_REST_Response(
            array(
                'success' => true,
                'id' => (int) $post_id,
                'link' => get_permalink((int) $post_id),
            )
        );
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
        if (! $screen || (! in_array($screen->post_type, array('medlemsfartyg', 'ssf_ship_submission'), true) && false === strpos($hook, 'ssf-medlemsfartyg'))) {
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
            || has_shortcode($post->post_content, 'ssf_fartyg_grid')
            || has_shortcode($post->post_content, 'ssf_fartygsuppgifter_form');
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
            'token_days' => 30,
            'max_images' => 12,
            'max_image_mb' => 8,
            'allowed_image_types' => 'jpg,jpeg,png,webp',
            'invitation_text' => "Hej [NAMN],\n\nSveriges Segelfartygsförbund samlar in uppgifter om medlemsfartygen för att kunna presentera dem på förbundets webbplats.\n\nAnvänd länken nedan för att fylla i eller uppdatera uppgifter om [FARTYGSNAMN]. Du kan även ladda upp bilder och välja vilken bild som ska vara huvudbild.\n\n[LÄNK]\n\nLänken är personlig för detta fartyg och gäller till [DATUM].\n\nVänliga hälsningar\nSveriges Segelfartygsförbund",
            'thank_you_text' => 'Tack! Uppgifterna har skickats till SSF. Vi granskar materialet innan det publiceras på webbplatsen.',
            'privacy_text' => 'SSF behandlar uppgifterna för att administrera och presentera medlemsfartyget. Kontaktuppgifter visas bara publikt enligt dina val.',
        );

        return wp_parse_args((array) get_option('ssf_medlemsfartyg_settings', array()), $defaults);
    }

    public static function activate(): void
    {
        require_once SSF_MEDLEMSFARTYG_PATH . 'includes/class-ssf-medlemsfartyg-cpt.php';
        require_once SSF_MEDLEMSFARTYG_PATH . 'includes/class-ssf-medlemsfartyg-roles.php';
        require_once SSF_MEDLEMSFARTYG_PATH . 'includes/class-ssf-medlemsfartyg-tokens.php';

        (new SSF_Medlemsfartyg_CPT())->register();
        SSF_Medlemsfartyg_Roles::add_role();
        SSF_Medlemsfartyg_Tokens::create_table();
        update_option('ssf_medlemsfartyg_db_version', '0.2.0');

        if (! get_option('ssf_medlemsfartyg_settings')) {
            update_option('ssf_medlemsfartyg_settings', self::settings());
        }

        self::maybe_create_archive_page();
        self::maybe_create_collection_page();
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

    private static function maybe_create_collection_page(): void
    {
        $existing = get_page_by_path('fartygsuppgifter');
        if ($existing) {
            return;
        }

        wp_insert_post(
            array(
                'post_title' => 'Fartygsuppgifter',
                'post_name' => 'fartygsuppgifter',
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:shortcode -->[ssf_fartygsuppgifter_form]<!-- /wp:shortcode -->',
            )
        );
    }
}
