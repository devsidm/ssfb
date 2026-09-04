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
        $sections = array();
        foreach (SSF_Medlemsfartyg_Profile::sections() as $key => $section) {
            $sections[$key] = array('label' => $section['label'], 'fields' => array());
        }
        foreach (SSF_Medlemsfartyg_Profile::fields_for('', SSF_Medlemsfartyg_Profile::MODE_ADMIN) as $key => $field) {
            if (0 === strpos($key, 'post_') || 0 === strpos($key, 'tax_')) {
                continue;
            }
            $sections[$field['section']]['fields'][$key] = $field;
        }

        $sections['basic']['fields'] = array_merge(array(
            '_ssf_short_name' => array('label' => 'Kortnamn', 'type' => 'text'),
        ), $sections['basic']['fields']);
        $sections['basic']['fields']['_ssf_passengers'] = array('label' => 'Antal passagerare', 'type' => 'number');
        $sections['presentation']['fields'] = array_merge($sections['presentation']['fields'], array(
            '_ssf_other_info' => array('label' => 'Övrig information', 'type' => 'wysiwyg'),
            '_ssf_gallery_ids' => array('label' => 'Bildgalleri', 'type' => 'gallery'),
            '_ssf_booking_link' => array('label' => 'Bokning eller seglingsprogram', 'type' => 'url'),
            '_ssf_history_link' => array('label' => 'Mer historik', 'type' => 'url'),
            '_ssf_pdf_link' => array('label' => 'PDF/broschyr', 'type' => 'url'),
        ));
        $sections['contact']['fields']['_ssf_public_contact'] = array('label' => 'Visa namn och organisation publikt', 'type' => 'checkbox');
        $sections['contact']['fields']['_ssf_contact_button_text'] = array('label' => 'Kontaktknappstext', 'type' => 'text');
        $route_options = array('' => 'Inte angiven');
        foreach (SSF_Medlemsfartyg_Profile::routes() as $route_key => $route) {
            $route_options[$route_key] = $route['title'];
        }
        $sections['publishing'] = array(
            'label' => 'Publicering och ansökningsrelation',
            'fields' => array(
                '_ssf_public_visibility' => array('label' => 'Synlighet', 'type' => 'select', 'options' => array('draft' => 'Utkast', 'review' => 'Väntar på granskning', 'public' => 'Publik', 'hidden' => 'Dold')),
                '_ssf_application_route' => array('label' => 'Ansökningsväg', 'type' => 'select', 'options' => $route_options),
                '_ssf_show_in_archive' => array('label' => 'Visa på samlingssidan', 'type' => 'checkbox'),
                '_ssf_featured_ship' => array('label' => 'Prioriterat fartyg', 'type' => 'checkbox'),
                '_ssf_sort_order' => array('label' => 'Sorteringsordning', 'type' => 'number'),
            ),
        );
        return array_filter($sections, static function (array $section): bool { return ! empty($section['fields']); });
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
        $application_id = (int) get_post_meta($post->ID, '_ssf_source_application_id', true);
        echo '<p><strong>' . esc_html__('Granskningsstatus:', 'ssf-medlemsfartyg') . '</strong><br>' . esc_html($status) . '</p>';
        if ($application_id) {
            echo '<p><a href="' . esc_url(get_edit_post_link($application_id)) . '">' . esc_html__('Öppna kopplad ansökan', 'ssf-medlemsfartyg') . '</a></p>';
        }
        echo '<p class="description">För publicering: välj synlighet Publik under Fartygsdata och publicera därefter WordPress-posten.</p>';
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
        } elseif ('select' === $field['type']) {
            echo '<select name="' . esc_attr($key) . '">';
            foreach ((array) ($field['options'] ?? array()) as $option_value => $option_label) {
                echo '<option value="' . esc_attr((string) $option_value) . '" ' . selected((string) $value, (string) $option_value, false) . '>' . esc_html($option_label) . '</option>';
            }
            echo '</select>';
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

    public static function save_fields_from_request(int $post_id, array $source, bool $preserve_missing = false, string $updated_source = ''): void
    {
        foreach (self::fields() as $section) {
            foreach ($section['fields'] as $key => $field) {
                if ($preserve_missing && ! array_key_exists($key, $source)) {
                    continue;
                }

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
        update_post_meta($post_id, '_ssf_profile_updated_at', current_time('mysql'));
        update_post_meta($post_id, '_ssf_profile_updated_source', $updated_source ?: (is_admin() ? 'admin' : 'portal'));
    }
}
