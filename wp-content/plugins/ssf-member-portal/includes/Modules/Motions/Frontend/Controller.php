<?php

namespace SSF\MemberPortal\Modules\Motions\Frontend;

use SSF\MemberPortal\Modules\Motions\MotionDeadline;
use SSF\MemberPortal\Modules\Motions\MotionService;
use SSF\MemberPortal\Modules\Motions\MotionStatus;

if (! defined('ABSPATH')) {
    exit;
}

final class Controller
{
    private MotionDeadline $deadline;
    private MotionService $service;

    public function __construct(MotionDeadline $deadline, MotionService $service)
    {
        $this->deadline = $deadline;
        $this->service = $service;
        add_shortcode('ssf_member_portal_motions', array($this, 'form_shortcode'));
        add_shortcode('ssf_member_portal_motion_status', array($this, 'status_shortcode'));
        add_action('admin_post_nopriv_ssf_member_portal_submit_motion', array($this, 'submit'));
        add_action('admin_post_ssf_member_portal_submit_motion', array($this, 'submit'));
    }

    public function form_shortcode(array $atts = array()): string
    {
        $atts = shortcode_atts(array('meeting_id' => 0), $atts, 'ssf_member_portal_motions');
        $requested_id = absint($atts['meeting_id']);
        if (! $requested_id && isset($_GET['meeting']) && is_scalar($_GET['meeting'])) {
            $requested_id = absint(wp_unslash($_GET['meeting']));
        }
        $meeting = $requested_id ? $this->deadline->meeting($requested_id) : $this->deadline->active_meeting();
        $period = $this->deadline->state($meeting);
        $error = sanitize_text_field(wp_unslash($_GET['ssf_motion_error'] ?? ''));
        return $this->template('form', array('period' => $period, 'deadline' => $this->deadline, 'error' => $error));
    }

    public function status_shortcode(): string
    {
        $number = sanitize_text_field(wp_unslash($_GET['motion'] ?? ''));
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        $meeting_id = isset($_GET['meeting']) && is_scalar($_GET['meeting']) ? absint(wp_unslash($_GET['meeting'])) : 0;
        $motion = $this->service->find($number, $token, $meeting_id);
        if (! $motion) {
            return $this->template('status', array('motion' => null, 'deadline' => $this->deadline));
        }
        if (! empty($_GET['confirmation'])) {
            return $this->template('confirmation', array('motion' => $motion, 'deadline' => $this->deadline, 'status_url' => $this->service->status_url($number, $token, (int) get_post_meta($motion->ID, '_ssf_mp_annual_meeting_id', true))));
        }
        return $this->template('status', array('motion' => $motion, 'deadline' => $this->deadline));
    }

    public function submit(): void
    {
        $meeting_id = absint($_POST['meeting_id'] ?? 0);
        $form_url = (int) get_option('ssf_member_portal_motion_form_page_id') ? get_permalink((int) get_option('ssf_member_portal_motion_form_page_id')) : home_url('/lamna-motion/');
        if ($meeting_id) {
            $form_url = add_query_arg('meeting', $meeting_id, $form_url);
        }
        if (! isset($_POST['ssf_member_portal_motion_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_member_portal_motion_nonce'])), 'ssf_member_portal_submit_motion') || ! empty($_POST['company'])) {
            wp_safe_redirect(add_query_arg('ssf_motion_error', rawurlencode(__('Formuläret kunde inte verifieras.', 'ssf-member-portal')), $form_url));
            exit;
        }
        $result = $this->service->submit(wp_unslash($_POST), $_FILES);
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('ssf_motion_error', rawurlencode($result->get_error_message()), $form_url));
            exit;
        }
        wp_safe_redirect(add_query_arg('confirmation', '1', $result['status_url']));
        exit;
    }

    private function template(string $name, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        include SSF_MEMBER_PORTAL_PATH . 'templates/motions/' . $name . '.php';
        return (string) ob_get_clean();
    }
}
