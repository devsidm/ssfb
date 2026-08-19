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
    }

    public function register_shortcodes(): void
    {
        add_shortcode('ssf_application_form', array($this, 'application_form'));
        add_shortcode('ssf_application_status', array($this, 'status_page'));
    }

    public function application_form(): string
    {
        if (! empty($_GET['ssf_application_sent']) && ! empty($_GET['token'])) {
            $status_link = SSF_Medlemsprocess_Application::status_link(sanitize_text_field(wp_unslash($_GET['token'])));
            $mail_sent = 'sent' === sanitize_key(wp_unslash($_GET['ssf_mail'] ?? ''));
            $message = $mail_sent
                ? 'Vi har skickat en bekräftelse till din e-postadress. Du kan följa ärendet med den personliga statuslänken.'
                : 'Ansökan är registrerad, men vi kunde inte bekräfta e-postleveransen. Spara den personliga statuslänken och kontakta SSF om du behöver hjälp.';
            return '<section class="ssf-process-shell ssf-process-confirmation"><p class="ssf-process-eyebrow">Ansökan mottagen</p><h1>Tack för din ansökan</h1><p>' . esc_html($message) . '</p><p><a class="ssf-process-button" href="' . esc_url($status_link) . '">Följ ansökan</a></p></section>';
        }

        $settings = SSF_Medlemsprocess_Plugin::settings();
        ob_start();
        include SSF_MEDLEMSPROCESS_PATH . 'templates/application-form.php';
        return ob_get_clean();
    }

    public function status_page(): string
    {
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
        ob_start();
        include SSF_MEDLEMSPROCESS_PATH . 'templates/status-page.php';
        return ob_get_clean();
    }

    public function submit_application(): void
    {
        $this->assert_nonce('ssf_application_submit');
        if (! empty($_POST['website'])) {
            wp_die('Formuläret kunde inte skickas.');
        }
        if (! empty(get_transient($this->rate_key()))) {
            wp_die('För många försök. Vänta en stund och försök igen.');
        }
        if (empty($_POST['confirm_accuracy']) || empty($_POST['privacy_consent']) || empty($_POST['upload_rights'])) {
            wp_die('Du behöver bekräfta uppgifterna och samtycket innan ansökan kan skickas.');
        }

        $data = $this->collect_application_data();
        if (! $data['ship_name'] || ! is_email($data['applicant_email']) || ! $data['applicant_name']) {
            wp_die('Fyll i fartygsnamn, namn och en giltig e-postadress.');
        }
        $created = SSF_Medlemsprocess_Application::create($data);
        if (! $created['id']) {
            wp_die('Ansökan kunde inte sparas. Försök igen eller kontakta SSF.');
        }
        $files = $this->handle_uploads($created['id'], 'ssf_application_files');
        update_post_meta($created['id'], '_ssf_application_files', $files);
        set_transient($this->rate_key(), 1, MINUTE_IN_SECONDS * 2);
        $mail_sent = SSF_Medlemsprocess_Plugin::instance()->emails->send_received($created['id'], $created['token']);
        wp_safe_redirect(SSF_Medlemsprocess_Plugin::page_url('ansokan', array('ssf_application_sent' => '1', 'token' => rawurlencode($created['token']), 'ssf_mail' => $mail_sent ? 'sent' : 'failed')));
        exit;
    }

    public function submit_completion(): void
    {
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $application_id = SSF_Medlemsprocess_Application::find_by_token($token);
        if (! $application_id || ! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'ssf_application_completion_' . $application_id)) {
            wp_die('Länken är ogiltig eller har gått ut.');
        }
        $message = sanitize_textarea_field(wp_unslash($_POST['completion_message'] ?? ''));
        if (! $message && empty($_FILES['ssf_completion_files']['name'][0])) {
            wp_die('Skriv ett svar eller bifoga en fil innan du skickar kompletteringen.');
        }
        $files = $this->handle_uploads($application_id, 'ssf_completion_files');
        $all_files = array_merge((array) get_post_meta($application_id, '_ssf_completion_files', true), $files);
        update_post_meta($application_id, '_ssf_completion_files', array_map('intval', $all_files));
        SSF_Medlemsprocess_Application::add_history($application_id, 'completion', $message ?: 'Kompletterande filer skickades in.', true, array('files' => $files));
        SSF_Medlemsprocess_Application::transition($application_id, 'completion_submitted', '', false);
        $new_token = SSF_Medlemsprocess_Application::issue_token($application_id);
        SSF_Medlemsprocess_Plugin::instance()->emails->send_template('completion_received', $application_id, array('status_link' => SSF_Medlemsprocess_Application::status_link($new_token)));
        wp_safe_redirect(SSF_Medlemsprocess_Application::status_link($new_token));
        exit;
    }

    private function collect_application_data(): array
    {
        $fields = array(
            'application_path', 'ship_is_sailing', 'ship_professional_use', 'ship_traditional_newbuild',
            'ship_length', 'ship_beam', 'ship_draft', 'ship_register', 'ship_registry_number', 'ship_name',
            'ship_type', 'ship_rig', 'ship_build_year', 'ship_shipyard', 'ship_home_port', 'ship_restoration',
            'ship_short_description', 'ship_history', 'ship_current_use', 'applicant_name', 'applicant_phone',
            'ship_description', 'applicant_organization', 'applicant_address', 'applicant_website',
        );
        $data = array();
        foreach ($fields as $field) {
            $data[$field] = sanitize_textarea_field(wp_unslash($_POST[$field] ?? ''));
        }
        $data['applicant_email'] = sanitize_email(wp_unslash($_POST['applicant_email'] ?? ''));
        $data['confirm_accuracy'] = '1';
        $data['privacy_consent'] = '1';
        $data['upload_rights'] = '1';
        return $data;
    }

    private function handle_uploads(int $application_id, string $field): array
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
            $file = array(
                'name' => sanitize_file_name((string) $name), 'type' => (string) $files['type'][$index],
                'tmp_name' => (string) $files['tmp_name'][$index], 'error' => (int) $files['error'][$index], 'size' => (int) $files['size'][$index],
            );
            $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
            if (empty($checked['ext']) || ! in_array(strtolower($checked['ext']), array('jpg', 'jpeg', 'png', 'webp', 'pdf'), true)) {
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
}
