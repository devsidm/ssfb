<?php

namespace SSF\MemberPortal\Modules\Motions;

use SSF\MemberPortal\Core\Capabilities;

if (! defined('ABSPATH')) {
    exit;
}

final class MotionPermissions
{
    public static function can_manage(): bool
    {
        return current_user_can(Capabilities::MANAGE_MOTIONS) || current_user_can('manage_options');
    }
}
