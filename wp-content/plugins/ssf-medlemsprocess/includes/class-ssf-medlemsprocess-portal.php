<?php
/**
 * Frontend case-handling portal for membership applications.
 *
 * @package SSF_Medlemsprocess
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsprocess_Portal
{
    private const ACTION = 'ssf_membership_portal_action';

    public function __construct()
    {
        add_shortcode('ssf_membership_review_portal', array($this, 'shortcode'));
        add_action('admin_post_' . self::ACTION, array($this, 'handle_action'));
        add_action('wp_head', array($this, 'noindex_meta'));
        add_filter('wp_robots', array($this, 'robots'));
        add_filter('query_vars', array($this, 'query_vars'));
        add_action('init', array($this, 'rewrite_rules'));
    }

    public function query_vars(array $vars): array
    {
        $vars[] = 'ssf_application_number';
        $vars[] = 'ssf_membership_portal_view';
        return $vars;
    }

    public function rewrite_rules(): void
    {
        add_rewrite_rule('^medlemskap/handlaggning/aspiranter/?$', 'index.php?pagename=medlemskap/handlaggning&ssf_membership_portal_view=aspiranter', 'top');
        add_rewrite_rule('^medlemskap/handlaggning/([^/]+)/?$', 'index.php?pagename=medlemskap/handlaggning&ssf_application_number=$matches[1]', 'top');
    }

    public static function page_id(): int
    {
        return (int) get_option('ssf_medlemsprocess_handlaggning_page_id');
    }

    public static function page_url(array $args = array()): string
    {
        if (class_exists('SSF_Workspace') && 0 === strpos((string) get_query_var('ssf_workspace_path'), 'ansokningar')) {
            return add_query_arg($args, SSF_Workspace::url('ansokningar'));
        }
        $page_id = self::page_id();
        $url = $page_id ? get_permalink($page_id) : home_url('/medlemskap/handlaggning/');
        return add_query_arg($args, $url);
    }

    public static function review_url(int $application_id): string
    {
        if (class_exists('SSF_Workspace') && 0 === strpos((string) get_query_var('ssf_workspace_path'), 'ansokningar')) {
            return SSF_Workspace::url('ansokningar/' . $application_id);
        }
        $number = (string) get_post_meta($application_id, '_ssf_application_number', true);
        $identifier = rawurlencode($number ?: (string) $application_id);
        $base = self::page_url();
        return self::page_id() ? trailingslashit($base) . $identifier . '/' : self::page_url(array('application' => $identifier));
    }

    public static function aspirants_url(): string
    {
        $base = self::page_url();
        return self::page_id() ? trailingslashit($base) . 'aspiranter/' : self::page_url(array('view' => 'aspiranter'));
    }

    public function noindex_meta(): void
    {
        if ($this->is_portal_page()) {
            echo "<meta name=\"robots\" content=\"noindex,nofollow\">\n";
        }
    }

    public function robots(array $robots): array
    {
        if ($this->is_portal_page()) {
            $robots['noindex'] = true;
            $robots['nofollow'] = true;
        }
        return $robots;
    }

    public function shortcode(): string
    {
        if (! is_user_logged_in()) {
            return $this->login_prompt();
        }
        if (! $this->can_view_portal()) {
            status_header(403);
            return '<div class="ssf-portal ssf-portal-message"><h1>Handläggningsportal</h1><p>Du saknar behörighet att visa medlemsansökningar.</p></div>';
        }

        $application_id = $this->current_application_id();
        ob_start();
        echo '<div class="ssf-portal" data-ssf-membership-portal>';
        if ($application_id) {
            $this->render_case($application_id);
        } elseif ('aspiranter' === $this->current_view()) {
            $this->render_notice();
            $this->render_aspirants();
        } else {
            $this->render_notice();
            $this->render_overview();
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    public function handle_action(): void
    {
        if (! is_user_logged_in() || ! $this->can_view_portal()) {
            wp_die('Du saknar behörighet.');
        }
        $application_id = absint($_POST['application_id'] ?? 0);
        if (! $application_id || ! current_user_can('edit_ssf_application', $application_id) || ! check_admin_referer('ssf_membership_portal_' . $application_id)) {
            wp_die('Du saknar behörighet.');
        }

        $expected_status = sanitize_key((string) wp_unslash($_POST['expected_status'] ?? ''));
        $current_status = SSF_Medlemsprocess_Application::status($application_id);
        if ($expected_status && $expected_status !== $current_status) {
            $this->redirect($application_id, 'changed');
        }

        $operation = sanitize_key((string) wp_unslash($_POST['portal_operation'] ?? ''));
        $message = '';
        $ok = false;
        switch ($operation) {
            case 'transition':
                $ok = $this->handle_transition($application_id);
                $target_status = sanitize_key((string) wp_unslash($_POST['target_status'] ?? ''));
                $message = $ok ? ('approved_aspirant' === $target_status ? 'aspirant_approved' : 'updated') : ('approved_aspirant' === $target_status && get_post_meta($application_id, '_ssf_decision_date_required', true) ? 'decision_date_missing' : 'failed');
                break;
            case 'book_inspection':
                $ok = $this->handle_booking($application_id);
                $message = $ok ? 'updated' : 'failed';
                break;
            case 'inspection_progress':
                $target = sanitize_key((string) wp_unslash($_POST['inspection_status'] ?? ''));
                $ok = SSF_Medlemsprocess_Application::set_inspection_status($application_id, $target, 'membership_portal');
                if ($ok) {
                    SSF_Medlemsprocess_Plugin::instance()->sharepoint->push_status($application_id);
                }
                $message = $ok ? 'updated' : 'failed';
                break;
            case 'membership_decision':
                $ok = $this->handle_membership_decision($application_id);
                $message = $ok ? 'updated' : 'failed';
                break;
            case 'payment':
                $received = ! empty($_POST['payment_received']);
                $was_received = SSF_Medlemsprocess_Application::payment_received($application_id);
                $ok = SSF_Medlemsprocess_Application::set_payment_received($application_id, $received, 'membership_portal', sanitize_text_field(wp_unslash($_POST['payment_received_date'] ?? '')));
                $message = $ok ? 'payment_updated' : ($was_received === $received ? 'payment_unchanged' : 'failed');
                break;
            case 'note':
                $note = sanitize_textarea_field(wp_unslash($_POST['internal_note'] ?? ''));
                if ($note) {
                    SSF_Medlemsprocess_Application::add_history($application_id, 'note', $note, false, array('source' => 'membership_portal'));
                    $ok = true;
                }
                $message = $ok ? 'note_added' : 'failed';
                break;
        }

        $this->redirect($application_id, $message ?: 'failed');
    }

    private function handle_transition(int $application_id): bool
    {
        $target = sanitize_key((string) wp_unslash($_POST['target_status'] ?? ''));
        $public_comment = sanitize_textarea_field(wp_unslash($_POST['public_comment'] ?? ''));
        $internal_note = sanitize_textarea_field(wp_unslash($_POST['internal_note'] ?? ''));
        $decision_date = sanitize_text_field(wp_unslash($_POST['decision_date'] ?? ''));

        if (in_array($target, array('needs_completion', 'rejected'), true) && '' === $public_comment) {
            return false;
        }
        if ('approved_aspirant' === $target && (! current_user_can('ssf_decide_applications') || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $decision_date))) {
            update_post_meta($application_id, '_ssf_decision_date_required', '1');
            SSF_Medlemsprocess_Application::add_history($application_id, 'workflow_warning', 'Aspirantbeslut avbröts eftersom beslutsdatum saknas eller är ogiltigt.', false, array('source' => 'membership_portal'));
            return false;
        }
        if ('rejected' === $target && ! current_user_can('ssf_decide_applications')) {
            return false;
        }

        $changed = SSF_Medlemsprocess_Application::transition($application_id, $target, $public_comment, true, 'membership_portal', $decision_date);
        if ($changed && $internal_note) {
            SSF_Medlemsprocess_Application::add_history($application_id, 'note', $internal_note, false, array('source' => 'membership_portal'));
        }
        if ($changed) {
            SSF_Medlemsprocess_Plugin::instance()->sharepoint->push_status($application_id);
        }
        return $changed;
    }

    private function handle_booking(int $application_id): bool
    {
        $booking = array();
        foreach (array('date', 'start', 'end', 'location', 'type', 'contact', 'participants', 'comment') as $key) {
            $booking[$key] = sanitize_text_field(wp_unslash($_POST['booking'][$key] ?? ''));
        }
        if (! $booking['date']) {
            return false;
        }
        $changed = SSF_Medlemsprocess_Application::set_inspection_status($application_id, 'booked', 'membership_portal');
        if (! $changed) {
            return false;
        }
        update_post_meta($application_id, '_ssf_booking', $booking);
        SSF_Medlemsprocess_Application::add_history($application_id, 'booking', 'Inspektion bokades via handläggningsportalen.', false, array('source' => 'membership_portal'));
        if (! empty($_POST['send_booking_email'])) {
            SSF_Medlemsprocess_Plugin::instance()->emails->send_booking($application_id, $booking);
        }
        SSF_Medlemsprocess_Plugin::instance()->sharepoint->push_status($application_id);
        return $changed;
    }

    private function handle_membership_decision(int $application_id): bool
    {
        if (! current_user_can('ssf_decide_applications')) {
            return false;
        }
        $decision = sanitize_key((string) wp_unslash($_POST['membership_decision'] ?? ''));
        if (! in_array($decision, array('member_ship', 'closed'), true)) {
            return false;
        }
        $changed = SSF_Medlemsprocess_Application::set_membership_status($application_id, $decision, 'membership_portal');
        if ($changed && 'member_ship' === $decision) {
            SSF_Medlemsprocess_Application::create_member_ship($application_id);
        }
        if ($changed) {
            SSF_Medlemsprocess_Plugin::instance()->sharepoint->push_status($application_id);
        }
        return $changed;
    }

    private function render_overview(): void
    {
        $applications = $this->applications();
        $filtered = $this->filtered_applications($applications);
        echo '<header class="ssf-portal-head"><div><p class="ssf-portal-kicker">SSF Medlemskap</p><h1>Handläggning</h1><p>Hej ' . esc_html(wp_get_current_user()->display_name ?: 'handläggare') . '</p></div><div class="ssf-portal-head-actions"><a class="ssf-portal-button" href="' . esc_url(self::aspirants_url()) . '">Aspiranter</a></div></header>';
        $this->render_need_action($applications);
        $this->render_filters();
        $this->render_kanban($filtered);
    }

    private function render_aspirants(): void
    {
        $applications = get_posts(array(
            'post_type' => SSF_Medlemsprocess_Application::POST_TYPE,
            'post_status' => 'private',
            'posts_per_page' => 200,
            'orderby' => 'meta_value',
            'order' => 'ASC',
            'meta_key' => '_ssf_aspirant_review_due_at',
            'meta_query' => array(array('key' => '_ssf_membership_status', 'value' => array('aspirant', 'follow_up'), 'compare' => 'IN')),
        ));
        echo '<header class="ssf-portal-head"><div><p class="ssf-portal-kicker">SSF Medlemskap</p><h1>Aspiranter</h1></div><div class="ssf-portal-head-actions"><a class="ssf-portal-button" href="' . esc_url(self::page_url()) . '">Översikt</a></div></header>';
        echo '<section class="ssf-portal-panel"><table class="ssf-portal-table"><thead><tr><th>Fartyg</th><th>Aspirant från</th><th>Uppföljning</th><th>Tid kvar</th><th>Status</th></tr></thead><tbody>';
        if (! $applications) {
            echo '<tr><td colspan="5">Det finns inga aspiranter som behöver följas upp.</td></tr>';
        }
        foreach ($applications as $application) {
            $id = (int) $application->ID;
            $data = SSF_Medlemsprocess_Application::data($id);
            $review = (string) get_post_meta($id, '_ssf_aspirant_review_due_at', true);
            echo '<tr><td><a href="' . esc_url(self::review_url($id)) . '">' . esc_html($data['ship_name'] ?? get_the_title($id)) . '</a><small>' . esc_html((string) get_post_meta($id, '_ssf_application_number', true)) . '</small></td><td>' . esc_html((string) get_post_meta($id, '_ssf_aspirant_started_at', true)) . '</td><td>' . esc_html($review) . '</td><td>' . esc_html($this->days_label($review)) . '</td><td>' . $this->membership_chip(SSF_Medlemsprocess_Application::membership_status($id)) . '</td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private function render_case(int $application_id): void
    {
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $status = SSF_Medlemsprocess_Application::status($application_id);
        $tab = sanitize_key((string) ($_GET['tab'] ?? 'overview'));
        echo '<a class="ssf-portal-back" href="' . esc_url(self::page_url()) . '">Till alla ansökningar</a>';
        echo '<header class="ssf-case-hero"><div><p class="ssf-portal-kicker">' . esc_html((string) get_post_meta($application_id, '_ssf_application_number', true)) . '</p><h1>' . esc_html($data['ship_name'] ?? get_the_title($application_id)) . '</h1><div class="ssf-chip-row">' . $this->status_chip($status) . $this->membership_chip(SSF_Medlemsprocess_Application::membership_status($application_id)) . $this->payment_chip($application_id) . '</div></div><div class="ssf-case-meta"><span>Sökande</span><strong>' . esc_html((string) ($data['applicant_name'] ?? '')) . '</strong><span>Ansökningsväg</span><strong>' . esc_html((string) ($data['application_path'] ?? '')) . '</strong></div></header>';
        $this->render_process($status);
        echo '<nav class="ssf-case-tabs">';
        foreach (array('overview' => 'Översikt', 'application' => 'Ansökan', 'documents' => 'Dokument', 'inspection' => 'Inspektion', 'history' => 'Historik') as $key => $label) {
            echo '<a class="' . esc_attr($tab === $key ? 'is-active' : '') . '" href="' . esc_url(add_query_arg(array('tab' => $key), self::review_url($application_id))) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        if ('application' === $tab) {
            $this->render_application_tab($application_id, $data);
        } elseif ('documents' === $tab) {
            $this->render_documents_tab($application_id);
        } elseif ('inspection' === $tab) {
            $this->render_inspection_tab($application_id);
        } elseif ('history' === $tab) {
            $this->render_history_tab($application_id);
        } else {
            $this->render_case_overview($application_id, $data);
        }
    }

    private function render_case_overview(int $application_id, array $data): void
    {
        echo '<div class="ssf-case-grid"><section class="ssf-portal-panel" id="next-step"><h2>Nästa steg</h2>';
        if (! in_array(sanitize_key((string) ($_GET['portal_message'] ?? '')), array('payment_updated', 'payment_unchanged'), true)) {
            $this->render_notice();
        }
        $this->render_actions($application_id);
        echo '</section><section class="ssf-portal-panel"><h2>Snabböversikt</h2><dl class="ssf-definition-grid">';
        foreach (array('Fartygsombud' => 'applicant_name', 'E-post' => 'applicant_email', 'Telefon' => 'applicant_phone', 'Hemmahamn' => 'ship_home_port', 'Byggår' => 'ship_build_year', 'Rigg' => 'ship_rig') as $label => $key) {
            echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html((string) ($data[$key] ?? '')) . '</dd></div>';
        }
        echo '</dl></section></div>';
        echo '<section class="ssf-portal-panel" id="payment-status"><h2>Betalning</h2>';
        if (in_array(sanitize_key((string) ($_GET['portal_message'] ?? '')), array('payment_updated', 'payment_unchanged'), true)) {
            $this->render_notice();
        }
        if (current_user_can('edit_ssf_application', $application_id)) {
            echo $this->payment_form($application_id);
        }
        echo '</section>';
        echo '<section class="ssf-portal-panel"><h2>Intern notering</h2>' . $this->action_form($application_id, 'note', '<label>Intern notering<textarea name="internal_note" rows="4" required></textarea></label><button class="ssf-portal-button" type="submit">Lägg till anteckning</button>') . '</section>';
    }

    private function render_actions(int $application_id): void
    {
        $status = SSF_Medlemsprocess_Application::status($application_id);
        if ('received' === $status) {
            echo $this->transition_button($application_id, 'under_review', 'Påbörja granskning');
            return;
        }
        if ('under_review' === $status) {
            echo $this->transition_form($application_id, 'needs_completion', 'Begär komplettering', true);
            if (current_user_can('ssf_decide_applications')) {
                echo $this->approve_aspirant_form($application_id);
                echo $this->transition_form($application_id, 'rejected', 'Avslå ansökan', true, true);
            }
            return;
        }
        if ('awaiting_completion' === $status) {
            echo '<p class="ssf-muted">Väntar på sökanden.</p>';
            echo $this->transition_button($application_id, 'under_review', 'Komplettering mottagen');
            return;
        }
        if (in_array($status, array('inspection_planned', 'inspection_booked', 'inspection_completed', 'awaiting_decision'), true)) {
            echo '<div class="ssf-decision-panel"><p class="ssf-portal-kicker">Äldre ärende</p><h3>Fatta ansökningsbeslut</h3><p class="ssf-muted">Tidigare inspektionsstatus bevaras. Inspektion är inte ett krav för aspirantbeslut.</p>';
            if (current_user_can('ssf_decide_applications')) {
                echo $this->approve_aspirant_form($application_id);
                echo $this->transition_form($application_id, 'rejected', 'Avslå ansökan', true, true);
            }
            echo '</div>';
            return;
        }
        $membership = SSF_Medlemsprocess_Application::membership_status($application_id);
        if (in_array($membership, array('aspirant', 'follow_up'), true)) {
            $review = (string) get_post_meta($application_id, '_ssf_aspirant_review_due_at', true);
            $inspection = SSF_Medlemsprocess_Application::inspection_status($application_id);
            echo '<div class="ssf-aspirant-summary"><strong>ASPIRANTÅR</strong><span>Aspirant från ' . esc_html((string) get_post_meta($application_id, '_ssf_aspirant_started_at', true)) . '</span><span>Planerad uppföljning ' . esc_html($review) . ' (' . esc_html($this->days_label($review)) . ')</span><span>Inspektion: ' . esc_html(SSF_Medlemsprocess_Application::inspection_statuses()[$inspection]) . '</span></div>';
            if ('not_planned' === $inspection) {
                echo $this->inspection_progress_form($application_id, 'planning', 'Planera inspektion');
            } elseif ('planning' === $inspection) {
                echo $this->booking_form($application_id);
            } elseif ('booked' === $inspection) {
                echo '<p>Inspektionen är bokad. Inspektörerna startar och färdigställer det gemensamma protokollet i sin arbetsvy.</p>';
            } elseif ('in_progress' === $inspection) {
                echo '<p>Den fysiska inspektionen och protokollarbetet pågår.</p>';
            } elseif ('completed' === $inspection) {
                echo $this->inspection_progress_form($application_id, 'final_review', 'Gå direkt till slutbedömning');
                echo $this->inspection_progress_form($application_id, 'follow_up', 'Starta uppföljning');
            } elseif ('follow_up' === $inspection) {
                echo $this->inspection_progress_form($application_id, 'final_review', 'Gå till slutbedömning');
            }
            if ('follow_up' === $membership) {
                echo $this->membership_decision_form($application_id);
            }
            return;
        }
        if ('approved_aspirant' === $status && 'not_member' === $membership) {
            echo '<p class="ssf-muted">Aspirantbeslut finns registrerat men aspirantåret har inte startat. Kontrollera beslutsdatumet.</p>';
            return;
        }
        echo '<p class="ssf-muted">Inga rekommenderade åtgärder just nu.</p>';
    }

    private function render_application_tab(int $application_id, array $data): void
    {
        $groups = array(
            'Fartygsombud' => array('applicant_name', 'applicant_email', 'applicant_phone', 'applicant_address', 'applicant_organization'),
            'Fartyget' => array('ship_name', 'ship_owner', 'ship_home_port', 'ship_registry_number', 'ship_build_year', 'ship_shipyard', 'ship_length', 'ship_beam', 'ship_draft', 'ship_rig', 'ship_sail_area', 'ship_engine'),
            'Historia och användning' => array('ship_history', 'ship_current_use', 'ship_description'),
        );
        foreach ($groups as $title => $keys) {
            echo '<section class="ssf-portal-panel"><h2>' . esc_html($title) . '</h2><dl class="ssf-definition-grid">';
            foreach ($keys as $key) {
                if ('' === (string) ($data[$key] ?? '')) {
                    continue;
                }
                echo '<div><dt>' . esc_html($this->field_label($key)) . '</dt><dd>' . nl2br(esc_html((string) $data[$key])) . '</dd></div>';
            }
            echo '</dl></section>';
        }
    }

    private function render_documents_tab(int $application_id): void
    {
        echo '<section class="ssf-portal-panel"><h2>Dokument</h2><div class="ssf-document-list">';
        $pdf_url = (string) get_post_meta($application_id, '_ssf_sp_pdf_web_url', true);
        $folder_url = (string) get_post_meta($application_id, '_ssf_sp_application_web_url', true);
        $documents = array_merge((array) get_post_meta($application_id, '_ssf_application_files', true), (array) get_post_meta($application_id, '_ssf_completion_files', true));
        $images = array_filter(array_merge(array((int) get_post_meta($application_id, '_ssf_application_main_image_id', true)), (array) get_post_meta($application_id, '_ssf_application_gallery_ids', true)));
        echo '<article><strong>Ansökan.pdf</strong><span>' . ($pdf_url ? '<a href="' . esc_url($pdf_url) . '" target="_blank" rel="noopener">Öppna</a>' : 'Synkas till SharePoint') . '</span></article>';
        echo '<article><strong>Bilder</strong><span>' . esc_html((string) count($images)) . ' filer</span></article>';
        echo '<article><strong>Bilagor</strong><span>' . esc_html((string) count($documents)) . ' filer</span></article>';
        if ($folder_url) {
            echo '<p><a class="ssf-portal-button" href="' . esc_url($folder_url) . '" target="_blank" rel="noopener">Öppna mapp i SharePoint</a></p>';
        }
        echo '</div></section>';
    }

    private function render_inspection_tab(int $application_id): void
    {
        $booking = (array) get_post_meta($application_id, '_ssf_booking', true);
        $inspection = (array) get_post_meta($application_id, '_ssf_inspection', true);
        $inspection_id = SSF_Medlemsprocess_Inspection::inspection_for_application($application_id);
        $record = $inspection_id ? SSF_Medlemsprocess_Inspection::record($inspection_id) : array();
        if ($record) {
            $snapshot = (array) ($record['application_snapshot'] ?? array());
            $answer = (array) ($record['answers']['10'] ?? array());
            $deviations = SSF_Medlemsprocess_Inspection::deviations($record);
            $inspector_names = array();
            foreach (array((int) ($record['lead_inspector_user_id'] ?? 0), (int) ($record['co_inspector_user_id'] ?? 0)) as $user_id) { $user = $user_id ? get_userdata($user_id) : false; if ($user) { $inspector_names[] = $user->display_name; } }
            echo '<section class="ssf-portal-panel"><p class="ssf-portal-kicker">Beslutsunderlag</p><h2>Inspektionsprotokoll</h2><dl class="ssf-definition-grid">';
            foreach (array('Fartyg' => $snapshot['ship_name'] ?? '', 'Ansökningsnummer' => $snapshot['number'] ?? '', 'Aspirant sedan' => get_post_meta($application_id, '_ssf_aspirant_started_at', true), 'Inspektionsdatum' => $record['details']['date'] ?? '', 'Inspektörer' => implode(', ', $inspector_names), 'Status' => $record['status'] ?? '') as $label => $value) {
                echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html((string) $value) . '</dd></div>';
            }
            echo '</dl><h3>Inspektörernas rekommendation</h3><p><strong>' . esc_html((string) ($answer['selected_option'] ?? 'Inte angiven')) . '</strong></p><h3>Motivering</h3><p>' . nl2br(esc_html((string) ($record['summary'] ?? ''))) . '</p>';
            echo '<h3>Punkter att granska</h3><p>' . esc_html((string) count($deviations)) . ' svar med kommentar eller avvikelse.</p><ul>';
            foreach ($deviations as $item) { echo '<li><strong>' . esc_html(strtoupper($item['id'])) . '</strong> ' . esc_html($item['answer']) . ($item['comment'] ? ' – ' . esc_html($item['comment']) : '') . '</li>'; }
            echo '</ul>';
            if (! empty($record['photos'])) {
                echo '<h3>Bilder</h3><div class="ssf-document-list">';
                foreach (array_slice((array) $record['photos'], 0, 8) as $photo) { echo '<article><img src="' . esc_url(SSF_Medlemsprocess_Plugin::instance()->inspector->photo_url($inspection_id, (string) $photo['id'])) . '" alt="' . esc_attr((string) ($photo['caption'] ?: 'Inspektionsbild')) . '" style="max-width:160px;height:auto"><span>' . esc_html((string) ($photo['caption'] ?? '')) . '</span></article>'; }
                echo '</div>';
            }
            echo '<p><a class="ssf-portal-button" href="' . esc_url(SSF_Medlemsprocess_Plugin::instance()->inspector->case_url($application_id, $inspection_id)) . '">Läs hela protokollet</a></p></section>';
            return;
        }
        echo '<section class="ssf-portal-panel"><h2>Inspektion</h2><dl class="ssf-definition-grid">';
        foreach (array('Datum' => $booking['date'] ?? $inspection['date'] ?? '', 'Tid' => trim(($booking['start'] ?? '') . ' ' . ($booking['end'] ?? '')), 'Plats' => $booking['location'] ?? $inspection['place'] ?? '', 'Inspektör' => $inspection['inspector'] ?? '') as $label => $value) {
            echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html((string) $value) . '</dd></div>';
        }
        echo '</dl></section>';
    }

    private function render_history_tab(int $application_id): void
    {
        $history = array_reverse((array) get_post_meta($application_id, '_ssf_application_history', true));
        echo '<section class="ssf-portal-panel"><h2>Historik</h2><ol class="ssf-timeline">';
        foreach ($history as $entry) {
            $author = ! empty($entry['author']) ? get_the_author_meta('display_name', (int) $entry['author']) : '';
            echo '<li><time>' . esc_html((string) ($entry['time'] ?? $entry['changed_at'] ?? '')) . '</time><strong>' . esc_html((string) ($entry['message'] ?? '')) . '</strong><span>' . esc_html(trim(($author ?: 'System') . ' · ' . (string) ($entry['source'] ?? ''))) . '</span></li>';
        }
        echo '</ol></section>';
    }

    private function render_need_action(array $applications): void
    {
        $counts = array('received' => 0, 'under_review' => 0, 'awaiting_completion' => 0, 'inspection_pending' => 0, 'follow_up' => 0);
        foreach ($applications as $application) {
            $status = SSF_Medlemsprocess_Application::status((int) $application->ID);
            if (isset($counts[$status])) {
                ++$counts[$status];
            }
            if ('follow_up' === SSF_Medlemsprocess_Application::membership_status((int) $application->ID)) {
                ++$counts['follow_up'];
            }
            if ('aspirant' === SSF_Medlemsprocess_Application::membership_status((int) $application->ID) && in_array(SSF_Medlemsprocess_Application::inspection_status((int) $application->ID), array('not_planned', 'planning'), true)) {
                ++$counts['inspection_pending'];
            }
        }
        echo '<section class="ssf-action-strip"><h2>Behöver åtgärd</h2>';
        foreach (array('received' => 'Nya ansökningar', 'under_review' => 'Väntar på granskning', 'awaiting_completion' => 'Väntar komplettering', 'inspection_pending' => 'Inspektion under aspirantåret', 'follow_up' => 'Aspirantuppföljning') as $key => $label) {
            $url = 'inspection_pending' === $key ? self::aspirants_url() : add_query_arg('status', $key, self::page_url());
            echo '<a href="' . esc_url($url) . '"><strong>' . esc_html((string) $counts[$key]) . '</strong><span>' . esc_html($label) . '</span></a>';
        }
        echo '</section>';
    }

    private function render_filters(): void
    {
        echo '<form class="ssf-portal-filters" method="get"><label>Sök<input type="search" name="q" value="' . esc_attr((string) ($_GET['q'] ?? '')) . '" placeholder="Fartyg, nummer eller sökande"></label><label>Status<select name="status"><option value="">Alla statusar</option>';
        foreach (SSF_Medlemsprocess_Application::workflow_statuses() as $key => $status) {
            echo '<option value="' . esc_attr($key) . '" ' . selected((string) ($_GET['status'] ?? ''), $key, false) . '>' . esc_html($status['label']) . '</option>';
        }
        echo '</select></label><label>Medlemsstatus<select name="membership"><option value="">Alla</option>';
        foreach (SSF_Medlemsprocess_Application::membership_statuses() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected((string) ($_GET['membership'] ?? ''), $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label><button class="ssf-portal-button" type="submit">Filtrera</button></form>';
    }

    private function render_kanban(array $applications): void
    {
        $columns = $this->kanban_columns();
        echo '<section class="ssf-kanban" aria-label="Ansökningskanban">';
        foreach ($columns as $column_key => $column) {
            echo '<div class="ssf-kanban-column" data-kanban-column="' . esc_attr($column_key) . '" data-column-label="' . esc_attr($column['label']) . '"><header><h2>' . esc_html($column['label']) . '</h2></header>';
            $count = 0;
            foreach ($applications as $application) {
                $id = (int) $application->ID;
                if (! in_array(SSF_Medlemsprocess_Application::status($id), $column['statuses'], true) && ! ('aspirant' === $column_key && 'aspirant' === SSF_Medlemsprocess_Application::membership_status($id)) && ! ('closed' === $column_key && in_array(SSF_Medlemsprocess_Application::membership_status($id), array('member_ship', 'closed'), true))) {
                    continue;
                }
                ++$count;
                $this->render_card($id);
            }
            if (0 === $count) {
                echo '<p class="ssf-empty">Inga ärenden här just nu.</p>';
            }
            echo '</div>';
        }
        echo '</section>';
    }

    private function render_card(int $application_id): void
    {
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $status = SSF_Medlemsprocess_Application::status($application_id);
        $sharepoint = (string) get_post_meta($application_id, '_ssf_sp_sync_status', true);
        echo '<article class="ssf-kanban-card" draggable="true" data-application="' . esc_attr((string) $application_id) . '" data-case-url="' . esc_url(self::review_url($application_id)) . '">';
        echo '<strong>' . esc_html((string) get_post_meta($application_id, '_ssf_application_number', true)) . '</strong>';
        echo '<h3>' . esc_html((string) ($data['ship_name'] ?? get_the_title($application_id))) . '</h3>';
        echo '<p>' . esc_html((string) ($data['applicant_name'] ?? '')) . '</p>';
        echo '<div class="ssf-chip-row">' . $this->status_chip($status) . '</div>';
        echo '<small>' . esc_html($this->age_label((string) get_post_meta($application_id, '_ssf_status_changed_at', true) ?: (string) get_post_meta($application_id, '_ssf_submitted_at', true))) . '</small>';
        echo '<div class="ssf-card-indicators"><span>' . esc_html('synced' === $sharepoint ? 'SharePoint OK' : 'Synk väntar') . '</span></div>';
        echo '<a class="ssf-card-link" href="' . esc_url(self::review_url($application_id)) . '">Öppna ärende</a>';
        echo '</article>';
    }

    private function render_process(string $status): void
    {
        $steps = array('received' => 'Inkommen', 'under_review' => 'Granskning', 'awaiting_completion' => 'Komplettering', 'approved_aspirant' => 'Aspirant');
        $current_step = (int) (SSF_Medlemsprocess_Application::workflow_statuses()[$status]['step'] ?? 0);
        echo '<ol class="ssf-process-steps">';
        foreach ($steps as $key => $label) {
            $step = (int) (SSF_Medlemsprocess_Application::workflow_statuses()[$key]['step'] ?? 0);
            $class = $key === $status ? 'is-current' : ($step < $current_step ? 'is-done' : '');
            echo '<li class="' . esc_attr($class) . '"><span></span>' . esc_html($label) . '</li>';
        }
        echo '</ol>';
    }

    private function applications(): array
    {
        return get_posts(array('post_type' => SSF_Medlemsprocess_Application::POST_TYPE, 'post_status' => 'private', 'posts_per_page' => 200, 'orderby' => 'modified', 'order' => 'DESC'));
    }

    private function filtered_applications(array $applications): array
    {
        $query = strtolower(sanitize_text_field((string) ($_GET['q'] ?? '')));
        $status_filter = sanitize_key((string) ($_GET['status'] ?? ''));
        $membership_filter = sanitize_key((string) ($_GET['membership'] ?? ''));
        return array_values(array_filter($applications, function ($application) use ($query, $status_filter, $membership_filter): bool {
            $id = (int) $application->ID;
            if ($status_filter && $status_filter !== SSF_Medlemsprocess_Application::status($id)) {
                return false;
            }
            if ($membership_filter && $membership_filter !== SSF_Medlemsprocess_Application::membership_status($id)) {
                return false;
            }
            if (! $query) {
                return true;
            }
            $data = SSF_Medlemsprocess_Application::data($id);
            $haystack = strtolower(implode(' ', array(get_post_meta($id, '_ssf_application_number', true), $data['ship_name'] ?? '', $data['applicant_name'] ?? '', $data['applicant_email'] ?? '')));
            return false !== strpos($haystack, $query);
        }));
    }

    private function current_application_id(): int
    {
        $raw = sanitize_text_field((string) (get_query_var('ssf_application_number') ?: ($_GET['application'] ?? $_GET['id'] ?? '')));
        if (! $raw) {
            return 0;
        }
        if (ctype_digit($raw)) {
            $post = get_post((int) $raw);
            return $post && SSF_Medlemsprocess_Application::POST_TYPE === $post->post_type ? (int) $post->ID : 0;
        }
        $ids = get_posts(array('post_type' => SSF_Medlemsprocess_Application::POST_TYPE, 'post_status' => 'private', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => '_ssf_application_number', 'meta_value' => rawurldecode($raw)));
        return $ids ? (int) $ids[0] : 0;
    }

    private function current_view(): string
    {
        return sanitize_key((string) (get_query_var('ssf_membership_portal_view') ?: ($_GET['view'] ?? '')));
    }

    private function is_portal_page(): bool
    {
        $page_id = self::page_id();
        return $page_id && is_page($page_id);
    }

    private function can_view_portal(): bool
    {
        return current_user_can('ssf_view_applications') || current_user_can('edit_ssf_applications') || current_user_can('manage_options');
    }

    private function login_prompt(): string
    {
        $url = wp_login_url($this->current_url());
        return '<div class="ssf-portal ssf-portal-message"><h1>Handläggningsportal</h1><p>Logga in för att fortsätta till medlemsansökningarna.</p><p><a class="ssf-portal-button" href="' . esc_url($url) . '">Logga in</a></p></div>';
    }

    private function current_url(): string
    {
        return home_url(add_query_arg(array(), (string) ($_SERVER['REQUEST_URI'] ?? '/')));
    }

    private function redirect(int $application_id, string $message): void
    {
        $anchor = in_array($message, array('payment_updated', 'payment_unchanged'), true) ? '#payment-status' : '#next-step';
        $target = self::review_url($application_id);
        $referer = wp_get_referer();
        if (class_exists('SSF_Workspace') && $referer && 0 === strpos($referer, SSF_Workspace::url('ansokningar/' . $application_id))) {
            $target = SSF_Workspace::url('ansokningar/' . $application_id);
        }
        wp_safe_redirect(add_query_arg('portal_message', $message, $target) . $anchor);
        exit;
    }

    private function render_notice(): void
    {
        $message = sanitize_key((string) ($_GET['portal_message'] ?? ''));
        $labels = array(
            'updated' => 'Status uppdaterad.',
            'aspirant_approved' => '✓ Fartyget är nu aspirant. Planera inspektion under aspirantåret.',
            'note_added' => 'Intern notering sparad.',
            'payment_updated' => 'Betalningsstatus sparad.',
            'payment_unchanged' => 'Betalningsstatus är oförändrad.',
            'changed' => 'Ärendet har uppdaterats av någon annan. Ladda om sidan innan du fortsätter.',
            'drag_opened' => 'Välj och bekräfta rätt nästa steg för ärendet.',
            'failed' => 'Åtgärden kunde inte genomföras. Kontrollera att arbetsflödet tillåter steget.',
        );
        $labels['decision_date_missing'] = 'Beslutsdatum krävs för att godkänna som aspirant. Inget beslut har sparats.';
        if (isset($labels[$message])) {
            echo '<div class="ssf-portal-notice">' . esc_html($labels[$message]) . '</div>';
        }
    }

    private function action_form(int $application_id, string $operation, string $inner): string
    {
        ob_start();
        echo '<form class="ssf-portal-action-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="portal_operation" value="' . esc_attr($operation) . '"><input type="hidden" name="application_id" value="' . esc_attr((string) $application_id) . '"><input type="hidden" name="expected_status" value="' . esc_attr(SSF_Medlemsprocess_Application::status($application_id)) . '">';
        wp_nonce_field('ssf_membership_portal_' . $application_id);
        echo $inner;
        echo '</form>';
        return (string) ob_get_clean();
    }

    private function transition_button(int $application_id, string $target, string $label): string
    {
        return $this->action_form($application_id, 'transition', '<input type="hidden" name="target_status" value="' . esc_attr($target) . '"><button class="ssf-portal-button ssf-portal-button-primary" type="submit">' . esc_html($label) . '</button>');
    }

    private function transition_form(int $application_id, string $target, string $label, bool $requires_public_comment = false, bool $decision = false): string
    {
        $fields = '<input type="hidden" name="target_status" value="' . esc_attr($target) . '"><details class="ssf-action-details"><summary>' . esc_html($label) . '</summary>';
        if ($requires_public_comment) {
            $fields .= '<label>Meddelande till sökanden<textarea name="public_comment" rows="4" required></textarea></label>';
        }
        if ($decision) {
            $fields .= '<label>Intern notering<textarea name="internal_note" rows="3"></textarea></label>';
        }
        $fields .= '<button class="ssf-portal-button ssf-portal-button-primary" type="submit">' . esc_html($label) . '</button></details>';
        return $this->action_form($application_id, 'transition', $fields);
    }

    private function approve_aspirant_form(int $application_id): string
    {
        $today = wp_date('Y-m-d');
        $review = (new DateTimeImmutable($today, wp_timezone()))->modify('+1 year')->format('Y-m-d');
        $dialog_id = 'ssf-aspirant-dialog-' . $application_id;
        $fields = '<input type="hidden" name="target_status" value="approved_aspirant"><button class="ssf-portal-button ssf-portal-button-primary" type="button" data-ssf-dialog-open="' . esc_attr($dialog_id) . '">Godkänn som aspirant</button><dialog class="ssf-decision-dialog" id="' . esc_attr($dialog_id) . '" aria-labelledby="' . esc_attr($dialog_id) . '-title"><div class="ssf-decision-dialog-inner"><h3 id="' . esc_attr($dialog_id) . '-title">Godkänn som aspirant</h3><p>Styrelsens godkännande startar fartygets aspirantår. Inspektion genomförs under aspirantåret och är inte ett krav för aspirantstatus.</p><label>Beslutsdatum och aspirant från<input type="date" name="decision_date" value="' . esc_attr($today) . '" required></label><p class="ssf-muted">Aspirantstart är samma datum som styrelsens beslut. Uppföljning planeras ett år senare (vid dagens datum ' . esc_html($review) . ').</p><label>Meddelande till sökanden<textarea name="public_comment" rows="3"></textarea></label><div class="ssf-dialog-actions"><button class="ssf-portal-button" type="button" data-ssf-dialog-close>Avbryt</button><button class="ssf-portal-button ssf-portal-button-primary" type="submit">Godkänn som aspirant</button></div></div></dialog>';
        return $this->action_form($application_id, 'transition', $fields);
    }

    private function inspection_progress_form(int $application_id, string $target, string $label): string
    {
        return $this->action_form($application_id, 'inspection_progress', '<input type="hidden" name="inspection_status" value="' . esc_attr($target) . '"><button class="ssf-portal-button ssf-portal-button-primary" type="submit">' . esc_html($label) . '</button>');
    }

    private function booking_form(int $application_id): string
    {
        $fields = '<input type="hidden" name="target_status" value="inspection_booked"><label>Datum<input type="date" name="booking[date]" required></label><label>Starttid<input type="time" name="booking[start]"></label><label>Plats<input type="text" name="booking[location]"></label><label>Kontaktperson<input type="text" name="booking[contact]"></label><label>Deltagare<input type="text" name="booking[participants]"></label><label>Praktisk kommentar<textarea name="booking[comment]" rows="3"></textarea></label><label class="ssf-checkbox"><input type="checkbox" name="send_booking_email" value="1"> Skicka bokningsmail</label><button class="ssf-portal-button ssf-portal-button-primary" type="submit">Boka inspektion</button>';
        return $this->action_form($application_id, 'book_inspection', $fields);
    }

    private function membership_decision_form(int $application_id): string
    {
        $fields = '<button class="ssf-portal-button ssf-portal-button-primary" type="submit" name="membership_decision" value="member_ship">Godkänn som medlemsfartyg</button><button class="ssf-portal-button" type="submit" name="membership_decision" value="closed">Avsluta</button>';
        return $this->action_form($application_id, 'membership_decision', $fields);
    }

    private function payment_form(int $application_id): string
    {
        $checked = SSF_Medlemsprocess_Application::payment_received($application_id) ? ' checked' : '';
        $date = SSF_Medlemsprocess_Application::payment_received_date($application_id);
        $fields = '<label class="ssf-checkbox"><input type="checkbox" name="payment_received" value="1"' . $checked . '> Betalning mottagen</label><label>Betaldatum<input type="date" name="payment_received_date" value="' . esc_attr($date) . '"></label><button class="ssf-portal-button" type="submit">Spara betalningsstatus</button>';
        return $this->action_form($application_id, 'payment', $fields);
    }

    private function payment_chip(int $application_id): string
    {
        if (! SSF_Medlemsprocess_Application::payment_received($application_id)) { return ''; }
        $date = SSF_Medlemsprocess_Application::payment_received_date($application_id);
        return '<span class="ssf-status-chip" title="' . esc_attr($date ? 'Betald ' . $date : 'Betald') . '">Betald ✓</span>';
    }

    private function kanban_columns(): array
    {
        return array(
            'received' => array('label' => 'Inkommen', 'statuses' => array('received')),
            'review' => array('label' => 'Granskning', 'statuses' => array('under_review')),
            'completion' => array('label' => 'Komplettering', 'statuses' => array('needs_completion', 'awaiting_completion')),
            'inspection' => array('label' => 'Äldre inspektionsärenden', 'statuses' => array('inspection_planned', 'inspection_booked', 'inspection_completed')),
            'decision' => array('label' => 'Äldre slutbedömning', 'statuses' => array('awaiting_decision')),
            'aspirant' => array('label' => 'Aspirant', 'statuses' => array('approved_aspirant')),
            'closed' => array('label' => 'Avslutade', 'statuses' => array('rejected')),
        );
    }

    private function status_chip(string $status): string
    {
        return '<span class="ssf-status-chip ssf-status-' . esc_attr($status) . '">' . esc_html(SSF_Medlemsprocess_Application::status_label($status)) . '</span>';
    }

    private function membership_chip(string $status): string
    {
        return '<span class="ssf-status-chip ssf-membership-' . esc_attr($status) . '">' . esc_html(SSF_Medlemsprocess_Application::membership_status_label($status)) . '</span>';
    }

    private function age_label(string $date): string
    {
        if (! $date) {
            return 'Ingen tidsstämpel';
        }
        $days = max(0, (int) floor((current_time('timestamp') - strtotime($date)) / DAY_IN_SECONDS));
        return 0 === $days ? 'Uppdaterad idag' : $days . ' dagar i detta steg';
    }

    private function days_label(string $date): string
    {
        if (! $date) {
            return 'Ej satt';
        }
        $days = (int) floor((strtotime($date . ' 00:00:00') - current_time('timestamp')) / DAY_IN_SECONDS);
        if ($days < 0) {
            return 'Förfallen';
        }
        return 0 === $days ? 'Idag' : $days . ' dagar kvar';
    }

    private function field_label(string $key): string
    {
        return ucwords(str_replace('_', ' ', preg_replace('/^(ship|applicant)_/', '', $key)));
    }
}
