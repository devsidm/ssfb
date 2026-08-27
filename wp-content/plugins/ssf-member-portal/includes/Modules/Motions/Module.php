<?php

namespace SSF\MemberPortal\Modules\Motions;

use SSF\MemberPortal\Modules\AnnualMeetings\Module as AnnualMeetings;
use SSF\MemberPortal\Modules\Motions\Admin\Controller as AdminController;
use SSF\MemberPortal\Modules\Motions\Frontend\Controller as FrontendController;

if (! defined('ABSPATH')) {
    exit;
}

final class Module
{
    private MotionPostType $post_type;
    private MotionDeadline $deadline;
    private MotionService $service;
    private AdminController $admin;

    public function __construct(AnnualMeetings $meetings)
    {
        $this->post_type = new MotionPostType();
        $this->deadline = new MotionDeadline($meetings);
        $sharepoint = new MotionSharePoint();
        $this->service = new MotionService($this->deadline, new MotionNumber(), new MotionFiles(), new MotionMailer(), $sharepoint);
        $this->admin = new AdminController($meetings, $this->deadline, $this->service);
        new FrontendController($this->deadline, $this->service);
    }

    public function register(): void
    {
        $this->post_type->register();
    }

    public function register_admin_menu(string $parent): void
    {
        $this->admin->register_menu($parent);
    }

    public function render_dashboard(): void
    {
        $this->admin->render_dashboard();
    }

    public function test_sharepoint(): array
    {
        $result = $this->service->sharepoint_diagnostics();
        if (is_wp_error($result)) {
            $error_data = (array) $result->get_error_data();
            return array(
                'ok' => false,
                'message' => $result->get_error_message(),
                'http_status' => (int) ($error_data['http_status'] ?? $error_data['status'] ?? 0),
                'endpoint' => (string) ($error_data['endpoint'] ?? ''),
                'graph_code' => (string) ($error_data['graph_code'] ?? $error_data['microsoft_code'] ?? ''),
            );
        }
        return array('ok' => true, 'message' => __('SharePoint-diagnostik slutförd utan skrivning.', 'ssf-member-portal'), 'result' => $result);
    }
}
