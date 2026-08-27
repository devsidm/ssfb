<?php

namespace SSF\MemberPortal\Core;

if (! defined('ABSPATH')) {
    exit;
}

final class Capabilities
{
    public const MANAGE = 'ssf_manage_member_portal';
    public const MANAGE_MOTIONS = 'ssf_manage_motions';
    public const MANAGE_ANNUAL_MEETINGS = 'manage_ssf_annual_meetings';

    public static function register(): void
    {
        $administrator = get_role('administrator');
        if (! $administrator) {
            return;
        }

        $administrator->add_cap(self::MANAGE);
        $administrator->add_cap(self::MANAGE_MOTIONS);
        $administrator->add_cap(self::MANAGE_ANNUAL_MEETINGS);
    }
}
