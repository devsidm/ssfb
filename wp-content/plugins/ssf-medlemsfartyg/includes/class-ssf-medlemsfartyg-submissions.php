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
        add_action('admin_notices', array($this, 'render_admin_notice'));
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
        $nonce = wp_create_nonce('ssf_review_submission_' . $post->ID);
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
        echo '<div class="ssf-submission-review" data-action-url="' . esc_url(admin_url('admin-post.php')) . '" data-submission-id="' . esc_attr((string) $post->ID) . '" data-nonce="' . esc_attr($nonce) . '">';
        if ($images) {
            echo '<h3>' . esc_html__('Bilder', 'ssf-medlemsfartyg') . '</h3><div class="ssf-submission-images">';
            foreach ($images as $image_id) {
                echo '<label><input class="ssf-submission-featured-image" type="radio" name="featured_image" value="' . esc_attr((string) $image_id) . '" ' . checked($featured, $image_id, false) . '> ' . wp_get_attachment_image($image_id, 'thumbnail') . '</label>';
            }
            echo '</div>';
        }
        ?>
        <p><button type="button" class="button button-primary" data-ssf-submission-action="approve"><?php esc_html_e('Godkänn alla ändringar', 'ssf-medlemsfartyg'); ?></button></p>
        <label><?php esc_html_e('Intern kommentar', 'ssf-medlemsfartyg'); ?><textarea class="ssf-submission-review-note" rows="3"></textarea></label>
        <p><button type="button" class="button-link-delete" data-ssf-submission-action="reject"><?php esc_html_e('Avvisa', 'ssf-medlemsfartyg'); ?></button></p>
        </div>
        <?php
    }

    public function approve(): void
    {
        $submission_id = (int) ($_POST['submission_id'] ?? 0);
        $this->assert_review_request($submission_id);
        $ship_id = (int) get_post_meta($submission_id, '_ssf_ship_id', true);
        $data = (array) get_post_meta($submission_id, '_ssf_submission_data', true);
        $images = array_map('intval', (array) get_post_meta($submission_id, '_ssf_submission_images', true));
        $featured = (int) ($_POST['featured_image'] ?? 0);
        if (! $featured) {
            $featured = (int) get_post_meta($submission_id, '_ssf_submission_featured_image', true);
        }
        $current_images = array_filter(array_map('intval', array_merge(
            array((int) get_post_thumbnail_id($ship_id)),
            explode(',', (string) get_post_meta($ship_id, '_ssf_gallery_ids', true))
        )));

        SSF_Medlemsfartyg_Profile::save($ship_id, $data, 'update_link');
        SSF_Medlemsfartyg_Meta::save_fields_from_request($ship_id, $data, true, 'update_link');
        foreach (array('fartygstyp', 'fartygsstatus', 'fartygsregion', 'fartygsanvandning') as $taxonomy) {
            if (! empty($data['tax_' . $taxonomy])) {
                wp_set_object_terms($ship_id, array_map('sanitize_text_field', (array) $data['tax_' . $taxonomy]), $taxonomy, false);
            }
        }
        if ($featured) {
            set_post_thumbnail($ship_id, $featured);
            $all_images = array_values(array_unique(array_merge($current_images, $images)));
            update_post_meta($ship_id, '_ssf_gallery_ids', implode(',', array_diff($all_images, array($featured))));
        } elseif ($images) {
            update_post_meta($ship_id, '_ssf_gallery_ids', implode(',', array_values(array_unique(array_merge($current_images, $images)))));
        }
        update_post_meta($submission_id, '_ssf_submission_status', 'approved');
        update_post_meta($submission_id, '_ssf_approved_by', get_current_user_id());
        update_post_meta($submission_id, '_ssf_approved_at', current_time('mysql'));
        update_post_meta($ship_id, '_ssf_review_status', 'Granskad');
        $this->redirect_to_edit($ship_id, 'approved');
        exit;
    }

    public function reject(): void
    {
        $submission_id = (int) ($_POST['submission_id'] ?? 0);
        $this->assert_review_request($submission_id);
        update_post_meta($submission_id, '_ssf_submission_status', 'rejected');
        update_post_meta($submission_id, '_ssf_review_note', sanitize_textarea_field(wp_unslash($_POST['review_note'] ?? '')));
        $this->redirect_to_edit($submission_id, 'rejected');
        exit;
    }

    public function render_admin_notice(): void
    {
        if (! current_user_can('manage_options') || empty($_GET['ssf_submission_notice'])) {
            return;
        }

        $notice = sanitize_key((string) wp_unslash($_GET['ssf_submission_notice']));
        $messages = array(
            'approved' => array('success', __('Ändringarna har tillämpats på fartygsprofilen.', 'ssf-medlemsfartyg')),
            'rejected' => array('success', __('Inskicket har avvisats.', 'ssf-medlemsfartyg')),
        );
        if (! isset($messages[$notice])) {
            return;
        }

        printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($messages[$notice][0]), esc_html($messages[$notice][1]));
    }

    private function redirect_to_edit(int $post_id, string $notice): void
    {
        wp_safe_redirect(add_query_arg('ssf_submission_notice', $notice, get_edit_post_link($post_id, '')));
    }

    private function assert_review_request(int $submission_id): void
    {
        if (! $submission_id || ! current_user_can('manage_options') || ! check_admin_referer('ssf_review_submission_' . $submission_id)) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-medlemsfartyg'));
        }
    }
}
