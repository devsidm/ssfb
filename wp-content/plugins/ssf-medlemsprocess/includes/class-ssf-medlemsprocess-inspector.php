<?php
/** Restricted mobile-first workspace for membership inspections. */
if (! defined('ABSPATH')) { exit; }

class SSF_Medlemsprocess_Inspector
{
    public function __construct()
    {
        add_action('init', array($this, 'register_shortcodes'), 99);
        add_action('admin_init', array($this, 'redirect_from_admin'));
        add_action('wp_ajax_ssf_membership_inspection_save', array($this, 'ajax_save'));
        add_action('wp_ajax_ssf_membership_inspection_photo', array($this, 'ajax_photo'));
        add_action('wp_ajax_ssf_membership_inspection_photo_update', array($this, 'ajax_photo_update'));
        add_action('wp_ajax_ssf_membership_inspection_workflow', array($this, 'ajax_workflow'));
        add_action('admin_post_ssf_membership_inspection_photo', array($this, 'serve_photo'));
        add_action('admin_post_nopriv_ssf_membership_inspection_photo', array($this, 'serve_photo'));
    }

    public function register_shortcodes(): void { add_shortcode('ssf_inspector_portal', array($this, 'portal')); }

    public function redirect_from_admin(): void
    {
        global $pagenow;
        if ('admin-post.php' === $pagenow || wp_doing_ajax() || current_user_can('manage_options')) { return; }
        if (current_user_can('ssf_view_assigned_applications')) { wp_safe_redirect(SSF_Medlemsprocess_Plugin::page_url('mina_inspektioner')); exit; }
    }

    /** Legacy checklist reader retained for historical reports only. */
    public static function checklist_sections(): array
    {
        return array('Äldre inspektionsformat' => array(
            'identity_name' => 'Fartygets namn och identitet stämmer',
            'identity_type' => 'Fartygstyp, rigg och huvudmått stämmer',
            'history' => 'Fartygets historik är dokumenterad',
            'professional_history' => 'Tidigare yrkesanvändning är belagd eller beskriven',
            'heritage' => 'Kulturhistoriskt värde är sammantaget bedömt',
            'recommendation_ready' => 'Underlaget räcker för en rekommendation',
        ));
    }

    public function render_assignment_fields(int $application_id): void
    {
        $assigned = $this->assigned_ids($application_id);
        $lead = (int) ($assigned[0] ?? 0); $co = (int) ($assigned[1] ?? 0);
        $deadline = (string) get_post_meta($application_id, '_ssf_inspector_deadline', true);
        $task = (string) get_post_meta($application_id, '_ssf_inspector_task', true);
        $users = $this->inspector_users(); ?>
        <p class="description">Huvud- och medinspektör arbetar i samma protokoll. Huvudinspektören färdigställer.</p>
        <p><label for="ssf-lead-inspector">Huvudinspektör</label><select id="ssf-lead-inspector" name="ssf_lead_inspector_id" style="width:100%"><option value="0">Ej tilldelad</option><?php foreach ($users as $user) : ?><option value="<?php echo esc_attr((string) $user->ID); ?>" <?php selected($lead, $user->ID); ?>><?php echo esc_html($user->display_name); ?></option><?php endforeach; ?></select></p>
        <p><label for="ssf-co-inspector">Medinspektör (valfri)</label><select id="ssf-co-inspector" name="ssf_co_inspector_id" style="width:100%"><option value="0">Ingen</option><?php foreach ($users as $user) : ?><option value="<?php echo esc_attr((string) $user->ID); ?>" <?php selected($co, $user->ID); ?>><?php echo esc_html($user->display_name); ?></option><?php endforeach; ?></select></p>
        <p><label for="ssf-inspector-deadline">Önskat klart-datum</label><input id="ssf-inspector-deadline" type="date" name="ssf_inspector_deadline" value="<?php echo esc_attr($deadline); ?>" style="width:100%"></p>
        <p><label for="ssf-inspector-task">Praktisk kommentar / uppdrag</label><textarea id="ssf-inspector-task" name="ssf_inspector_task" rows="4" style="width:100%"><?php echo esc_textarea($task); ?></textarea></p><?php
    }

    public function save_assignment(int $application_id, array $request): void
    {
        $before = $this->assigned_ids($application_id);
        $legacy = array_values(array_unique(array_filter(array_map('absint', (array) ($request['ssf_inspector_ids'] ?? array())))));
        $lead = absint($request['ssf_lead_inspector_id'] ?? ($legacy[0] ?? 0));
        $co = absint($request['ssf_co_inspector_id'] ?? ($legacy[1] ?? 0));
        if ($co === $lead) { $co = 0; }
        $valid = array();
        foreach (array($lead, $co) as $user_id) { $user = $user_id ? get_userdata($user_id) : false; if ($user && $this->is_inspector($user)) { $valid[] = $user_id; } }
        update_post_meta($application_id, '_ssf_inspector_ids', $valid);
        update_post_meta($application_id, '_ssf_inspector_deadline', $this->date_value((string) ($request['ssf_inspector_deadline'] ?? '')));
        update_post_meta($application_id, '_ssf_inspector_task', sanitize_textarea_field((string) ($request['ssf_inspector_task'] ?? '')));
        if ($valid) { SSF_Medlemsprocess_Inspection::ensure_real($application_id, (int) $valid[0], (int) ($valid[1] ?? 0)); }
        if ($before !== $valid) {
            $names = array_map(static function (int $id): string { $user = get_userdata($id); return $user ? $user->display_name : ''; }, $valid);
            SSF_Medlemsprocess_Application::add_history($application_id, 'inspection_assignment', $names ? 'Inspektörer tilldelade: ' . implode(', ', array_filter($names)) . '.' : 'Inspektörstilldelning togs bort.', false, array('audience' => 'inspectors'));
            foreach (array_diff($valid, $before) as $id) { $user = get_userdata($id); if ($user) { SSF_Medlemsprocess_Plugin::instance()->emails->send_inspector_assignment($application_id, $user); } }
        }
    }

    public function assignment_summary(int $application_id): string
    {
        $names = array(); foreach ($this->assigned_ids($application_id) as $index => $id) { $user = get_userdata($id); if ($user) { $names[] = (0 === $index ? 'Huvud: ' : 'Med: ') . $user->display_name; } }
        return $names ? implode(' · ', $names) : 'Ej tilldelad';
    }

    public function report_summary(int $application_id): string
    {
        $id = SSF_Medlemsprocess_Inspection::inspection_for_application($application_id);
        if ($id) { $record = SSF_Medlemsprocess_Inspection::record($id); $progress = SSF_Medlemsprocess_Inspection::progress($record); $labels = array('draft' => 'Pågår', 'ready_confirmation' => 'Väntar på bekräftelse', 'ready_finalization' => 'Klar att färdigställa', 'completed' => 'Protokoll klart'); return ($labels[$record['status'] ?? 'draft'] ?? 'Pågår') . ' · ' . $progress['complete'] . ' av ' . $progress['total'] . ' svar'; }
        return get_post_meta($application_id, '_ssf_inspector_reports', true) ? 'Äldre inspektionsformat' : 'Ej påbörjad';
    }

    public function portal(): string
    {
        $portal_url = class_exists('SSF_Workspace') && 0 === strpos((string) get_query_var('ssf_workspace_path'), 'inspektioner') ? SSF_Workspace::url('inspektioner') : SSF_Medlemsprocess_Plugin::page_url('mina_inspektioner');
        if (! is_user_logged_in()) { return '<section class="ssf-inspector-shell ssf-inspector-empty"><h1>Mina inspektioner</h1><p>Logga in för att öppna dina tilldelade ärenden.</p><p><a class="ssf-process-button" href="' . esc_url(wp_login_url($portal_url)) . '">Logga in</a></p></section>'; }
        if (! current_user_can('ssf_view_assigned_applications') && ! current_user_can('ssf_view_applications') && ! current_user_can('manage_options')) { return '<section class="ssf-inspector-shell ssf-inspector-empty"><h1>Åtkomst saknas</h1><p>Kontot saknar inspektionsbehörighet.</p></section>'; }
        wp_enqueue_style('ssf-inspector-portal', SSF_MEDLEMSPROCESS_URL . 'assets/css/ssf-inspector-portal.css', array('ssf-medlemsprocess'), SSF_MEDLEMSPROCESS_VERSION);
        wp_enqueue_script('ssf-inspector-portal', SSF_MEDLEMSPROCESS_URL . 'assets/js/ssf-inspector-portal.js', array(), SSF_MEDLEMSPROCESS_VERSION, true);
        $user_id = get_current_user_id(); $inspection_id = absint($_GET['inspection'] ?? 0); $case_id = absint($_GET['case'] ?? 0); $legacy_case_id = 0;
        if (! $inspection_id && $case_id && $this->can_view_case($case_id, $user_id)) {
            $inspection_id = SSF_Medlemsprocess_Inspection::inspection_for_application($case_id);
            if (! $inspection_id && get_post_meta($case_id, '_ssf_inspector_reports', true)) { $legacy_case_id = $case_id; }
            elseif (! $inspection_id) { $assigned = $this->assigned_ids($case_id); $inspection_id = SSF_Medlemsprocess_Inspection::ensure_real($case_id, (int) ($assigned[0] ?? 0), (int) ($assigned[1] ?? 0)); }
        }
        $view = 'dashboard'; $case = null; $cases = array();
        if ($inspection_id) {
            if (! SSF_Medlemsprocess_Inspection::can_read($inspection_id, $user_id)) { return '<section class="ssf-inspector-shell ssf-inspector-empty"><h1>Åtkomst saknas</h1><p>Inspektionen finns inte bland dina uppdrag.</p></section>'; }
            $view = 'case'; $case = $this->case_data($inspection_id);
            wp_localize_script('ssf-inspector-portal', 'SSFMembershipInspection', array('ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('ssf_membership_inspection_' . $inspection_id), 'inspection' => $inspection_id, 'user' => $user_id));
        } elseif ($legacy_case_id) {
            $view = 'legacy'; $case = $this->legacy_full_case_data($legacy_case_id);
        } else {
            foreach ($this->assigned_applications($user_id) as $application_id) { $id = SSF_Medlemsprocess_Inspection::inspection_for_application($application_id); $cases[] = $id ? $this->case_data($id) : $this->legacy_case_data($application_id); }
        }
        ob_start(); include SSF_MEDLEMSPROCESS_PATH . 'templates/inspector-portal.php'; return (string) ob_get_clean();
    }

    private function case_data(int $inspection_id): array
    {
        $record = SSF_Medlemsprocess_Inspection::record($inspection_id); $application_id = (int) $record['application_id']; $snapshot = (array) ($record['application_snapshot'] ?? array());
        $lead = get_userdata((int) $record['lead_inspector_user_id']); $co = ! empty($record['co_inspector_user_id']) ? get_userdata((int) $record['co_inspector_user_id']) : false;
        return array('id' => $application_id, 'inspection_id' => $inspection_id, 'record' => $record, 'number' => $snapshot['number'] ?? '', 'title' => $snapshot['ship_name'] ?? get_the_title($application_id), 'deadline' => (string) get_post_meta($application_id, '_ssf_inspector_deadline', true), 'task' => (string) get_post_meta($application_id, '_ssf_inspector_task', true), 'booking' => (array) get_post_meta($application_id, '_ssf_booking', true), 'legacy_files' => $this->legacy_files($application_id), 'progress' => SSF_Medlemsprocess_Inspection::progress($record), 'deviations' => SSF_Medlemsprocess_Inspection::deviations($record), 'lead_name' => $lead ? $lead->display_name : '', 'co_name' => $co ? $co->display_name : '', 'is_lead' => get_current_user_id() === (int) $record['lead_inspector_user_id'], 'is_co' => get_current_user_id() === (int) $record['co_inspector_user_id'], 'can_edit' => SSF_Medlemsprocess_Inspection::can_edit($inspection_id), 'url' => $this->case_url($application_id, $inspection_id));
    }

    private function legacy_case_data(int $application_id): array
    {
        $data = SSF_Medlemsprocess_Application::data($application_id);
        return array('id' => $application_id, 'inspection_id' => 0, 'number' => (string) get_post_meta($application_id, '_ssf_application_number', true), 'title' => $data['ship_name'] ?? get_the_title($application_id), 'deadline' => (string) get_post_meta($application_id, '_ssf_inspector_deadline', true), 'task' => (string) get_post_meta($application_id, '_ssf_inspector_task', true), 'legacy' => true, 'url' => $this->case_url($application_id));
    }

    private function legacy_full_case_data(int $application_id): array
    {
        $case = $this->legacy_case_data($application_id); $case['data'] = SSF_Medlemsprocess_Application::data($application_id); $case['reports'] = (array) get_post_meta($application_id, '_ssf_inspector_reports', true); $case['inspection'] = (array) get_post_meta($application_id, '_ssf_inspection', true); return $case;
    }

    private function legacy_files(int $application_id): array
    {
        $files = array();
        foreach (array('Ansökningsfil' => '_ssf_application_files', 'Komplettering' => '_ssf_completion_files') as $label => $meta_key) {
            foreach (array_map('intval', (array) get_post_meta($application_id, $meta_key, true)) as $id) { $url = wp_get_attachment_url($id); if ($url) { $files[] = array('id' => $id, 'label' => $label, 'url' => $url, 'note' => ''); } }
        }
        foreach ((array) get_post_meta($application_id, '_ssf_inspector_files', true) as $file) {
            if (! empty($file['question_id'])) { continue; } $id = absint($file['id'] ?? 0); $url = $id ? wp_get_attachment_url($id) : '';
            if ($url) { $files[] = array('id' => $id, 'label' => 'Inspektörsfil', 'url' => $url, 'note' => (string) ($file['note'] ?? '')); }
        }
        return $files;
    }

    public function assigned_applications(int $user_id): array
    {
        $ids = get_posts(array('post_type' => SSF_Medlemsprocess_Application::POST_TYPE, 'post_status' => 'private', 'posts_per_page' => 500, 'fields' => 'ids', 'orderby' => 'modified', 'order' => 'DESC'));
        return array_values(array_filter(array_map('intval', $ids), function (int $id) use ($user_id): bool { return $this->can_view_case($id, $user_id); }));
    }

    public function can_view_case(int $application_id, int $user_id): bool
    {
        if (! $application_id || SSF_Medlemsprocess_Application::POST_TYPE !== get_post_type($application_id)) { return false; }
        if (class_exists('SSF_Access_Control') && ! SSF_Access_Control::is_active($user_id)) { return false; }
        return user_can($user_id, 'manage_options') || user_can($user_id, 'ssf_view_applications') || (user_can($user_id, 'ssf_view_assigned_applications') && in_array($user_id, $this->assigned_ids($application_id), true));
    }

    public function assigned_ids(int $application_id): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', (array) get_post_meta($application_id, '_ssf_inspector_ids', true))))), 0, 2);
        if (! $ids) { $legacy = absint(get_post_meta($application_id, '_ssf_assigned_user', true)); if ($legacy) { $ids[] = $legacy; } }
        return $ids;
    }

    private function inspector_users(): array { return array_values(array_filter(get_users(array('orderby' => 'display_name', 'order' => 'ASC')), array($this, 'is_inspector'))); }
    private function is_inspector(WP_User $user): bool { return user_can($user, 'ssf_view_assigned_applications') && (! class_exists('SSF_Access_Control') || SSF_Access_Control::is_active((int) $user->ID)); }

    public function case_url(int $application_id, int $inspection_id = 0): string
    {
        $args = $inspection_id ? array('inspection' => $inspection_id) : array('case' => $application_id);
        if (class_exists('SSF_Workspace') && 0 === strpos((string) get_query_var('ssf_workspace_path'), 'inspektioner')) { return add_query_arg($args, SSF_Workspace::url('inspektioner')); }
        return SSF_Medlemsprocess_Plugin::page_url('mina_inspektioner', $args);
    }

    public function photo_url(int $inspection_id, string $photo_id, string $token = ''): string
    {
        $args = array('action' => 'ssf_membership_inspection_photo', 'inspection_id' => $inspection_id, 'photo_id' => $photo_id);
        if ($token) { $args['token'] = $token; } else { $args['_wpnonce'] = wp_create_nonce('ssf_membership_inspection_photo_' . $inspection_id . '_' . $photo_id); }
        return add_query_arg($args, admin_url('admin-post.php'));
    }

    private function verify_ajax(int $id): int
    {
        $user_id = get_current_user_id();
        if (! check_ajax_referer('ssf_membership_inspection_' . $id, 'nonce', false) || ! SSF_Medlemsprocess_Inspection::can_edit($id, $user_id)) { wp_send_json_error(array('message' => 'Du saknar behörighet till inspektionen.'), 403); }
        return $user_id;
    }

    public function ajax_save(): void
    {
        $id = absint($_POST['inspection_id'] ?? 0); $user_id = $this->verify_ajax($id); $kind = sanitize_key(wp_unslash($_POST['kind'] ?? 'answer'));
        $result = 'details' === $kind ? SSF_Medlemsprocess_Inspection::save_details($id, (array) wp_unslash($_POST['details'] ?? array()), (string) wp_unslash($_POST['summary'] ?? ''), $user_id) : SSF_Medlemsprocess_Inspection::save_answer($id, sanitize_key(wp_unslash($_POST['question_id'] ?? '')), sanitize_text_field(wp_unslash($_POST['selected_option'] ?? '')), (string) wp_unslash($_POST['comment'] ?? ''), $user_id);
        $result['ok'] ? wp_send_json_success($result) : wp_send_json_error($result, 400);
    }

    public function ajax_photo(): void
    {
        $id = absint($_POST['inspection_id'] ?? 0); $user_id = $this->verify_ajax($id);
        $result = SSF_Medlemsprocess_Inspection::add_photo($id, sanitize_key(wp_unslash($_POST['question_id'] ?? '')), (string) wp_unslash($_POST['caption'] ?? ''), sanitize_text_field(wp_unslash($_POST['operation_id'] ?? '')), (array) ($_FILES['photo'] ?? array()), $user_id);
        if (! empty($result['ok'])) { $result['photo']['url'] = $this->photo_url($id, (string) $result['photo']['id']); wp_send_json_success($result); }
        wp_send_json_error($result, 400);
    }

    public function ajax_photo_update(): void
    {
        $id = absint($_POST['inspection_id'] ?? 0); $user_id = $this->verify_ajax($id);
        $result = SSF_Medlemsprocess_Inspection::update_photo($id, sanitize_text_field(wp_unslash($_POST['photo_id'] ?? '')), (string) wp_unslash($_POST['caption'] ?? ''), ! empty($_POST['delete']), $user_id);
        $result['ok'] ? wp_send_json_success($result) : wp_send_json_error($result, 400);
    }

    public function ajax_workflow(): void
    {
        $id = absint($_POST['inspection_id'] ?? 0); $user_id = $this->verify_ajax($id); $action = sanitize_key(wp_unslash($_POST['workflow_action'] ?? ''));
        if ('ready' === $action) { $result = SSF_Medlemsprocess_Inspection::ready($id, $user_id); }
        elseif ('confirm' === $action) { $result = SSF_Medlemsprocess_Inspection::confirm($id, $user_id); }
        elseif ('finalize' === $action) { $result = SSF_Medlemsprocess_Inspection::finalize($id, $user_id); }
        else { $result = array('ok' => false, 'message' => 'Ogiltig åtgärd.'); }
        $result['ok'] ? wp_send_json_success($result) : wp_send_json_error($result, 409);
    }

    public function serve_photo(): void
    {
        $inspection_id = absint($_GET['inspection_id'] ?? 0); $photo_id = sanitize_text_field(wp_unslash($_GET['photo_id'] ?? '')); $record = SSF_Medlemsprocess_Inspection::record($inspection_id); $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        $applicant = $token && 'completed' === ($record['status'] ?? '') && (int) ($record['application_id'] ?? 0) === SSF_Medlemsprocess_Application::find_by_token($token);
        $authorized = $applicant || (is_user_logged_in() && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'ssf_membership_inspection_photo_' . $inspection_id . '_' . $photo_id) && SSF_Medlemsprocess_Inspection::can_read($inspection_id));
        $photo = $authorized ? SSF_Medlemsprocess_Inspection::photo($inspection_id, $photo_id, $applicant) : array();
        if (! $photo || ($applicant && 'protocol' !== ($photo['visibility'] ?? ''))) { status_header(403); exit; }
        $base = wp_normalize_path(SSF_Medlemsprocess_Inspection::private_photo_dir()['base']); $path = wp_normalize_path($base . '/' . ltrim((string) $photo['path'], '/'));
        if (0 !== strpos($path, $base . '/') || ! is_file($path)) { status_header(404); exit; }
        nocache_headers(); header('Content-Type: image/jpeg'); header('Content-Length: ' . filesize($path)); header('X-Content-Type-Options: nosniff'); readfile($path); exit;
    }

    /** Legacy history visibility reader retained for existing assignments. */
    private function history_for_inspector(int $application_id, int $user_id): array
    {
        $safe = array('submitted', 'status', 'booking', 'completion', 'inspection_assignment', 'inspection_report', 'inspector_message');
        return array_values(array_filter((array) get_post_meta($application_id, '_ssf_application_history', true), static function ($item) use ($user_id, $safe): bool {
            return is_array($item) && (! empty($item['public']) || (int) ($item['author'] ?? 0) === $user_id || 'inspectors' === ($item['audience'] ?? '') || in_array($item['type'] ?? '', $safe, true));
        }));
    }

    private function date_value(string $value): string { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : ''; }
}
