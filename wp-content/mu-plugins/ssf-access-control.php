<?php
/**
 * SSF business permissions, independent of Microsoft identity.
 *
 * @package SSF
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Access_Control
{
    public const GROUP_META = '_ssf_permission_groups';
    public const STATUS_META = '_ssf_access_status';
    public const AUDIT_OPTION = 'ssf_microsoft_login_permission_audit';
    public const MANAGE_USERS = 'ssf_manage_users';

    public static function boot(): void
    {
        add_filter('user_has_cap', array(__CLASS__, 'grant_capabilities'), 10, 4);
        add_action('init', array(__CLASS__, 'ensure_admin_capability'), 6);
    }

    public static function groups(): array
    {
        return array(
            'styrelse' => array(
                'label' => 'Styrelse',
                'description' => 'Styrelsearbete i medlemsportal och årsmötesflöden.',
                'capabilities' => array('ssf_manage_member_portal', 'ssf_manage_motions', 'manage_ssf_annual_meetings', 'ssf_view_applications'),
            ),
            'ansokningar' => array(
                'label' => 'Ansökningar',
                'description' => 'Handläggning av fartygs- och medlemsansökningar.',
                'capabilities' => array('ssf_view_applications', 'edit_ssf_applications', 'edit_others_ssf_applications', 'read_private_ssf_applications', 'edit_private_ssf_applications', 'edit_published_ssf_applications', 'ssf_review_applications', 'ssf_decide_applications', 'ssf_manage_application_settings'),
            ),
            'motioner' => array(
                'label' => 'Motioner',
                'description' => 'Motioner, statusflöde och motionsrelaterad SharePoint-synk.',
                'capabilities' => array('ssf_manage_motions'),
            ),
            'arsmoten' => array(
                'label' => 'Årsmöten',
                'description' => 'Årsmöten, anmälningar och deltagarexporter.',
                'capabilities' => array('manage_ssf_annual_meetings', 'ssf_manage_member_portal'),
            ),
            'inspektorer' => array(
                'label' => 'Inspektioner',
                'description' => 'Tilldelade inspektioner utan övrig administration.',
                'capabilities' => array('ssf_view_assigned_applications', 'ssf_view_application_details', 'ssf_edit_inspection', 'ssf_submit_inspection', 'ssf_send_application_message'),
            ),
            'nyheter' => array(
                'label' => 'Nyheter',
                'description' => 'Skriva, granska och publicera nyheter.',
                'capabilities' => array('ssf_manage_news', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'upload_files'),
            ),
            'systemadministration' => array(
                'label' => 'Systemadministration',
                'description' => 'Användare, systeminställningar, Microsoft och diagnostik.',
                'capabilities' => array('ssf_manage_microsoft_login', 'ssf_manage_permission_groups', self::MANAGE_USERS, 'ssf_manage_member_portal', 'manage_ssf_features', 'manage_ssf_releases'),
            ),
        );
    }

    public static function user_groups(int $user_id): array
    {
        $stored = (array) get_user_meta($user_id, self::GROUP_META, true);
        return array_values(array_intersect(array_map('sanitize_key', array_filter($stored, 'is_scalar')), array_keys(self::groups())));
    }

    public static function is_active(int $user_id): bool
    {
        return 'inactive' !== (string) get_user_meta($user_id, self::STATUS_META, true);
    }

    public static function can_handle_membership(int $user_id): bool
    {
        return $user_id > 0 && self::is_active($user_id)
            && (user_can($user_id, 'ssf_review_applications') || user_can($user_id, 'manage_options'));
    }

    public static function can_manage_users(): bool
    {
        return current_user_can(self::MANAGE_USERS) || current_user_can('manage_options');
    }

    public static function grant_capabilities(array $allcaps, array $caps, array $args, WP_User $user): array
    {
        if (! self::is_active((int) $user->ID)) {
            foreach (array_keys($allcaps) as $capability) {
                $allcaps[$capability] = false;
            }
            return $allcaps;
        }
        foreach (self::user_groups((int) $user->ID) as $key) {
            foreach (self::groups()[$key]['capabilities'] as $capability) {
                $allcaps[$capability] = true;
            }
        }
        return $allcaps;
    }

    public static function save_groups(int $target_user_id, array $groups, int $actor_user_id): void
    {
        $old = self::user_groups($target_user_id);
        $new = array_values(array_unique(array_intersect(array_map('sanitize_key', array_filter($groups, 'is_scalar')), array_keys(self::groups()))));
        sort($old);
        sort($new);
        if ($old === $new) {
            return;
        }
        update_user_meta($target_user_id, self::GROUP_META, $new);
        self::audit($target_user_id, $actor_user_id, 'groups_changed', $old, $new);
    }

    public static function set_active(int $target_user_id, bool $active, int $actor_user_id): void
    {
        if (! $active && ($target_user_id === $actor_user_id || user_can($target_user_id, 'manage_options'))) {
            return;
        }
        $was_active = self::is_active($target_user_id);
        if ($was_active === $active) {
            return;
        }
        update_user_meta($target_user_id, self::STATUS_META, $active ? 'active' : 'inactive');
        self::audit($target_user_id, $actor_user_id, $active ? 'reactivated' : 'deactivated', self::user_groups($target_user_id), self::user_groups($target_user_id));
    }

    public static function audit_identity_event(int $target_user_id, int $actor_user_id, string $event): void
    {
        if (! in_array($event, array('microsoft_linked', 'microsoft_unlinked', 'invitation_created', 'invitation_resent', 'invitation_canceled', 'invitation_activated'), true)) {
            return;
        }
        self::audit($target_user_id, $actor_user_id, $event, array(), array());
    }

    private static function audit(int $target_user_id, int $actor_user_id, string $event, array $before, array $after): void
    {
        $entries = (array) get_option(self::AUDIT_OPTION, array());
        $entries[] = array(
            'target_user_id' => $target_user_id,
            'actor_user_id' => $actor_user_id,
            'event' => $event,
            'groups_before' => array_values($before),
            'groups_after' => array_values($after),
            'timestamp' => gmdate('c'),
        );
        update_option(self::AUDIT_OPTION, array_slice($entries, -100), false);
    }

    public static function ensure_admin_capability(): void
    {
        $administrator = get_role('administrator');
        if ($administrator) {
            if (! $administrator->has_cap(self::MANAGE_USERS)) {
                $administrator->add_cap(self::MANAGE_USERS);
            }
            if (! $administrator->has_cap('ssf_manage_microsoft_login')) {
                $administrator->add_cap('ssf_manage_microsoft_login');
            }
        }
    }
}

SSF_Access_Control::boot();
