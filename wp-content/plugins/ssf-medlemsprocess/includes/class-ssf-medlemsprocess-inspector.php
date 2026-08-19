<?php
/**
 * Restricted front-end workspace for SSF inspectors.
 *
 * @package SSF_Medlemsprocess
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsprocess_Inspector
{
    private const CHECK_STATUSES = array(
        '' => 'Inte bedömd',
        'met' => 'Uppfyllt',
        'not_met' => 'Uppfyller ej',
        'completion' => 'Komplettering krävs',
        'na' => 'Ej relevant',
    );

    public function __construct()
    {
        add_action('init', array($this, 'register_shortcodes'), 99);
        add_action('admin_init', array($this, 'redirect_from_admin'));
        add_action('admin_post_ssf_inspector_save_report', array($this, 'save_report'));
        add_action('admin_post_ssf_inspector_send_message', array($this, 'send_message'));
    }

    public function register_shortcodes(): void
    {
        add_shortcode('ssf_inspector_portal', array($this, 'portal'));
    }

    public function redirect_from_admin(): void
    {
        global $pagenow;
        if ('admin-post.php' === $pagenow || wp_doing_ajax() || current_user_can('manage_options')) {
            return;
        }
        if (current_user_can('ssf_view_assigned_applications')) {
            wp_safe_redirect(SSF_Medlemsprocess_Plugin::page_url('mina_inspektioner'));
            exit;
        }
    }

    public static function checklist_sections(): array
    {
        return array(
            'Fartyg och identitet' => array(
                'identity_name' => 'Fartygets namn och identitet stämmer',
                'identity_home_port' => 'Hemmahamn och registreringsuppgifter stämmer',
                'identity_type' => 'Fartygstyp, rigg och huvudmått stämmer',
                'identity_owner' => 'Fartygsombud och kontaktuppgifter är bekräftade',
                'identity_use' => 'Nuvarande användning är tydligt beskriven',
            ),
            'Skrov, däck och överbyggnad' => array(
                'hull_condition' => 'Skrovets allmänna skick är bedömt',
                'hull_damage' => 'Synliga skador, röta eller korrosion är noterade',
                'deck_condition' => 'Däck, överbyggnad och luckor är bedömda',
                'watertight' => 'Genomföringar och täthet är översiktligt bedömda',
                'maintenance' => 'Underhållsbehov och prioriteringar är noterade',
            ),
            'Rigg och segel' => array(
                'rig_type' => 'Riggens typ och helhetsintryck är bedömt',
                'masts' => 'Master, rundhult och infästningar är bedömda',
                'standing_rig' => 'Stående rigg är bedömd',
                'running_rig' => 'Löpande rigg är bedömd',
                'sails' => 'Segel och segelhantering är relevanta för fartyget',
            ),
            'Maskin, el och system' => array(
                'engine' => 'Hjälpmotor och maskinutrymme är översiktligt bedömda',
                'fuel' => 'Bränslesystem och ventilation är översiktligt bedömda',
                'electric' => 'Elsystem är översiktligt bedömt',
                'pumps' => 'Läns- och pumpsystem är översiktligt bedömda',
                'freshwater' => 'Vatten- och sanitetslösningar är relevanta och bedömda',
            ),
            'Säkerhet och drift' => array(
                'fire' => 'Brandskydd och släckutrustning är översiktligt bedömda',
                'lifesaving' => 'Livräddningsutrustning är översiktligt bedömd',
                'navigation' => 'Navigations- och kommunikationsutrustning är relevant',
                'emergency' => 'Nödrutiner och säkerhetsorganisation är beskrivna',
                'staffing' => 'Bemanning och kompetens är rimliga för verksamheten',
                'operation' => 'Fartygets praktiska drift är tydligt beskriven',
            ),
            'Kulturhistoriskt värde' => array(
                'history' => 'Fartygets historik är dokumenterad',
                'professional_history' => 'Tidigare yrkesanvändning är belagd eller beskriven',
                'originality' => 'Bevarade originaldetaljer och karaktär är bedömda',
                'restorations' => 'Restaureringar och förändringar är dokumenterade',
                'heritage' => 'Kulturhistoriskt värde är sammantaget bedömt',
            ),
            'Dokumentation och underlag' => array(
                'documentation_images' => 'Tillräckliga bilder finns',
                'documentation_register' => 'Register- eller ägaruppgifter finns när det behövs',
                'documentation_history' => 'Historiskt och tekniskt underlag är tillräckligt',
                'documentation_missing' => 'Eventuella saknade underlag är tydligt angivna',
            ),
            'Samlad bedömning' => array(
                'membership_fit' => 'Fartyget bedöms passa SSF:s ändamål',
                'requirements' => 'Grundkraven är sammantaget bedömda',
                'recommendation_ready' => 'Underlaget räcker för en rekommendation',
            ),
        );
    }

    public function render_assignment_fields(int $application_id): void
    {
        $assigned = $this->assigned_ids($application_id);
        $deadline = (string) get_post_meta($application_id, '_ssf_inspector_deadline', true);
        $task = (string) get_post_meta($application_id, '_ssf_inspector_task', true);
        $users = $this->inspector_users();
        ?>
        <p class="description">Tilldelade inspektörer ser ärendet i portalen Mina inspektioner, inte i WordPress admin.</p>
        <?php if (! $users) : ?>
            <p>Det finns inga användare med rollen Inspektör ännu.</p>
        <?php else : ?>
            <fieldset>
                <legend class="screen-reader-text">Tilldelade inspektörer</legend>
                <?php foreach ($users as $user) : ?>
                    <label style="display:block;margin:7px 0"><input type="checkbox" name="ssf_inspector_ids[]" value="<?php echo esc_attr((string) $user->ID); ?>" <?php checked(in_array((int) $user->ID, $assigned, true)); ?>> <?php echo esc_html($user->display_name); ?></label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
        <p><label for="ssf-inspector-deadline">Önskat klart-datum</label><input id="ssf-inspector-deadline" type="date" name="ssf_inspector_deadline" value="<?php echo esc_attr($deadline); ?>" style="width:100%"></p>
        <p><label for="ssf-inspector-task">Uppdrag till inspektören</label><textarea id="ssf-inspector-task" name="ssf_inspector_task" rows="4" style="width:100%" placeholder="Exempel: Kontrollera rigg och dokumentation inför styrelsebeslut."><?php echo esc_textarea($task); ?></textarea></p>
        <?php
    }

    public function save_assignment(int $application_id, array $request): void
    {
        $before = $this->assigned_ids($application_id);
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) ($request['ssf_inspector_ids'] ?? array())))));
        $valid_ids = array();
        foreach ($ids as $user_id) {
            $user = get_userdata($user_id);
            if ($user && $this->is_inspector($user)) {
                $valid_ids[] = $user_id;
            }
        }

        update_post_meta($application_id, '_ssf_inspector_ids', $valid_ids);
        update_post_meta($application_id, '_ssf_inspector_deadline', $this->date_value((string) ($request['ssf_inspector_deadline'] ?? '')));
        update_post_meta($application_id, '_ssf_inspector_task', sanitize_textarea_field((string) ($request['ssf_inspector_task'] ?? '')));

        if ($before !== $valid_ids) {
            $names = array();
            foreach ($valid_ids as $user_id) {
                $user = get_userdata($user_id);
                if ($user) {
                    $names[] = $user->display_name;
                }
            }
            SSF_Medlemsprocess_Application::add_history($application_id, 'inspection_assignment', $names ? 'Inspektör tilldelad: ' . implode(', ', $names) . '.' : 'Inspektörstilldelning togs bort.', false, array('audience' => 'inspectors'));
            foreach (array_diff($valid_ids, $before) as $user_id) {
                $user = get_userdata($user_id);
                if ($user) {
                    SSF_Medlemsprocess_Plugin::instance()->emails->send_inspector_assignment($application_id, $user);
                }
            }
        }
    }

    public function assignment_summary(int $application_id): string
    {
        $names = array();
        foreach ($this->assigned_ids($application_id) as $user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                $names[] = $user->display_name;
            }
        }
        return $names ? implode(', ', $names) : 'Ej tilldelad';
    }

    public function report_summary(int $application_id): string
    {
        $assigned = $this->assigned_ids($application_id);
        if (! $assigned) {
            return 'Ej tilldelad';
        }
        $reports = $this->reports($application_id);
        $complete = 0;
        $draft = 0;
        $last_opened = (array) get_post_meta($application_id, '_ssf_inspector_last_opened', true);
        $latest = 0;
        foreach ($assigned as $user_id) {
            $state = $reports[$user_id]['status'] ?? 'not_started';
            $complete += 'complete' === $state ? 1 : 0;
            $draft += 'draft' === $state ? 1 : 0;
            $opened = strtotime((string) ($last_opened[$user_id] ?? ''));
            $latest = max($latest, $opened ?: 0);
        }
        $parts = array();
        if ($complete) {
            $parts[] = $complete . ' klar';
        }
        if ($draft) {
            $parts[] = $draft . ' utkast';
        }
        if (! $parts) {
            $parts[] = 'Ej påbörjad';
        }
        if ($latest) {
            $parts[] = 'senast öppnad ' . wp_date('j/n H:i', $latest);
        }
        return implode(', ', $parts);
    }

    public function portal(): string
    {
        $portal_url = SSF_Medlemsprocess_Plugin::page_url('mina_inspektioner');
        if (! is_user_logged_in()) {
            return '<section class="ssf-inspector-shell ssf-inspector-empty"><h1>Mina inspektioner</h1><p>Logga in för att öppna dina tilldelade ärenden.</p><p><a class="ssf-process-button" href="' . esc_url(wp_login_url($portal_url)) . '">Logga in</a></p></section>';
        }
        if (! current_user_can('ssf_view_assigned_applications') && ! current_user_can('manage_options')) {
            return '<section class="ssf-inspector-shell ssf-inspector-empty"><h1>Åtkomst saknas</h1><p>Det här kontot har inte rollen Inspektör. Kontakta SSF om du behöver hjälp.</p></section>';
        }

        $user_id = get_current_user_id();
        $case_id = absint($_GET['case'] ?? 0);
        $notice = sanitize_key(wp_unslash($_GET['ssf_inspector_saved'] ?? ''));
        $error = sanitize_key(wp_unslash($_GET['ssf_inspector_error'] ?? ''));
        $view = 'dashboard';
        $cases = array();
        $case = null;
        if ($case_id) {
            if (! $this->can_view_case($case_id, $user_id)) {
                return '<section class="ssf-inspector-shell ssf-inspector-empty"><h1>Åtkomst saknas</h1><p>Ärendet finns inte i dina tilldelade inspektioner.</p><p><a class="ssf-process-button ssf-process-button--secondary" href="' . esc_url($portal_url) . '">Till mina ärenden</a></p></section>';
            }
            $this->mark_opened($case_id, $user_id);
            $view = 'case';
            $case = $this->case_data($case_id, $user_id);
        } else {
            foreach ($this->assigned_applications($user_id) as $application_id) {
                $cases[] = $this->case_data($application_id, $user_id);
            }
        }

        ob_start();
        include SSF_MEDLEMSPROCESS_PATH . 'templates/inspector-portal.php';
        return (string) ob_get_clean();
    }

    public function save_report(): void
    {
        $application_id = absint($_POST['application_id'] ?? 0);
        $user_id = get_current_user_id();
        $this->assert_case_access($application_id, $user_id, 'ssf_inspector_report_');
        $action = 'complete' === sanitize_key(wp_unslash($_POST['report_action'] ?? 'draft')) ? 'complete' : 'draft';
        $inspection = $this->sanitize_inspection((array) wp_unslash($_POST['ssf_inspection'] ?? array()));
        $portal_url = $this->case_url($application_id);

        if ('complete' === $action) {
            $missing = $this->missing_checks($inspection['checks']);
            if ($missing || empty($inspection['recommendation'])) {
                wp_safe_redirect(add_query_arg(array('ssf_inspector_error' => 'checklist', 'missing' => count($missing)), $portal_url));
                exit;
            }
        }

        $reports = $this->reports($application_id);
        $current = (array) ($reports[$user_id] ?? array());
        $state = 'complete' === $action ? 'complete' : (($current['status'] ?? '') === 'complete' ? 'complete' : 'draft');
        $files = $this->handle_uploads($application_id, 'ssf_inspector_files', ! empty($_POST['ssf_inspector_files_visible']));
        if ($files) {
            $stored_files = (array) get_post_meta($application_id, '_ssf_inspector_files', true);
            foreach ($files as $file_id) {
                $stored_files[] = array('id' => $file_id, 'inspector_id' => $user_id, 'time' => current_time('mysql'), 'visible_to_applicant' => ! empty($_POST['ssf_inspector_files_visible']), 'note' => sanitize_text_field(wp_unslash($_POST['ssf_inspector_file_note'] ?? '')));
            }
            update_post_meta($application_id, '_ssf_inspector_files', $stored_files);
        }

        $reports[$user_id] = array(
            'status' => $state,
            'updated_at' => current_time('mysql'),
            'completed_at' => 'complete' === $state ? ($current['completed_at'] ?? current_time('mysql')) : '',
            'inspection' => $inspection,
            'files' => $files,
        );
        update_post_meta($application_id, '_ssf_inspector_reports', $reports);
        update_post_meta($application_id, '_ssf_inspection', $inspection);

        if ('complete' === $action && 'complete' !== ($current['status'] ?? '')) {
            SSF_Medlemsprocess_Application::add_history($application_id, 'inspection_report', 'Inspektionsrapport markerades som klar.', false, array('audience' => 'inspectors', 'inspector_id' => $user_id));
            if ($this->all_assigned_reports_complete($application_id)) {
                $previous_status = SSF_Medlemsprocess_Application::status($application_id);
                if (! in_array($previous_status, array('awaiting_decision', 'approved', 'approved_aspirant', 'rejected', 'archived'), true)) {
                    SSF_Medlemsprocess_Application::transition($application_id, 'inspection_completed', '', false);
                }
                SSF_Medlemsprocess_Plugin::instance()->emails->send_inspection_complete($application_id);
            }
        }

        wp_safe_redirect(add_query_arg('ssf_inspector_saved', $action, $portal_url));
        exit;
    }

    public function send_message(): void
    {
        $application_id = absint($_POST['application_id'] ?? 0);
        $user_id = get_current_user_id();
        $this->assert_case_access($application_id, $user_id, 'ssf_inspector_message_');
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
        if (! $message) {
            wp_safe_redirect(add_query_arg('ssf_inspector_error', 'message', $this->case_url($application_id)));
            exit;
        }
        $requires_completion = ! empty($_POST['requires_completion']);
        $send_email = ! empty($_POST['send_email']);
        if ($requires_completion) {
            SSF_Medlemsprocess_Application::transition($application_id, 'needs_completion', $message, false);
        } else {
            SSF_Medlemsprocess_Application::add_history($application_id, 'inspector_message', $message, true, array('audience' => 'inspectors', 'inspector_id' => $user_id));
        }
        if ($send_email) {
            $token = SSF_Medlemsprocess_Application::issue_token($application_id);
            $template = $requires_completion ? 'completion_required' : 'reminder';
            SSF_Medlemsprocess_Plugin::instance()->emails->send_template($template, $application_id, array('admin_comment' => $message, 'status_link' => SSF_Medlemsprocess_Application::status_link($token)));
        }
        wp_safe_redirect(add_query_arg('ssf_inspector_saved', 'message', $this->case_url($application_id)));
        exit;
    }

    public function case_url(int $application_id): string
    {
        return SSF_Medlemsprocess_Plugin::page_url('mina_inspektioner', array('case' => $application_id));
    }

    private function case_data(int $application_id, int $user_id): array
    {
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $reports = $this->reports($application_id);
        $report = (array) ($reports[$user_id] ?? array());
        $inspection = (array) ($report['inspection'] ?? get_post_meta($application_id, '_ssf_inspection', true));
        $inspection['checks'] = (array) ($inspection['checks'] ?? array());
        $deadline = (string) get_post_meta($application_id, '_ssf_inspector_deadline', true);
        $history = $this->history_for_inspector($application_id, $user_id);
        return array(
            'id' => $application_id,
            'number' => (string) get_post_meta($application_id, '_ssf_application_number', true),
            'title' => get_the_title($application_id),
            'data' => $data,
            'status' => SSF_Medlemsprocess_Application::status($application_id),
            'status_label' => SSF_Medlemsprocess_Application::status_label(SSF_Medlemsprocess_Application::status($application_id)),
            'deadline' => $deadline,
            'task' => (string) get_post_meta($application_id, '_ssf_inspector_task', true),
            'booking' => (array) get_post_meta($application_id, '_ssf_booking', true),
            'report' => $report,
            'inspection' => $inspection,
            'files' => $this->files($application_id),
            'history' => $history,
            'progress' => $this->check_progress($inspection['checks']),
            'url' => $this->case_url($application_id),
        );
    }

    private function assigned_applications(int $user_id): array
    {
        $ids = get_posts(array(
            'post_type' => SSF_Medlemsprocess_Application::POST_TYPE,
            'post_status' => 'private',
            'posts_per_page' => 500,
            'fields' => 'ids',
            'orderby' => 'modified',
            'order' => 'DESC',
        ));
        return array_values(array_filter(array_map('intval', $ids), function (int $application_id) use ($user_id): bool {
            return $this->can_view_case($application_id, $user_id);
        }));
    }

    private function can_view_case(int $application_id, int $user_id): bool
    {
        if (! $application_id || SSF_Medlemsprocess_Application::POST_TYPE !== get_post_type($application_id)) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        return user_can($user_id, 'ssf_view_assigned_applications') && in_array($user_id, $this->assigned_ids($application_id), true);
    }

    private function assert_case_access(int $application_id, int $user_id, string $nonce_prefix): void
    {
        if (! $application_id || ! $this->can_view_case($application_id, $user_id) || ! check_admin_referer($nonce_prefix . $application_id)) {
            wp_die('Du saknar behörighet för ärendet.', 'Åtkomst saknas', array('response' => 403));
        }
    }

    private function assigned_ids(int $application_id): array
    {
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) get_post_meta($application_id, '_ssf_inspector_ids', true)))));
        if (! $ids) {
            $legacy_id = absint(get_post_meta($application_id, '_ssf_assigned_user', true));
            $legacy_user = $legacy_id ? get_userdata($legacy_id) : false;
            if ($legacy_user && $this->is_inspector($legacy_user)) {
                $ids[] = $legacy_id;
            }
        }
        return $ids;
    }

    private function inspector_users(): array
    {
        return array_values(array_filter(get_users(array('orderby' => 'display_name', 'order' => 'ASC')), array($this, 'is_inspector')));
    }

    private function is_inspector(WP_User $user): bool
    {
        return (bool) array_intersect(array('ssf_inspector', 'ssf_inspektor'), (array) $user->roles);
    }

    private function reports(int $application_id): array
    {
        return (array) get_post_meta($application_id, '_ssf_inspector_reports', true);
    }

    private function all_assigned_reports_complete(int $application_id): bool
    {
        $assigned = $this->assigned_ids($application_id);
        if (! $assigned) {
            return false;
        }
        $reports = $this->reports($application_id);
        foreach ($assigned as $user_id) {
            if ('complete' !== ($reports[$user_id]['status'] ?? '')) {
                return false;
            }
        }
        return true;
    }

    private function mark_opened(int $application_id, int $user_id): void
    {
        $opened = (array) get_post_meta($application_id, '_ssf_inspector_last_opened', true);
        $opened[$user_id] = current_time('mysql');
        update_post_meta($application_id, '_ssf_inspector_last_opened', $opened);
    }

    private function check_progress(array $checks): array
    {
        $total = 0;
        $complete = 0;
        foreach (self::checklist_sections() as $points) {
            foreach ($points as $key => $label) {
                $total++;
                if (! empty($checks[$key]['status'])) {
                    $complete++;
                }
            }
        }
        return array('complete' => $complete, 'total' => $total, 'percent' => $total ? (int) round(($complete / $total) * 100) : 0);
    }

    private function missing_checks(array $checks): array
    {
        $missing = array();
        foreach (self::checklist_sections() as $points) {
            foreach ($points as $key => $label) {
                if (empty($checks[$key]['status'])) {
                    $missing[] = $key;
                }
            }
        }
        return $missing;
    }

    private function sanitize_inspection(array $inspection): array
    {
        $clean = array();
        foreach (array('date', 'place', 'inspector', 'attendees', 'type', 'conditions', 'summary', 'strengths', 'deficiencies', 'actions', 'board_comment', 'public_comment', 'recommendation') as $key) {
            $clean[$key] = sanitize_textarea_field($inspection[$key] ?? '');
        }
        $clean['checks'] = array();
        foreach ((array) ($inspection['checks'] ?? array()) as $key => $item) {
            $status = sanitize_key($item['status'] ?? '');
            $clean['checks'][sanitize_key($key)] = array(
                'status' => array_key_exists($status, self::CHECK_STATUSES) ? $status : '',
                'comment' => sanitize_textarea_field($item['comment'] ?? ''),
            );
        }
        return $clean;
    }

    private function files(int $application_id): array
    {
        $files = array();
        foreach (array('application' => '_ssf_application_files', 'completion' => '_ssf_completion_files') as $source => $meta_key) {
            foreach (array_map('intval', (array) get_post_meta($application_id, $meta_key, true)) as $file_id) {
                if (wp_get_attachment_url($file_id)) {
                    $files[] = array('id' => $file_id, 'source' => $source, 'visible_to_applicant' => true, 'note' => '');
                }
            }
        }
        foreach ((array) get_post_meta($application_id, '_ssf_inspector_files', true) as $file) {
            if (! empty($file['id']) && wp_get_attachment_url((int) $file['id'])) {
                $file['source'] = 'inspector';
                $files[] = $file;
            }
        }
        return $files;
    }

    private function history_for_inspector(int $application_id, int $user_id): array
    {
        $safe_types = array('submitted', 'status', 'booking', 'completion', 'inspection_assignment', 'inspection_report', 'inspector_message');
        $history = (array) get_post_meta($application_id, '_ssf_application_history', true);
        return array_values(array_filter($history, static function (array $item) use ($user_id, $safe_types): bool {
            return ! empty($item['public']) || (int) ($item['author'] ?? 0) === $user_id || 'inspectors' === ($item['audience'] ?? '') || in_array($item['type'] ?? '', $safe_types, true);
        }));
    }

    private function handle_uploads(int $application_id, string $field, bool $visible_to_applicant): array
    {
        if (empty($_FILES[$field]['name'][0])) {
            return array();
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $settings = SSF_Medlemsprocess_Plugin::settings();
        $files = $_FILES[$field];
        $attachments = array();
        foreach ((array) $files['name'] as $index => $name) {
            if (UPLOAD_ERR_OK !== (int) $files['error'][$index]) {
                continue;
            }
            $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
            if (! in_array($extension, array('jpg', 'jpeg', 'png', 'webp', 'pdf'), true)) {
                continue;
            }
            $max_bytes = ('pdf' === $extension ? (int) $settings['max_file_mb'] : (int) $settings['max_image_mb']) * MB_IN_BYTES;
            if ((int) $files['size'][$index] > $max_bytes) {
                continue;
            }
            $file = array('name' => sanitize_file_name((string) $name), 'type' => (string) $files['type'][$index], 'tmp_name' => (string) $files['tmp_name'][$index], 'error' => (int) $files['error'][$index], 'size' => (int) $files['size'][$index]);
            $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
            if (empty($checked['ext']) || ! in_array(strtolower($checked['ext']), array('jpg', 'jpeg', 'png', 'webp', 'pdf'), true)) {
                continue;
            }
            $upload = wp_handle_upload($file, array('test_form' => false));
            if (! empty($upload['error'])) {
                continue;
            }
            $attachment_id = wp_insert_attachment(array('post_mime_type' => $upload['type'], 'post_title' => sanitize_text_field(pathinfo($file['name'], PATHINFO_FILENAME)), 'post_status' => 'inherit', 'post_parent' => $application_id), $upload['file'], $application_id);
            if (! is_wp_error($attachment_id)) {
                wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));
                $attachments[] = (int) $attachment_id;
            }
        }
        return $attachments;
    }

    private function date_value(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }
}
