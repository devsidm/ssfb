<?php
/**
 * Public application and applicant-status interactions.
 *
 * @package SSF_Medlemsprocess
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsprocess_Public
{
    public function __construct()
    {
        add_action('init', array($this, 'register_shortcodes'), 99);
        add_action('admin_post_nopriv_ssf_submit_application', array($this, 'submit_application'));
        add_action('admin_post_ssf_submit_application', array($this, 'submit_application'));
        add_action('admin_post_nopriv_ssf_submit_completion', array($this, 'submit_completion'));
        add_action('admin_post_ssf_submit_completion', array($this, 'submit_completion'));
        add_filter('wp_robots', array($this, 'robots'));
    }

    public function register_shortcodes(): void
    {
        add_shortcode('ssf_application_form', array($this, 'application_form'));
        add_shortcode('ssf_application_status', array($this, 'status_page'));
    }

    public function application_form(): string
    {
        if (! $this->applications_enabled()) {
            return '<section class="ssf-process-shell"><p class="ssf-process-eyebrow">Medlemskap</p><h2>Ansökan är tillfälligt stängd</h2><p>Den digitala ansökningsfunktionen är tillfälligt stängd medan vi färdigställer den nya medlemsprocessen.</p><p>För frågor om medlemskap, <a href="' . esc_url(home_url('/kontakta-oss/')) . '">kontakta SSF</a>.</p></section>';
        }
        if (! empty($_GET['ssf_application_sent']) && ! empty($_GET['token'])) {
            $confirmation_token = sanitize_text_field(wp_unslash($_GET['token']));
            $status_link = SSF_Medlemsprocess_Application::status_link($confirmation_token);
            $confirmation_id = SSF_Medlemsprocess_Application::find_by_token($confirmation_token);
            $confirmation_number = $confirmation_id ? (string) get_post_meta($confirmation_id, '_ssf_application_number', true) : '';
            $mail_sent = 'sent' === sanitize_key(wp_unslash($_GET['ssf_mail'] ?? ''));
            $message = $mail_sent
                ? 'Vi har skickat en bekräftelse till din e-postadress. Du kan följa ärendet med den personliga statuslänken.'
                : 'Ansökan är registrerad, men vi kunde inte bekräfta e-postleveransen. Spara den personliga statuslänken och kontakta SSF om du behöver hjälp.';
            return '<section class="ssf-process-shell ssf-process-confirmation"><p class="ssf-process-eyebrow">Ansökan mottagen</p><h1>Tack för din ansökan</h1>' . ($confirmation_number ? '<p>Ansökningsnummer: <strong>' . esc_html($confirmation_number) . '</strong></p>' : '') . '<p>' . esc_html($message) . '</p><p><a class="ssf-process-button" href="' . esc_url($status_link) . '">Följ ansökan</a></p></section>';
        }

        $settings = SSF_Medlemsprocess_Plugin::settings();
        $application_content = function_exists('ssf_site_page_content') ? ssf_site_page_content('application') : array();
        ob_start();
        include SSF_MEDLEMSPROCESS_PATH . 'templates/application-form.php';
        return ob_get_clean();
    }

    public function status_page(): string
    {
        $this->send_private_status_headers();
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $application_id = SSF_Medlemsprocess_Application::find_by_token($token);
        if (! $application_id) {
            return '<section class="ssf-process-shell ssf-process-error"><h1>Länken är ogiltig eller har gått ut</h1><p>Kontakta SSF om du behöver en ny statuslänk.</p></section>';
        }

        $application = get_post($application_id);
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $status = SSF_Medlemsprocess_Application::status($application_id);
        $history = (array) get_post_meta($application_id, '_ssf_application_history', true);
        $booking = (array) get_post_meta($application_id, '_ssf_booking', true);
        $files = array_map('intval', (array) get_post_meta($application_id, '_ssf_application_files', true));
        $completion_files = array_map('intval', (array) get_post_meta($application_id, '_ssf_completion_files', true));
        $inspector_files = (array) get_post_meta($application_id, '_ssf_inspector_files', true);
        $inspector_visible_files = array_map('intval', wp_list_pluck(array_filter($inspector_files, static function ($file) { return ! empty($file['visible_to_applicant']); }), 'id'));
        $inspection_id = SSF_Medlemsprocess_Inspection::inspection_for_application($application_id);
        $inspection_record = $inspection_id ? SSF_Medlemsprocess_Inspection::record($inspection_id) : array();
        if (! empty($_GET['inspection_protocol']) && 'completed' === ($inspection_record['status'] ?? '') && ! empty($inspection_record['final_snapshot'])) {
            wp_enqueue_style('ssf-inspector-portal', SSF_MEDLEMSPROCESS_URL . 'assets/css/ssf-inspector-portal.css', array('ssf-medlemsprocess'), SSF_MEDLEMSPROCESS_VERSION);
            $protocol = $inspection_record['final_snapshot'];
            $photo_token = $token;
            ob_start();
            include SSF_MEDLEMSPROCESS_PATH . 'templates/inspection-protocol.php';
            return ob_get_clean();
        }
        ob_start();
        include SSF_MEDLEMSPROCESS_PATH . 'templates/status-page.php';
        return ob_get_clean();
    }

    public function robots(array $robots): array
    {
        $pages = (array) (SSF_Medlemsprocess_Plugin::settings()['pages'] ?? array());
        if (! empty($_GET['token']) && ! empty($pages['ansokan_status']) && is_page((int) $pages['ansokan_status'])) {
            $robots['noindex'] = true;
            $robots['nofollow'] = true;
            $robots['noarchive'] = true;
            $robots['nosnippet'] = true;
        }
        return $robots;
    }

    private function send_private_status_headers(): void
    {
        if (headers_sent()) {
            return;
        }

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true);
        header('Pragma: no-cache', true);
    }

    public function submit_application(): void
    {
        if (! $this->applications_enabled()) {
            wp_safe_redirect(add_query_arg('ssf_application_closed', '1', SSF_Medlemsprocess_Plugin::page_url('ansokan')));
            exit;
        }
        $this->assert_nonce('ssf_application_submit');
        if (class_exists('SSF_Antispam') && ! SSF_Antispam::validate('membership_application')) {
            wp_die(esc_html(SSF_Antispam::error_message()));
        }
        if (! empty($_POST['website'])) {
            wp_die('Formuläret kunde inte skickas.');
        }
        if (! empty(get_transient($this->rate_key()))) {
            wp_die('För många försök. Vänta en stund och försök igen.');
        }
        $submission_key = sanitize_text_field(wp_unslash($_POST['submission_key'] ?? ''));
        if (! wp_is_uuid($submission_key)) {
            wp_die('Formuläret saknar ett giltigt inskicknings-ID. Ladda om sidan och försök igen.');
        }
        $submission_cache_key = 'ssf_application_submit_' . md5($submission_key);
        $previous_submission = get_transient($submission_cache_key);
        if (is_array($previous_submission) && ! empty($previous_submission['token'])) {
            $this->redirect_to_confirmation((string) $previous_submission['token'], ! empty($previous_submission['mail_sent']));
        }
        if ('processing' === $previous_submission) {
            wp_die('Ansökan behandlas redan. Vänta en kort stund innan du försöker igen.');
        }
        if (empty($_POST['confirm_accuracy']) || empty($_POST['privacy_consent']) || empty($_POST['upload_rights'])) {
            wp_die('Du behöver bekräfta uppgifterna och samtycket innan ansökan kan skickas.');
        }

        $data = $this->collect_application_data();
        if (! is_email($data['applicant_email']) || ! $data['applicant_first_name'] || ! $data['applicant_last_name']) {
            wp_die('Fyll i förnamn, efternamn och en giltig e-postadress.');
        }
        if ($data['applicant_invoice_email'] && ! is_email($data['applicant_invoice_email'])) {
            wp_die('Fyll i en giltig e-postadress för fakturor.');
        }
        if (class_exists('SSF_Medlemsfartyg_Profile')) {
            $errors = SSF_Medlemsfartyg_Profile::validate((array) $data['vessel_profile'], (string) $data['application_route'], SSF_Medlemsfartyg_Profile::MODE_APPLICATION);
            if ($errors->has_errors()) {
                wp_die(esc_html(implode(' ', $errors->get_error_messages())));
            }
        }
        $upload_errors = $this->validate_uploads();
        if ($upload_errors->has_errors()) {
            wp_die(esc_html(implode(' ', $upload_errors->get_error_messages())));
        }
        set_transient($submission_cache_key, 'processing', 10 * MINUTE_IN_SECONDS);
        $created = SSF_Medlemsprocess_Application::create($data);
        if (! $created['id']) {
            delete_transient($submission_cache_key);
            wp_die('Ansökan kunde inte sparas. Försök igen eller kontakta SSF.');
        }
        $main_images = $this->handle_uploads($created['id'], 'ssf_application_main_image');
        $gallery = $this->handle_uploads($created['id'], 'ssf_application_gallery');
        $documents = $this->handle_uploads($created['id'], 'ssf_application_documents');
        $files = array_merge($main_images, $gallery, $documents);
        update_post_meta($created['id'], '_ssf_application_files', $files);
        update_post_meta($created['id'], '_ssf_application_main_image_id', (int) ($main_images[0] ?? 0));
        update_post_meta($created['id'], '_ssf_application_gallery_ids', array_map('intval', $gallery));
        update_post_meta($created['id'], '_ssf_application_document_ids', array_map('intval', $documents));
        $ship_id = (int) get_post_meta($created['id'], '_ssf_linked_ship_id', true);
        if ($ship_id && class_exists('SSF_Medlemsfartyg_Profile')) {
            SSF_Medlemsfartyg_Profile::attach_application_files($ship_id, $files, (int) ($main_images[0] ?? 0));
        }
        $pdf_id = SSF_Medlemsprocess_Plugin::instance()->pdf->create_attachment($created['id']);
        if ($pdf_id) {
            update_post_meta($created['id'], '_ssf_application_pdf_id', $pdf_id);
        }
        SSF_Medlemsprocess_Plugin::instance()->sharepoint->queue($created['id']);
        set_transient($this->rate_key(), 1, MINUTE_IN_SECONDS * 2);
        $mail_sent = SSF_Medlemsprocess_Plugin::instance()->emails->send_received($created['id'], $created['token']);
        set_transient($submission_cache_key, array('id' => $created['id'], 'token' => $created['token'], 'mail_sent' => $mail_sent), 30 * MINUTE_IN_SECONDS);
        $this->redirect_to_confirmation($created['token'], $mail_sent);
    }

    public function submit_completion(): void
    {
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $application_id = SSF_Medlemsprocess_Application::find_by_token($token);
        if (! $application_id || ! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'ssf_application_completion_' . $application_id)) {
            wp_die('Länken är ogiltig eller har gått ut.');
        }
        if (class_exists('SSF_Antispam') && ! SSF_Antispam::validate('application_completion')) {
            wp_die(esc_html(SSF_Antispam::error_message()));
        }
        $current_status = SSF_Medlemsprocess_Application::status($application_id);
        if (in_array($current_status, array('approved_aspirant', 'approved', 'rejected', 'archived'), true)) {
            wp_die('Ärendet är avslutat och kan inte kompletteras.');
        }
        $message = sanitize_textarea_field(wp_unslash($_POST['completion_message'] ?? ''));
        if (! $message && empty($_FILES['ssf_completion_files']['name'][0])) {
            wp_die('Skriv ett svar eller bifoga en fil innan du skickar kompletteringen.');
        }
        $upload_errors = $this->validate_uploads(array(
            'ssf_completion_files' => array('jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx'),
        ));
        if ($upload_errors->has_errors()) {
            wp_die(esc_html(implode(' ', $upload_errors->get_error_messages())));
        }
        $files = $this->handle_uploads($application_id, 'ssf_completion_files');
        if (! $message && ! $files) {
            wp_die('Filen kunde inte sparas. Kontrollera filen och försök igen.');
        }
        $all_files = array_merge((array) get_post_meta($application_id, '_ssf_completion_files', true), $files);
        update_post_meta($application_id, '_ssf_completion_files', array_map('intval', $all_files));
        SSF_Medlemsprocess_Application::add_history($application_id, 'completion', $message ?: 'Kompletterande filer skickades in.', true, array('files' => $files));
        if (in_array($current_status, array('needs_completion', 'awaiting_completion'), true)) {
            SSF_Medlemsprocess_Application::transition($application_id, 'under_review', '', false, 'system');
        }
        $new_token = SSF_Medlemsprocess_Application::issue_token($application_id);
        SSF_Medlemsprocess_Plugin::instance()->emails->send_template('completion_received', $application_id, array('status_link' => SSF_Medlemsprocess_Application::status_link($new_token)));
        SSF_Medlemsprocess_Plugin::instance()->sharepoint->queue($application_id);
        wp_safe_redirect(SSF_Medlemsprocess_Application::status_link($new_token));
        exit;
    }

    private function collect_application_data(): array
    {
        $fields = array('applicant_first_name', 'applicant_last_name', 'applicant_phone', 'applicant_organization', 'applicant_street', 'applicant_postal_code', 'applicant_city', 'applicant_website');
        $data = array();
        foreach ($fields as $field) {
            $data[$field] = sanitize_text_field(wp_unslash($_POST[$field] ?? ''));
        }
        $data['applicant_email'] = sanitize_email(wp_unslash($_POST['applicant_email'] ?? ''));
        $data['applicant_invoice_email'] = sanitize_email(wp_unslash($_POST['applicant_invoice_email'] ?? ''));
        $data['applicant_name'] = trim($data['applicant_first_name'] . ' ' . $data['applicant_last_name']);
        $data['applicant_address'] = trim(implode(', ', array_filter(array($data['applicant_street'], trim($data['applicant_postal_code'] . ' ' . $data['applicant_city'])))));
        $data['application_route'] = sanitize_key(wp_unslash($_POST['application_route'] ?? ''));
        if (class_exists('SSF_Medlemsfartyg_Profile')) {
            $data['vessel_profile'] = SSF_Medlemsfartyg_Profile::collect($_POST, $data['application_route'], SSF_Medlemsfartyg_Profile::MODE_APPLICATION);
        } else {
            $data['vessel_profile'] = array('post_title' => sanitize_text_field(wp_unslash($_POST['post_title'] ?? '')));
        }
        $data['confirm_accuracy'] = '1';
        $data['privacy_consent'] = '1';
        $data['upload_rights'] = '1';
        return $data;
    }

    private function handle_uploads(int $application_id, string $field): array
    {
        if (empty($_FILES[$field]['name'])) {
            return array();
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $settings = SSF_Medlemsprocess_Plugin::settings();
        $files = $_FILES[$field];
        if (! is_array($files['name'])) {
            foreach (array('name', 'type', 'tmp_name', 'error', 'size') as $part) {
                $files[$part] = array($files[$part]);
            }
        }
        $attachments = array();
        foreach ((array) $files['name'] as $index => $name) {
            if (UPLOAD_ERR_OK !== (int) $files['error'][$index]) {
                continue;
            }
            $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
            if (! in_array($extension, array('jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx'), true)) {
                continue;
            }
            $max_bytes = (in_array($extension, array('pdf', 'doc', 'docx'), true) ? (int) $settings['max_file_mb'] : (int) $settings['max_image_mb']) * MB_IN_BYTES;
            if ((int) $files['size'][$index] > $max_bytes) {
                continue;
            }
            $file = array(
                'name' => sanitize_file_name((string) $name), 'type' => (string) $files['type'][$index],
                'tmp_name' => (string) $files['tmp_name'][$index], 'error' => (int) $files['error'][$index], 'size' => (int) $files['size'][$index],
            );
            $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
            if (empty($checked['ext']) || ! in_array(strtolower($checked['ext']), array('jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx'), true)) {
                continue;
            }
            $upload = wp_handle_upload($file, array('test_form' => false));
            if (! empty($upload['error'])) {
                continue;
            }
            $attachment_id = wp_insert_attachment(array(
                'post_mime_type' => $upload['type'], 'post_title' => sanitize_text_field(pathinfo($file['name'], PATHINFO_FILENAME)),
                'post_status' => 'inherit', 'post_parent' => $application_id,
            ), $upload['file'], $application_id);
            if (! is_wp_error($attachment_id)) {
                $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
                wp_update_attachment_metadata($attachment_id, $metadata);
                $attachments[] = (int) $attachment_id;
            }
        }
        return $attachments;
    }

    private function validate_uploads(?array $fields = null): WP_Error
    {
        $errors = new WP_Error();
        $settings = SSF_Medlemsprocess_Plugin::settings();
        $fields = $fields ?: array(
            'ssf_application_main_image' => array('jpg', 'jpeg', 'png', 'webp'),
            'ssf_application_gallery' => array('jpg', 'jpeg', 'png', 'webp'),
            'ssf_application_documents' => array('jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx'),
        );
        foreach ($fields as $field => $allowed) {
            if (empty($_FILES[$field]['name'])) {
                continue;
            }
            $files = $_FILES[$field];
            if (! is_array($files['name'])) {
                foreach (array('name', 'type', 'tmp_name', 'error', 'size') as $part) {
                    $files[$part] = array($files[$part]);
                }
            }
            if ('ssf_application_gallery' === $field && count(array_filter((array) $files['name'])) > 10) {
                $errors->add('too_many_images', 'Du kan ladda upp högst 10 övriga bilder.');
            }
            foreach ((array) $files['name'] as $index => $name) {
                if (UPLOAD_ERR_NO_FILE === (int) $files['error'][$index]) {
                    continue;
                }
                if (UPLOAD_ERR_OK !== (int) $files['error'][$index]) {
                    $errors->add('upload_error', sprintf('Filen %s kunde inte tas emot.', sanitize_file_name((string) $name)));
                    continue;
                }
                $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
                if (! in_array($extension, $allowed, true)) {
                    $errors->add('file_type', sprintf('Filtypen för %s är inte tillåten.', sanitize_file_name((string) $name)));
                    continue;
                }
                $is_document = in_array($extension, array('pdf', 'doc', 'docx'), true);
                $max_bytes = (int) ($is_document ? $settings['max_file_mb'] : $settings['max_image_mb']) * MB_IN_BYTES;
                if ((int) $files['size'][$index] > $max_bytes) {
                    $errors->add('file_size', sprintf('Filen %s är för stor.', sanitize_file_name((string) $name)));
                    continue;
                }
                $checked = wp_check_filetype_and_ext((string) $files['tmp_name'][$index], sanitize_file_name((string) $name));
                if (empty($checked['ext']) || ! in_array(strtolower((string) $checked['ext']), $allowed, true)) {
                    $errors->add('file_content', sprintf('Innehållet i %s stämmer inte med en tillåten filtyp.', sanitize_file_name((string) $name)));
                }
            }
        }
        return $errors;
    }

    private function redirect_to_confirmation(string $token, bool $mail_sent): void
    {
        wp_safe_redirect(SSF_Medlemsprocess_Plugin::page_url('ansokan', array(
            'ssf_application_sent' => '1',
            'token' => rawurlencode($token),
            'ssf_mail' => $mail_sent ? 'sent' : 'failed',
        )));
        exit;
    }

    private function assert_nonce(string $action): void
    {
        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), $action)) {
            wp_die('Sessionen har gått ut. Ladda om sidan och försök igen.');
        }
    }

    private function rate_key(): string
    {
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return 'ssf_application_rate_' . md5($ip);
    }

    private function applications_enabled(): bool
    {
        return ! class_exists('SSF_Feature_Manager') || SSF_Feature_Manager::can_access('applications');
    }
}
