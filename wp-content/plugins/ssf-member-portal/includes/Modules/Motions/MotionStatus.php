<?php

namespace SSF\MemberPortal\Modules\Motions;

if (! defined('ABSPATH')) {
    exit;
}

final class MotionStatus
{
    public const IN_SORTERAD = 'in_sorterad';
    public const UNDER_BEHANDLING = 'under_behandling';
    public const BEGAR_KOMPLETTERING = 'begar_komplettering';
    public const FARDIGBEHANDLAD = 'fardigbehandlad';
    public const TILL_ARSMOTET = 'till_arsmotet';
    public const AVSLUTAD = 'avslutad';

    // Keep these aliases while existing motion records are normalised on update.
    public const RECEIVED = self::IN_SORTERAD;
    public const RECEIVED_LATE = self::IN_SORTERAD;
    public const UNDER_REVIEW = self::UNDER_BEHANDLING;
    public const CLOSED = self::AVSLUTAD;

    public static function all(): array
    {
        return array(
            self::IN_SORTERAD => __('Inkommen', 'ssf-member-portal'),
            self::UNDER_BEHANDLING => __('Under behandling', 'ssf-member-portal'),
            self::BEGAR_KOMPLETTERING => __('Begär komplettering', 'ssf-member-portal'),
            self::FARDIGBEHANDLAD => __('Färdigbehandlad', 'ssf-member-portal'),
            self::TILL_ARSMOTET => __('Till årsmötet', 'ssf-member-portal'),
            self::AVSLUTAD => __('Avslutad', 'ssf-member-portal'),
        );
    }

    public static function label(string $status): string
    {
        $status = self::canonical($status);
        return $status ? self::all()[$status] : __('Inkommen', 'ssf-member-portal');
    }

    /**
     * Converts a SharePoint choice label or legacy stored value to one status key.
     */
    public static function canonical(string $status): ?string
    {
        $status = trim($status);
        if (isset(self::all()[$status])) {
            return $status;
        }

        $labels = array();
        foreach (self::all() as $key => $label) {
            $labels[self::normalise_label($label)] = $key;
        }
        $legacy = array(
            'received' => self::IN_SORTERAD,
            'received_late' => self::IN_SORTERAD,
            'under_review' => self::UNDER_BEHANDLING,
            'closed' => self::AVSLUTAD,
        );

        return $labels[self::normalise_label($status)] ?? ($legacy[$status] ?? null);
    }

    public static function is_valid(string $status): bool
    {
        return null !== self::canonical($status);
    }

    private static function normalise_label(string $label): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower(trim($label), 'UTF-8')
            : strtolower(trim($label));
    }
}
