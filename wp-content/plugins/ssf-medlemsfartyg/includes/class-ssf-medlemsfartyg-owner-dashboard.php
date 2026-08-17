<?php
/**
 * Owner dashboard.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsfartyg_Owner_Dashboard
{
    public function __construct()
    {
        add_action('admin_post_ssf_save_owner_ship', array($this, 'save_owner_ship'));
    }

    public function render(): void
    {
        if (! is_user_logged_in()) {
            wp_die(esc_html__('Du behöver vara inloggad.', 'ssf-medlemsfartyg'));
        }

        $ship_id = isset($_GET['ship_id']) ? (int) $_GET['ship_id'] : 0;
        if ($ship_id) {
            $this->render_edit($ship_id);
            return;
        }

        $this->render_list();
    }

    private function editable_ship_ids(): array
    {
        if (current_user_can('manage_options')) {
            $posts = get_posts(array('post_type' => 'medlemsfartyg', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids'));
            return array_map('intval', $posts);
        }

        $posts = get_posts(
            array(
                'post_type' => 'medlemsfartyg',
                'post_status' => 'any',
                'numberposts' => -1,
                'fields' => 'ids',
                'meta_query' => array(
                    array(
                        'key' => '_ssf_ship_owner_users',
                        'value' => '"' . get_current_user_id() . '"',
                        'compare' => 'LIKE',
                    ),
                ),
            )
        );

        return array_map('intval', $posts);
    }

    private function render_list(): void
    {
        $ids = $this->editable_ship_ids();
        include SSF_Medlemsfartyg_Templates::locate('owner-dashboard.php');
    }

    private function render_edit(int $ship_id): void
    {
        if (! SSF_Medlemsfartyg_Roles::user_can_edit_ship(get_current_user_id(), $ship_id) && ! current_user_can('manage_options')) {
            wp_die(esc_html__('Du kan inte redigera det här fartyget.', 'ssf-medlemsfartyg'));
        }

        $post = get_post($ship_id);
        include SSF_Medlemsfartyg_Templates::locate('owner-edit-form.php');
    }

    public function save_owner_ship(): void
    {
        $ship_id = isset($_POST['ship_id']) ? (int) $_POST['ship_id'] : 0;
        if (! $ship_id || ! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'ssf_save_owner_ship_' . $ship_id)) {
            wp_die(esc_html__('Formuläret kunde inte verifieras.', 'ssf-medlemsfartyg'));
        }

        if (! SSF_Medlemsfartyg_Roles::user_can_edit_ship(get_current_user_id(), $ship_id) && ! current_user_can('manage_options')) {
            wp_die(esc_html__('Du kan inte redigera det här fartyget.', 'ssf-medlemsfartyg'));
        }

        wp_update_post(
            array(
                'ID' => $ship_id,
                'post_title' => sanitize_text_field(wp_unslash($_POST['post_title'] ?? '')),
                'post_excerpt' => sanitize_textarea_field(wp_unslash($_POST['post_excerpt'] ?? '')),
                'post_content' => wp_kses_post(wp_unslash($_POST['post_content'] ?? '')),
            )
        );

        SSF_Medlemsfartyg_Meta::save_fields_from_request($ship_id, $_POST);

        $settings = SSF_Medlemsfartyg_Plugin::settings();
        if (! current_user_can('manage_options') && 'yes' === $settings['require_review']) {
            update_post_meta($ship_id, '_ssf_review_status', 'Väntar på granskning');
        } else {
            update_post_meta($ship_id, '_ssf_review_status', 'Publicerad');
        }

        SSF_Medlemsfartyg_Plugin::instance()->notifications->send_owner_update_notice($ship_id, get_current_user_id());

        wp_safe_redirect(add_query_arg(array('page' => 'ssf-mina-fartyg', 'ship_id' => $ship_id, 'updated' => '1'), admin_url('edit.php?post_type=medlemsfartyg')));
        exit;
    }
}
