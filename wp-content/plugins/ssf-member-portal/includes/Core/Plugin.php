<?php

namespace SSF\MemberPortal\Core;

use SSF\MemberPortal\Modules\AnnualMeetings\Module as AnnualMeetings;
use SSF\MemberPortal\Modules\Motions\Module as Motions;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?Plugin $instance = null;

    private AnnualMeetings $meetings;
    private Motions $motions;

    public static function instance(): Plugin
    {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->meetings = new AnnualMeetings();
        $this->motions = new Motions($this->meetings);

        (new Privacy())->hooks();
        add_action('init', array($this, 'register'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    public static function activate(): void
    {
        Capabilities::register();
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
        Capabilities::register();
        Settings::remove_legacy_graph_settings();
        $this->meetings->register();
        $this->motions->register();
        self::install_pages();
    }

    public function admin_menu(): void
    {
        add_menu_page(__('SSF', 'ssf-member-portal'), __('SSF', 'ssf-member-portal'), Capabilities::MANAGE, 'ssf', array($this, 'render_dashboard'), 'dashicons-admin-generic', 25);
        add_submenu_page('ssf', __('SSF - Översikt', 'ssf-member-portal'), __('Översikt', 'ssf-member-portal'), Capabilities::MANAGE, 'ssf', array($this, 'render_dashboard'));
        $this->motions->register_admin_menu('ssf');
        add_submenu_page('ssf', __('Systemstatus', 'ssf-member-portal'), __('Systemstatus', 'ssf-member-portal'), Capabilities::MANAGE, 'ssf-member-portal-status', array($this, 'render_system_status'));
    }

    public function render_dashboard(): void
    {
        $this->motions->render_dashboard();
    }

    public function render_system_status(): void
    {
        if (! current_user_can(Capabilities::MANAGE)) {
            return;
        }
        $logs = Logger::recent();
        ?>
        <div class="wrap"><h1><?php esc_html_e('SSF systemstatus', 'ssf-member-portal'); ?></h1>
        <p><?php esc_html_e('Senaste händelser för Medlemsportalen.', 'ssf-member-portal'); ?></p>
        <table class="widefat striped"><thead><tr><th><?php esc_html_e('Tid', 'ssf-member-portal'); ?></th><th><?php esc_html_e('Händelse', 'ssf-member-portal'); ?></th><th><?php esc_html_e('Information', 'ssf-member-portal'); ?></th></tr></thead><tbody>
        <?php foreach ($logs as $log) : ?><tr><td><?php echo esc_html(wp_date('Y-m-d H:i', (int) $log['at'])); ?></td><td><?php echo esc_html($log['event']); ?></td><td><?php echo esc_html(wp_json_encode($log['context'])); ?></td></tr><?php endforeach; ?>
        <?php if (! $logs) : ?><tr><td colspan="3"><?php esc_html_e('Inga händelser har registrerats ännu.', 'ssf-member-portal'); ?></td></tr><?php endif; ?>
        </tbody></table></div>
        <?php
    }

    public function enqueue_assets(): void
    {
        if (! is_page(array((int) get_option('ssf_member_portal_motion_form_page_id'), (int) get_option('ssf_member_portal_motion_status_page_id')))) {
            return;
        }
        wp_enqueue_style('ssf-member-portal-motions', SSF_MEMBER_PORTAL_URL . 'assets/css/motions.css', array(), SSF_MEMBER_PORTAL_VERSION);
        wp_enqueue_script('ssf-member-portal-motions', SSF_MEMBER_PORTAL_URL . 'assets/js/motions.js', array(), SSF_MEMBER_PORTAL_VERSION, true);
    }

    public function register_rest_routes(): void
    {
        $routes = array(
            '/sharepoint/test' => 'test_sharepoint',
            '/sharepoint/authentication' => 'test_sharepoint_authentication',
            '/sharepoint/temporary-write' => 'test_sharepoint_temporary_write',
            '/sharepoint/motion-folder' => 'test_sharepoint_motion_folder',
            '/sharepoint/test-file' => 'test_sharepoint_file',
            '/sharepoint/test-file/delete' => 'delete_sharepoint_file',
        );
        foreach ($routes as $route => $method) {
            register_rest_route(
                'ssf-member-portal/v1',
                $route,
                array(
                    'methods' => 'POST',
                    'callback' => array($this, 'rest_sharepoint_action'),
                    'permission_callback' => static function (): bool {
                        return current_user_can(Capabilities::MANAGE);
                    },
                    'args' => array('action' => array('default' => $method)),
                )
            );
        }
    }

    public function test_sharepoint(): \WP_REST_Response
    {
        $result = $this->motions->test_sharepoint();
        return new \WP_REST_Response($result, $result['ok'] ? 200 : 422);
    }

    public function rest_sharepoint_action(\WP_REST_Request $request): \WP_REST_Response
    {
        $action = sanitize_key((string) $request->get_param('action'));
        $allowed = array(
            'test_sharepoint',
            'test_sharepoint_authentication',
            'test_sharepoint_temporary_write',
            'test_sharepoint_motion_folder',
            'test_sharepoint_file',
            'delete_sharepoint_file',
        );
        if (! in_array($action, $allowed, true)) {
            return new \WP_REST_Response(array('ok' => false, 'message' => __('Ogiltig SharePoint-åtgärd.', 'ssf-member-portal')), 400);
        }

        $result = $this->motions->{$action}();
        return new \WP_REST_Response($result, $result['ok'] ? 200 : 422);
    }

    private static function install_pages(): void
    {
        $pages = array(
            'motion_form' => array('lamna-motion', 'Lämna motion', '[ssf_member_portal_motions]'),
            'motion_status' => array('motion-status', 'Följ min motion', '[ssf_member_portal_motion_status]'),
        );
        foreach ($pages as $key => $page) {
            $existing = get_page_by_path($page[0]);
            $id = $existing ? $existing->ID : wp_insert_post(array('post_title' => $page[1], 'post_name' => $page[0], 'post_content' => $page[2], 'post_status' => 'publish', 'post_type' => 'page'));
            update_option('ssf_member_portal_' . $key . '_page_id', (int) $id, false);
        }
    }
}
