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

    public function form_shortcode(): string
    {
        $period = $this->deadline->state();
        $error = sanitize_text_field(wp_unslash($_GET['ssf_motion_error'] ?? ''));
        return $this->template('form', array('period' => $period, 'deadline' => $this->deadline, 'error' => $error));
    }

    public function status_shortcode(): string
    {
        $number = sanitize_text_field(wp_unslash($_GET['motion'] ?? ''));
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        $motion = $this->service->find($number, $token);
        if (! $motion) {
            return $this->template('status', array('motion' => null, 'deadline' => $this->deadline));
        }
        if (! empty($_GET['confirmation'])) {
            return $this->template('confirmation', array('motion' => $motion, 'deadline' => $this->deadline, 'status_url' => $this->service->status_url($number, $token)));
        }
        return $this->template('status', array('motion' => $motion, 'deadline' => $this->deadline));
    }

    public function submit(): void
    {
        $form_url = (int) get_option('ssf_member_portal_motion_form_page_id') ? get_permalink((int) get_option('ssf_member_portal_motion_form_page_id')) : home_url('/lamna-motion/');
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
