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
        $headers = array('Content-Type: text/html; charset=UTF-8');
        if ($email) {
            $submitted_at = (int) get_post_meta($motion_id, '_ssf_mp_submitted_at', true);
            \SSF_Email_Template::send($email, __('Din motion har tagits emot', 'ssf-member-portal'), 'motion_received', array(
                'recipient_name' => $name,
                'body' => array(__('Tack för din motion till Sveriges Segelfartygsförbund. Vi har registrerat motionen och du kan följa handläggningen via länken nedan.', 'ssf-member-portal')),
                'sections' => array(array('title' => __('Motion', 'ssf-member-portal'), 'rows' => array(
                    __('Motion', 'ssf-member-portal') => $number,
                    __('Inkommen', 'ssf-member-portal') => $submitted_at ? wp_date('j F Y, H:i', $submitted_at, wp_timezone()) : wp_date('j F Y', null, wp_timezone()),
                    __('Status', 'ssf-member-portal') => MotionStatus::label(MotionStatus::INKOMMEN),
                ))),
                'notice_title' => $late ? __('Observera', 'ssf-member-portal') : '',
                'notice' => $late ? __('Motionen registrerades efter ordinarie motionsfrist.', 'ssf-member-portal') : '',
                'button_label' => __('Följ din motion', 'ssf-member-portal'),
                'button_url' => $status_url,
            ));
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
        $extra = $this->status_message($new_status);
        $motion_reference = trim($number . ($title ? ' – ' . $title : ''));
        $sent = $email && \SSF_Email_Template::send($email, __('Din motion har uppdaterats', 'ssf-member-portal'), 'motion_status', array(
            'recipient_name' => $name,
            'body' => array(__('Statusen för din motion har ändrats.', 'ssf-member-portal')),
            'sections' => array(array('title' => __('Motion', 'ssf-member-portal'), 'rows' => array(
                __('Motion', 'ssf-member-portal') => $motion_reference,
                __('Tidigare status', 'ssf-member-portal') => MotionStatus::label($old_status),
                __('Ny status', 'ssf-member-portal') => MotionStatus::label($new_status),
            ))),
            'notice_title' => $extra ? __('Meddelande från SSF', 'ssf-member-portal') : '',
            'notice' => $extra,
            'button_label' => $status_url ? __('Följ din motion', 'ssf-member-portal') : '',
            'button_url' => $status_url,
        ));
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
