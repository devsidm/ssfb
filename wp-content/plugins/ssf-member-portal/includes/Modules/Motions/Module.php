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
}
