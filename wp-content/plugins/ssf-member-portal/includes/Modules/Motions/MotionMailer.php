<?php

namespace SSF\MemberPortal\Modules\Motions;

use SSF\MemberPortal\Core\Settings;

if (! defined('ABSPATH')) {
    exit;
}

final class MotionMailer
{
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
        $recipient = sanitize_email((string) Settings::all()['notification_email']);
        if ($recipient) {
            wp_mail($recipient, sprintf(__('Ny motion %s', 'ssf-member-portal'), $number), sprintf('<p>En ny motion har inkommit: <strong>%s</strong>.</p><p><a href="%s">Öppna statuslänken</a></p>', esc_html($number), esc_url($status_url)), $headers);
        }
    }
}
