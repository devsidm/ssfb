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
    private PowerAutomateWebhook $webhook;

    public function __construct(AnnualMeetings $meetings)
    {
        $this->post_type = new MotionPostType();
        $this->deadline = new MotionDeadline($meetings);
        $sharepoint = new MotionSharePoint();
        $mailer = new MotionMailer();
        $statuses = new MotionStatusService($sharepoint, $mailer);
        $sharepoint->set_status_service($statuses);
        $this->service = new MotionService($this->deadline, new MotionNumber(), new MotionFiles(), $mailer, $sharepoint, $statuses);
        $this->webhook = new PowerAutomateWebhook($statuses);
        $this->admin = new AdminController($meetings, $this->deadline, $this->service);
        new FrontendController($this->deadline, $this->service);
    }

    public function register(): void
    {
        $this->post_type->register();
        $this->service->register_sharepoint_status_poll();
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
        return $this->graph_result($this->service->sharepoint_diagnostics(), __('SharePoint-diagnostik slutförd utan skrivning.', 'ssf-member-portal'));
    }

    public function test_sharepoint_authentication(): array
    {
        return $this->graph_result($this->service->sharepoint_authentication(), __('Microsoft Entra-autentisering slutförd.', 'ssf-member-portal'));
    }

    public function test_sharepoint_temporary_write(): array
    {
        return $this->graph_result($this->service->test_sharepoint_temporary_write(), __('Temporär testmapp skapades och togs bort.', 'ssf-member-portal'));
    }

    public function test_sharepoint_motion_folder(): array
    {
        $meeting = $this->deadline->active_meeting();
        $year = (int) ($meeting['year'] ?: wp_date('Y', null, wp_timezone()));
        return $this->graph_result($this->service->test_sharepoint_write_access($year), __('Motionsmappen är klar.', 'ssf-member-portal'));
    }

    public function test_sharepoint_file(): array
    {
        $meeting = $this->deadline->active_meeting();
        $year = (int) ($meeting['year'] ?: wp_date('Y', null, wp_timezone()));
        return $this->graph_result($this->service->upload_sharepoint_test_file($year), __('Testfilen har laddats upp.', 'ssf-member-portal'));
    }

    public function delete_sharepoint_file(): array
    {
        return $this->graph_result($this->service->delete_sharepoint_test_file(), __('Testfilen har tagits bort.', 'ssf-member-portal'));
    }

    public function test_sharepoint_motion_schema(): array
    {
        return $this->graph_result($this->service->ensure_sharepoint_status_schema(), __('SharePoints motionsstatus är konfigurerad.', 'ssf-member-portal'));
    }

    public function handle_power_automate_webhook(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->webhook->handle($request);
    }

    private function graph_result($result, string $success_message): array
    {
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
        return array('ok' => true, 'message' => $success_message, 'result' => $result);
    }
}
