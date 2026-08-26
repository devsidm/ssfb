<?php

namespace SSF\MemberPortal\Modules\Motions\Admin;

use SSF\MemberPortal\Core\Capabilities;
use SSF\MemberPortal\Core\Settings;
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
        add_submenu_page($parent, __('Microsoft 365', 'ssf-member-portal'), __('Microsoft 365', 'ssf-member-portal'), Capabilities::MANAGE, 'ssf-member-portal-microsoft365', array($this, 'render_microsoft365'));
    }

    public function render_dashboard(): void
    {
        if (! MotionPermissions::can_manage()) {
            return;
        }
        $state = $this->deadline->state();
        $meeting = $state['meeting'];
        $counts = wp_count_posts(MotionPostType::POST_TYPE);
        $under_review = count(get_posts(array('post_type' => MotionPostType::POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_ssf_mp_status', 'meta_value' => MotionStatus::UNDER_REVIEW)));
        $sync_errors = count(get_posts(array('post_type' => MotionPostType::POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_ssf_mp_sharepoint_status', 'meta_value' => 'retrying')));
        ?>
        <div class="wrap"><h1><?php esc_html_e('SSF', 'ssf-member-portal'); ?></h1>
        <div class="postbox" style="max-width:820px;padding:20px"><h2><?php esc_html_e('Motioner', 'ssf-member-portal'); ?></h2>
        <table class="widefat striped"><tbody>
        <tr><th><?php esc_html_e('Motionsperiod', 'ssf-member-portal'); ?></th><td><?php echo esc_html($this->state_label($state)); ?></td></tr>
        <tr><th><?php esc_html_e('Årsmöte', 'ssf-member-portal'); ?></th><td><?php echo esc_html($meeting['year'] ?: '–'); ?></td></tr>
        <tr><th><?php esc_html_e('Stänger', 'ssf-member-portal'); ?></th><td><?php echo esc_html($this->deadline->format((int) $state['closes_at']) ?: '–'); ?></td></tr>
        <tr><th><?php esc_html_e('Inkomna motioner', 'ssf-member-portal'); ?></th><td><?php echo esc_html((string) ((int) ($counts->private ?? 0))); ?></td></tr>
        <tr><th><?php esc_html_e('Under behandling', 'ssf-member-portal'); ?></th><td><?php echo esc_html((string) $under_review); ?></td></tr>
        <tr><th><?php esc_html_e('Synkfel', 'ssf-member-portal'); ?></th><td><?php echo esc_html((string) $sync_errors); ?></td></tr>
        </tbody></table>
        <p><a class="button button-primary" href="<?php echo esc_url(admin_url('edit.php?post_type=' . MotionPostType::POST_TYPE)); ?>"><?php esc_html_e('Hantera motioner', 'ssf-member-portal'); ?></a></p>
        </div></div>
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
        <div class="wrap"><h1><?php esc_html_e('Motionsperiod', 'ssf-member-portal'); ?></h1>
        <div class="notice notice-info"><p><strong><?php echo esc_html($this->state_label($state)); ?></strong><?php if ($state['closes_at']) : ?> – <?php echo esc_html($this->period_detail($state)); ?><?php endif; ?></p></div>
        <h2><?php echo esc_html(sprintf(__('Motionsperiod – Årsmöte %s', 'ssf-member-portal'), $meeting['year'] ?: '–')); ?></h2>
        <?php if ($meeting['id']) : ?>
          <p><?php esc_html_e('Öppnar:', 'ssf-member-portal'); ?> <strong><?php echo esc_html($this->deadline->format((int) $state['opens_at'])); ?></strong><br><?php esc_html_e('Stänger:', 'ssf-member-portal'); ?> <strong><?php echo esc_html($this->deadline->format((int) $state['closes_at'])); ?></strong></p>
          <p><a class="button" href="<?php echo esc_url(get_edit_post_link((int) $meeting['id'])); ?>"><?php esc_html_e('Redigera årsmöte och datum', 'ssf-member-portal'); ?></a></p>
        <?php else : ?>
          <p><?php esc_html_e('Skapa ett årsmöte och välj det som aktivt under Inställningar.', 'ssf-member-portal'); ?></p>
        <?php endif; ?>
        <p><a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . AnnualMeetings::POST_TYPE)); ?>"><?php esc_html_e('Lägg till årsmöte', 'ssf-member-portal'); ?></a></p></div>
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
        <div class="wrap"><h1><?php esc_html_e('Medlemsportal – Inställningar', 'ssf-member-portal'); ?></h1>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ssf_member_portal_save_motion_settings"><?php wp_nonce_field('ssf_member_portal_save_motion_settings'); ?>
        <table class="form-table" role="presentation"><tbody>
        <tr><th><label for="ssf-active-meeting"><?php esc_html_e('Aktivt årsmöte', 'ssf-member-portal'); ?></label></th><td><select id="ssf-active-meeting" name="active_meeting_id"><option value="0"><?php esc_html_e('Välj årsmöte', 'ssf-member-portal'); ?></option><?php foreach ($this->meetings->all() as $item) : $data = $this->meetings->data($item->ID); ?><option value="<?php echo esc_attr($item->ID); ?>" <?php selected($active_id, $item->ID); ?>><?php echo esc_html($data['year'] ? sprintf(__('Årsmöte %d', 'ssf-member-portal'), $data['year']) : $item->post_title); ?></option><?php endforeach; ?></select></td></tr>
        <tr><th><?php esc_html_e('Sen inlämning', 'ssf-member-portal'); ?></th><td><label><input type="checkbox" name="late_override" value="1" <?php checked('yes', get_option('ssf_member_portal_late_override', 'no')); ?>> <?php esc_html_e('Tillåt inlämning även efter sista motionsdag', 'ssf-member-portal'); ?></label><p class="description"><?php esc_html_e('Sena motioner märks alltid permanent som inkomna efter motionsfrist.', 'ssf-member-portal'); ?></p></td></tr>
        <tr><th><label for="ssf-notification-email"><?php esc_html_e('Mottagare av ny motion', 'ssf-member-portal'); ?></label></th><td><input id="ssf-notification-email" class="regular-text" type="email" name="notification_email" value="<?php echo esc_attr($settings['notification_email']); ?>"></td></tr>
        <tr><th><label for="ssf-upload-size"><?php esc_html_e('Max filstorlek', 'ssf-member-portal'); ?></label></th><td><input id="ssf-upload-size" class="small-text" type="number" min="1" max="25" name="max_upload_mb" value="<?php echo esc_attr($settings['max_upload_mb']); ?>"> MB</td></tr>
        </tbody></table><?php submit_button(__('Spara inställningar', 'ssf-member-portal')); ?></form></div>
        <?php
    }

    public function render_microsoft365(): void
    {
        if (! current_user_can(Capabilities::MANAGE)) {
            return;
        }
        $settings = Settings::all();
        ?>
        <div class="wrap"><h1><?php esc_html_e('Medlemsportal – Microsoft 365', 'ssf-member-portal'); ?></h1>
        <p><?php esc_html_e('SharePoint är ett dokumentarkiv. Motionen sparas alltid först i WordPress och fungerar även när Microsoft 365 inte är tillgängligt.', 'ssf-member-portal'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ssf_member_portal_save_motion_settings"><?php wp_nonce_field('ssf_member_portal_save_motion_settings'); ?>
        <input type="hidden" name="active_meeting_id" value="<?php echo esc_attr((string) get_option('ssf_member_portal_active_meeting_id', 0)); ?>"><input type="hidden" name="notification_email" value="<?php echo esc_attr($settings['notification_email']); ?>"><input type="hidden" name="max_upload_mb" value="<?php echo esc_attr($settings['max_upload_mb']); ?>">
        <table class="form-table" role="presentation"><tbody>
        <tr><th><?php esc_html_e('Aktivera SharePoint-synk', 'ssf-member-portal'); ?></th><td><label><input type="checkbox" name="sharepoint_enabled" value="1" <?php checked('yes', $settings['sharepoint_enabled']); ?>> <?php esc_html_e('Köa motionsbilagor för synk till SharePoint', 'ssf-member-portal'); ?></label></td></tr>
        <tr><th><label for="ssf-tenant"><?php esc_html_e('Microsoft Entra Tenant ID', 'ssf-member-portal'); ?></label></th><td><input id="ssf-tenant" class="regular-text code" name="microsoft_tenant_id" value="<?php echo esc_attr($settings['microsoft_tenant_id']); ?>"></td></tr>
        <tr><th><label for="ssf-client-id"><?php esc_html_e('Application (client) ID', 'ssf-member-portal'); ?></label></th><td><input id="ssf-client-id" class="regular-text code" name="microsoft_client_id" value="<?php echo esc_attr($settings['microsoft_client_id']); ?>"></td></tr>
        <tr><th><label for="ssf-client-secret"><?php esc_html_e('Client secret', 'ssf-member-portal'); ?></label></th><td><input id="ssf-client-secret" class="regular-text" type="password" name="microsoft_client_secret" value="" autocomplete="new-password"><p class="description"><?php esc_html_e('Lämna tomt för att behålla ett redan sparat secret.', 'ssf-member-portal'); ?></p></td></tr>
        <tr><th><label for="ssf-site-id"><?php esc_html_e('SharePoint Site ID', 'ssf-member-portal'); ?></label></th><td><input id="ssf-site-id" class="large-text code" name="sharepoint_site_id" value="<?php echo esc_attr($settings['sharepoint_site_id']); ?>"></td></tr>
        <tr><th><label for="ssf-drive-id"><?php esc_html_e('Document Library / Drive ID', 'ssf-member-portal'); ?></label></th><td><input id="ssf-drive-id" class="large-text code" name="sharepoint_drive_id" value="<?php echo esc_attr($settings['sharepoint_drive_id']); ?>"></td></tr>
        </tbody></table><p class="description"><?php esc_html_e('Ge appen minsta nödvändiga Graph-behörighet för den valda dokumentytan, till exempel Sites.Selected med godkänd platsbehörighet.', 'ssf-member-portal'); ?></p><?php submit_button(__('Spara Microsoft 365-inställningar', 'ssf-member-portal')); ?></form></div>
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

    public function add_motion_meta_box(): void
    {
        add_meta_box('ssf-member-portal-motion', __('Motionens uppgifter', 'ssf-member-portal'), array($this, 'render_motion_meta_box'), MotionPostType::POST_TYPE, 'normal', 'high');
    }

    public function render_motion_meta_box(\WP_Post $post): void
    {
        $status = (string) get_post_meta($post->ID, '_ssf_mp_status', true);
        $attachments = (array) get_post_meta($post->ID, '_ssf_mp_file_ids', true);
        wp_nonce_field('ssf_member_portal_motion_admin', 'ssf_member_portal_motion_admin_nonce');
        ?>
        <table class="widefat striped"><tbody>
        <tr><th><?php esc_html_e('Motionsnummer', 'ssf-member-portal'); ?></th><td><?php echo esc_html(get_post_meta($post->ID, '_ssf_mp_motion_number', true)); ?></td></tr>
        <tr><th><?php esc_html_e('Motionär', 'ssf-member-portal'); ?></th><td><?php echo esc_html(get_post_meta($post->ID, '_ssf_mp_submitter_name', true)); ?><br><a href="mailto:<?php echo esc_attr(get_post_meta($post->ID, '_ssf_mp_submitter_email', true)); ?>"><?php echo esc_html(get_post_meta($post->ID, '_ssf_mp_submitter_email', true)); ?></a></td></tr>
        <tr><th><?php esc_html_e('Inskickad', 'ssf-member-portal'); ?></th><td><?php echo esc_html($this->deadline->format((int) get_post_meta($post->ID, '_ssf_mp_submitted_at', true))); ?></td></tr>
        <tr><th><?php esc_html_e('Motionsfrist', 'ssf-member-portal'); ?></th><td><?php echo esc_html($this->deadline->format((int) get_post_meta($post->ID, '_ssf_mp_submission_deadline_at', true))); ?></td></tr>
        <tr><th><label for="ssf-motion-status"><?php esc_html_e('Status', 'ssf-member-portal'); ?></label></th><td><select id="ssf-motion-status" name="ssf_mp_status"><?php foreach (MotionStatus::all() as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td></tr>
        <tr><th><?php esc_html_e('Bilagor', 'ssf-member-portal'); ?></th><td><?php foreach ($attachments as $attachment_id) : ?><a href="<?php echo esc_url(wp_get_attachment_url((int) $attachment_id)); ?>" target="_blank" rel="noopener"><?php echo esc_html(get_the_title((int) $attachment_id)); ?></a><br><?php endforeach; ?><?php if (! $attachments) { esc_html_e('Inga bilagor.', 'ssf-member-portal'); } ?></td></tr>
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
        return array('cb' => $columns['cb'], 'title' => __('Motion', 'ssf-member-portal'), 'motion_status' => __('Status', 'ssf-member-portal'), 'motion_submitted' => __('Inskickad', 'ssf-member-portal'), 'date' => $columns['date']);
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

    private function state_label(array $state): string
    {
        $labels = array('open' => __('Öppen', 'ssf-member-portal'), 'upcoming' => __('Ej öppnad', 'ssf-member-portal'), 'closed' => __('Stängd', 'ssf-member-portal'), 'late' => __('Sen inlämning tillåten', 'ssf-member-portal'), 'not_configured' => __('Inte konfigurerad', 'ssf-member-portal'));
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
