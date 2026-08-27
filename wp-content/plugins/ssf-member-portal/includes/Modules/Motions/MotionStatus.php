<?php

namespace SSF\MemberPortal\Modules\Motions;

if (! defined('ABSPATH')) {
    exit;
}

final class MotionStatus
{
    public const INKOMMEN = 'inkommen';
    public const UNDER_BEHANDLING = 'under_behandling';
    public const BEGAR_KOMPLETTERING = 'begar_komplettering';
    public const FARDIGBEHANDLAD_AV_STYRELSEN = 'fardigbehandlad_av_styrelsen';
    public const TILL_ARSMOTET = 'till_arsmotet';
    public const BESLUTAD_PA_ARSMOTET = 'beslutad_pa_arsmotet';
    public const AVSLUTAD = 'avslutad';

    // Keep these aliases while existing motion records are normalised on update.
    public const IN_SORTERAD = self::INKOMMEN;
    public const FARDIGBEHANDLAD = self::FARDIGBEHANDLAD_AV_STYRELSEN;
    public const RECEIVED = self::INKOMMEN;
    public const RECEIVED_LATE = self::INKOMMEN;
    public const UNDER_REVIEW = self::UNDER_BEHANDLING;
    public const CLOSED = self::AVSLUTAD;

    public static function all(): array
    {
        return array(
            self::INKOMMEN => __('Inkommen', 'ssf-member-portal'),
            self::UNDER_BEHANDLING => __('Under behandling', 'ssf-member-portal'),
            self::BEGAR_KOMPLETTERING => __('Begär komplettering', 'ssf-member-portal'),
            self::FARDIGBEHANDLAD_AV_STYRELSEN => __('Färdigbehandlad av styrelsen', 'ssf-member-portal'),
            self::TILL_ARSMOTET => __('Till årsmötet', 'ssf-member-portal'),
            self::BESLUTAD_PA_ARSMOTET => __('Beslutad på årsmötet', 'ssf-member-portal'),
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
            'in_sorterad' => self::INKOMMEN,
            'received' => self::INKOMMEN,
            'received_late' => self::INKOMMEN,
            'under_review' => self::UNDER_BEHANDLING,
            'fardigbehandlad' => self::FARDIGBEHANDLAD_AV_STYRELSEN,
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
