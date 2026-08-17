<?php
/**
 * Submission review flow.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsfartyg_Submissions
{
    public function __construct()
    {
        add_action('add_meta_boxes_ssf_ship_submission', array($this, 'add_meta_boxes'));
        add_action('admin_post_ssf_approve_ship_submission', array($this, 'approve'));
        add_action('admin_post_ssf_reject_ship_submission', array($this, 'reject'));
    }

    public static function create(int $ship_id, int $token_id, array $data, array $image_ids, int $featured_id): int
    {
        $submission_id = wp_insert_post(
            array(
                'post_type' => 'ssf_ship_submission',
                'post_status' => 'private',
                'post_title' => 'Inskickade uppgifter - ' . get_the_title($ship_id),
                'post_content' => wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            )
        );

        update_post_meta($submission_id, '_ssf_ship_id', $ship_id);
        update_post_meta($submission_id, '_ssf_token_id', $token_id);
        update_post_meta($submission_id, '_ssf_submission_data', $data);
        update_post_meta($submission_id, '_ssf_submission_images', $image_ids);
        update_post_meta($submission_id, '_ssf_submission_featured_image', $featured_id);
        update_post_meta($submission_id, '_ssf_submission_status', 'pending_review');

        return (int) $submission_id;
    }

    public function add_meta_boxes(): void
    {
        add_meta_box('ssf_submission_review', __('Granska inskickade uppgifter', 'ssf-medlemsfartyg'), array($this, 'render_review'), 'ssf_ship_submission', 'normal', 'high');
    }

    public function render_review(WP_Post $post): void
    {
        $ship_id = (int) get_post_meta($post->ID, '_ssf_ship_id', true);
        $data = (array) get_post_meta($post->ID, '_ssf_submission_data', true);
        $images = array_map('intval', (array) get_post_meta($post->ID, '_ssf_submission_images', true));
        $featured = (int) get_post_meta($post->ID, '_ssf_submission_featured_image', true);
        echo '<p><strong>' . esc_html__('Fartyg:', 'ssf-medlemsfartyg') . '</strong> <a href="' . esc_url(get_edit_post_link($ship_id)) . '">' . esc_html(get_the_title($ship_id)) . '</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Fält', 'ssf-medlemsfartyg') . '</th><th>' . esc_html__('Nuvarande', 'ssf-medlemsfartyg') . '</th><th>' . esc_html__('Inskickat', 'ssf-medlemsfartyg') . '</th></tr></thead><tbody>';
        foreach ($data as $key => $value) {
            if (0 === strpos($key, 'tax_') || in_array($key, array('post_title', 'post_excerpt', 'post_content'), true)) {
                $current = in_array($key, array('post_title', 'post_excerpt', 'post_content'), true) ? get_post_field(str_replace('post_', 'post_', $key), $ship_id) : '';
            } else {
                $current = get_post_meta($ship_id, $key, true);
            }
            echo '<tr><td>' . esc_html($key) . '</td><td>' . esc_html(wp_strip_all_tags((string) $current)) . '</td><td>' . wp_kses_post(wpautop((string) $value)) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ($images) {
            echo '<h3>' . esc_html__('Bilder', 'ssf-medlemsfartyg') . '</h3><div class="ssf-submission-images">';
            foreach ($images as $image_id) {
                echo '<label><input type="radio" form="ssf-approve-submission" name="featured_image" value="' . esc_attr((string) $image_id) . '" ' . checked($featured, $image_id, false) . '> ' . wp_get_attachment_image($image_id, 'thumbnail') . '</label>';
            }
            echo '</div>';
        }
        ?>
        <form id="ssf-approve-submission" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ssf_approve_ship_submission">
            <input type="hidden" name="submission_id" value="<?php echo esc_attr((string) $post->ID); ?>">
            <?php wp_nonce_field('ssf_review_submission_' . $post->ID); ?>
            <?php submit_button(__('Godkänn alla ändringar', 'ssf-medlemsfartyg')); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ssf_reject_ship_submission">
            <input type="hidden" name="submission_id" value="<?php echo esc_attr((string) $post->ID); ?>">
            <?php wp_nonce_field('ssf_review_submission_' . $post->ID); ?>
            <label><?php esc_html_e('Intern kommentar', 'ssf-medlemsfartyg'); ?><textarea name="review_note" rows="3"></textarea></label>
            <?php submit_button(__('Avvisa', 'ssf-medlemsfartyg'), 'delete'); ?>
        </form>
        <?php
    }

    public function approve(): void
    {
        $submission_id = (int) ($_POST['submission_id'] ?? 0);
        $this->assert_review_request($submission_id);
        $ship_id = (int) get_post_meta($submission_id, '_ssf_ship_id', true);
        $data = (array) get_post_meta($submission_id, '_ssf_submission_data', true);
        $images = array_map('intval', (array) get_post_meta($submission_id, '_ssf_submission_images', true));
        $featured = (int) ($_POST['featured_image'] ?? get_post_meta($submission_id, '_ssf_submission_featured_image', true));

        wp_update_post(array(
            'ID' => $ship_id,
            'post_title' => sanitize_text_field($data['post_title'] ?? get_the_title($ship_id)),
            'post_excerpt' => sanitize_textarea_field($data['post_excerpt'] ?? ''),
            'post_content' => wp_kses_post($data['post_content'] ?? ''),
        ));
        SSF_Medlemsfartyg_Meta::save_fields_from_request($ship_id, $data);
        foreach (array('fartygstyp', 'fartygsstatus', 'fartygsregion', 'fartygsanvandning') as $taxonomy) {
            if (! empty($data['tax_' . $taxonomy])) {
                wp_set_object_terms($ship_id, array_map('sanitize_text_field', (array) $data['tax_' . $taxonomy]), $taxonomy, false);
            }
        }
        if ($featured) {
            set_post_thumbnail($ship_id, $featured);
        }
        update_post_meta($ship_id, '_ssf_gallery_ids', implode(',', array_diff($images, array($featured))));
        update_post_meta($submission_id, '_ssf_submission_status', 'approved');
        update_post_meta($submission_id, '_ssf_approved_by', get_current_user_id());
        update_post_meta($submission_id, '_ssf_approved_at', current_time('mysql'));
        wp_safe_redirect(get_edit_post_link($ship_id, ''));
        exit;
    }

    public function reject(): void
    {
        $submission_id = (int) ($_POST['submission_id'] ?? 0);
        $this->assert_review_request($submission_id);
        update_post_meta($submission_id, '_ssf_submission_status', 'rejected');
        update_post_meta($submission_id, '_ssf_review_note', sanitize_textarea_field(wp_unslash($_POST['review_note'] ?? '')));
        wp_safe_redirect(get_edit_post_link($submission_id, ''));
        exit;
    }

    private function assert_review_request(int $submission_id): void
    {
        if (! $submission_id || ! current_user_can('manage_options') || ! check_admin_referer('ssf_review_submission_' . $submission_id)) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-medlemsfartyg'));
        }
    }
}
