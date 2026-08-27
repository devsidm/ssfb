<?php

namespace SSF\MemberPortal\Modules\AnnualMeetings;

use SSF\MemberPortal\Core\Capabilities;

if (! defined('ABSPATH')) {
    exit;
}

final class Admin
{
    private Module $meetings;
    private RegistrationService $registrations;

    public function __construct(Module $meetings, RegistrationService $registrations)
    {
        $this->meetings = $meetings;
        $this->registrations = $registrations;
        add_filter('manage_' . RegistrationPostType::POST_TYPE . '_posts_columns', array($this, 'columns'));
        add_action('manage_' . RegistrationPostType::POST_TYPE . '_posts_custom_column', array($this, 'column'), 10, 2);
        add_action('restrict_manage_posts', array($this, 'filters'));
        add_action('pre_get_posts', array($this, 'filter_query'));
        add_action('add_meta_boxes_' . RegistrationPostType::POST_TYPE, array($this, 'meta_box'));
        add_filter('post_row_actions', array($this, 'row_actions'), 10, 2);
        add_action('admin_post_ssf_member_portal_export_meeting_registrations', array($this, 'export'));
        add_action('admin_post_ssf_member_portal_retry_meeting_sync', array($this, 'retry_sync'));
        add_action('admin_post_ssf_member_portal_resend_meeting_confirmation', array($this, 'resend_confirmation'));
    }

    public function register_menu(string $parent): void
    {
        add_submenu_page($parent, __('Årsmöten', 'ssf-member-portal'), __('Årsmöten', 'ssf-member-portal'), Capabilities::MANAGE_ANNUAL_MEETINGS, 'edit.php?post_type=' . Module::POST_TYPE);
        add_submenu_page($parent, __('Anmälningar', 'ssf-member-portal'), __('Anmälningar', 'ssf-member-portal'), Capabilities::MANAGE_ANNUAL_MEETINGS, 'ssf-member-portal-meeting-registrations', array($this, 'dashboard'));
    }

    public function dashboard(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ANNUAL_MEETINGS)) {
            return;
        }
        ?>
        <div class="wrap"><h1><?php esc_html_e('Årsmötesanmälningar', 'ssf-member-portal'); ?></h1>
        <p><?php esc_html_e('WordPress är den primära deltagarlistan. SharePoint-filen är en synkroniserad Excel-projektion.', 'ssf-member-portal'); ?></p>
        <?php if (isset($_GET['ssf_am_notice'])) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['ssf_am_notice']))); ?></p></div><?php endif; ?>
        <table class="widefat striped"><thead><tr><th><?php esc_html_e('Årsmöte', 'ssf-member-portal'); ?></th><th><?php esc_html_e('Anmälda', 'ssf-member-portal'); ?></th><th><?php esc_html_e('Program och mat', 'ssf-member-portal'); ?></th><th><?php esc_html_e('SharePoint', 'ssf-member-portal'); ?></th><th><?php esc_html_e('Åtgärder', 'ssf-member-portal'); ?></th></tr></thead><tbody>
        <?php foreach ($this->meetings->all() as $post) : $meeting = $this->meetings->data($post->ID); $registrations = $this->registrations->registrations($post->ID); $stats = $this->statistics($meeting, $registrations); $list_url = add_query_arg(array('post_type' => RegistrationPostType::POST_TYPE, 'ssf_am_meeting' => $post->ID), admin_url('edit.php')); ?>
            <tr><td><strong><?php echo esc_html(get_the_title($post)); ?></strong><br><span class="description"><?php echo esc_html((string) $meeting['year']); ?></span></td><td><?php echo esc_html((string) $stats['registered']); ?> <?php esc_html_e('bekräftade', 'ssf-member-portal'); ?><?php if ($stats['waitlist']) : ?><br><?php echo esc_html((string) $stats['waitlist']); ?> <?php esc_html_e('på reservlista', 'ssf-member-portal'); ?><?php endif; ?></td><td><?php foreach ($stats['selections'] as $label => $count) : ?><div><?php echo esc_html($label . ': ' . $count); ?></div><?php endforeach; ?></td><td><?php $excel_url = (string) get_post_meta($post->ID, '_ssf_am_sharepoint_excel_url', true); $sync = (string) get_post_meta($post->ID, '_ssf_am_sharepoint_excel_synced_at', true); if ($excel_url) : ?><a href="<?php echo esc_url($excel_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Öppna deltagarlista', 'ssf-member-portal'); ?></a><?php endif; ?><?php if ($sync) : ?><br><span class="description"><?php echo esc_html(sprintf(__('Synkad %s', 'ssf-member-portal'), $sync)); ?></span><?php endif; ?></td><td><p><a class="button" href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('Visa deltagare', 'ssf-member-portal'); ?></a></p><?php $this->export_link($post->ID, 'csv', __('Ladda ner CSV', 'ssf-member-portal')); ?><br><?php $this->export_link($post->ID, 'xlsx', __('Ladda ner Excel', 'ssf-member-portal')); ?><p><?php $this->retry_link($post->ID); ?></p></td></tr>
        <?php endforeach; ?>
        <?php if (! $this->meetings->all()) : ?><tr><td colspan="5"><?php esc_html_e('Skapa först ett årsmöte.', 'ssf-member-portal'); ?></td></tr><?php endif; ?>
        </tbody></table></div>
        <?php
    }

    public function columns(array $columns): array
    {
        return array(
            'cb' => $columns['cb'] ?? '<input type="checkbox">',
            'title' => __('Namn', 'ssf-member-portal'),
            'ssf_am_meeting' => __('Årsmöte', 'ssf-member-portal'),
            'ssf_am_relationship' => __('Medlemstyp', 'ssf-member-portal'),
            'ssf_am_vessels' => __('Fartyg', 'ssf-member-portal'),
            'ssf_am_contact' => __('Kontakt', 'ssf-member-portal'),
            'ssf_am_status' => __('Status', 'ssf-member-portal'),
            'ssf_am_program' => __('Program', 'ssf-member-portal'),
            'ssf_am_food' => __('Mat', 'ssf-member-portal'),
            'ssf_am_sharepoint' => __('SharePoint', 'ssf-member-portal'),
            'date' => __('Senast ändrad', 'ssf-member-portal'),
        );
    }

    public function column(string $column, int $post_id): void
    {
        $post = get_post($post_id);
        $meeting = $post ? $this->meetings->data((int) $post->post_parent) : array();
        $registration = $this->registrations->details($post_id, $meeting);
        switch ($column) {
            case 'ssf_am_meeting': echo esc_html(get_the_title((int) $meeting['id']) . ' ' . $meeting['year']); break;
            case 'ssf_am_relationship': echo esc_html($registration['relationship_label']); break;
            case 'ssf_am_vessels': echo esc_html(implode(', ', array_merge($registration['represented_vessels'], $registration['associated_vessels']))); break;
            case 'ssf_am_contact': echo esc_html($registration['email']); ?><br><?php echo esc_html($registration['phone']); break;
            case 'ssf_am_status': echo esc_html($registration['status_label']); break;
            case 'ssf_am_program': echo esc_html(implode(', ', $registration['program_labels'])); break;
            case 'ssf_am_food': echo esc_html(implode(', ', $registration['food'])); break;
            case 'ssf_am_sharepoint': echo esc_html($registration['sharepoint_sync_status']); if ($registration['sharepoint_last_error']) { ?><br><span class="description"><?php echo esc_html($registration['sharepoint_last_error']); ?></span><?php } break;
        }
    }

    public function filters(string $post_type): void
    {
        if (RegistrationPostType::POST_TYPE !== $post_type) {
            return;
        }
        $selected_meeting = absint($_GET['ssf_am_meeting'] ?? 0);
        $selected_relationship = sanitize_key($_GET['ssf_am_relationship'] ?? '');
        $selected_status = sanitize_key($_GET['ssf_am_status'] ?? '');
        $selected_program = sanitize_key($_GET['ssf_am_program'] ?? '');
        $selected_food = sanitize_text_field(wp_unslash($_GET['ssf_am_food'] ?? ''));
        $selected_vessel = sanitize_text_field(wp_unslash($_GET['ssf_am_vessel'] ?? ''));
        ?>
        <select name="ssf_am_meeting"><option value="0"><?php esc_html_e('Alla årsmöten', 'ssf-member-portal'); ?></option><?php foreach ($this->meetings->all() as $meeting) : ?><option value="<?php echo esc_attr($meeting->ID); ?>" <?php selected($selected_meeting, $meeting->ID); ?>><?php echo esc_html(get_the_title($meeting)); ?></option><?php endforeach; ?></select>
        <select name="ssf_am_relationship"><option value=""><?php esc_html_e('Alla medlemstyper', 'ssf-member-portal'); ?></option><option value="representative" <?php selected($selected_relationship, 'representative'); ?>><?php esc_html_e('Fartygsombud', 'ssf-member-portal'); ?></option><option value="supporter" <?php selected($selected_relationship, 'supporter'); ?>><?php esc_html_e('Stödmedlem', 'ssf-member-portal'); ?></option><option value="guest" <?php selected($selected_relationship, 'guest'); ?>><?php esc_html_e('Annan/inbjuden', 'ssf-member-portal'); ?></option></select>
        <select name="ssf_am_status"><option value=""><?php esc_html_e('Alla statusar', 'ssf-member-portal'); ?></option><option value="registered" <?php selected($selected_status, 'registered'); ?>><?php esc_html_e('Bekräftad', 'ssf-member-portal'); ?></option><option value="waitlist" <?php selected($selected_status, 'waitlist'); ?>><?php esc_html_e('Reservlista', 'ssf-member-portal'); ?></option><option value="cancelled" <?php selected($selected_status, 'cancelled'); ?>><?php esc_html_e('Avbokad', 'ssf-member-portal'); ?></option></select>
        <?php if ($selected_meeting) : $meeting = $this->meetings->data($selected_meeting); $vessels = array(); foreach ($this->registrations->registrations($selected_meeting) as $registration) { $data = $this->registrations->details($registration->ID, $meeting); $vessels = array_merge($vessels, $data['represented_vessels'], $data['associated_vessels']); } $vessels = array_values(array_unique(array_filter($vessels))); ?>
            <select name="ssf_am_program"><option value=""><?php esc_html_e('Alla programval', 'ssf-member-portal'); ?></option><?php foreach ($meeting['program'] as $item) : if (! empty($item['ask'])) : ?><option value="<?php echo esc_attr($item['key']); ?>" <?php selected($selected_program, $item['key']); ?>><?php echo esc_html($item['title']); ?></option><?php endif; endforeach; ?></select>
            <select name="ssf_am_food"><option value=""><?php esc_html_e('Alla matval', 'ssf-member-portal'); ?></option><?php foreach ($meeting['food_options'] as $food) : ?><option value="<?php echo esc_attr($food); ?>" <?php selected($selected_food, $food); ?>><?php echo esc_html($food); ?></option><?php endforeach; ?></select>
            <select name="ssf_am_vessel"><option value=""><?php esc_html_e('Alla fartyg', 'ssf-member-portal'); ?></option><?php foreach ($vessels as $vessel) : ?><option value="<?php echo esc_attr($vessel); ?>" <?php selected($selected_vessel, $vessel); ?>><?php echo esc_html($vessel); ?></option><?php endforeach; ?></select>
            <?php $this->export_link($selected_meeting, 'csv', __('Exportera CSV', 'ssf-member-portal')); echo ' '; $this->export_link($selected_meeting, 'xlsx', __('Exportera Excel', 'ssf-member-portal')); endif;
    }

    public function filter_query(\WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query() || RegistrationPostType::POST_TYPE !== $query->get('post_type')) {
            return;
        }
        $meeting = absint($_GET['ssf_am_meeting'] ?? 0);
        if ($meeting) {
            $query->set('post_parent', $meeting);
        }
        $meta_query = (array) $query->get('meta_query');
        foreach (array('relationship', 'status') as $key) {
            $value = sanitize_key($_GET['ssf_am_' . $key] ?? '');
            if ($value) {
                $meta_query[] = array('key' => '_ssf_am_' . $key, 'value' => $value);
            }
        }
        $program = sanitize_key($_GET['ssf_am_program'] ?? '');
        if ($program) {
            $meta_query[] = array('key' => '_ssf_am_program', 'value' => $program, 'compare' => 'LIKE');
        }
        $food = sanitize_text_field(wp_unslash($_GET['ssf_am_food'] ?? ''));
        if ($food) {
            $meta_query[] = array('key' => '_ssf_am_food', 'value' => $food, 'compare' => 'LIKE');
        }
        $vessel = sanitize_text_field(wp_unslash($_GET['ssf_am_vessel'] ?? ''));
        if ($vessel) {
            $meta_query[] = array(
                'relation' => 'OR',
                array('key' => '_ssf_am_represented_vessels', 'value' => $vessel, 'compare' => 'LIKE'),
                array('key' => '_ssf_am_associated_vessels', 'value' => $vessel, 'compare' => 'LIKE'),
            );
        }
        $query->set('meta_query', $meta_query);
    }

    public function meta_box(): void
    {
        add_meta_box('ssf-am-registration-details', __('Anmälningsuppgifter', 'ssf-member-portal'), array($this, 'render_meta_box'), RegistrationPostType::POST_TYPE, 'normal', 'high');
    }

    public function render_meta_box(\WP_Post $post): void
    {
        $meeting = $this->meetings->data((int) $post->post_parent);
        $registration = $this->registrations->details($post->ID, $meeting);
        ?>
        <table class="widefat striped"><tbody><tr><th><?php esc_html_e('Registrerings-ID', 'ssf-member-portal'); ?></th><td><?php echo esc_html($registration['registration_id']); ?></td></tr><tr><th><?php esc_html_e('Kontakt', 'ssf-member-portal'); ?></th><td><?php echo esc_html($registration['email']); ?><br><?php echo esc_html($registration['phone']); ?></td></tr><tr><th><?php esc_html_e('Relation', 'ssf-member-portal'); ?></th><td><?php echo esc_html($registration['relationship_label']); ?></td></tr><tr><th><?php esc_html_e('Fartyg', 'ssf-member-portal'); ?></th><td><?php echo esc_html(implode(', ', array_merge($registration['represented_vessels'], $registration['associated_vessels']))); ?></td></tr><tr><th><?php esc_html_e('Program', 'ssf-member-portal'); ?></th><td><?php echo esc_html(implode(', ', $registration['program_labels'])); ?></td></tr><tr><th><?php esc_html_e('Mat', 'ssf-member-portal'); ?></th><td><?php echo esc_html(implode(', ', $registration['food'])); ?><?php if ($registration['food_note']) : ?><br><?php echo esc_html($registration['food_note']); ?><?php endif; ?></td></tr><tr><th><?php esc_html_e('SharePoint', 'ssf-member-portal'); ?></th><td><?php echo esc_html($registration['sharepoint_sync_status']); ?><?php if ($registration['sharepoint_last_error']) : ?><br><?php echo esc_html($registration['sharepoint_last_error']); ?><?php endif; ?></td></tr></tbody></table>
        <?php if ($registration['answers']) : ?><h3><?php esc_html_e('Övriga svar', 'ssf-member-portal'); ?></h3><ul><?php foreach ($registration['answers'] as $key => $answer) : ?><li><strong><?php echo esc_html($key); ?>:</strong> <?php echo esc_html(is_array($answer) ? implode(', ', $answer) : $answer); ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php $this->resend_link($post->ID); ?>
        <?php
    }

    public function row_actions(array $actions, \WP_Post $post): array
    {
        if (RegistrationPostType::POST_TYPE === $post->post_type && current_user_can(Capabilities::MANAGE_ANNUAL_MEETINGS)) {
            $actions['ssf_resend'] = $this->resend_link($post->ID, false);
        }
        return $actions;
    }

    public function export(): void
    {
        $this->guard('ssf_member_portal_export_meeting_registrations');
        $meeting_id = absint($_GET['meeting_id'] ?? 0);
        $format = sanitize_key($_GET['format'] ?? 'csv');
        $meeting = $this->meetings->data($meeting_id);
        $filters = array(
            'relationship' => sanitize_key($_GET['ssf_am_relationship'] ?? ''),
            'status' => sanitize_key($_GET['ssf_am_status'] ?? ''),
        );
        $registrations = $this->registrations->registrations($meeting_id, array_filter($filters));
        $program = sanitize_key($_GET['ssf_am_program'] ?? '');
        $food = sanitize_text_field(wp_unslash($_GET['ssf_am_food'] ?? ''));
        $vessel = sanitize_text_field(wp_unslash($_GET['ssf_am_vessel'] ?? ''));
        if ($program || $food || $vessel) {
            $registrations = array_values(array_filter($registrations, function (\WP_Post $registration) use ($program, $food, $vessel): bool {
                $data = $this->registrations->details($registration->ID);
                if ($program && empty($data['program'][$program])) {
                    return false;
                }
                if ($food && ! in_array($food, $data['food'], true)) {
                    return false;
                }
                return ! $vessel || in_array($vessel, array_merge($data['represented_vessels'], $data['associated_vessels']), true);
            }));
        }
        $data = (new RegistrationExport())->data($meeting, $registrations);
        $year = (int) ($meeting['year'] ?: gmdate('Y'));
        if ('xlsx' === $format) {
            $file = (new RegistrationExport())->create_xlsx($meeting, $registrations);
            if (is_wp_error($file)) {
                wp_die(esc_html($file->get_error_message()));
            }
            nocache_headers();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="Anmalningar-SSF-Arsmote-' . $year . '.xlsx"');
            readfile($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
            wp_delete_file($file);
            exit;
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Anmalningar-SSF-Arsmote-' . $year . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, $data['headers'], ';');
        foreach ($data['rows'] as $row) {
            fputcsv($output, $row, ';');
        }
        fclose($output);
        exit;
    }

    public function retry_sync(): void
    {
        $this->guard('ssf_member_portal_retry_meeting_sync');
        $meeting_id = absint($_GET['meeting_id'] ?? 0);
        $this->registrations->queue_sync($meeting_id);
        $this->redirect_dashboard(__('Synkronisering har lagts i kö.', 'ssf-member-portal'));
    }

    public function resend_confirmation(): void
    {
        $this->guard('ssf_member_portal_resend_meeting_confirmation');
        $registration_id = absint($_GET['registration_id'] ?? 0);
        $sent = $this->registrations->resend_confirmation($registration_id);
        wp_safe_redirect(add_query_arg('ssf_am_notice', rawurlencode($sent ? __('Bekräftelsen har skickats igen.', 'ssf-member-portal') : __('Bekräftelsen kunde inte skickas.', 'ssf-member-portal')), get_edit_post_link($registration_id, 'url')));
        exit;
    }

    private function statistics(array $meeting, array $registrations): array
    {
        $statistics = array('registered' => 0, 'waitlist' => 0, 'selections' => array());
        foreach ($registrations as $post) {
            $registration = $this->registrations->details($post->ID, $meeting);
            if (RegistrationService::REGISTERED === $registration['status']) {
                $statistics['registered']++;
            }
            if (RegistrationService::WAITLIST === $registration['status']) {
                $statistics['waitlist']++;
            }
            if (RegistrationService::CANCELLED === $registration['status']) {
                continue;
            }
            foreach ($registration['program_labels'] as $label) {
                $statistics['selections'][$label] = ($statistics['selections'][$label] ?? 0) + 1;
            }
            foreach ($registration['food'] as $food) {
                $statistics['selections'][$food] = ($statistics['selections'][$food] ?? 0) + 1;
            }
        }
        return $statistics;
    }

    private function export_link(int $meeting_id, string $format, string $label): void
    {
        $args = array('action' => 'ssf_member_portal_export_meeting_registrations', 'meeting_id' => $meeting_id, 'format' => $format);
        foreach (array('ssf_am_relationship', 'ssf_am_status', 'ssf_am_program', 'ssf_am_food', 'ssf_am_vessel') as $key) {
            if (isset($_GET[$key]) && is_scalar($_GET[$key])) {
                $args[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
            }
        }
        $url = wp_nonce_url(add_query_arg($args, admin_url('admin-post.php')), 'ssf_member_portal_export_meeting_registrations');
        printf('<a href="%s">%s</a>', esc_url($url), esc_html($label));
    }

    private function retry_link(int $meeting_id): void
    {
        $url = wp_nonce_url(add_query_arg(array('action' => 'ssf_member_portal_retry_meeting_sync', 'meeting_id' => $meeting_id), admin_url('admin-post.php')), 'ssf_member_portal_retry_meeting_sync');
        printf('<a class="button" href="%s">%s</a>', esc_url($url), esc_html__('Synkronisera igen', 'ssf-member-portal'));
    }

    private function resend_link(int $registration_id, bool $echo = true): string
    {
        $url = wp_nonce_url(add_query_arg(array('action' => 'ssf_member_portal_resend_meeting_confirmation', 'registration_id' => $registration_id), admin_url('admin-post.php')), 'ssf_member_portal_resend_meeting_confirmation');
        $link = sprintf('<a href="%s">%s</a>', esc_url($url), esc_html__('Skicka bekräftelse igen', 'ssf-member-portal'));
        if ($echo) {
            echo $link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        return $link;
    }

    private function guard(string $action): void
    {
        if (! current_user_can(Capabilities::MANAGE_ANNUAL_MEETINGS) || ! check_admin_referer($action)) {
            wp_die(esc_html__('Du har inte behörighet att göra detta.', 'ssf-member-portal'));
        }
    }

    private function redirect_dashboard(string $notice): void
    {
        wp_safe_redirect(add_query_arg('ssf_am_notice', rawurlencode($notice), admin_url('admin.php?page=ssf-member-portal-meeting-registrations')));
        exit;
    }
}
