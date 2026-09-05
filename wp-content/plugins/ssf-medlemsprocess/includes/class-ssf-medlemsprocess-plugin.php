<?php
/**
 * Plugin bootstrap and shared settings.
 *
 * @package SSF_Medlemsprocess
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Medlemsprocess_Plugin
{
    private static ?SSF_Medlemsprocess_Plugin $instance = null;

    public SSF_Medlemsprocess_Application $applications;
    public SSF_Medlemsprocess_Emails $emails;
    public SSF_Medlemsprocess_Public $public;
    public SSF_Medlemsprocess_Admin $admin;
    public SSF_Medlemsprocess_Inspector $inspector;

    public static function instance(): SSF_Medlemsprocess_Plugin
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        foreach (array('application', 'emails', 'public', 'admin', 'inspector') as $file) {
            require_once SSF_MEDLEMSPROCESS_PATH . 'includes/class-ssf-medlemsprocess-' . $file . '.php';
        }

        $this->applications = new SSF_Medlemsprocess_Application();
        $this->emails = new SSF_Medlemsprocess_Emails();
        $this->public = new SSF_Medlemsprocess_Public();
        $this->admin = new SSF_Medlemsprocess_Admin();
        $this->inspector = new SSF_Medlemsprocess_Inspector();

        add_action('init', array($this, 'register'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_assets'));
    }

    public static function activate(): void
    {
        $plugin = self::instance();
        $plugin->register();
        self::install_pages();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public function register(): void
    {
        $this->applications->register_post_type();
        self::register_roles();
        self::install_pages();
    }

    private static function install_pages(): void
    {
        $pages = array(
            'ansokan' => array('Ansökan', '[ssf_application_form]'),
            'ansokan-status' => array('Ansökan status', '[ssf_application_status]'),
            'mina-inspektioner' => array('Mina inspektioner', '[ssf_inspector_portal]'),
        );
        foreach ($pages as $slug => $page) {
            $existing = get_page_by_path($slug);
            if (! $existing) {
                $page_id = wp_insert_post(array(
                    'post_title' => $page[0],
                    'post_name' => $slug,
                    'post_content' => $page[1],
                    'post_status' => 'publish',
                    'post_type' => 'page',
                ));
            } else {
                $page_id = $existing->ID;
            }
            update_option('ssf_medlemsprocess_' . str_replace('-', '_', $slug) . '_page_id', (int) $page_id, false);
        }
    }

    private static function register_roles(): void
    {
        $inspector_capabilities = array(
            'read' => true,
            'ssf_view_assigned_applications' => true,
            'ssf_view_application_details' => true,
            'ssf_edit_inspection' => true,
            'ssf_submit_inspection' => true,
            'ssf_send_application_message' => true,
        );
        $roles = array(
            'ssf_inspector' => array('Inspektör', $inspector_capabilities),
            // Keep the original role available so existing users retain access after the upgrade.
            'ssf_inspektor' => array('Inspektör', $inspector_capabilities),
            'ssf_beslutsfattare' => array('Beslutsfattare', array('read' => true, 'ssf_view_applications' => true, 'ssf_decide_applications' => true)),
        );
        $all_capabilities = array(
            'read_ssf_application', 'edit_ssf_application', 'delete_ssf_application', 'edit_ssf_applications',
            'edit_others_ssf_applications', 'publish_ssf_applications', 'read_private_ssf_applications',
            'delete_ssf_applications', 'delete_private_ssf_applications', 'delete_published_ssf_applications',
            'delete_others_ssf_applications', 'edit_private_ssf_applications', 'edit_published_ssf_applications',
        );
        $decision_capabilities = array('read_ssf_application', 'edit_ssf_application', 'edit_ssf_applications', 'edit_others_ssf_applications', 'read_private_ssf_applications', 'edit_private_ssf_applications', 'edit_published_ssf_applications');

        foreach ($roles as $slug => $role) {
            $wp_role = get_role($slug) ?: add_role($slug, $role[0], $role[1]);
            if ($wp_role) {
                foreach ($role[1] as $capability => $grant) {
                    $wp_role->add_cap($capability, $grant);
                }
                foreach ($all_capabilities as $capability) {
                    $wp_role->remove_cap($capability);
                }
                if ('ssf_beslutsfattare' === $slug) {
                    foreach ($decision_capabilities as $capability) {
                        $wp_role->add_cap($capability);
                    }
                }
            }
        }

        $administrator = get_role('administrator');
        if ($administrator) {
            foreach (array_merge($all_capabilities, array('ssf_view_applications', 'ssf_edit_applications', 'ssf_review_applications', 'ssf_inspect_applications', 'ssf_decide_applications', 'ssf_manage_application_settings', 'ssf_view_assigned_applications', 'ssf_view_application_details', 'ssf_edit_inspection', 'ssf_submit_inspection', 'ssf_send_application_message')) as $capability) {
                $administrator->add_cap($capability);
            }
        }
    }

    public static function settings(): array
    {
        $defaults = array(
            'admin_email' => get_option('admin_email'),
            'application_notification_email' => '',
            'token_days' => 365,
            'max_image_mb' => 8,
            'max_file_mb' => 10,
            'templates' => array(),
        );
        return wp_parse_args((array) get_option('ssf_medlemsprocess_settings', array()), $defaults);
    }

    public static function page_url(string $page, array $args = array()): string
    {
        $page_id = (int) get_option('ssf_medlemsprocess_' . $page . '_page_id');
        $url = $page_id ? get_permalink($page_id) : home_url('/' . str_replace('_', '-', $page) . '/');
        return add_query_arg($args, $url);
    }

    public function enqueue_public_assets(): void
    {
        $application_pages = array((int) get_option('ssf_medlemsprocess_ansokan_page_id'), (int) get_option('ssf_medlemsprocess_ansokan_status_page_id'));
        $inspector_page = (int) get_option('ssf_medlemsprocess_mina_inspektioner_page_id');
        if (! is_page(array_merge($application_pages, array($inspector_page)))) {
            return;
        }
        wp_enqueue_style('ssf-medlemsprocess', SSF_MEDLEMSPROCESS_URL . 'assets/css/ssf-medlemsprocess.css', array(), SSF_MEDLEMSPROCESS_VERSION);
        wp_enqueue_script('ssf-medlemsprocess', SSF_MEDLEMSPROCESS_URL . 'assets/js/ssf-medlemsprocess.js', array(), SSF_MEDLEMSPROCESS_VERSION, true);
        if (is_page($inspector_page)) {
            wp_enqueue_style('ssf-inspector-portal', SSF_MEDLEMSPROCESS_URL . 'assets/css/ssf-inspector-portal.css', array('ssf-medlemsprocess'), SSF_MEDLEMSPROCESS_VERSION);
            wp_enqueue_script('ssf-inspector-portal', SSF_MEDLEMSPROCESS_URL . 'assets/js/ssf-inspector-portal.js', array(), SSF_MEDLEMSPROCESS_VERSION, true);
        }
    }
}
