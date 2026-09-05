<?php

namespace SSF\MemberPortal\Modules\Motions\Admin;

use SSF\MemberPortal\Core\Capabilities;
use SSF\MemberPortal\Core\Settings;
use SSF\MemberPortal\Integrations\Microsoft365\Configuration;
use SSF\MemberPortal\Modules\AnnualMeetings\Module as AnnualMeetings;
use SSF\MemberPortal\Modules\Motions\MotionDeadline;
use SSF\MemberPortal\Modules\Motions\MotionPermissions;
use SSF\MemberPortal\Modules\Motions\MotionPostType;
use SSF\MemberPortal\Modules\Motions\MotionService;
use SSF\MemberPortal\Modules\Motions\MotionStatus;

if (! defined('ABSPATH')) {
    exit;
}

final class Controller
{
    private AnnualMeetings $meetings;
    private MotionDeadline $deadline;
    private MotionService $service;

    public function __construct(AnnualMeetings $meetings, MotionDeadline $deadline, MotionService $service)
    {
        $this->meetings = $meetings;
        $this->deadline = $deadline;
        $this->service = $service;

        add_action('admin_post_ssf_member_portal_save_motion_settings', array($this, 'save_settings'));
        add_action('admin_post_ssf_member_portal_save_microsoft365_configuration', array($this, 'save_microsoft365_configuration'));
        add_action('admin_post_ssf_member_portal_reset_microsoft365_configuration', array($this, 'reset_microsoft365_configuration'));
        add_action('admin_post_ssf_member_portal_generate_webhook_secret', array($this, 'generate_webhook_secret'));
        add_action('admin_post_ssf_member_portal_test_power_automate_webhook', array($this, 'test_power_automate_webhook'));
        add_action('admin_post_ssf_member_portal_test_sharepoint_authentication', array($this, 'test_sharepoint_authentication'));
        add_action('admin_post_ssf_member_portal_test_sharepoint', array($this, 'test_sharepoint'));
        add_action('admin_post_ssf_member_portal_test_sharepoint_temporary_write', array($this, 'test_sharepoint_temporary_write'));
        add_action('admin_post_ssf_member_portal_test_sharepoint_write', array($this, 'test_sharepoint_write'));
        add_action('admin_post_ssf_member_portal_upload_sharepoint_test_file', array($this, 'upload_sharepoint_test_file'));
        add_action('admin_post_ssf_member_portal_delete_sharepoint_test_file', array($this, 'delete_sharepoint_test_file'));
        add_action('admin_post_ssf_member_portal_retry_sharepoint_sync', array($this, 'retry_sharepoint_sync'));
        add_action('admin_post_ssf_member_portal_ensure_sharepoint_motion_schema', array($this, 'ensure_sharepoint_motion_schema'));
        add_action('admin_post_ssf_member_portal_poll_sharepoint_motion_statuses', array($this, 'poll_sharepoint_motion_statuses'));
        add_action('admin_post_ssf_member_portal_poll_sharepoint_motion_status', array($this, 'poll_sharepoint_motion_status'));
        add_action('admin_post_ssf_member_portal_resend_motion_status_email', array($this, 'resend_motion_status_email'));
        add_action('add_meta_boxes_' . MotionPostType::POST_TYPE, array($this, 'add_motion_meta_box'));
        add_action('save_post_' . MotionPostType::POST_TYPE, array($this, 'save_motion'), 10, 2);
        add_filter('manage_' . MotionPostType::POST_TYPE . '_posts_columns', array($this, 'columns'));
        add_action('manage_' . MotionPostType::POST_TYPE . '_posts_custom_column', array($this, 'column_content'), 10, 2);
        add_action('restrict_manage_posts', array($this, 'motion_filters'));
        add_action('pre_get_posts', array($this, 'filter_motion_query'));
    }

    public function register_menu(string $parent): void
    {
        add_submenu_page($parent, __('Alla motioner', 'ssf-member-portal'), __('Motioner', 'ssf-member-portal'), Capabilities::MANAGE_MOTIONS, 'edit.php?post_type=' . MotionPostType::POST_TYPE);
        add_submenu_page($parent, __('Inställningar', 'ssf-member-portal'), __('Inställningar', 'ssf-member-portal'), Capabilities::MANAGE, 'ssf-member-portal-settings', array($this, 'render_settings'));
        add_submenu_page(class_exists('SSF_Admin_Navigation') ? null : $parent, __('Microsoft 365', 'ssf-member-portal'), __('Microsoft 365', 'ssf-member-portal'), Capabilities::MANAGE_MOTIONS, 'ssf-member-portal-microsoft365', array($this, 'render_microsoft365'));
    }

    public function render_dashboard(): void
    {
        if (! MotionPermissions::can_manage()) {
            return;
        }

        $state = $this->deadline->state();
        $meeting = $state['meeting'];
        $counts = wp_count_posts(MotionPostType::POST_TYPE);
        $under_review = count(get_posts(array(
            'post_type' => MotionPostType::POST_TYPE,
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'meta_key' => '_ssf_mp_status',
            'meta_value' => MotionStatus::UNDER_REVIEW,
        )));
        $sync_errors = count(get_posts(array(
            'post_type' => MotionPostType::POST_TYPE,
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'meta_key' => '_ssf_mp_sharepoint_status',
            'meta_value' => 'error',
        )));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('SSF', 'ssf-member-portal'); ?></h1>
            <div class="postbox" style="max-width:820px;padding:20px">
                <h2><?php esc_html_e('Motioner', 'ssf-member-portal'); ?></h2>
                <table class="widefat striped"><tbody>
                <tr><th><?php esc_html_e('Motionsperiod', 'ssf-member-portal'); ?></th><td><?php echo esc_html($this->state_label($state)); ?></td></tr>
                <tr><th><?php esc_html_e('Årsmöte', 'ssf-member-portal'); ?></th><td><?php echo esc_html($meeting['year'] ?: '–'); ?></td></tr>
                <tr><th><?php esc_html_e('Stänger', 'ssf-member-portal'); ?></th><td><?php echo esc_html($this->deadline->format((int) $state['closes_at']) ?: '–'); ?></td></tr>
                <tr><th><?php esc_html_e('Inkomna motioner', 'ssf-member-portal'); ?></th><td><?php echo esc_html((string) ((int) ($counts->private ?? 0))); ?></td></tr>
                <tr><th><?php esc_html_e('Under behandling', 'ssf-member-portal'); ?></th><td><?php echo esc_html((string) $under_review); ?></td></tr>
                <tr><th><?php esc_html_e('Synkfel', 'ssf-member-portal'); ?></th><td><?php echo esc_html((string) $sync_errors); ?></td></tr>
                </tbody></table>
                <p><a class="button button-primary" href="<?php echo esc_url(admin_url('edit.php?post_type=' . MotionPostType::POST_TYPE)); ?>"><?php esc_html_e('Hantera motioner', 'ssf-member-portal'); ?></a></p>
            </div>
        </div>
        <?php
    }

    public function render_period(): void
    {
        if (! MotionPermissions::can_manage()) {
            return;
        }

        $state = $this->deadline->state();
        $meeting = $state['meeting'];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Motionsperiod', 'ssf-member-portal'); ?></h1>
            <div class="notice notice-info"><p><strong><?php echo esc_html($this->state_label($state)); ?></strong><?php if ($state['closes_at']) : ?> – <?php echo esc_html($this->period_detail($state)); ?><?php endif; ?></p></div>
            <h2><?php echo esc_html(sprintf(__('Motionsperiod – Årsmöte %s', 'ssf-member-portal'), $meeting['year'] ?: '–')); ?></h2>
            <?php if ($meeting['id']) : ?>
                <p><?php esc_html_e('Öppnar:', 'ssf-member-portal'); ?> <strong><?php echo esc_html($this->deadline->format((int) $state['opens_at'])); ?></strong><br>
                <?php esc_html_e('Stänger:', 'ssf-member-portal'); ?> <strong><?php echo esc_html($this->deadline->format((int) $state['closes_at'])); ?></strong></p>
                <p><a class="button" href="<?php echo esc_url(get_edit_post_link((int) $meeting['id'])); ?>"><?php esc_html_e('Redigera årsmöte och datum', 'ssf-member-portal'); ?></a></p>
            <?php else : ?>
                <p><?php esc_html_e('Skapa ett årsmöte och välj det som aktivt under Inställningar.', 'ssf-member-portal'); ?></p>
            <?php endif; ?>
            <p><a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . AnnualMeetings::POST_TYPE)); ?>"><?php esc_html_e('Lägg till årsmöte', 'ssf-member-portal'); ?></a></p>
        </div>
        <?php
    }

    public function render_settings(): void
    {
        if (! current_user_can(Capabilities::MANAGE)) {
            return;
        }

        $settings = Settings::all();
        $active_id = (int) get_option('ssf_member_portal_active_meeting_id', 0);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Medlemsportal – Inställningar', 'ssf-member-portal'); ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_member_portal_save_motion_settings">
                <?php wp_nonce_field('ssf_member_portal_save_motion_settings'); ?>
                <table class="form-table" role="presentation"><tbody>
                <tr><th><label for="ssf-active-meeting"><?php esc_html_e('Aktivt årsmöte', 'ssf-member-portal'); ?></label></th><td>
                    <select id="ssf-active-meeting" name="active_meeting_id"><option value="0"><?php esc_html_e('Välj årsmöte', 'ssf-member-portal'); ?></option>
                    <?php foreach ($this->meetings->all() as $item) : $data = $this->meetings->data($item->ID); ?>
                        <option value="<?php echo esc_attr($item->ID); ?>" <?php selected($active_id, $item->ID); ?>><?php echo esc_html($data['year'] ? sprintf(__('Årsmöte %d', 'ssf-member-portal'), $data['year']) : $item->post_title); ?></option>
                    <?php endforeach; ?></select>
                </td></tr>
                <tr><th><?php esc_html_e('Sen inlämning', 'ssf-member-portal'); ?></th><td><?php esc_html_e('Konfigureras på respektive årsmöte under Motionsperiod.', 'ssf-member-portal'); ?></td></tr>
                <tr><th><?php esc_html_e('Mottagare av ny motion', 'ssf-member-portal'); ?></th><td><p class="description"><?php esc_html_e('Styrs centralt under SSF → System → Microsoft 365.', 'ssf-member-portal'); ?></p></td></tr>
                <tr><th><label for="ssf-upload-size"><?php esc_html_e('Max filstorlek', 'ssf-member-portal'); ?></label></th><td><input id="ssf-upload-size" class="small-text" type="number" min="1" max="25" name="max_upload_mb" value="<?php echo esc_attr($settings['max_upload_mb']); ?>"> MB</td></tr>
                <tr><th><?php esc_html_e('Kompletterande statusmeddelanden', 'ssf-member-portal'); ?></th><td>
                    <p class="description"><?php esc_html_e('Visas i e-post när SharePoint eller Power Automate ändrar status. Lämna tomt för att använda standardmeddelandet.', 'ssf-member-portal'); ?></p>
                    <?php foreach (MotionStatus::all() as $status => $label) : ?>
                        <p><label for="ssf-status-message-<?php echo esc_attr($status); ?>"><strong><?php echo esc_html($label); ?></strong></label><br><textarea id="ssf-status-message-<?php echo esc_attr($status); ?>" class="large-text" rows="2" name="motion_status_messages[<?php echo esc_attr($status); ?>]"><?php echo esc_textarea((string) ($settings['motion_status_messages'][$status] ?? '')); ?></textarea></p>
                    <?php endforeach; ?>
                </td></tr>
                </tbody></table>
                <?php submit_button(__('Spara inställningar', 'ssf-member-portal')); ?>
            </form>
        </div>
        <?php
    }

    public function render_microsoft365(): void
    {
        if (! $this->can_manage_microsoft365()) {
            return;
        }

        $config = Configuration::public_status();
        $values = Configuration::editable_values();
        $webhook = Configuration::webhook_public_status();
        $webhook_url = rest_url('ssf-motions/v1/status');
        $last_webhook = (array) get_option('ssf_member_portal_power_automate_last_result', array());
        $generated_secret = (string) get_transient('ssf_member_portal_generated_webhook_secret_' . get_current_user_id());
        if ($generated_secret) {
            delete_transient('ssf_member_portal_generated_webhook_secret_' . get_current_user_id());
        }
        $notice = get_transient('ssf_member_portal_sharepoint_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('ssf_member_portal_sharepoint_notice_' . get_current_user_id());
        }
        $diagnostics = (array) get_option('ssf_member_portal_graph_diagnostics', array());
        $test_file = (array) get_option('ssf_member_portal_graph_test_file', array());
        $schema = (array) get_option('ssf_member_portal_graph_motion_schema', array());
        $poll = $this->service->sharepoint_status_poll_diagnostics();
        $next_poll = wp_next_scheduled('ssf_motion_sharepoint_status_poll');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Microsoft 365', 'ssf-member-portal'); ?></h1>
            <?php if (class_exists('SSF_Admin_Navigation')) { \SSF_Admin_Navigation::render_system_tabs('ssf-member-portal-microsoft365'); } ?>
            <p><?php esc_html_e('Central konfiguration för interna e-postmottagare och SharePoint-integrationen för motioner.', 'ssf-member-portal'); ?></p>
            <?php if ($notice) : ?><div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['message']); ?></p></div><?php endif; ?>

            <?php if (class_exists('SSF_Email_Router')) { \SSF_Email_Router::render_admin_section(); } ?>

            <h2><?php esc_html_e('SharePoint för motioner', 'ssf-member-portal'); ?></h2>
            <p><?php esc_html_e('Motionen sparas alltid först i WordPress. SharePoint är ett asynkront dokumentarkiv och kan inte blockera en inskickad motion.', 'ssf-member-portal'); ?></p>

            <div class="postbox" style="max-width:980px;padding:20px">
                <h2><?php esc_html_e('Konfigurationsstatus', 'ssf-member-portal'); ?></h2>
                <table class="widefat striped"><tbody>
                <?php foreach (array('tenant_id' => 'Tenant ID', 'client_id' => 'Client ID', 'client_secret' => 'Client secret', 'site_id' => 'Site ID', 'drive_id' => 'Drive ID', 'document_library_list_id' => 'Document library / List ID', 'annual_meeting_folder_id' => 'Årsmöten-mappens ID', 'annual_meeting_folder_name' => 'Årsmöten-mappens namn') as $key => $label) : ?>
                    <tr><th><?php echo esc_html($label); ?></th><td><?php echo esc_html($config[$key]['configured'] ? __('Konfigurerad', 'ssf-member-portal') : __('Saknas', 'ssf-member-portal')); ?><?php if ($config[$key]['configured']) : ?> <span class="description">(<?php echo esc_html('server' === $config[$key]['source'] ? __('server', 'ssf-member-portal') : ('default' === $config[$key]['source'] ? __('SSF-standard', 'ssf-member-portal') : __('admin', 'ssf-member-portal'))); ?>)</span><?php endif; ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <p class="description"><?php esc_html_e('Serverkonfiguration prioriteras före värden som sparats här. Client secret visas aldrig igen.', 'ssf-member-portal'); ?></p>
            </div>

            <?php if (current_user_can(Capabilities::MANAGE)) : ?>
                <div class="postbox" style="max-width:980px;padding:20px">
                    <h2><?php esc_html_e('Konfigurera anslutning', 'ssf-member-portal'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ssf_member_portal_save_microsoft365_configuration">
                        <?php wp_nonce_field('ssf_member_portal_save_microsoft365_configuration'); ?>
                        <h3><?php esc_html_e('Microsoft Entra', 'ssf-member-portal'); ?></h3>
                        <table class="form-table" role="presentation"><tbody>
                        <tr><th><label for="ssf-graph-tenant-id"><?php esc_html_e('Tenant ID', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-tenant-id" class="regular-text code" name="graph[tenant_id]" value="<?php echo esc_attr($values['tenant_id']); ?>"></td></tr>
                        <tr><th><label for="ssf-graph-client-id"><?php esc_html_e('Application (client) ID', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-client-id" class="regular-text code" name="graph[client_id]" value="<?php echo esc_attr($values['client_id']); ?>"></td></tr>
                        <tr><th><label for="ssf-graph-client-secret"><?php esc_html_e('Client secret value', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-client-secret" class="regular-text" type="password" name="graph[client_secret]" value="" autocomplete="new-password"><p class="description"><?php esc_html_e('Lämna tomt för att behålla ett sparat secret. Secret ID fungerar inte här.', 'ssf-member-portal'); ?></p><label><input type="checkbox" name="graph[clear_client_secret]" value="1"> <?php esc_html_e('Ta bort sparat client secret', 'ssf-member-portal'); ?></label></td></tr>
                        </tbody></table>
                        <h3><?php esc_html_e('SharePoint', 'ssf-member-portal'); ?></h3>
                        <table class="form-table" role="presentation"><tbody>
                        <tr><th><label for="ssf-graph-site-id"><?php esc_html_e('SharePoint Site ID', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-site-id" class="large-text code" name="graph[site_id]" value="<?php echo esc_attr($values['site_id']); ?>"></td></tr>
                        <tr><th><label for="ssf-graph-drive-id"><?php esc_html_e('Document library / Drive ID', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-drive-id" class="large-text code" name="graph[drive_id]" value="<?php echo esc_attr($values['drive_id']); ?>"></td></tr>
                        <tr><th><label for="ssf-graph-list-id"><?php esc_html_e('Document library / List ID', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-list-id" class="large-text code" name="graph[document_library_list_id]" value="<?php echo esc_attr($values['document_library_list_id']); ?>"><p class="description"><?php esc_html_e('Kan lämnas tomt. Pluginet identifierar och sparar automatiskt list-ID:t för dokumentbiblioteket.', 'ssf-member-portal'); ?></p></td></tr>
                        <tr><th><label for="ssf-graph-library-name"><?php esc_html_e('Document library-namn', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-library-name" class="regular-text" name="graph[document_library_name]" value="<?php echo esc_attr($values['document_library_name']); ?>"></td></tr>
                        <tr><th><label for="ssf-graph-root-id"><?php esc_html_e('Årsmöten-mappens ID', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-root-id" class="large-text code" name="graph[annual_meeting_folder_id]" value="<?php echo esc_attr($values['annual_meeting_folder_id']); ?>"></td></tr>
                        <tr><th><label for="ssf-graph-root-name"><?php esc_html_e('Årsmöten-mappens namn', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-root-name" class="regular-text" name="graph[annual_meeting_folder_name]" value="<?php echo esc_attr($values['annual_meeting_folder_name']); ?>"></td></tr>
                        <tr><th><label for="ssf-graph-hostname"><?php esc_html_e('SharePoint hostname', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-hostname" class="regular-text code" name="graph[site_hostname]" value="<?php echo esc_attr($values['site_hostname']); ?>"></td></tr>
                        <tr><th><label for="ssf-graph-path"><?php esc_html_e('SharePoint site path', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-path" class="regular-text code" name="graph[site_path]" value="<?php echo esc_attr($values['site_path']); ?>"></td></tr>
                        <tr><th><label for="ssf-graph-wordpress-id-field"><?php esc_html_e('WordPressMotionID-fält', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-wordpress-id-field" class="regular-text code" name="graph[metadata_wordpress_motion_id_field]" value="<?php echo esc_attr($values['metadata_wordpress_motion_id_field']); ?>"></td></tr>
                        <tr><th><label for="ssf-graph-motion-number-field"><?php esc_html_e('Motionnummer-fält', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-motion-number-field" class="regular-text code" name="graph[metadata_motion_number_field]" value="<?php echo esc_attr($values['metadata_motion_number_field']); ?>"></td></tr>
                        <tr><th><label for="ssf-graph-status-field"><?php esc_html_e('Status-fält', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-status-field" class="regular-text code" name="graph[metadata_status_field]" value="<?php echo esc_attr($values['metadata_status_field']); ?>"></td></tr>
                        <tr><th><label for="ssf-graph-vessel-field"><?php esc_html_e('Fartyg-fält', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-vessel-field" class="regular-text code" name="graph[metadata_vessel_field]" value="<?php echo esc_attr($values['metadata_vessel_field']); ?>"></td></tr>
                        <tr><th><label for="ssf-graph-received-date-field"><?php esc_html_e('Inkommen datum-fält', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-received-date-field" class="regular-text code" name="graph[metadata_received_date_field]" value="<?php echo esc_attr($values['metadata_received_date_field']); ?>"><p class="description"><?php esc_html_e('Ange SharePoints interna fältnamn. Standardvärdena matchar de rekommenderade kolumnerna.', 'ssf-member-portal'); ?></p></td></tr>
                        </tbody></table>
                        <?php submit_button(__('Spara anslutningsinställningar', 'ssf-member-portal')); ?>
                    </form>
                    <?php $this->microsoft365_button('ssf_member_portal_reset_microsoft365_configuration', 'ssf_member_portal_reset_microsoft365_configuration', __('Återställ till SSF-standardvärden', 'ssf-member-portal'), 'secondary'); ?>
                </div>
            <?php endif; ?>

            <?php $this->render_webhook_section($webhook, $webhook_url, $last_webhook, $generated_secret); ?>

            <div class="postbox" style="max-width:980px;padding:20px">
                <h2><?php esc_html_e('SharePoint motionsstatus', 'ssf-member-portal'); ?></h2>
                <table class="widefat striped"><tbody>
                <tr><th><?php esc_html_e('Status-kolumn', 'ssf-member-portal'); ?></th><td><?php echo esc_html(! empty($schema['status_column_id']) ? __('Finns', 'ssf-member-portal') : __('Inte kontrollerad', 'ssf-member-portal')); ?></td></tr>
                <tr><th><?php esc_html_e('Internfält', 'ssf-member-portal'); ?></th><td><code><?php echo esc_html((string) ($schema['status_field'] ?? '–')); ?></code></td></tr>
                <tr><th><?php esc_html_e('Krävda statusar', 'ssf-member-portal'); ?></th><td><?php echo esc_html(sprintf(__('%1$d av %2$d', 'ssf-member-portal'), count((array) ($schema['choices'] ?? array())), count(MotionStatus::all()))); ?></td></tr>
                <tr><th><?php esc_html_e('Senast verifierad', 'ssf-member-portal'); ?></th><td><?php echo esc_html((string) ($schema['verified_at'] ?? __('Aldrig', 'ssf-member-portal'))); ?></td></tr>
                </tbody></table>
                <p><?php $this->microsoft365_button('ssf_member_portal_ensure_sharepoint_motion_schema', 'ssf_member_portal_ensure_sharepoint_motion_schema', __('Kontrollera och reparera statuskolumn', 'ssf-member-portal'), 'secondary'); ?></p>
            </div>

            <div class="postbox" style="max-width:980px;padding:20px">
                <h2><?php esc_html_e('SharePoint statuskontroll', 'ssf-member-portal'); ?></h2>
                <table class="widefat striped"><tbody>
                <tr><th><?php esc_html_e('Senast kontroll', 'ssf-member-portal'); ?></th><td><?php echo esc_html((string) ($poll['timestamp'] ?? __('Aldrig', 'ssf-member-portal'))); ?></td></tr>
                <tr><th><?php esc_html_e('Motioner kontrollerade', 'ssf-member-portal'); ?></th><td><?php echo esc_html((string) ($poll['checked'] ?? 0)); ?></td></tr>
                <tr><th><?php esc_html_e('Statusändringar', 'ssf-member-portal'); ?></th><td><?php echo esc_html((string) ($poll['changed'] ?? 0)); ?></td></tr>
                <tr><th><?php esc_html_e('Skickade e-postmeddelanden', 'ssf-member-portal'); ?></th><td><?php echo esc_html((string) ($poll['emails_sent'] ?? 0)); ?></td></tr>
                <tr><th><?php esc_html_e('Fel', 'ssf-member-portal'); ?></th><td><?php echo esc_html((string) ($poll['errors'] ?? 0)); ?></td></tr>
                <tr><th><?php esc_html_e('Nästa kontroll', 'ssf-member-portal'); ?></th><td><?php echo esc_html($next_poll ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_poll) : __('Inte schemalagd', 'ssf-member-portal')); ?></td></tr>
                </tbody></table>
                <p><?php $this->microsoft365_button('ssf_member_portal_poll_sharepoint_motion_statuses', 'ssf_member_portal_poll_sharepoint_motion_statuses', __('Kontrollera status nu', 'ssf-member-portal'), 'primary'); ?></p>
                <p class="description"><?php esc_html_e('Automatisk kontroll körs ungefär var 30:e minut. WordPress cron är trafikdriven; konfigurera en system-cron för wp-cron.php om sajten har låg trafik.', 'ssf-member-portal'); ?></p>
            </div>

            <div class="postbox" style="max-width:980px;padding:20px">
                <h2><?php esc_html_e('Diagnostik och test', 'ssf-member-portal'); ?></h2>
                <p><?php esc_html_e('Anslutningstestet är skrivskyddat. Mappskapande, testfil och borttagning kräver varsin uttrycklig åtgärd.', 'ssf-member-portal'); ?></p>
                <p>
                    <?php $this->microsoft365_button('ssf_member_portal_test_sharepoint_authentication', 'ssf_member_portal_test_sharepoint_authentication', __('Testa autentisering', 'ssf-member-portal'), 'secondary'); ?>
                    <?php $this->microsoft365_button('ssf_member_portal_test_sharepoint', 'ssf_member_portal_test_sharepoint', __('Testa läsåtkomst', 'ssf-member-portal'), 'secondary'); ?>
                    <?php $this->microsoft365_button('ssf_member_portal_test_sharepoint_temporary_write', 'ssf_member_portal_test_sharepoint_temporary_write', __('Testa skrivåtkomst', 'ssf-member-portal'), 'secondary'); ?>
                    <?php $this->microsoft365_button('ssf_member_portal_test_sharepoint_write', 'ssf_member_portal_test_sharepoint_write', __('Förbered motionsmapp', 'ssf-member-portal'), 'secondary'); ?>
                    <?php $this->microsoft365_button('ssf_member_portal_upload_sharepoint_test_file', 'ssf_member_portal_upload_sharepoint_test_file', __('Testa filuppladdning', 'ssf-member-portal'), 'primary'); ?>
                    <?php if (! empty($test_file['id'])) : ?><?php $this->microsoft365_button('ssf_member_portal_delete_sharepoint_test_file', 'ssf_member_portal_delete_sharepoint_test_file', __('Ta bort testfil', 'ssf-member-portal'), 'secondary'); ?><?php endif; ?>
                </p>
                <?php if (! empty($test_file['web_url'])) : ?><p><a href="<?php echo esc_url($test_file['web_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Öppna senast uppladdade testfil i SharePoint', 'ssf-member-portal'); ?></a></p><?php endif; ?>
            </div>

            <?php if ($diagnostics) : ?>
                <div class="postbox" style="max-width:980px;padding:20px">
                    <h2><?php esc_html_e('Senaste diagnostik', 'ssf-member-portal'); ?></h2>
                    <p><?php echo esc_html($diagnostics['ok'] ?? false ? __('Status: OK', 'ssf-member-portal') : __('Status: Fel', 'ssf-member-portal')); ?> · <?php echo esc_html((string) ($diagnostics['timestamp'] ?? '')); ?></p>
                    <pre style="white-space:pre-wrap;max-height:360px;overflow:auto"><?php echo esc_html(wp_json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                </div>
            <?php endif; ?>
            <p class="description"><?php esc_html_e('Den här integrationen använder Microsoft Graph Application permission Sites.Selected med en explicit write-grant till styrelsens SharePoint-site. Lägg inte till bredare fil- eller sitebehörigheter när Sites.Selected fungerar.', 'ssf-member-portal'); ?></p>
        </div>
        <?php
    }

    private function render_webhook_section(array $webhook, string $webhook_url, array $last_webhook, string $generated_secret): void
    {
        $motions = get_posts(array(
            'post_type' => MotionPostType::POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => 20,
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        ?>
        <div class="postbox" style="max-width:980px;padding:20px">
            <h2><?php esc_html_e('Power Automate statussynk', 'ssf-member-portal'); ?></h2>
            <table class="widefat striped"><tbody>
            <tr><th><?php esc_html_e('Webhook URL', 'ssf-member-portal'); ?></th><td><code><?php echo esc_html($webhook_url); ?></code></td></tr>
            <tr><th><?php esc_html_e('Webhook secret', 'ssf-member-portal'); ?></th><td><?php echo esc_html($webhook['configured'] ? __('Konfigurerad', 'ssf-member-portal') : __('Saknas', 'ssf-member-portal')); ?><?php if ($webhook['configured']) : ?> <span class="description">(<?php echo esc_html('server' === $webhook['source'] ? __('server', 'ssf-member-portal') : __('admin', 'ssf-member-portal')); ?>)</span><?php endif; ?></td></tr>
            <tr><th><?php esc_html_e('Inbound sync', 'ssf-member-portal'); ?></th><td><?php echo esc_html($webhook['inbound_enabled'] ? __('Aktiverad', 'ssf-member-portal') : __('Avstängd', 'ssf-member-portal')); ?></td></tr>
            <tr><th><?php esc_html_e('Senaste webhook', 'ssf-member-portal'); ?></th><td><?php echo esc_html((string) ($last_webhook['timestamp'] ?? __('Ingen ännu', 'ssf-member-portal'))); ?></td></tr>
            <tr><th><?php esc_html_e('Senaste resultat', 'ssf-member-portal'); ?></th><td><?php echo esc_html((string) ($last_webhook['result'] ?? '–')); ?><?php if (! empty($last_webhook['http_status'])) : ?> (HTTP <?php echo esc_html((string) $last_webhook['http_status']); ?>)<?php endif; ?></td></tr>
            </tbody></table>

            <?php if (current_user_can(Capabilities::MANAGE)) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px">
                    <input type="hidden" name="action" value="ssf_member_portal_save_microsoft365_configuration">
                    <?php wp_nonce_field('ssf_member_portal_save_microsoft365_configuration'); ?>
                    <table class="form-table" role="presentation"><tbody>
                    <tr><th><label for="ssf-power-automate-webhook-url"><?php esc_html_e('Webhook URL', 'ssf-member-portal'); ?></label></th><td><input id="ssf-power-automate-webhook-url" class="large-text code" type="text" readonly value="<?php echo esc_attr($webhook_url); ?>"> <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('ssf-power-automate-webhook-url').value)"><?php esc_html_e('Kopiera webhook URL', 'ssf-member-portal'); ?></button></td></tr>
                    <tr><th><label for="ssf-webhook-secret"><?php esc_html_e('Power Automate webhook secret', 'ssf-member-portal'); ?></label></th><td><input id="ssf-webhook-secret" class="regular-text" type="password" name="webhook[webhook_secret]" value="" autocomplete="new-password"><p class="description"><?php esc_html_e('Lämna tomt för att behålla ett sparat secret-värde. Det visas aldrig igen.', 'ssf-member-portal'); ?></p></td></tr>
                    <tr><th><?php esc_html_e('Inbound sync', 'ssf-member-portal'); ?></th><td><label><input type="checkbox" name="webhook[inbound_enabled]" value="1" <?php checked($webhook['inbound_enabled']); ?>> <?php esc_html_e('Aktivera inkommande statusuppdateringar från Power Automate', 'ssf-member-portal'); ?></label></td></tr>
                    </tbody></table>
                    <?php submit_button(__('Spara webhookinställningar', 'ssf-member-portal')); ?>
                </form>
                <?php $this->microsoft365_button('ssf_member_portal_generate_webhook_secret', 'ssf_member_portal_generate_webhook_secret', __('Generera nytt webhook secret', 'ssf-member-portal'), 'secondary'); ?>
                <?php if ($generated_secret) : ?><div class="notice notice-warning inline"><p><strong><?php esc_html_e('Kopiera detta webhook secret nu. Det visas bara denna gång:', 'ssf-member-portal'); ?></strong><br><code style="user-select:all"><?php echo esc_html($generated_secret); ?></code></p></div><?php endif; ?>

                <?php if ($motions) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px">
                        <input type="hidden" name="action" value="ssf_member_portal_test_power_automate_webhook">
                        <?php wp_nonce_field('ssf_member_portal_test_power_automate_webhook'); ?>
                        <label for="ssf-webhook-test-motion"><?php esc_html_e('Testmotion', 'ssf-member-portal'); ?></label>
                        <select id="ssf-webhook-test-motion" name="motion_id">
                            <?php foreach ($motions as $motion) : ?><option value="<?php echo esc_attr($motion->ID); ?>"><?php echo esc_html(get_post_meta($motion->ID, '_ssf_mp_motion_number', true) . ' – ' . $motion->post_title); ?></option><?php endforeach; ?>
                        </select>
                        <select name="status"><?php foreach (MotionStatus::all() as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($value, MotionStatus::UNDER_BEHANDLING); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
                        <label><input type="checkbox" name="confirm" value="1"> <?php esc_html_e('Jag förstår att testet uppdaterar vald motion.', 'ssf-member-portal'); ?></label>
                        <?php submit_button(__('Testa webhook', 'ssf-member-portal'), 'secondary', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function save_settings(): void
    {
        if (! current_user_can(Capabilities::MANAGE) || ! check_admin_referer('ssf_member_portal_save_motion_settings')) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-member-portal'));
        }

        update_option('ssf_member_portal_active_meeting_id', absint($_POST['active_meeting_id'] ?? 0), false);
        Settings::save(wp_unslash($_POST));
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=ssf-member-portal-settings'));
        exit;
    }

    public function save_microsoft365_configuration(): void
    {
        if (! current_user_can(Capabilities::MANAGE) || ! check_admin_referer('ssf_member_portal_save_microsoft365_configuration')) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-member-portal'));
        }

        $result = Configuration::save_admin((array) wp_unslash($_POST['graph'] ?? array()));
        if (! is_wp_error($result) && isset($_POST['webhook'])) {
            $result = Configuration::save_webhook_settings((array) wp_unslash($_POST['webhook']));
        }
        $notice = is_wp_error($result)
            ? array('type' => 'error', 'message' => $result->get_error_message())
            : array('type' => 'success', 'message' => __('Microsoft 365-konfigurationen har sparats.', 'ssf-member-portal'));
        set_transient('ssf_member_portal_sharepoint_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=ssf-member-portal-microsoft365'));
        exit;
    }

    public function generate_webhook_secret(): void
    {
        $this->guard_microsoft365_action('ssf_member_portal_generate_webhook_secret');
        $secret = Configuration::generate_webhook_secret();
        if (is_wp_error($secret)) {
            set_transient('ssf_member_portal_sharepoint_notice_' . get_current_user_id(), array('type' => 'error', 'message' => $secret->get_error_message()), MINUTE_IN_SECONDS);
        } else {
            set_transient('ssf_member_portal_generated_webhook_secret_' . get_current_user_id(), $secret, MINUTE_IN_SECONDS);
            set_transient('ssf_member_portal_sharepoint_notice_' . get_current_user_id(), array('type' => 'success', 'message' => __('Ett nytt webhook secret har skapats.', 'ssf-member-portal')), MINUTE_IN_SECONDS);
        }
        wp_safe_redirect(admin_url('admin.php?page=ssf-member-portal-microsoft365'));
        exit;
    }

    public function test_power_automate_webhook(): void
    {
        $this->guard_microsoft365_action('ssf_member_portal_test_power_automate_webhook');
        $motion_id = absint($_POST['motion_id'] ?? 0);
        $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
        if (empty($_POST['confirm']) || ! $motion_id || ! MotionStatus::is_valid($status)) {
            wp_die(esc_html__('Välj en giltig motion och bekräfta testuppdateringen.', 'ssf-member-portal'));
        }

        $result = $this->service->update_status($motion_id, $status, 'power_automate', array('changed_at' => gmdate('c')));
        $notice = is_wp_error($result)
            ? array('type' => 'error', 'message' => $result->get_error_message())
            : array('type' => 'success', 'message' => __('Webhook-testet har genomförts. Ingen status har skrivits tillbaka till SharePoint.', 'ssf-member-portal'));
        set_transient('ssf_member_portal_sharepoint_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=ssf-member-portal-microsoft365'));
        exit;
    }

    public function reset_microsoft365_configuration(): void
    {
        if (! current_user_can(Capabilities::MANAGE) || ! check_admin_referer('ssf_member_portal_reset_microsoft365_configuration')) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-member-portal'));
        }

        Configuration::reset_admin_defaults();
        set_transient('ssf_member_portal_sharepoint_notice_' . get_current_user_id(), array('type' => 'success', 'message' => __('SSF-standardvärdena har återställts. Client secret har behållits.', 'ssf-member-portal')), MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=ssf-member-portal-microsoft365'));
        exit;
    }

    public function test_sharepoint_authentication(): void
    {
        $this->guard_microsoft365_action('ssf_member_portal_test_sharepoint_authentication');
        $this->complete_microsoft365_action(
            $this->service->sharepoint_authentication(),
            __('Microsoft Entra-autentiseringen är verifierad.', 'ssf-member-portal')
        );
    }

    public function test_sharepoint(): void
    {
        $this->guard_microsoft365_action('ssf_member_portal_test_sharepoint');
        $this->complete_microsoft365_action(
            $this->service->sharepoint_diagnostics(),
            __('Anslutningen till Microsoft Graph och SharePoint är verifierad utan skrivning.', 'ssf-member-portal')
        );
    }

    public function test_sharepoint_write(): void
    {
        $this->guard_microsoft365_action('ssf_member_portal_test_sharepoint_write');
        $this->complete_microsoft365_action(
            $this->service->test_sharepoint_write_access($this->current_year()),
            __('Skrivåtkomsten är verifierad. Mappstrukturen finns nu tillgänglig.', 'ssf-member-portal')
        );
    }

    public function test_sharepoint_temporary_write(): void
    {
        $this->guard_microsoft365_action('ssf_member_portal_test_sharepoint_temporary_write');
        $this->complete_microsoft365_action(
            $this->service->test_sharepoint_temporary_write(),
            __('Temporär SharePoint-testmapp skapades och togs bort.', 'ssf-member-portal')
        );
    }

    public function upload_sharepoint_test_file(): void
    {
        $this->guard_microsoft365_action('ssf_member_portal_upload_sharepoint_test_file');
        $this->complete_microsoft365_action(
            $this->service->upload_sharepoint_test_file($this->current_year()),
            __('Testfilen har laddats upp till SharePoint.', 'ssf-member-portal')
        );
    }

    public function delete_sharepoint_test_file(): void
    {
        $this->guard_microsoft365_action('ssf_member_portal_delete_sharepoint_test_file');
        $this->complete_microsoft365_action(
            $this->service->delete_sharepoint_test_file(),
            __('Testfilen har tagits bort från SharePoint.', 'ssf-member-portal')
        );
    }

    public function retry_sharepoint_sync(): void
    {
        $this->guard_microsoft365_action('ssf_member_portal_retry_sharepoint_sync', 'ssf_member_portal_retry_sharepoint_sync_nonce');
        $motion_id = absint($_POST['motion_id'] ?? 0);
        $motion = $motion_id ? get_post($motion_id) : null;
        if (! $motion || MotionPostType::POST_TYPE !== $motion->post_type) {
            wp_die(esc_html__('Motionen kunde inte hittas.', 'ssf-member-portal'));
        }

        $this->service->retry_sharepoint_sync($motion_id);
        set_transient('ssf_member_portal_sharepoint_notice_' . get_current_user_id(), array('type' => 'success', 'message' => __('Motionen har köats för en ny SharePoint-synk.', 'ssf-member-portal')), MINUTE_IN_SECONDS);
        wp_safe_redirect(get_edit_post_link($motion_id, 'url') ?: admin_url('edit.php?post_type=' . MotionPostType::POST_TYPE));
        exit;
    }

    public function ensure_sharepoint_motion_schema(): void
    {
        $this->guard_microsoft365_action('ssf_member_portal_ensure_sharepoint_motion_schema');
        $this->complete_microsoft365_action(
            $this->service->ensure_sharepoint_status_schema(),
            __('SharePoints statuskolumn är kontrollerad och klar.', 'ssf-member-portal')
        );
    }

    public function poll_sharepoint_motion_statuses(): void
    {
        $this->guard_microsoft365_action('ssf_member_portal_poll_sharepoint_motion_statuses');
        $result = $this->service->poll_sharepoint_statuses();
        if (empty($result['ok'])) {
            $result = new \WP_Error('sharepoint_status_poll_failed', __('SharePoint-statuskontrollen kunde inte starta. Kontrollera Microsoft 365-konfigurationen.', 'ssf-member-portal'));
        }
        $this->complete_microsoft365_action(
            $result,
            __('SharePoint-statusarna har kontrollerats.', 'ssf-member-portal')
        );
    }

    public function poll_sharepoint_motion_status(): void
    {
        $this->guard_microsoft365_action('ssf_member_portal_poll_sharepoint_motion_status', 'ssf_member_portal_poll_sharepoint_motion_status_nonce');
        $motion_id = absint($_POST['motion_id'] ?? 0);
        $result = $motion_id ? $this->service->poll_sharepoint_motion_status($motion_id) : new \WP_Error('motion_not_found', __('Motionen kunde inte hittas.', 'ssf-member-portal'));
        $notice = is_wp_error($result)
            ? array('type' => 'error', 'message' => $result->get_error_message())
            : array('type' => 'success', 'message' => __('Motionens SharePoint-status har kontrollerats.', 'ssf-member-portal'));
        set_transient('ssf_member_portal_sharepoint_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS);
        wp_safe_redirect(get_edit_post_link($motion_id, 'url') ?: admin_url('edit.php?post_type=' . MotionPostType::POST_TYPE));
        exit;
    }

    public function resend_motion_status_email(): void
    {
        $this->guard_microsoft365_action('ssf_member_portal_resend_motion_status_email', 'ssf_member_portal_resend_motion_status_email_nonce');
        $motion_id = absint($_POST['motion_id'] ?? 0);
        $result = $motion_id ? $this->service->resend_status_email($motion_id) : new \WP_Error('motion_not_found', __('Motionen kunde inte hittas.', 'ssf-member-portal'));
        $notice = is_wp_error($result) || ! $result
            ? array('type' => 'error', 'message' => is_wp_error($result) ? $result->get_error_message() : __('Statusmeddelandet kunde inte skickas.', 'ssf-member-portal'))
            : array('type' => 'success', 'message' => __('Statusmeddelandet har skickats.', 'ssf-member-portal'));
        set_transient('ssf_member_portal_sharepoint_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS);
        wp_safe_redirect(get_edit_post_link($motion_id, 'url') ?: admin_url('edit.php?post_type=' . MotionPostType::POST_TYPE));
        exit;
    }

    public function add_motion_meta_box(): void
    {
        add_meta_box('ssf-member-portal-motion', __('Motionens uppgifter', 'ssf-member-portal'), array($this, 'render_motion_meta_box'), MotionPostType::POST_TYPE, 'normal', 'high');
    }

    public function render_motion_meta_box(\WP_Post $post): void
    {
        $status = MotionStatus::canonical((string) get_post_meta($post->ID, '_ssf_mp_status', true)) ?: MotionStatus::IN_SORTERAD;
        $status_source = (string) get_post_meta($post->ID, '_ssf_mp_status_source', true);
        $status_updated_at = (string) get_post_meta($post->ID, '_ssf_mp_status_updated_at', true);
        $status_updated_label = $this->format_timestamp($status_updated_at);
        $status_history = (array) get_post_meta($post->ID, '_ssf_mp_status_history', true);
        $sharepoint_web_url = (string) get_post_meta($post->ID, '_ssf_mp_sharepoint_web_url', true);
        $attachments = (array) get_post_meta($post->ID, '_ssf_mp_file_ids', true);
        $sync_status = (string) get_post_meta($post->ID, '_ssf_mp_sharepoint_status', true);
        $sync_error = (string) get_post_meta($post->ID, '_ssf_mp_sharepoint_last_error', true);
        $sharepoint_items = (array) get_post_meta($post->ID, '_ssf_mp_sharepoint_items', true);
        $sharepoint_status = (string) get_post_meta($post->ID, '_ssf_mp_sharepoint_last_status', true);
        $sharepoint_checked_at = (string) get_post_meta($post->ID, '_ssf_mp_sharepoint_last_checked_at', true);
        $sharepoint_warning = (string) get_post_meta($post->ID, '_ssf_mp_sharepoint_status_warning', true);
        $sharepoint_poll_error = (string) get_post_meta($post->ID, '_ssf_mp_sharepoint_status_poll_error', true);
        $status_email_error = (string) get_post_meta($post->ID, '_ssf_mp_status_email_error', true);
        $status_email_history = (array) get_post_meta($post->ID, '_ssf_mp_status_email_history', true);
        $list_id = (string) get_post_meta($post->ID, '_ssf_mp_graph_list_id', true);
        $list_item_id = (string) (get_post_meta($post->ID, '_ssf_mp_graph_list_item_id', true) ?: get_post_meta($post->ID, '_ssf_mp_sharepoint_list_item_id', true));
        wp_nonce_field('ssf_member_portal_motion_admin', 'ssf_member_portal_motion_admin_nonce');
        wp_nonce_field('ssf_member_portal_poll_sharepoint_motion_status', 'ssf_member_portal_poll_sharepoint_motion_status_nonce');
        wp_nonce_field('ssf_member_portal_resend_motion_status_email', 'ssf_member_portal_resend_motion_status_email_nonce');
        ?>
        <table class="widefat striped"><tbody>
        <tr><th><?php esc_html_e('Motionsnummer', 'ssf-member-portal'); ?></th><td><?php echo esc_html(get_post_meta($post->ID, '_ssf_mp_motion_number', true)); ?></td></tr>
        <tr><th><?php esc_html_e('Motionär', 'ssf-member-portal'); ?></th><td><?php echo esc_html(get_post_meta($post->ID, '_ssf_mp_submitter_name', true)); ?><br><a href="mailto:<?php echo esc_attr(get_post_meta($post->ID, '_ssf_mp_submitter_email', true)); ?>"><?php echo esc_html(get_post_meta($post->ID, '_ssf_mp_submitter_email', true)); ?></a></td></tr>
        <tr><th><?php esc_html_e('Inskickad', 'ssf-member-portal'); ?></th><td><?php echo esc_html($this->deadline->format((int) get_post_meta($post->ID, '_ssf_mp_submitted_at', true))); ?></td></tr>
        <tr><th><?php esc_html_e('Motionsfrist', 'ssf-member-portal'); ?></th><td><?php echo esc_html($this->deadline->format((int) get_post_meta($post->ID, '_ssf_mp_submission_deadline_at', true))); ?></td></tr>
        <tr><th><label for="ssf-motion-status"><?php esc_html_e('Status', 'ssf-member-portal'); ?></label></th><td><select id="ssf-motion-status" name="ssf_mp_status"><?php foreach (MotionStatus::all() as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td></tr>
        <tr><th><?php esc_html_e('Statuskälla', 'ssf-member-portal'); ?></th><td><?php echo esc_html($status_source ?: __('WordPress', 'ssf-member-portal')); ?></td></tr>
        <tr><th><?php esc_html_e('Senast uppdaterad', 'ssf-member-portal'); ?></th><td><?php echo esc_html($status_updated_label ?: '–'); ?></td></tr>
        <?php if ($sharepoint_web_url) : ?><tr><th><?php esc_html_e('SharePoint', 'ssf-member-portal'); ?></th><td><a href="<?php echo esc_url($sharepoint_web_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Öppna dokument', 'ssf-member-portal'); ?></a></td></tr><?php endif; ?>
        <tr><th><?php esc_html_e('Bilagor', 'ssf-member-portal'); ?></th><td><?php foreach ($attachments as $attachment_id) : ?><a href="<?php echo esc_url(wp_get_attachment_url((int) $attachment_id)); ?>" target="_blank" rel="noopener"><?php echo esc_html(get_the_title((int) $attachment_id)); ?></a><br><?php endforeach; ?><?php if (! $attachments) { esc_html_e('Inga bilagor.', 'ssf-member-portal'); } ?></td></tr>
        <tr><th><?php esc_html_e('SharePoint-synk', 'ssf-member-portal'); ?></th><td>
            <strong><?php echo esc_html($sync_status ?: __('Inte köad', 'ssf-member-portal')); ?></strong>
            <?php if ($sync_error) : ?><br><span class="description"><?php echo esc_html($sync_error); ?></span><?php endif; ?>
            <?php foreach ($sharepoint_items as $item) : ?><?php if (! empty($item['web_url'])) : ?><br><a href="<?php echo esc_url($item['web_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($item['filename'] ?? __('Öppna bilaga i SharePoint', 'ssf-member-portal'))); ?></a><?php endif; ?><?php endforeach; ?>
            <?php if (in_array($sync_status, array('error', 'pending'), true)) : ?>
                <?php wp_nonce_field('ssf_member_portal_retry_sharepoint_sync', 'ssf_member_portal_retry_sharepoint_sync_nonce'); ?>
                <button type="submit" class="button" formmethod="post" formaction="<?php echo esc_url(admin_url('admin-post.php?action=ssf_member_portal_retry_sharepoint_sync')); ?>" name="motion_id" value="<?php echo esc_attr($post->ID); ?>"><?php esc_html_e('Försök igen', 'ssf-member-portal'); ?></button>
            <?php endif; ?>
        </td></tr>
        <tr><th><?php esc_html_e('SharePoint-status', 'ssf-member-portal'); ?></th><td>
            <strong><?php echo esc_html($sharepoint_status ?: __('Inte kontrollerad', 'ssf-member-portal')); ?></strong>
            <?php if ($sharepoint_checked_at) : ?><br><?php echo esc_html(sprintf(__('Senast kontrollerad: %s', 'ssf-member-portal'), $this->format_timestamp($sharepoint_checked_at))); ?><?php endif; ?>
            <?php if ($sharepoint_warning || $sharepoint_poll_error) : ?><br><span class="description"><?php echo esc_html($sharepoint_warning ?: $sharepoint_poll_error); ?></span><?php endif; ?>
            <?php if ($list_item_id) : ?><br><button type="submit" class="button" formmethod="post" formaction="<?php echo esc_url(admin_url('admin-post.php?action=ssf_member_portal_poll_sharepoint_motion_status')); ?>" name="motion_id" value="<?php echo esc_attr($post->ID); ?>"><?php esc_html_e('Kontrollera status nu', 'ssf-member-portal'); ?></button><?php endif; ?>
            <?php if ($list_item_id || $list_id) : ?><details style="margin-top:8px"><summary><?php esc_html_e('Avancerad diagnostik', 'ssf-member-portal'); ?></summary><code><?php echo esc_html('List ID: ' . ($list_id ?: '–') . ' · List item ID: ' . ($list_item_id ?: '–')); ?></code></details><?php endif; ?>
        </td></tr>
        <tr><th><?php esc_html_e('Statusmeddelande', 'ssf-member-portal'); ?></th><td>
            <?php if ($status_email_error) : ?><span class="description"><?php echo esc_html($status_email_error); ?></span><br><?php endif; ?>
            <?php if ($status_email_history) : ?><?php $last_email = end($status_email_history); ?><?php echo esc_html(sprintf(__('Senaste försök: %1$s (%2$s)', 'ssf-member-portal'), $this->format_timestamp((string) ($last_email['attempted_at'] ?? '')), (string) ($last_email['result'] ?? ''))); ?><br><?php endif; ?>
            <button type="submit" class="button" formmethod="post" formaction="<?php echo esc_url(admin_url('admin-post.php?action=ssf_member_portal_resend_motion_status_email')); ?>" name="motion_id" value="<?php echo esc_attr($post->ID); ?>"><?php esc_html_e('Skicka statusmail igen', 'ssf-member-portal'); ?></button>
        </td></tr>
        <tr><th><?php esc_html_e('Statushistorik', 'ssf-member-portal'); ?></th><td><?php if ($status_history) : ?><table class="widefat striped"><thead><tr><th><?php esc_html_e('Ändring', 'ssf-member-portal'); ?></th><th><?php esc_html_e('Tid', 'ssf-member-portal'); ?></th><th><?php esc_html_e('Källa', 'ssf-member-portal'); ?></th></tr></thead><tbody><?php foreach (array_reverse($status_history) as $entry) : ?><tr><td><?php echo esc_html((string) ($entry['old_status_label'] ?? '') . ' → ' . (string) ($entry['new_status_label'] ?? '')); ?></td><td><?php echo esc_html($this->format_timestamp((string) ($entry['changed_at'] ?? ''))); ?></td><td><?php echo esc_html((string) ($entry['source'] ?? '')); ?></td></tr><?php endforeach; ?></tbody></table><?php else : esc_html_e('Ingen statusändring har registrerats ännu.', 'ssf-member-portal'); endif; ?></td></tr>
        </tbody></table>
        <?php
    }

    public function save_motion(int $post_id, \WP_Post $post): void
    {
        if (! MotionPermissions::can_manage() || ! isset($_POST['ssf_member_portal_motion_admin_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_member_portal_motion_admin_nonce'])), 'ssf_member_portal_motion_admin')) {
            return;
        }

        $status = sanitize_key(wp_unslash($_POST['ssf_mp_status'] ?? ''));
        if (MotionStatus::is_valid($status)) {
            $this->service->update_status($post_id, $status, 'wordpress');
        }
    }

    public function columns(array $columns): array
    {
        return array(
            'cb' => $columns['cb'],
            'title' => __('Motion', 'ssf-member-portal'),
            'motion_meeting' => __('Årsmöte', 'ssf-member-portal'),
            'motion_status' => __('Status', 'ssf-member-portal'),
            'motion_submitted' => __('Inskickad', 'ssf-member-portal'),
            'date' => $columns['date'],
        );
    }

    public function column_content(string $column, int $post_id): void
    {
        if ('motion_meeting' === $column) {
            $meeting_id = (int) get_post_meta($post_id, '_ssf_mp_annual_meeting_id', true);
            echo esc_html($meeting_id ? get_the_title($meeting_id) : __('Ej kopplad', 'ssf-member-portal'));
        }
        if ('motion_status' === $column) {
            echo esc_html(MotionStatus::label((string) get_post_meta($post_id, '_ssf_mp_status', true)));
        }
        if ('motion_submitted' === $column) {
            echo esc_html($this->deadline->format((int) get_post_meta($post_id, '_ssf_mp_submitted_at', true)));
        }
    }

    public function motion_filters(string $post_type): void
    {
        if (MotionPostType::POST_TYPE !== $post_type) {
            return;
        }
        $selected = isset($_GET['ssf_am_meeting']) && is_scalar($_GET['ssf_am_meeting']) ? absint(wp_unslash($_GET['ssf_am_meeting'])) : 0;
        ?><select name="ssf_am_meeting"><option value="0"><?php esc_html_e('Alla årsmöten', 'ssf-member-portal'); ?></option><?php foreach ($this->meetings->all() as $meeting) : ?><option value="<?php echo esc_attr($meeting->ID); ?>" <?php selected($selected, $meeting->ID); ?>><?php echo esc_html(get_the_title($meeting)); ?></option><?php endforeach; ?></select><?php
    }

    public function filter_motion_query(\WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query() || MotionPostType::POST_TYPE !== $query->get('post_type')) {
            return;
        }
        $meeting_id = isset($_GET['ssf_am_meeting']) && is_scalar($_GET['ssf_am_meeting']) ? absint(wp_unslash($_GET['ssf_am_meeting'])) : 0;
        if (! $meeting_id) {
            return;
        }
        $meta_query = (array) $query->get('meta_query');
        $meta_query[] = array('key' => '_ssf_mp_annual_meeting_id', 'value' => $meeting_id);
        $query->set('meta_query', $meta_query);
    }

    private function microsoft365_button(string $action, string $nonce_action, string $label, string $class): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <?php wp_nonce_field($nonce_action); ?>
            <?php submit_button($label, $class, 'submit', false); ?>
        </form>
        <?php
    }

    private function guard_microsoft365_action(string $nonce_action, string $nonce_field = '_wpnonce'): void
    {
        if (! $this->can_manage_microsoft365() || ! check_admin_referer($nonce_action, $nonce_field)) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-member-portal'));
        }
    }

    private function complete_microsoft365_action($result, string $success_message): void
    {
        if (is_wp_error($result)) {
            $data = (array) $result->get_error_data();
            $diagnostics = array(
                'ok' => false,
                'timestamp' => gmdate('c'),
                'message' => $result->get_error_message(),
                'http_status' => (int) ($data['http_status'] ?? $data['status'] ?? 0),
                'endpoint' => (string) ($data['endpoint'] ?? ''),
                'graph_code' => (string) ($data['graph_code'] ?? $data['microsoft_code'] ?? ''),
                'missing' => array_values((array) ($data['missing'] ?? array())),
            );
            $notice = array('type' => 'error', 'message' => $result->get_error_message());
        } else {
            $diagnostics = array(
                'ok' => true,
                'timestamp' => gmdate('c'),
                'result' => $this->without_sensitive_values($result),
            );
            $notice = array('type' => 'success', 'message' => $success_message);
        }

        update_option('ssf_member_portal_graph_diagnostics', $diagnostics, false);
        set_transient('ssf_member_portal_sharepoint_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=ssf-member-portal-microsoft365'));
        exit;
    }

    private function without_sensitive_values($value, string $key = '')
    {
        if (preg_match('/(token|secret|authorization)/i', $key)) {
            return '[redacted]';
        }
        if (is_array($value)) {
            $safe = array();
            foreach ($value as $child_key => $child_value) {
                $safe[$child_key] = $this->without_sensitive_values($child_value, (string) $child_key);
            }
            return $safe;
        }
        if (is_scalar($value) || null === $value) {
            return $value;
        }

        return '';
    }

    private function current_year(): int
    {
        return (int) wp_date('Y', null, wp_timezone());
    }

    private function format_timestamp(string $timestamp): string
    {
        $time = strtotime($timestamp);
        return $time ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $time) : '';
    }

    private function can_manage_microsoft365(): bool
    {
        return current_user_can(Capabilities::MANAGE) || MotionPermissions::can_manage();
    }

    private function state_label(array $state): string
    {
        $labels = array(
            'open' => __('Öppen', 'ssf-member-portal'),
            'upcoming' => __('Ej öppnad', 'ssf-member-portal'),
            'closed' => __('Stängd', 'ssf-member-portal'),
            'late' => __('Sen inlämning tillåten', 'ssf-member-portal'),
            'not_configured' => __('Inte konfigurerad', 'ssf-member-portal'),
        );
        return $labels[$state['state']] ?? $labels['not_configured'];
    }

    private function period_detail(array $state): string
    {
        if ('open' === $state['state']) {
            return sprintf(__('Tid kvar till stängning: %s', 'ssf-member-portal'), $this->deadline->time_remaining((int) $state['closes_at']));
        }
        if ('upcoming' === $state['state']) {
            return sprintf(__('Öppnar %s', 'ssf-member-portal'), $this->deadline->format((int) $state['opens_at']));
        }

        return $this->deadline->format((int) $state['closes_at']);
    }
}
