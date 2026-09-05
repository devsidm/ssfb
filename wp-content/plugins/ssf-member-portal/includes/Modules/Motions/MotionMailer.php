<?php

namespace SSF\MemberPortal\Modules\Motions;

use SSF\MemberPortal\Core\Settings;
use SSF\MemberPortal\Core\Logger;

if (! defined('ABSPATH')) {
    exit;
}

final class MotionMailer
{
    private const STATUS_RETRY_HOOK = 'ssf_member_portal_retry_motion_status_email';
    private const STATUS_RETRY_DELAYS = array(5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS);

    public function __construct()
    {
        add_action(self::STATUS_RETRY_HOOK, array($this, 'retry_status_email'));
    }

    public function send_received(int $motion_id, string $status_url): void
    {
        $number = (string) get_post_meta($motion_id, '_ssf_mp_motion_number', true);
        $name = (string) get_post_meta($motion_id, '_ssf_mp_submitter_name', true);
        $email = sanitize_email((string) get_post_meta($motion_id, '_ssf_mp_submitter_email', true));
        $late = (bool) get_post_meta($motion_id, '_ssf_mp_submitted_after_deadline', true);
        $subject = sprintf(__('SSF: bekräftelse på motion %s', 'ssf-member-portal'), $number);
        $body = sprintf('<p>Hej %s,</p><p>Vi har tagit emot din motion <strong>%s</strong>%s.</p><p>Du kan följa motionen här: <a href="%s">%s</a></p>', esc_html($name), esc_html($number), $late ? ' <strong>' . esc_html__('efter motionsfrist', 'ssf-member-portal') . '</strong>' : '', esc_url($status_url), esc_html($status_url));
        $headers = array('Content-Type: text/html; charset=UTF-8');
        if ($email) {
            wp_mail($email, $subject, $body, $headers);
        }
        $internal_subject = sprintf(__('Ny motion %s', 'ssf-member-portal'), $number);
        $internal_body = sprintf('<p>En ny motion har inkommit: <strong>%s</strong>.</p><p><a href="%s">Öppna statuslänken</a></p>', esc_html($number), esc_url($status_url));
        \SSF_Email_Router::send_to_function('annual_meeting_motion', $internal_subject, $internal_body, $headers);
    }

    /**
     * Sends one notification only after a persisted SharePoint-originated
     * status change. Failed sends stay queued for a bounded retry sequence.
     */
    public function send_status_change(int $motion_id, string $old_status, string $new_status): bool
    {
        $email = sanitize_email((string) get_post_meta($motion_id, '_ssf_mp_submitter_email', true));
        $number = (string) get_post_meta($motion_id, '_ssf_mp_motion_number', true);
        $name = sanitize_text_field((string) get_post_meta($motion_id, '_ssf_mp_submitter_name', true));
        $post = get_post($motion_id);
        $title = $post ? preg_replace('/^Motion\\s+[^:]+:\\s*/u', '', (string) $post->post_title) : '';
        $status_url = esc_url_raw((string) get_post_meta($motion_id, '_ssf_mp_status_url', true));
        $first_name = trim((string) preg_split('/\\s+/u', $name)[0]);
        $subject = sprintf(__('Din motion har fått ny status – %s', 'ssf-member-portal'), $number);
        $extra = $this->status_message($new_status);
        $body = sprintf(
            '<p>Hej %s,</p><p>Statusen för din motion har uppdaterats.</p><p><strong>Motion:</strong><br>%s – %s</p><p><strong>Tidigare status:</strong> %s<br><strong>Ny status:</strong> %s</p>%s%s<p>Med vänlig hälsning<br>Sveriges Segelfartygsförbund</p>',
            esc_html($first_name ?: $name ?: __('medlem', 'ssf-member-portal')),
            esc_html($number),
            esc_html($title),
            esc_html(MotionStatus::label($old_status)),
            esc_html(MotionStatus::label($new_status)),
            $extra ? '<p>' . nl2br(esc_html($extra)) . '</p>' : '',
            $status_url ? '<p>Du kan följa din motion här:<br><a href="' . esc_url($status_url) . '">' . esc_html($status_url) . '</a></p>' : ''
        );

        $sent = $email && wp_mail($email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
        $this->record_status_email($motion_id, $old_status, $new_status, $email, $sent ? 'sent' : 'failed');
        if ($sent) {
            update_post_meta($motion_id, '_ssf_mp_last_notified_status', $new_status);
            update_post_meta($motion_id, '_ssf_mp_last_notified_at', gmdate('c'));
            delete_post_meta($motion_id, '_ssf_mp_status_email_error');
            delete_post_meta($motion_id, '_ssf_mp_pending_status_email');
            return true;
        }

        $error = $email ? __('wp_mail kunde inte skicka statusmeddelandet.', 'ssf-member-portal') : __('Motionen saknar giltig e-postadress för statusmeddelande.', 'ssf-member-portal');
        update_post_meta($motion_id, '_ssf_mp_status_email_error', $error);
        Logger::add('motion_status_email_failed', array('motion_id' => $motion_id, 'status' => $new_status, 'recipient' => $email ? 'present' : 'missing'));
        $pending = (array) get_post_meta($motion_id, '_ssf_mp_pending_status_email', true);
        $attempts = (int) ($pending['attempts'] ?? 0) + 1;
        $pending = array('old_status' => $old_status, 'new_status' => $new_status, 'attempts' => $attempts, 'last_attempt_at' => gmdate('c'));
        update_post_meta($motion_id, '_ssf_mp_pending_status_email', $pending);
        if ($email && $attempts <= count(self::STATUS_RETRY_DELAYS) && ! wp_next_scheduled(self::STATUS_RETRY_HOOK, array($motion_id))) {
            wp_schedule_single_event(time() + self::STATUS_RETRY_DELAYS[$attempts - 1], self::STATUS_RETRY_HOOK, array($motion_id));
        }

        return false;
    }

    public function retry_status_email(int $motion_id): void
    {
        $pending = (array) get_post_meta($motion_id, '_ssf_mp_pending_status_email', true);
        $old_status = MotionStatus::canonical((string) ($pending['old_status'] ?? ''));
        $new_status = MotionStatus::canonical((string) ($pending['new_status'] ?? ''));
        if (! $old_status || ! $new_status) {
            return;
        }

        $this->send_status_change($motion_id, $old_status, $new_status);
    }

    public function resend_status_email(int $motion_id)
    {
        $pending = (array) get_post_meta($motion_id, '_ssf_mp_pending_status_email', true);
        $new_status = MotionStatus::canonical((string) ($pending['new_status'] ?? get_post_meta($motion_id, '_ssf_mp_status', true)));
        $old_status = MotionStatus::canonical((string) ($pending['old_status'] ?? '')) ?: MotionStatus::INKOMMEN;
        if (! $new_status) {
            return new \WP_Error('motion_status_email_missing_status', __('Motionen saknar en giltig status för e-post.', 'ssf-member-portal'));
        }

        return $this->send_status_change($motion_id, $old_status, $new_status);
    }

    private function status_message(string $status): string
    {
        $messages = (array) (Settings::all()['motion_status_messages'] ?? array());
        return sanitize_textarea_field((string) ($messages[$status] ?? ''));
    }

    private function record_status_email(int $motion_id, string $old_status, string $new_status, string $email, string $result): void
    {
        $history = (array) get_post_meta($motion_id, '_ssf_mp_status_email_history', true);
        $history[] = array(
            'old_status' => $old_status,
            'new_status' => $new_status,
            'recipient' => $email,
            'result' => $result,
            'attempted_at' => gmdate('c'),
        );
        update_post_meta($motion_id, '_ssf_mp_status_email_history', array_slice($history, -50));
        if ('sent' === $result) {
            Logger::add('motion_status_email_sent', array('motion_id' => $motion_id, 'status' => $new_status));
        }
    }
}
