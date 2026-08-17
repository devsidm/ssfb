<?php
/**
 * Ship metadata.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsfartyg_Meta
{
    public static function fields(): array
    {
        return array(
            'grunduppgifter' => array(
                'label' => __('Grunduppgifter', 'ssf-medlemsfartyg'),
                'fields' => array(
                    '_ssf_short_name' => array('label' => 'Kortnamn', 'type' => 'text'),
                    '_ssf_home_port' => array('label' => 'Hemmahamn', 'type' => 'text'),
                    '_ssf_registry_number' => array('label' => 'Registernummer', 'type' => 'text'),
                    '_ssf_call_sign' => array('label' => 'Signalbokstäver', 'type' => 'text'),
                    '_ssf_mmsi' => array('label' => 'MMSI', 'type' => 'text'),
                    '_ssf_show_in_archive' => array('label' => 'Visa på samlingssidan', 'type' => 'checkbox'),
                    '_ssf_featured_ship' => array('label' => 'Prioriterat fartyg', 'type' => 'checkbox'),
                    '_ssf_sort_order' => array('label' => 'Sorteringsordning', 'type' => 'number'),
                ),
            ),
            'technical' => array(
                'label' => __('Mått och teknisk info', 'ssf-medlemsfartyg'),
                'fields' => array(
                    '_ssf_build_year' => array('label' => 'Byggår', 'type' => 'number'),
                    '_ssf_shipyard' => array('label' => 'Byggplats / varv', 'type' => 'text'),
                    '_ssf_length' => array('label' => 'Längd', 'type' => 'text'),
                    '_ssf_beam' => array('label' => 'Bredd', 'type' => 'text'),
                    '_ssf_draft' => array('label' => 'Djupgående', 'type' => 'text'),
                    '_ssf_displacement' => array('label' => 'Deplacement', 'type' => 'text'),
                    '_ssf_rig' => array('label' => 'Rigtyp', 'type' => 'text'),
                    '_ssf_material' => array('label' => 'Material', 'type' => 'text'),
                    '_ssf_engine' => array('label' => 'Motor', 'type' => 'text'),
                    '_ssf_sail_area' => array('label' => 'Segelyta', 'type' => 'text'),
                    '_ssf_passengers' => array('label' => 'Antal passagerare', 'type' => 'number'),
                ),
            ),
            'contact' => array(
                'label' => __('Fartygsombud / ägare', 'ssf-medlemsfartyg'),
                'fields' => array(
                    '_ssf_contact_name' => array('label' => 'Namn på fartygsombud', 'type' => 'text'),
                    '_ssf_organization' => array('label' => 'Organisation / rederi / förening', 'type' => 'text'),
                    '_ssf_email' => array('label' => 'E-post', 'type' => 'email'),
                    '_ssf_phone' => array('label' => 'Telefon', 'type' => 'text'),
                    '_ssf_website' => array('label' => 'Webbplats', 'type' => 'url'),
                    '_ssf_facebook' => array('label' => 'Facebook', 'type' => 'url'),
                    '_ssf_instagram' => array('label' => 'Instagram', 'type' => 'url'),
                    '_ssf_other_link' => array('label' => 'Annan länk', 'type' => 'url'),
                    '_ssf_public_contact' => array('label' => 'Visa kontaktuppgifter publikt', 'type' => 'checkbox'),
                    '_ssf_contact_button_text' => array('label' => 'Kontaktknappstext', 'type' => 'text'),
                ),
            ),
            'presentation' => array(
                'label' => __('Presentation', 'ssf-medlemsfartyg'),
                'fields' => array(
                    '_ssf_short_presentation' => array('label' => 'Kort presentation', 'type' => 'textarea'),
                    '_ssf_today' => array('label' => 'Vad gör fartyget idag?', 'type' => 'wysiwyg'),
                    '_ssf_history' => array('label' => 'Historia om fartyget', 'type' => 'wysiwyg'),
                    '_ssf_activity' => array('label' => 'Verksamhet', 'type' => 'wysiwyg'),
                    '_ssf_future' => array('label' => 'Kommande planer', 'type' => 'wysiwyg'),
                    '_ssf_other_info' => array('label' => 'Övrig information', 'type' => 'wysiwyg'),
                    '_ssf_gallery_ids' => array('label' => 'Bildgalleri', 'type' => 'gallery'),
                    '_ssf_booking_link' => array('label' => 'Bokning eller seglingsprogram', 'type' => 'url'),
                    '_ssf_history_link' => array('label' => 'Mer historik', 'type' => 'url'),
                    '_ssf_pdf_link' => array('label' => 'PDF/broschyr', 'type' => 'url'),
                ),
            ),
        );
    }

    public function __construct()
    {
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post_medlemsfartyg', array($this, 'save'), 10, 2);
    }

    public function add_meta_boxes(): void
    {
        add_meta_box('ssf_ship_data', __('Fartygsdata', 'ssf-medlemsfartyg'), array($this, 'render_ship_data_box'), 'medlemsfartyg', 'normal', 'high');
        add_meta_box('ssf_ship_owners', __('Fartygsombud med åtkomst', 'ssf-medlemsfartyg'), array($this, 'render_owners_box'), 'medlemsfartyg', 'side');
        add_meta_box('ssf_ship_review', __('Granskning', 'ssf-medlemsfartyg'), array($this, 'render_review_box'), 'medlemsfartyg', 'side');
    }

    public function render_ship_data_box(WP_Post $post): void
    {
        wp_nonce_field('ssf_ship_meta', 'ssf_ship_meta_nonce');

        foreach (self::fields() as $section) {
            echo '<section class="ssf-admin-section"><h3>' . esc_html($section['label']) . '</h3><div class="ssf-admin-grid">';
            foreach ($section['fields'] as $key => $field) {
                $this->render_field($post->ID, $key, $field);
            }
            echo '</div></section>';
        }
    }

    public function render_owners_box(WP_Post $post): void
    {
        $owners = array_map('intval', (array) get_post_meta($post->ID, '_ssf_ship_owner_users', true));
        $users = get_users(array('role__in' => array('ssf_fartygsombud', 'administrator'), 'fields' => array('ID', 'display_name', 'user_email')));

        echo '<p>' . esc_html__('Välj ett eller flera användarkonton som får redigera fartyget.', 'ssf-medlemsfartyg') . '</p>';
        foreach ($users as $user) {
            printf(
                '<label class="ssf-check-row"><input type="checkbox" name="ssf_ship_owner_users[]" value="%d" %s> %s <small>%s</small></label>',
                (int) $user->ID,
                checked(in_array((int) $user->ID, $owners, true), true, false),
                esc_html($user->display_name),
                esc_html($user->user_email)
            );
        }
    }

    public function render_review_box(WP_Post $post): void
    {
        $status = get_post_meta($post->ID, '_ssf_review_status', true) ?: __('Publicerad', 'ssf-medlemsfartyg');
        echo '<p><strong>' . esc_html__('Granskningsstatus:', 'ssf-medlemsfartyg') . '</strong><br>' . esc_html($status) . '</p>';
        echo '<p><label><input type="checkbox" name="ssf_review_approved" value="1"> ' . esc_html__('Markera som granskad', 'ssf-medlemsfartyg') . '</label></p>';
    }

    private function render_field(int $post_id, string $key, array $field): void
    {
        $value = get_post_meta($post_id, $key, true);
        echo '<label><span>' . esc_html($field['label']) . '</span>';
        if ('textarea' === $field['type']) {
            printf('<textarea name="%s" rows="4">%s</textarea>', esc_attr($key), esc_textarea((string) $value));
        } elseif ('wysiwyg' === $field['type']) {
            printf('<textarea class="ssf-rich" name="%s" rows="6">%s</textarea>', esc_attr($key), esc_textarea((string) $value));
        } elseif ('checkbox' === $field['type']) {
            printf('<input type="checkbox" name="%s" value="1" %s>', esc_attr($key), checked('1', (string) $value, false));
        } elseif ('gallery' === $field['type']) {
            printf('<input class="ssf-gallery-field" type="text" name="%s" value="%s"><button type="button" class="button ssf-gallery-button">%s</button>', esc_attr($key), esc_attr((string) $value), esc_html__('Välj bilder', 'ssf-medlemsfartyg'));
        } else {
            printf('<input type="%s" name="%s" value="%s">', esc_attr($field['type']), esc_attr($key), esc_attr((string) $value));
        }
        echo '</label>';
    }

    public function save(int $post_id, WP_Post $post): void
    {
        if (! isset($_POST['ssf_ship_meta_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_ship_meta_nonce'])), 'ssf_ship_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (! current_user_can('edit_ssf_ship', $post_id) && ! current_user_can('manage_options')) {
            return;
        }

        self::save_fields_from_request($post_id, $_POST);

        $owners = isset($_POST['ssf_ship_owner_users']) ? array_map('intval', (array) wp_unslash($_POST['ssf_ship_owner_users'])) : array();
        update_post_meta($post_id, '_ssf_ship_owner_users', array_values(array_filter($owners)));

        if (isset($_POST['ssf_review_approved'])) {
            update_post_meta($post_id, '_ssf_review_status', 'Publicerad');
        }
    }

    public static function save_fields_from_request(int $post_id, array $source): void
    {
        foreach (self::fields() as $section) {
            foreach ($section['fields'] as $key => $field) {
                $value = isset($source[$key]) ? wp_unslash($source[$key]) : '';
                if ('checkbox' === $field['type']) {
                    update_post_meta($post_id, $key, isset($source[$key]) ? '1' : '0');
                    continue;
                }
                if ('email' === $field['type']) {
                    $clean = sanitize_email((string) $value);
                } elseif ('url' === $field['type']) {
                    $clean = esc_url_raw((string) $value);
                } elseif (in_array($field['type'], array('textarea', 'wysiwyg'), true)) {
                    $clean = wp_kses_post((string) $value);
                } else {
                    $clean = sanitize_text_field((string) $value);
                }
                update_post_meta($post_id, $key, $clean);
            }
        }
    }
}
