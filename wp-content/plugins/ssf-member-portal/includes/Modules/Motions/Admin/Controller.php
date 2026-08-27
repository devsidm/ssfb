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
        add_action('admin_post_ssf_member_portal_test_sharepoint_authentication', array($this, 'test_sharepoint_authentication'));
        add_action('admin_post_ssf_member_portal_test_sharepoint', array($this, 'test_sharepoint'));
        add_action('admin_post_ssf_member_portal_test_sharepoint_temporary_write', array($this, 'test_sharepoint_temporary_write'));
        add_action('admin_post_ssf_member_portal_test_sharepoint_write', array($this, 'test_sharepoint_write'));
        add_action('admin_post_ssf_member_portal_upload_sharepoint_test_file', array($this, 'upload_sharepoint_test_file'));
        add_action('admin_post_ssf_member_portal_delete_sharepoint_test_file', array($this, 'delete_sharepoint_test_file'));
        add_action('admin_post_ssf_member_portal_retry_sharepoint_sync', array($this, 'retry_sharepoint_sync'));
        add_action('add_meta_boxes_' . MotionPostType::POST_TYPE, array($this, 'add_motion_meta_box'));
        add_action('save_post_' . MotionPostType::POST_TYPE, array($this, 'save_motion'), 10, 2);
        add_filter('manage_' . MotionPostType::POST_TYPE . '_posts_columns', array($this, 'columns'));
        add_action('manage_' . MotionPostType::POST_TYPE . '_posts_custom_column', array($this, 'column_content'), 10, 2);
    }

    public function register_menu(string $parent): void
    {
        add_submenu_page($parent, __('Alla motioner', 'ssf-member-portal'), __('Motioner', 'ssf-member-portal'), Capabilities::MANAGE_MOTIONS, 'edit.php?post_type=' . MotionPostType::POST_TYPE);
        add_submenu_page($parent, __('Motionsperiod', 'ssf-member-portal'), __('Motionsperiod', 'ssf-member-portal'), Capabilities::MANAGE_MOTIONS, 'ssf-member-portal-motion-period', array($this, 'render_period'));
        add_submenu_page($parent, __('Inställningar', 'ssf-member-portal'), __('Inställningar', 'ssf-member-portal'), Capabilities::MANAGE, 'ssf-member-portal-settings', array($this, 'render_settings'));
        add_submenu_page($parent, __('Microsoft 365', 'ssf-member-portal'), __('Microsoft 365', 'ssf-member-portal'), Capabilities::MANAGE_MOTIONS, 'ssf-member-portal-microsoft365', array($this, 'render_microsoft365'));
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
                <tr><th><?php esc_html_e('Sen inlämning', 'ssf-member-portal'); ?></th><td><label><input type="checkbox" name="late_override" value="1" <?php checked('yes', get_option('ssf_member_portal_late_override', 'no')); ?>> <?php esc_html_e('Tillåt inlämning även efter sista motionsdag', 'ssf-member-portal'); ?></label><p class="description"><?php esc_html_e('Sena motioner märks alltid permanent som inkomna efter motionsfrist.', 'ssf-member-portal'); ?></p></td></tr>
                <tr><th><label for="ssf-notification-email"><?php esc_html_e('Mottagare av ny motion', 'ssf-member-portal'); ?></label></th><td><input id="ssf-notification-email" class="regular-text" type="email" name="notification_email" value="<?php echo esc_attr($settings['notification_email']); ?>"></td></tr>
                <tr><th><label for="ssf-upload-size"><?php esc_html_e('Max filstorlek', 'ssf-member-portal'); ?></label></th><td><input id="ssf-upload-size" class="small-text" type="number" min="1" max="25" name="max_upload_mb" value="<?php echo esc_attr($settings['max_upload_mb']); ?>"> MB</td></tr>
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
        $notice = get_transient('ssf_member_portal_sharepoint_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('ssf_member_portal_sharepoint_notice_' . get_current_user_id());
        }
        $diagnostics = (array) get_option('ssf_member_portal_graph_diagnostics', array());
        $test_file = (array) get_option('ssf_member_portal_graph_test_file', array());
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Motioner – Microsoft 365', 'ssf-member-portal'); ?></h1>
            <p><?php esc_html_e('Motionen sparas alltid först i WordPress. SharePoint är ett asynkront dokumentarkiv och kan inte blockera en inskickad motion.', 'ssf-member-portal'); ?></p>
            <?php if ($notice) : ?><div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['message']); ?></p></div><?php endif; ?>

            <div class="postbox" style="max-width:980px;padding:20px">
                <h2><?php esc_html_e('Konfigurationsstatus', 'ssf-member-portal'); ?></h2>
                <table class="widefat striped"><tbody>
                <?php foreach (array('tenant_id' => 'Tenant ID', 'client_id' => 'Client ID', 'client_secret' => 'Client secret', 'site_id' => 'Site ID', 'drive_id' => 'Drive ID', 'annual_meeting_folder_id' => 'Årsmöten-mappens ID', 'annual_meeting_folder_name' => 'Årsmöten-mappens namn') as $key => $label) : ?>
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
                        <tr><th><label for="ssf-graph-root-id"><?php esc_html_e('Årsmöten-mappens ID', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-root-id" class="large-text code" name="graph[annual_meeting_folder_id]" value="<?php echo esc_attr($values['annual_meeting_folder_id']); ?>"></td></tr>
                        <tr><th><label for="ssf-graph-root-name"><?php esc_html_e('Årsmöten-mappens namn', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-root-name" class="regular-text" name="graph[annual_meeting_folder_name]" value="<?php echo esc_attr($values['annual_meeting_folder_name']); ?>"></td></tr>
                        <tr><th><label for="ssf-graph-hostname"><?php esc_html_e('SharePoint hostname', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-hostname" class="regular-text code" name="graph[site_hostname]" value="<?php echo esc_attr($values['site_hostname']); ?>"></td></tr>
                        <tr><th><label for="ssf-graph-path"><?php esc_html_e('SharePoint site path', 'ssf-member-portal'); ?></label></th><td><input id="ssf-graph-path" class="regular-text code" name="graph[site_path]" value="<?php echo esc_attr($values['site_path']); ?>"></td></tr>
                        </tbody></table>
                        <?php submit_button(__('Spara anslutningsinställningar', 'ssf-member-portal')); ?>
                    </form>
                    <?php $this->microsoft365_button('ssf_member_portal_reset_microsoft365_configuration', 'ssf_member_portal_reset_microsoft365_configuration', __('Återställ till SSF-standardvärden', 'ssf-member-portal'), 'secondary'); ?>
                </div>
            <?php endif; ?>

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

    public function save_settings(): void
    {
        if (! current_user_can(Capabilities::MANAGE) || ! check_admin_referer('ssf_member_portal_save_motion_settings')) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-member-portal'));
        }

        update_option('ssf_member_portal_active_meeting_id', absint($_POST['active_meeting_id'] ?? 0), false);
        update_option('ssf_member_portal_late_override', ! empty($_POST['late_override']) ? 'yes' : 'no', false);
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
        $notice = is_wp_error($result)
            ? array('type' => 'error', 'message' => $result->get_error_message())
            : array('type' => 'success', 'message' => __('Microsoft 365-konfigurationen har sparats.', 'ssf-member-portal'));
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

    public function add_motion_meta_box(): void
    {
        add_meta_box('ssf-member-portal-motion', __('Motionens uppgifter', 'ssf-member-portal'), array($this, 'render_motion_meta_box'), MotionPostType::POST_TYPE, 'normal', 'high');
    }

    public function render_motion_meta_box(\WP_Post $post): void
    {
        $status = (string) get_post_meta($post->ID, '_ssf_mp_status', true);
        $attachments = (array) get_post_meta($post->ID, '_ssf_mp_file_ids', true);
        $sync_status = (string) get_post_meta($post->ID, '_ssf_mp_sharepoint_status', true);
        $sync_error = (string) get_post_meta($post->ID, '_ssf_mp_sharepoint_last_error', true);
        $sharepoint_items = (array) get_post_meta($post->ID, '_ssf_mp_sharepoint_items', true);
        wp_nonce_field('ssf_member_portal_motion_admin', 'ssf_member_portal_motion_admin_nonce');
        ?>
        <table class="widefat striped"><tbody>
        <tr><th><?php esc_html_e('Motionsnummer', 'ssf-member-portal'); ?></th><td><?php echo esc_html(get_post_meta($post->ID, '_ssf_mp_motion_number', true)); ?></td></tr>
        <tr><th><?php esc_html_e('Motionär', 'ssf-member-portal'); ?></th><td><?php echo esc_html(get_post_meta($post->ID, '_ssf_mp_submitter_name', true)); ?><br><a href="mailto:<?php echo esc_attr(get_post_meta($post->ID, '_ssf_mp_submitter_email', true)); ?>"><?php echo esc_html(get_post_meta($post->ID, '_ssf_mp_submitter_email', true)); ?></a></td></tr>
        <tr><th><?php esc_html_e('Inskickad', 'ssf-member-portal'); ?></th><td><?php echo esc_html($this->deadline->format((int) get_post_meta($post->ID, '_ssf_mp_submitted_at', true))); ?></td></tr>
        <tr><th><?php esc_html_e('Motionsfrist', 'ssf-member-portal'); ?></th><td><?php echo esc_html($this->deadline->format((int) get_post_meta($post->ID, '_ssf_mp_submission_deadline_at', true))); ?></td></tr>
        <tr><th><label for="ssf-motion-status"><?php esc_html_e('Status', 'ssf-member-portal'); ?></label></th><td><select id="ssf-motion-status" name="ssf_mp_status"><?php foreach (MotionStatus::all() as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td></tr>
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
        </tbody></table>
        <?php
    }

    public function save_motion(int $post_id, \WP_Post $post): void
    {
        if (! MotionPermissions::can_manage() || ! isset($_POST['ssf_member_portal_motion_admin_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_member_portal_motion_admin_nonce'])), 'ssf_member_portal_motion_admin')) {
            return;
        }

        $status = sanitize_key(wp_unslash($_POST['ssf_mp_status'] ?? ''));
        if (isset(MotionStatus::all()[$status])) {
            update_post_meta($post_id, '_ssf_mp_status', $status);
        }
    }

    public function columns(array $columns): array
    {
        return array(
            'cb' => $columns['cb'],
            'title' => __('Motion', 'ssf-member-portal'),
            'motion_status' => __('Status', 'ssf-member-portal'),
            'motion_submitted' => __('Inskickad', 'ssf-member-portal'),
            'date' => $columns['date'],
        );
    }

    public function column_content(string $column, int $post_id): void
    {
        if ('motion_status' === $column) {
            echo esc_html(MotionStatus::label((string) get_post_meta($post_id, '_ssf_mp_status', true)));
        }
        if ('motion_submitted' === $column) {
            echo esc_html($this->deadline->format((int) get_post_meta($post_id, '_ssf_mp_submitted_at', true)));
        }
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
