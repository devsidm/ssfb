<?php

namespace SSF\MemberPortal\Modules\Motions;

if (! defined('ABSPATH')) {
    exit;
}

final class MotionStatus
{
    public const RECEIVED = 'received';
    public const RECEIVED_LATE = 'received_late';
    public const UNDER_REVIEW = 'under_review';
    public const CLOSED = 'closed';

    public static function all(): array
    {
        return array(
            self::RECEIVED => __('Inkommen', 'ssf-member-portal'),
            self::RECEIVED_LATE => __('Inkommen efter motionsfrist', 'ssf-member-portal'),
            self::UNDER_REVIEW => __('Under behandling', 'ssf-member-portal'),
            self::CLOSED => __('Avslutad', 'ssf-member-portal'),
        );
    }

    public static function label(string $status): string
    {
        return self::all()[$status] ?? __('Inkommen', 'ssf-member-portal');
    }
}
