<?php

namespace SSF\MemberPortal\Modules\AnnualMeetings;

use SSF\MemberPortal\Core\Capabilities;

if (! defined('ABSPATH')) {
    exit;
}

final class Module
{
    public const POST_TYPE = 'ssf_annual_meeting';

    public function __construct()
    {
        add_action('add_meta_boxes_' . self::POST_TYPE, array($this, 'add_meta_box'));
        add_action('save_post_' . self::POST_TYPE, array($this, 'save'), 10, 2);
    }

    public function register(): void
    {
        register_post_type(self::POST_TYPE, array(
            'labels' => array('name' => __('Årsmöten', 'ssf-member-portal'), 'singular_name' => __('Årsmöte', 'ssf-member-portal'), 'add_new_item' => __('Lägg till årsmöte', 'ssf-member-portal'), 'edit_item' => __('Redigera årsmöte', 'ssf-member-portal')),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => array('title'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ));
    }

    public function add_meta_box(): void
    {
        add_meta_box('ssf-member-portal-meeting', __('Motionsperiod', 'ssf-member-portal'), array($this, 'render_meta_box'), self::POST_TYPE, 'normal', 'high');
    }

    public function render_meta_box(\WP_Post $post): void
    {
        wp_nonce_field('ssf_member_portal_meeting', 'ssf_member_portal_meeting_nonce');
        $data = $this->data($post->ID);
        ?>
        <p><label><strong><?php esc_html_e('År', 'ssf-member-portal'); ?></strong><br><input name="ssf_meeting_year" type="number" min="2000" max="2100" value="<?php echo esc_attr($data['year']); ?>"></label></p>
        <p><label><strong><?php esc_html_e('Mötesdatum', 'ssf-member-portal'); ?></strong><br><input name="ssf_meeting_date" type="date" value="<?php echo esc_attr($data['meeting_date']); ?>"></label></p>
        <p><label><strong><?php esc_html_e('Motioner öppnar', 'ssf-member-portal'); ?></strong><br><input name="ssf_motion_opens_at" type="datetime-local" value="<?php echo esc_attr($this->input_date($data['motion_opens_at'])); ?>"></label></p>
        <p><label><strong><?php esc_html_e('Motioner stänger', 'ssf-member-portal'); ?></strong><br><input name="ssf_motion_closes_at" type="datetime-local" value="<?php echo esc_attr($this->input_date($data['motion_closes_at'])); ?>"></label></p>
        <p><label><strong><?php esc_html_e('SharePoint-mapp', 'ssf-member-portal'); ?></strong><br><code>Årsmöten/<?php echo esc_html($data['year'] ?: 'YYYY'); ?>/Motioner/</code><br><span class="description"><?php esc_html_e('Mappen skapas först när SharePoint-synk har konfigurerats.', 'ssf-member-portal'); ?></span></label></p>
        <?php
    }

    public function save(int $post_id, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE || ! current_user_can('edit_post', $post_id) || ! isset($_POST['ssf_member_portal_meeting_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_member_portal_meeting_nonce'])), 'ssf_member_portal_meeting')) {
            return;
        }

        $year = absint($_POST['ssf_meeting_year'] ?? 0);
        $meeting_date = sanitize_text_field(wp_unslash($_POST['ssf_meeting_date'] ?? ''));
        $opens_at = $this->timestamp(sanitize_text_field(wp_unslash($_POST['ssf_motion_opens_at'] ?? '')));
        $closes_at = $this->timestamp(sanitize_text_field(wp_unslash($_POST['ssf_motion_closes_at'] ?? '')));
        if ($opens_at && $closes_at && $closes_at <= $opens_at) {
            $closes_at = 0;
            add_filter('redirect_post_location', static function (string $location): string { return add_query_arg('ssf_meeting_error', 'deadline', $location); });
        }
        update_post_meta($post_id, '_ssf_mp_meeting_year', $year);
        update_post_meta($post_id, '_ssf_mp_meeting_date', $meeting_date);
        update_post_meta($post_id, '_ssf_mp_motion_opens_at', $opens_at);
        update_post_meta($post_id, '_ssf_mp_motion_closes_at', $closes_at);
        update_post_meta($post_id, '_ssf_mp_sharepoint_folder', 'Årsmöten/' . $year . '/Motioner/');
    }

    public function data(int $meeting_id): array
    {
        return array(
            'id' => $meeting_id,
            'year' => (int) get_post_meta($meeting_id, '_ssf_mp_meeting_year', true),
            'meeting_date' => (string) get_post_meta($meeting_id, '_ssf_mp_meeting_date', true),
            'motion_opens_at' => (int) get_post_meta($meeting_id, '_ssf_mp_motion_opens_at', true),
            'motion_closes_at' => (int) get_post_meta($meeting_id, '_ssf_mp_motion_closes_at', true),
            'sharepoint_folder' => (string) get_post_meta($meeting_id, '_ssf_mp_sharepoint_folder', true),
        );
    }

    public function all(): array
    {
        return get_posts(array('post_type' => self::POST_TYPE, 'post_status' => array('publish', 'draft'), 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC'));
    }

    private function timestamp(string $value): int
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $value, wp_timezone());
        return $date ? $date->getTimestamp() : 0;
    }

    private function input_date(int $timestamp): string
    {
        return $timestamp ? wp_date('Y-m-d\\TH:i', $timestamp, wp_timezone()) : '';
    }
}
