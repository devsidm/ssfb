<?php

namespace SSF\MemberPortal\Modules\Motions;

use SSF\MemberPortal\Core\Logger;

if (! defined('ABSPATH')) {
    exit;
}

final class MotionService
{
    private MotionDeadline $deadline;
    private MotionNumber $numbers;
    private MotionFiles $files;
    private MotionMailer $mailer;
    private MotionSharePoint $sharepoint;

    public function __construct(MotionDeadline $deadline, MotionNumber $numbers, MotionFiles $files, MotionMailer $mailer, MotionSharePoint $sharepoint)
    {
        $this->deadline = $deadline;
        $this->numbers = $numbers;
        $this->files = $files;
        $this->mailer = $mailer;
        $this->sharepoint = $sharepoint;
    }

    public function submit(array $input, array $files)
    {
        $period = $this->deadline->state();
        if (! $period['allowed']) {
            return new \WP_Error('motion_period_closed', __('Motionsperioden är inte öppen för inlämning.', 'ssf-member-portal'));
        }
        $name = sanitize_text_field($input['name'] ?? '');
        $email = sanitize_email($input['email'] ?? '');
        $phone = sanitize_text_field($input['phone'] ?? '');
        $title = sanitize_text_field($input['title'] ?? '');
        $content = wp_kses_post($input['content'] ?? '');
        if (! $name || ! $email || ! $title || ! trim(wp_strip_all_tags($content))) {
            return new \WP_Error('motion_required', __('Fyll i namn, e-postadress, rubrik och motionstext.', 'ssf-member-portal'));
        }
        $upload_error = $this->files->validate($files);
        if ($upload_error) {
            return $upload_error;
        }

        $submitted_at = (new \DateTimeImmutable('now', wp_timezone()))->getTimestamp();
        $snapshot = $this->deadline->snapshot($period, $submitted_at);
        $number = $this->numbers->next((int) $snapshot['meeting_year']);
        $token = wp_generate_password(48, false, false);
        $motion_id = wp_insert_post(array('post_type' => MotionPostType::POST_TYPE, 'post_status' => 'private', 'post_title' => 'Motion ' . $number . ': ' . $title, 'post_content' => $content, 'post_author' => get_current_user_id()));
        if (is_wp_error($motion_id) || ! $motion_id) {
            return new \WP_Error('motion_save', __('Motionen kunde inte sparas. Försök igen.', 'ssf-member-portal'));
        }

        foreach ($snapshot as $key => $value) {
            update_post_meta($motion_id, '_ssf_mp_' . $key, $value);
        }
        update_post_meta($motion_id, '_ssf_mp_motion_number', $number);
        update_post_meta($motion_id, '_ssf_mp_status', $snapshot['submitted_after_deadline'] ? MotionStatus::RECEIVED_LATE : MotionStatus::RECEIVED);
        update_post_meta($motion_id, '_ssf_mp_submitter_name', $name);
        update_post_meta($motion_id, '_ssf_mp_submitter_email', $email);
        update_post_meta($motion_id, '_ssf_mp_submitter_phone', $phone);
        update_post_meta($motion_id, '_ssf_mp_access_token_hash', hash('sha256', $token));
        update_post_meta($motion_id, '_ssf_mp_member_user_id', get_current_user_id());
        $this->files->attach($files, $motion_id);

        $status_url = $this->status_url($number, $token);
        $this->mailer->send_received($motion_id, $status_url);
        $this->sharepoint->queue($motion_id);
        Logger::add('motion_received', array('motion_id' => $motion_id, 'number' => $number, 'late' => $snapshot['submitted_after_deadline']));
        return array('motion_id' => $motion_id, 'number' => $number, 'token' => $token, 'status_url' => $status_url);
    }

    public function find(string $number, string $token): ?\WP_Post
    {
        if (! $number || strlen($token) < 32) {
            return null;
        }
        $motions = get_posts(array('post_type' => MotionPostType::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => 1, 'meta_key' => '_ssf_mp_motion_number', 'meta_value' => sanitize_text_field($number)));
        if (! $motions) {
            return null;
        }
        return hash_equals((string) get_post_meta($motions[0]->ID, '_ssf_mp_access_token_hash', true), hash('sha256', $token)) ? $motions[0] : null;
    }

    public function status_url(string $number, string $token): string
    {
        $page_id = (int) get_option('ssf_member_portal_motion_status_page_id');
        return add_query_arg(array('motion' => $number, 'token' => $token), $page_id ? get_permalink($page_id) : home_url('/motion-status/'));
    }

    public function sharepoint_diagnostics()
    {
        return $this->sharepoint->diagnostics();
    }

    public function sharepoint_authentication()
    {
        return $this->sharepoint->test_authentication();
    }

    public function test_sharepoint_write_access(int $year)
    {
        return $this->sharepoint->test_write_access($year);
    }

    public function test_sharepoint_temporary_write()
    {
        return $this->sharepoint->test_temporary_write();
    }

    public function upload_sharepoint_test_file(int $year)
    {
        return $this->sharepoint->upload_test_file($year);
    }

    public function delete_sharepoint_test_file()
    {
        return $this->sharepoint->delete_test_file();
    }

    public function retry_sharepoint_sync(int $motion_id): void
    {
        $this->sharepoint->retry($motion_id);
    }
}
