<?php

namespace SSF\MemberPortal\Core;

if (! defined('ABSPATH')) {
    exit;
}

final class Capabilities
{
    public const MANAGE = 'ssf_manage_member_portal';
    public const MANAGE_MOTIONS = 'ssf_manage_motions';

    public static function register(): void
    {
        $administrator = get_role('administrator');
        if (! $administrator) {
            return;
        }

        $administrator->add_cap(self::MANAGE);
        $administrator->add_cap(self::MANAGE_MOTIONS);
    }
}
