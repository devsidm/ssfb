<?php
/**
 * WordPress administration for the SSF membership process.
 *
 * @package SSF_Medlemsprocess
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsprocess_Admin
{
    public function __construct()
    {
        add_action('add_meta_boxes_' . SSF_Medlemsprocess_Application::POST_TYPE, array($this, 'add_meta_boxes'));
        add_action('save_post_' . SSF_Medlemsprocess_Application::POST_TYPE, array($this, 'save_application'), 10, 2);
        add_action('admin_menu', array($this, 'add_menu_pages'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_filter('manage_' . SSF_Medlemsprocess_Application::POST_TYPE . '_posts_columns', array($this, 'columns'));
        add_action('manage_' . SSF_Medlemsprocess_Application::POST_TYPE . '_posts_custom_column', array($this, 'column_content'), 10, 2);
        add_action('admin_post_ssf_add_application_note', array($this, 'add_note'));
        add_action('admin_post_ssf_application_token_action', array($this, 'token_action'));
        add_action('admin_post_ssf_save_process_settings', array($this, 'save_settings'));
        add_action('admin_post_ssf_export_memlist', array($this, 'export_memlist'));
    }

    public function add_meta_boxes(): void
    {
        add_meta_box('ssf_application_overview', 'Översikt och status', array($this, 'render_overview'), SSF_Medlemsprocess_Application::POST_TYPE, 'normal', 'high');
        add_meta_box('ssf_application_review', 'Granskning', array($this, 'render_review'), SSF_Medlemsprocess_Application::POST_TYPE, 'normal', 'default');
        add_meta_box('ssf_application_inspection', 'Inspektion', array($this, 'render_inspection'), SSF_Medlemsprocess_Application::POST_TYPE, 'normal', 'default');
        add_meta_box('ssf_application_booking', 'Tidsbokning', array($this, 'render_booking'), SSF_Medlemsprocess_Application::POST_TYPE, 'side', 'default');
        add_meta_box('ssf_application_decision', 'Beslut och Memlist', array($this, 'render_decision'), SSF_Medlemsprocess_Application::POST_TYPE, 'side', 'high');
        add_meta_box('ssf_application_notes', 'Noteringar', array($this, 'render_notes'), SSF_Medlemsprocess_Application::POST_TYPE, 'normal', 'default');
        add_meta_box('ssf_application_history', 'Historik', array($this, 'render_history'), SSF_Medlemsprocess_Application::POST_TYPE, 'normal', 'default');
    }

    public function render_overview(WP_Post $post): void
    {
        $data = SSF_Medlemsprocess_Application::data($post->ID);
        $status = SSF_Medlemsprocess_Application::status($post->ID);
        $assigned = (int) get_post_meta($post->ID, '_ssf_assigned_user', true);
        wp_nonce_field('ssf_save_application_' . $post->ID, 'ssf_application_admin_nonce');
        ?>
        <div class="ssf-process-admin-overview">
            <div><span class="ssf-process-admin-label">Ärendenummer</span><strong><?php echo esc_html(get_post_meta($post->ID, '_ssf_application_number', true)); ?></strong></div>
            <div><span class="ssf-process-admin-label">Fartyg</span><strong><?php echo esc_html($data['ship_name'] ?? ''); ?></strong></div>
            <div><span class="ssf-process-admin-label">Sökande</span><strong><?php echo esc_html($data['applicant_name'] ?? ''); ?></strong><a href="mailto:<?php echo esc_attr($data['applicant_email'] ?? ''); ?>"><?php echo esc_html($data['applicant_email'] ?? ''); ?></a></div>
        </div>
        <div class="ssf-process-admin-grid">
            <label>Status<select name="ssf_process_status"><?php foreach (SSF_Medlemsprocess_Application::statuses() as $key => $item) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($status, $key); ?>><?php echo esc_html($item['label']); ?></option><?php endforeach; ?></select></label>
            <label>Ansvarig handläggare<?php wp_dropdown_users(array('name' => 'ssf_assigned_user', 'selected' => $assigned, 'show_option_none' => 'Ej tilldelad', 'role__in' => array('administrator', 'ssf_inspektor', 'ssf_beslutsfattare'))); ?></label>
            <label>Nästa åtgärd<input type="text" name="ssf_next_action" value="<?php echo esc_attr((string) get_post_meta($post->ID, '_ssf_next_action', true)); ?>" placeholder="Exempel: inväntar registreringsbevis"></label>
            <label>Publikt statusmeddelande<textarea name="ssf_status_message" rows="3" placeholder="Visas för sökanden vid statusändring"></textarea></label>
        </div>
        <p class="description">Statuslänkar är personliga. <button type="button" class="button-link" data-ssf-token-action="send" data-application-id="<?php echo esc_attr((string) $post->ID); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('ssf_application_token_' . $post->ID)); ?>">Skicka en ny statuslänk</button> eller <button type="button" class="button-link-delete" data-ssf-token-action="revoke" data-application-id="<?php echo esc_attr((string) $post->ID); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('ssf_application_token_' . $post->ID)); ?>">återkalla befintlig länk</button>.</p>
        <?php
    }

    public function render_review(WP_Post $post): void
    {
        $saved = (array) get_post_meta($post->ID, '_ssf_review', true);
        $this->render_checklist('ssf_review', self::review_points(), $saved);
    }

    public function render_inspection(WP_Post $post): void
    {
        $inspection = (array) get_post_meta($post->ID, '_ssf_inspection', true);
        ?>
        <div class="ssf-process-admin-grid">
            <label>Datum för inspektion<input type="date" name="ssf_inspection[date]" value="<?php echo esc_attr($inspection['date'] ?? ''); ?>"></label>
            <label>Plats<input type="text" name="ssf_inspection[place]" value="<?php echo esc_attr($inspection['place'] ?? ''); ?>"></label>
            <label>Inspektör<input type="text" name="ssf_inspection[inspector]" value="<?php echo esc_attr($inspection['inspector'] ?? wp_get_current_user()->display_name); ?>"></label>
            <label>Närvarande personer<input type="text" name="ssf_inspection[attendees]" value="<?php echo esc_attr($inspection['attendees'] ?? ''); ?>"></label>
            <label>Typ av inspektion<select name="ssf_inspection[type]"><?php foreach (array('Ombord', 'Dokumentgranskning', 'Digital genomgång', 'Annat') as $type) : ?><option <?php selected($inspection['type'] ?? '', $type); ?>><?php echo esc_html($type); ?></option><?php endforeach; ?></select></label>
            <label>Väder eller förhållanden<input type="text" name="ssf_inspection[conditions]" value="<?php echo esc_attr($inspection['conditions'] ?? ''); ?>"></label>
        </div>
        <?php $this->render_checklist('ssf_inspection[checks]', self::inspection_points(), (array) ($inspection['checks'] ?? array())); ?>
        <div class="ssf-process-admin-grid">
            <?php foreach (array('summary' => 'Sammanfattning', 'strengths' => 'Styrkor', 'deficiencies' => 'Brister', 'actions' => 'Rekommenderade åtgärder', 'board_comment' => 'Kommentar till styrelsen', 'public_comment' => 'Kommentar till sökanden') as $key => $label) : ?><label><?php echo esc_html($label); ?><textarea name="ssf_inspection[<?php echo esc_attr($key); ?>]" rows="3"><?php echo esc_textarea($inspection[$key] ?? ''); ?></textarea></label><?php endforeach; ?>
            <label>Rekommendation<select name="ssf_inspection[recommendation]"><?php foreach (array('', 'Rekommenderas för godkännande', 'Rekommenderas som aspirant', 'Komplettering krävs', 'Rekommenderas ej') as $value) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($inspection['recommendation'] ?? '', $value); ?>><?php echo esc_html($value ?: 'Välj rekommendation'); ?></option><?php endforeach; ?></select></label>
        </div>
        <?php
    }

    public function render_booking(WP_Post $post): void
    {
        $booking = (array) get_post_meta($post->ID, '_ssf_booking', true);
        ?><label>Datum<input type="date" name="ssf_booking[date]" value="<?php echo esc_attr($booking['date'] ?? ''); ?>"></label><label>Starttid<input type="time" name="ssf_booking[start]" value="<?php echo esc_attr($booking['start'] ?? ''); ?>"></label><label>Sluttid<input type="time" name="ssf_booking[end]" value="<?php echo esc_attr($booking['end'] ?? ''); ?>"></label><label>Plats eller länk<input type="text" name="ssf_booking[location]" value="<?php echo esc_attr($booking['location'] ?? ''); ?>"></label><label>Mötestyp<select name="ssf_booking[type]"><?php foreach (array('Telefonsamtal', 'Digitalt möte', 'Ombordbesök', 'Dokumentgranskning') as $type) : ?><option <?php selected($booking['type'] ?? '', $type); ?>><?php echo esc_html($type); ?></option><?php endforeach; ?></select></label><label>Deltagare<input type="text" name="ssf_booking[participants]" value="<?php echo esc_attr($booking['participants'] ?? ''); ?>"></label><label>Kommentar<textarea name="ssf_booking[comment]" rows="3"><?php echo esc_textarea($booking['comment'] ?? ''); ?></textarea></label><label class="ssf-process-check"><input type="checkbox" name="ssf_send_booking_email" value="1"> Skicka bokningsmail vid uppdatering</label><?php
    }

    public function render_decision(WP_Post $post): void
    {
        $decision = (array) get_post_meta($post->ID, '_ssf_decision', true);
        $linked_ship = (int) get_post_meta($post->ID, '_ssf_linked_ship_id', true);
        ?><label>Beslut<select name="ssf_decision[status]"><option value="">Inget beslut ännu</option><option value="approved" <?php selected($decision['status'] ?? '', 'approved'); ?>>Godkänd</option><option value="approved_aspirant" <?php selected($decision['status'] ?? '', 'approved_aspirant'); ?>>Godkänd som aspirant</option><option value="rejected" <?php selected($decision['status'] ?? '', 'rejected'); ?>>Avslagen</option><option value="paused" <?php selected($decision['status'] ?? '', 'paused'); ?>>Vilande</option></select></label><label>Intern motivering<textarea name="ssf_decision[internal_reason]" rows="3"><?php echo esc_textarea($decision['internal_reason'] ?? ''); ?></textarea></label><label>Motivering till sökanden<textarea name="ssf_decision[public_reason]" rows="3"><?php echo esc_textarea($decision['public_reason'] ?? ''); ?></textarea></label><label class="ssf-process-check"><input type="checkbox" name="ssf_create_ship" value="1" <?php checked($linked_ship > 0); ?> <?php disabled($linked_ship > 0); ?>> Skapa medlemsfartygsprofil automatiskt</label><?php if ($linked_ship) : ?><p><a href="<?php echo esc_url(get_edit_post_link($linked_ship)); ?>">Öppna kopplat medlemsfartyg</a></p><?php endif; ?><label>Memlist-status<select name="ssf_memlist_status"><?php foreach (array('not_ready' => 'Ej överförd', 'ready' => 'Redo för överföring', 'transferred' => 'Överförd') as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected(get_post_meta($post->ID, '_ssf_memlist_status', true), $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><label>Memlist-ID<input type="text" name="ssf_memlist_id" value="<?php echo esc_attr((string) get_post_meta($post->ID, '_ssf_memlist_id', true)); ?>"></label><?php
    }

    public function render_notes(WP_Post $post): void
    {
        ?><div class="ssf-process-note-form" data-action-url="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-application-id="<?php echo esc_attr((string) $post->ID); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('ssf_add_application_note_' . $post->ID)); ?>"><label>Notering<textarea class="ssf-process-note-text" rows="4" placeholder="Skriv en intern notering eller ett meddelande till sökanden"></textarea></label><label>Synlighet<select class="ssf-process-note-visibility"><option value="internal">Intern notering</option><option value="public">Meddelande till sökanden</option></select></label><label class="ssf-process-check"><input class="ssf-process-note-email" type="checkbox" value="1"> Skicka även via e-post</label><p><button type="button" class="button button-primary" data-ssf-add-note>Spara notering</button></p></div><?php
    }

    public function render_history(WP_Post $post): void
    {
        $history = array_reverse((array) get_post_meta($post->ID, '_ssf_application_history', true));
        if (! $history) { echo '<p>Ingen historik ännu.</p>'; return; }
        echo '<ol class="ssf-process-history">';
        foreach ($history as $item) {
            $author = ! empty($item['author']) ? get_userdata((int) $item['author']) : false;
            printf('<li><strong>%s</strong><span>%s%s</span><p>%s</p></li>', esc_html(mysql2date('j F Y, H:i', $item['time'] ?? '')), esc_html($item['public'] ?? false ? 'Synlig för sökanden' : 'Intern'), $author ? esc_html(' · ' . $author->display_name) : '', nl2br(esc_html($item['message'] ?? '')));
        }
        echo '</ol>';
    }

    public function save_application(int $post_id, WP_Post $post): void
    {
        if (! isset($_POST['ssf_application_admin_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_application_admin_nonce'])), 'ssf_save_application_' . $post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || ! current_user_can('edit_ssf_application', $post_id)) { return; }
        $status = sanitize_key(wp_unslash($_POST['ssf_process_status'] ?? SSF_Medlemsprocess_Application::status($post_id)));
        update_post_meta($post_id, '_ssf_assigned_user', (int) ($_POST['ssf_assigned_user'] ?? 0));
        update_post_meta($post_id, '_ssf_next_action', sanitize_text_field(wp_unslash($_POST['ssf_next_action'] ?? '')));
        update_post_meta($post_id, '_ssf_review', $this->sanitize_checklist((array) wp_unslash($_POST['ssf_review'] ?? array())));
        $inspection = $this->sanitize_inspection((array) wp_unslash($_POST['ssf_inspection'] ?? array()));
        update_post_meta($post_id, '_ssf_inspection', $inspection);
        $booking = $this->sanitize_booking((array) wp_unslash($_POST['ssf_booking'] ?? array()));
        update_post_meta($post_id, '_ssf_booking', $booking);
        if (! empty($_POST['ssf_send_booking_email']) && ! empty($booking['date'])) { SSF_Medlemsprocess_Plugin::instance()->emails->send_booking($post_id, $booking); SSF_Medlemsprocess_Application::add_history($post_id, 'booking', 'Bokningsmeddelande skickades.', false); }
        update_post_meta($post_id, '_ssf_memlist_status', sanitize_key(wp_unslash($_POST['ssf_memlist_status'] ?? 'not_ready')));
        update_post_meta($post_id, '_ssf_memlist_id', sanitize_text_field(wp_unslash($_POST['ssf_memlist_id'] ?? '')));
        $decision = $this->sanitize_decision((array) wp_unslash($_POST['ssf_decision'] ?? array()));
        if (! empty($decision['status']) && current_user_can('ssf_decide_applications')) {
            update_post_meta($post_id, '_ssf_decision', $decision);
            update_post_meta($post_id, '_ssf_decision_public_reason', $decision['public_reason']);
            $status = $decision['status'];
            if (! empty($_POST['ssf_create_ship']) && in_array($status, array('approved', 'approved_aspirant'), true)) { SSF_Medlemsprocess_Application::create_member_ship($post_id); }
        }
        SSF_Medlemsprocess_Application::transition($post_id, $status, sanitize_textarea_field(wp_unslash($_POST['ssf_status_message'] ?? '')));
    }

    public function add_note(): void
    {
        $post_id = (int) ($_POST['application_id'] ?? 0);
        if (! $post_id || ! current_user_can('edit_ssf_application', $post_id) || ! check_admin_referer('ssf_add_application_note_' . $post_id)) { wp_die('Du saknar behörighet.'); }
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
        $public = 'public' === sanitize_key(wp_unslash($_POST['visibility'] ?? 'internal'));
        if ($message) {
            SSF_Medlemsprocess_Application::add_history($post_id, 'note', $message, $public);
            if ($public && ! empty($_POST['send_email'])) { $token = SSF_Medlemsprocess_Application::issue_token($post_id); SSF_Medlemsprocess_Plugin::instance()->emails->send_template('reminder', $post_id, array('admin_comment' => $message, 'status_link' => SSF_Medlemsprocess_Application::status_link($token))); }
        }
        wp_safe_redirect(get_edit_post_link($post_id, ''));
        exit;
    }

    public function token_action(): void
    {
        $post_id = (int) ($_POST['application_id'] ?? 0);
        if (! $post_id || ! current_user_can('edit_ssf_application', $post_id) || ! check_admin_referer('ssf_application_token_' . $post_id)) { wp_die('Du saknar behörighet.'); }
        $action = sanitize_key(wp_unslash($_POST['token_action'] ?? ''));
        if ('revoke' === $action) { update_post_meta($post_id, '_ssf_status_token_revoked', '1'); SSF_Medlemsprocess_Application::add_history($post_id, 'token', 'Statuslänken återkallades.', false); }
        if ('send' === $action) { $token = SSF_Medlemsprocess_Application::issue_token($post_id); SSF_Medlemsprocess_Plugin::instance()->emails->send_template('reminder', $post_id, array('admin_comment' => 'Här är en ny personlig länk till ditt ärende.', 'status_link' => SSF_Medlemsprocess_Application::status_link($token))); }
        wp_safe_redirect(get_edit_post_link($post_id, ''));
        exit;
    }

    public function add_menu_pages(): void
    {
        add_submenu_page('edit.php?post_type=' . SSF_Medlemsprocess_Application::POST_TYPE, 'Översikt', 'Översikt', 'ssf_view_applications', 'ssf-medlemsprocess-overview', array($this, 'render_dashboard'));
        add_submenu_page('edit.php?post_type=' . SSF_Medlemsprocess_Application::POST_TYPE, 'Inställningar', 'Inställningar', 'ssf_manage_application_settings', 'ssf-medlemsprocess-settings', array($this, 'render_settings'));
    }

    public function render_dashboard(): void
    {
        if (! current_user_can('ssf_view_applications')) { wp_die('Du saknar behörighet.'); }
        $counts = array(); foreach (SSF_Medlemsprocess_Application::statuses() as $key => $item) { $counts[$key] = count(get_posts(array('post_type' => SSF_Medlemsprocess_Application::POST_TYPE, 'post_status' => 'private', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_ssf_process_status', 'meta_value' => $key))); }
        ?><div class="wrap ssf-process-dashboard"><h1>Medlemsprocess</h1><div class="ssf-process-dashboard-cards"><?php foreach (array('submitted', 'under_review', 'needs_completion', 'inspection_booked', 'awaiting_decision', 'approved') as $status) : ?><div class="ssf-process-dashboard-card"><span><?php echo esc_html(SSF_Medlemsprocess_Application::status_label($status)); ?></span><strong><?php echo esc_html((string) ($counts[$status] ?? 0)); ?></strong></div><?php endforeach; ?></div><p><a class="button button-primary" href="<?php echo esc_url(admin_url('edit.php?post_type=' . SSF_Medlemsprocess_Application::POST_TYPE)); ?>">Öppna ansökningar</a> <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_export_memlist'), 'ssf_export_memlist')); ?>">Exportera godkända till CSV</a></p></div><?php
    }

    public function render_settings(): void
    {
        if (! current_user_can('ssf_manage_application_settings')) { wp_die('Du saknar behörighet.'); }
        $settings = SSF_Medlemsprocess_Plugin::settings();
        ?><div class="wrap"><h1>Inställningar för medlemsprocessen</h1><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ssf_save_process_settings"><?php wp_nonce_field('ssf_save_process_settings'); ?><table class="form-table"><tr><th><label for="ssf-admin-email">Administratörens e-post</label></th><td><input id="ssf-admin-email" class="regular-text" type="email" name="settings[admin_email]" value="<?php echo esc_attr($settings['admin_email']); ?>"></td></tr><tr><th>Statuslänkens giltighet</th><td><input type="number" min="1" max="730" name="settings[token_days]" value="<?php echo esc_attr((string) $settings['token_days']); ?>"> dagar</td></tr><tr><th>Max bildstorlek</th><td><input type="number" min="1" max="32" name="settings[max_image_mb]" value="<?php echo esc_attr((string) $settings['max_image_mb']); ?>"> MB</td></tr><tr><th>Max PDF-storlek</th><td><input type="number" min="1" max="32" name="settings[max_file_mb]" value="<?php echo esc_attr((string) $settings['max_file_mb']); ?>"> MB</td></tr></table><h2>E-postmallar</h2><?php foreach (SSF_Medlemsprocess_Emails::templates() as $key => $template) : $saved = (array) ($settings['templates'][$key] ?? array()); ?><details><summary><?php echo esc_html($template['label']); ?></summary><p><label>Ämne<input class="large-text" type="text" name="settings[templates][<?php echo esc_attr($key); ?>][subject]" value="<?php echo esc_attr($saved['subject'] ?? $template['subject']); ?>"></label></p><p><label>Meddelande<textarea class="large-text" rows="8" name="settings[templates][<?php echo esc_attr($key); ?>][body]" ><?php echo esc_textarea($saved['body'] ?? $template['body']); ?></textarea></label></p></details><?php endforeach; submit_button('Spara inställningar'); ?></form></div><?php
    }

    public function save_settings(): void
    {
        if (! current_user_can('ssf_manage_application_settings') || ! check_admin_referer('ssf_save_process_settings')) { wp_die('Du saknar behörighet.'); }
        $raw = (array) wp_unslash($_POST['settings'] ?? array()); $settings = SSF_Medlemsprocess_Plugin::settings();
        $settings['admin_email'] = sanitize_email($raw['admin_email'] ?? $settings['admin_email']); $settings['token_days'] = max(1, min(730, (int) ($raw['token_days'] ?? $settings['token_days']))); $settings['max_image_mb'] = max(1, min(32, (int) ($raw['max_image_mb'] ?? $settings['max_image_mb']))); $settings['max_file_mb'] = max(1, min(32, (int) ($raw['max_file_mb'] ?? $settings['max_file_mb'])));
        $settings['templates'] = array(); foreach ((array) ($raw['templates'] ?? array()) as $key => $template) { if (isset(SSF_Medlemsprocess_Emails::templates()[$key])) { $settings['templates'][$key] = array('subject' => sanitize_text_field($template['subject'] ?? ''), 'body' => sanitize_textarea_field($template['body'] ?? '')); } }
        update_option('ssf_medlemsprocess_settings', $settings, false); wp_safe_redirect(add_query_arg('updated', '1', admin_url('edit.php?post_type=' . SSF_Medlemsprocess_Application::POST_TYPE . '&page=ssf-medlemsprocess-settings'))); exit;
    }

    public function export_memlist(): void
    {
        if (! current_user_can('ssf_manage_application_settings') || ! check_admin_referer('ssf_export_memlist')) { wp_die('Du saknar behörighet.'); }
        $applications = get_posts(array('post_type' => SSF_Medlemsprocess_Application::POST_TYPE, 'post_status' => 'private', 'posts_per_page' => -1, 'meta_query' => array(array('key' => '_ssf_process_status', 'value' => array('approved', 'approved_aspirant'), 'compare' => 'IN'))));
        nocache_headers(); header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename=ssf-memlist-' . gmdate('Y-m-d') . '.csv'); $output = fopen('php://output', 'w'); fwrite($output, "\xEF\xBB\xBF"); fputcsv($output, array('Ärendenummer', 'Status', 'Fartyg', 'Ombud', 'E-post', 'Telefon', 'Organisation', 'Memlist-ID'), ';');
        foreach ($applications as $application) { $data = SSF_Medlemsprocess_Application::data($application->ID); fputcsv($output, array(get_post_meta($application->ID, '_ssf_application_number', true), SSF_Medlemsprocess_Application::status_label(SSF_Medlemsprocess_Application::status($application->ID)), $data['ship_name'] ?? '', $data['applicant_name'] ?? '', $data['applicant_email'] ?? '', $data['applicant_phone'] ?? '', $data['applicant_organization'] ?? '', get_post_meta($application->ID, '_ssf_memlist_id', true)), ';'); }
        fclose($output); exit;
    }

    public function enqueue_assets(string $hook): void
    {
        $screen = get_current_screen(); if (! $screen || SSF_Medlemsprocess_Application::POST_TYPE !== $screen->post_type) { return; }
        wp_enqueue_style('ssf-medlemsprocess-admin', SSF_MEDLEMSPROCESS_URL . 'assets/css/ssf-medlemsprocess-admin.css', array(), SSF_MEDLEMSPROCESS_VERSION);
        wp_enqueue_script('ssf-medlemsprocess-admin', SSF_MEDLEMSPROCESS_URL . 'assets/js/ssf-medlemsprocess-admin.js', array(), SSF_MEDLEMSPROCESS_VERSION, true);
    }

    public function columns(array $columns): array { return array('cb' => $columns['cb'], 'title' => 'Ärende', 'ssf_ship' => 'Fartyg', 'ssf_applicant' => 'Sökande', 'ssf_status' => 'Status', 'ssf_assigned' => 'Ansvarig', 'ssf_activity' => 'Senaste aktivitet', 'date' => 'Inskickad'); }
    public function column_content(string $column, int $post_id): void { $data = SSF_Medlemsprocess_Application::data($post_id); if ('ssf_ship' === $column) echo esc_html($data['ship_name'] ?? ''); if ('ssf_applicant' === $column) echo esc_html($data['applicant_name'] ?? ''); if ('ssf_status' === $column) echo '<span class="ssf-process-status-badge">' . esc_html(SSF_Medlemsprocess_Application::status_label(SSF_Medlemsprocess_Application::status($post_id))) . '</span>'; if ('ssf_assigned' === $column) { $user = get_userdata((int) get_post_meta($post_id, '_ssf_assigned_user', true)); echo esc_html($user ? $user->display_name : 'Ej tilldelad'); } if ('ssf_activity' === $column) echo esc_html(get_post_meta($post_id, '_ssf_last_activity', true)); }

    private function render_checklist(string $name, array $groups, array $saved): void { foreach ($groups as $group => $points) { echo '<section class="ssf-process-checklist"><h3>' . esc_html($group) . '</h3>'; foreach ($points as $key => $label) { $value = (array) ($saved[$key] ?? array()); echo '<div class="ssf-process-check-row"><strong>' . esc_html($label) . '</strong><select name="' . esc_attr($name . '[' . $key . '][status]') . '">'; foreach (array('' => 'Inte bedömd', 'met' => 'Uppfyllt', 'not_met' => 'Uppfyller ej', 'completion' => 'Komplettering krävs', 'na' => 'Ej relevant') as $option => $option_label) { echo '<option value="' . esc_attr($option) . '" ' . selected($value['status'] ?? '', $option, false) . '>' . esc_html($option_label) . '</option>'; } echo '</select><input name="' . esc_attr($name . '[' . $key . '][comment]') . '" value="' . esc_attr($value['comment'] ?? '') . '" placeholder="Kommentar"></div>'; } echo '</section>'; } }
    private static function review_points(): array { return array('Ombud och formalia' => array('representative' => 'Fartygsombud är angivet', 'contact' => 'Kontaktuppgifter är kompletta', 'application' => 'Ansökan är komplett', 'consent' => 'Samtycke och GDPR är godkänt'), 'Grundkrav för fartyg' => array('sailing' => 'Segelfartyg eller segelfartyg med hjälpmotor', 'professional' => 'Seglande yrkeshistorik eller relevant traditionell nybyggnation', 'purpose' => 'Relevant för SSF:s syfte'), 'Mått och registrering' => array('length' => 'Längd i huvuddäck överstiger 12 meter', 'beam' => 'Bredd är minst 4 meter', 'register' => 'Registeruppgifter är angivna vid behov'), 'Dokumentation' => array('images' => 'Bilder finns', 'history' => 'Historik finns', 'technical' => 'Teknisk information finns'), 'Bedömning' => array('continue' => 'Ansökan kan gå vidare', 'inspection' => 'Inspektion rekommenderas', 'board' => 'Styrelsebeslut krävs')); }
    private static function inspection_points(): array { return array('Fartyg och identitet' => array('identity_name' => 'Fartygets namn stämmer', 'identity_port' => 'Hemmahamn stämmer', 'identity_type' => 'Fartygstyp stämmer', 'identity_register' => 'Registreringsuppgifter stämmer', 'identity_contact' => 'Ombudets uppgifter stämmer'), 'Skrov och däck' => array('hull' => 'Skrovets allmänna skick bedömt', 'deck' => 'Däckets allmänna skick bedömt', 'damage' => 'Synliga skador eller brister noterade', 'maintenance' => 'Underhållsbehov noterat'), 'Rigg och segel' => array('rig' => 'Riggens typ och skick bedömt', 'masts' => 'Master och rundhult bedömda', 'standing_rig' => 'Stående rigg bedömd', 'running_rig' => 'Löpande rigg bedömd', 'sails' => 'Segel finns och är relevanta'), 'Maskin och system' => array('engine' => 'Hjälpmotor bedömd vid behov', 'electric' => 'Elsystem översiktligt bedömt', 'pumps' => 'Läns- och pumpsystem översiktligt bedömt', 'fire' => 'Brandskydd översiktligt bedömt'), 'Säkerhet och användning' => array('usage' => 'Fartygets användning är beskriven', 'safety' => 'Säkerhetsnivå är översiktligt bedömd', 'staffing' => 'Bemanning och kompetens är beskriven vid behov'), 'Kulturhistoriskt värde' => array('history' => 'Fartygets historik är beskriven', 'professional_history' => 'Tidigare yrkesanvändning är beskriven', 'restorations' => 'Restaureringar och förändringar är beskrivna', 'heritage' => 'Kulturhistoriskt värde är bedömt'), 'Dokumentation' => array('documentation_images' => 'Bilder finns', 'documentation' => 'Dokumentation är tillräcklig', 'additional' => 'Kompletterande dokument behövs')); }
    private function sanitize_checklist(array $items): array { $clean = array(); foreach ($items as $key => $item) { $clean[sanitize_key($key)] = array('status' => sanitize_key($item['status'] ?? ''), 'comment' => sanitize_text_field($item['comment'] ?? '')); } return $clean; }
    private function sanitize_inspection(array $inspection): array { $clean = array(); foreach (array('date', 'place', 'inspector', 'attendees', 'type', 'conditions', 'summary', 'strengths', 'deficiencies', 'actions', 'board_comment', 'public_comment', 'recommendation') as $key) { $clean[$key] = sanitize_textarea_field($inspection[$key] ?? ''); } $clean['checks'] = $this->sanitize_checklist((array) ($inspection['checks'] ?? array())); return $clean; }
    private function sanitize_booking(array $booking): array { $clean = array(); foreach (array('date', 'start', 'end', 'location', 'type', 'participants', 'comment') as $key) $clean[$key] = sanitize_textarea_field($booking[$key] ?? ''); return $clean; }
    private function sanitize_decision(array $decision): array { return array('status' => sanitize_key($decision['status'] ?? ''), 'internal_reason' => sanitize_textarea_field($decision['internal_reason'] ?? ''), 'public_reason' => sanitize_textarea_field($decision['public_reason'] ?? ''), 'date' => current_time('mysql'), 'by' => get_current_user_id()); }
}
