<?php
/**
 * Public token-based collection form.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsfartyg_Public_Form
{
    public function __construct()
    {
        add_shortcode('ssf_fartygsuppgifter_form', array($this, 'shortcode'));
        add_action('admin_post_nopriv_ssf_submit_ship_collection', array($this, 'handle_submit'));
        add_action('admin_post_ssf_submit_ship_collection', array($this, 'handle_submit'));
    }

    public function shortcode(): string
    {
        if (! empty($_GET['ssf_sent'])) {
            $settings = SSF_Medlemsfartyg_Plugin::settings();
            return '<div class="ssf-collection-form"><h1>' . esc_html__('Tack!', 'ssf-medlemsfartyg') . '</h1><p>' . esc_html($settings['thank_you_text']) . '</p></div>';
        }

        $token_value = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $token = $token_value ? SSF_Medlemsfartyg_Tokens::get_by_token($token_value) : null;
        if (! $token) {
            return '<div class="ssf-collection-form ssf-collection-error"><h1>' . esc_html__('Länken är ogiltig eller har gått ut.', 'ssf-medlemsfartyg') . '</h1><p>' . esc_html__('Kontakta SSF för en ny länk.', 'ssf-medlemsfartyg') . '</p></div>';
        }

        if ('created' === $token->status || 'sent' === $token->status) {
            SSF_Medlemsfartyg_Tokens::update_status((int) $token->id, 'opened');
        }

        $ship_id = (int) $token->ship_id;
        $settings = SSF_Medlemsfartyg_Plugin::settings();
        ob_start();
        include SSF_MEDLEMSFARTYG_PATH . 'templates/public-collection-form.php';
        return ob_get_clean();
    }

    public function handle_submit(): void
    {
        $token_value = isset($_POST['ssf_token']) ? sanitize_text_field(wp_unslash($_POST['ssf_token'])) : '';
        $token = $token_value ? SSF_Medlemsfartyg_Tokens::get_by_token($token_value) : null;
        if (! $token || ! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'ssf_collect_ship_' . $token->id)) {
            wp_die(esc_html__('Länken är ogiltig eller har gått ut. Kontakta SSF för en ny länk.', 'ssf-medlemsfartyg'));
        }

        if (! empty($_POST['website'])) {
            wp_die(esc_html__('Formuläret kunde inte skickas.', 'ssf-medlemsfartyg'));
        }

        if (empty($_POST['_ssf_gdpr_consent']) || empty($_POST['_ssf_image_consent'])) {
            wp_die(esc_html__('Du behöver godkänna integritetstexten och bildvillkoren.', 'ssf-medlemsfartyg'));
        }

        $ship_id = (int) $token->ship_id;
        $route = (string) get_post_meta($ship_id, '_ssf_application_route', true);
        $data = $this->collect_data($route);
        $errors = SSF_Medlemsfartyg_Profile::validate($data, $route, SSF_Medlemsfartyg_Profile::MODE_UPDATE);
        if ($errors->has_errors()) {
            wp_die(esc_html(implode(' ', $errors->get_error_messages())));
        }
        $image_ids = $this->handle_uploads($ship_id);
        $featured_index = max(0, (int) ($_POST['featured_image_index'] ?? 0));
        $existing_featured = (int) ($_POST['existing_featured_image'] ?? 0);
        $allowed_existing = array_filter(array_map('intval', array_merge(
            array((int) get_post_thumbnail_id($ship_id)),
            explode(',', (string) get_post_meta($ship_id, '_ssf_gallery_ids', true))
        )));
        $featured_id = $existing_featured > 0 && in_array($existing_featured, $allowed_existing, true)
            ? $existing_featured
            : ($image_ids[$featured_index] ?? ($image_ids[0] ?? 0));

        $submission_id = SSF_Medlemsfartyg_Submissions::create($ship_id, (int) $token->id, $data, $image_ids, $featured_id);
        SSF_Medlemsfartyg_Tokens::update_status((int) $token->id, 'submitted');

        $internal_subject = 'Nya fartygsuppgifter inskickade - ' . get_the_title($ship_id);
        $internal_body = 'Nya uppgifter har skickats in för ' . get_the_title($ship_id) . ". Granska uppgifterna i WordPress:\n" . get_edit_post_link($submission_id, '');
        $internal_headers = array('Content-Type: text/plain; charset=UTF-8');
        SSF_Email_Router::send_to_function('vessel_update', $internal_subject, $internal_body, $internal_headers);

        if (is_email($data['_ssf_email'] ?? '')) {
            wp_mail(
                $data['_ssf_email'],
                'Tack - uppgifter om ' . get_the_title($ship_id) . ' har skickats till SSF',
                'Tack för att du skickat in uppgifter och bilder. SSF granskar materialet innan publicering.',
                array('Content-Type: text/plain; charset=UTF-8')
            );
        }

        wp_safe_redirect(add_query_arg('ssf_sent', '1', home_url('/fartygsuppgifter/')));
        exit;
    }

    private function collect_data(string $route): array
    {
        return SSF_Medlemsfartyg_Profile::collect($_POST, $route, SSF_Medlemsfartyg_Profile::MODE_UPDATE);
    }

    private function handle_uploads(int $ship_id): array
    {
        if (empty($_FILES['ssf_ship_images']['name'][0])) {
            return array();
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $settings = SSF_Medlemsfartyg_Plugin::settings();
        $max_images = (int) $settings['max_images'];
        $max_bytes = (int) $settings['max_image_mb'] * MB_IN_BYTES;
        $allowed = array_map('trim', explode(',', strtolower((string) $settings['allowed_image_types'])));
        $ids = array();
        $files = $_FILES['ssf_ship_images'];
        $count = min(count((array) $files['name']), $max_images);

        for ($i = 0; $i < $count; $i++) {
            if ((int) $files['error'][$i] !== UPLOAD_ERR_OK || (int) $files['size'][$i] > $max_bytes) {
                continue;
            }
            $ext = strtolower(pathinfo((string) $files['name'][$i], PATHINFO_EXTENSION));
            if (! in_array($ext, $allowed, true)) {
                continue;
            }

            $file = array(
                'name' => sanitize_file_name((string) $files['name'][$i]),
                'type' => (string) $files['type'][$i],
                'tmp_name' => (string) $files['tmp_name'][$i],
                'error' => (int) $files['error'][$i],
                'size' => (int) $files['size'][$i],
            );
            $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
            if (empty($check['ext']) || ! in_array(strtolower($check['ext']), $allowed, true)) {
                continue;
            }

            $_FILES['ssf_single_ship_image'] = $file;
            $attachment_id = media_handle_upload('ssf_single_ship_image', $ship_id);
            if (! is_wp_error($attachment_id)) {
                $caption = sanitize_text_field(wp_unslash($_POST['image_caption'][$i] ?? ''));
                $alt = sanitize_text_field(wp_unslash($_POST['image_alt'][$i] ?? ''));
                if ($caption) {
                    wp_update_post(array('ID' => $attachment_id, 'post_excerpt' => $caption));
                }
                if ($alt) {
                    update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
                }
                $ids[] = (int) $attachment_id;
            }
        }

        unset($_FILES['ssf_single_ship_image']);
        return $ids;
    }
}
