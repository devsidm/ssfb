<?php

namespace SSF\MemberPortal\Modules\Motions;

if (! defined('ABSPATH')) {
    exit;
}

final class MotionNumber
{
    public function next(int $year): string
    {
        $key = 'ssf_member_portal_motion_sequence_' . $year;
        $next = max(1, (int) get_option($key, 0) + 1);
        update_option($key, $next, false);
        return sprintf('%d-%03d', $year, $next);
    }
}
