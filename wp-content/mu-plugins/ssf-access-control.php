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
    public const PREVIOUS_GROUP_META = '_ssf_previous_permission_groups';
    public const STATUS_META = '_ssf_access_status';
    public const AUDIT_OPTION = 'ssf_microsoft_login_permission_audit';
    public const MANAGE_USERS = 'ssf_manage_users';
    public const STATUS_INVITED = 'invited';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_TERMINATED = 'terminated';
    public const STATUS_BLOCKED = 'blocked';

    public static function boot(): void
    {
        add_filter('user_has_cap', array(__CLASS__, 'grant_capabilities'), 10, 4);
        add_filter('authenticate', array(__CLASS__, 'block_inactive_login'), 99, 3);
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
                'capabilities' => array('ssf_view_assigned_applications', 'ssf_view_application_details', 'ssf_edit_inspection', 'ssf_submit_inspection', 'ssf_send_application_message', 'ssf_inspect_v2'),
            ),
            'inspektionsadmin' => array(
                'label' => 'Inspektionsadministration',
                'description' => 'Administrera inspektionsmallar och inspektioner.',
                'capabilities' => array('ssf_inspect_v2', 'ssf_manage_inspections'),
            ),
            'nyheter' => array(
                'label' => 'Webbredaktör',
                'description' => 'Nyheter, artikelförslag, publicering och omvärldsbevakning.',
                'capabilities' => array('ssf_manage_news', 'ssf_news_view', 'ssf_news_edit', 'ssf_news_publish', 'ssf_news_suggestions_manage', 'ssf_news_sources_manage', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'upload_files'),
            ),
            'nyhetsutkast' => array(
                'label' => 'Nyhetsskribent',
                'description' => 'Skriva och förhandsgranska nyhetsutkast; kan inte publicera.',
                'capabilities' => array('ssf_news_view', 'ssf_news_edit', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'upload_files'),
            ),
            'systemadministration' => array(
                'label' => 'Systemadministration',
                'description' => 'Användare, systeminställningar, Microsoft och diagnostik.',
                'capabilities' => array('ssf_manage_microsoft_login', 'ssf_manage_permission_groups', self::MANAGE_USERS, 'ssf_manage_member_portal', 'manage_ssf_features', 'manage_ssf_releases', 'ssf_inspect_v2', 'ssf_manage_inspections'),
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
        return self::STATUS_ACTIVE === self::status($user_id);
    }

    public static function status(int $user_id): string
    {
        $status = (string) get_user_meta($user_id, self::STATUS_META, true);
        if ('' === $status) {
            return self::STATUS_ACTIVE;
        }
        if ('inactive' === $status) {
            return self::STATUS_TERMINATED;
        }
        return in_array($status, array(self::STATUS_INVITED, self::STATUS_ACTIVE, self::STATUS_TERMINATED, self::STATUS_BLOCKED), true)
            ? $status
            : self::STATUS_ACTIVE;
    }

    public static function status_label(int $user_id): string
    {
        return array(
            self::STATUS_INVITED => 'Inbjuden',
            self::STATUS_ACTIVE => 'Aktiv',
            self::STATUS_TERMINATED => 'Avslutad',
            self::STATUS_BLOCKED => 'Spärrad',
        )[self::status($user_id)];
    }

    public static function previous_groups(int $user_id): array
    {
        $stored = (array) get_user_meta($user_id, self::PREVIOUS_GROUP_META, true);
        return array_values(array_intersect(array_map('sanitize_key', array_filter($stored, 'is_scalar')), array_keys(self::groups())));
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
            if (in_array('administrator', (array) $user->roles, true) && ! empty($allcaps['manage_options'])) {
                return $allcaps;
            }
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
        self::audit($target_user_id, $actor_user_id, 'permissions_changed', $old, $new);
    }

    public static function set_active(int $target_user_id, bool $active, int $actor_user_id): void
    {
        if ($active) {
            self::reactivate($target_user_id, array(), $actor_user_id);
            return;
        }
        self::disconnect($target_user_id, $actor_user_id);
    }

    public static function mark_invited(int $target_user_id, int $actor_user_id): void
    {
        if (self::STATUS_TERMINATED === self::status($target_user_id) || self::STATUS_BLOCKED === self::status($target_user_id)) {
            return;
        }
        update_user_meta($target_user_id, self::STATUS_META, self::STATUS_INVITED);
        self::audit($target_user_id, $actor_user_id, 'user_invited', array(), self::user_groups($target_user_id));
    }

    public static function disconnect(int $target_user_id, int $actor_user_id): bool
    {
        if ($target_user_id <= 0 || $target_user_id === $actor_user_id || user_can($target_user_id, 'manage_options')) {
            return false;
        }
        if (self::STATUS_TERMINATED === self::status($target_user_id)) {
            return true;
        }
        $groups = self::user_groups($target_user_id);
        update_user_meta($target_user_id, self::PREVIOUS_GROUP_META, $groups);
        update_user_meta($target_user_id, self::GROUP_META, array());
        update_user_meta($target_user_id, self::STATUS_META, self::STATUS_TERMINATED);
        self::destroy_sessions($target_user_id);
        self::audit($target_user_id, $actor_user_id, 'user_disconnected', $groups, array());
        return true;
    }

    public static function reactivate(int $target_user_id, array $groups, int $actor_user_id): bool
    {
        if ($target_user_id <= 0 || self::STATUS_TERMINATED !== self::status($target_user_id)) {
            return false;
        }
        $selected = array_values(array_unique(array_intersect(array_map('sanitize_key', array_filter($groups, 'is_scalar')), array_keys(self::groups()))));
        update_user_meta($target_user_id, self::GROUP_META, $selected);
        update_user_meta($target_user_id, self::STATUS_META, self::STATUS_ACTIVE);
        self::audit($target_user_id, $actor_user_id, 'user_reactivated', self::previous_groups($target_user_id), $selected);
        return true;
    }

    public static function activate_invitation(int $target_user_id): void
    {
        if (self::STATUS_INVITED === self::status($target_user_id)) {
            update_user_meta($target_user_id, self::STATUS_META, self::STATUS_ACTIVE);
        }
    }

    public static function destroy_sessions(int $user_id): void
    {
        if (class_exists('WP_Session_Tokens')) {
            WP_Session_Tokens::get_instance($user_id)->destroy_all();
        }
    }

    public static function block_inactive_login($user, string $username = '', string $password = '')
    {
        if (! $user instanceof WP_User || self::is_active((int) $user->ID)) {
            return $user;
        }
        if (in_array('administrator', (array) $user->roles, true) && user_can($user, 'manage_options')) {
            return $user;
        }
        $message = self::STATUS_TERMINATED === self::status((int) $user->ID)
            ? 'Ditt SSF-konto är avslutat. Kontakta en administratör om du behöver tillgång igen.'
            : 'Ditt SSF-konto är inte aktivt ännu. Kontakta en administratör.';
        return new WP_Error('ssf_account_not_active', $message);
    }

    public static function audit_identity_event(int $target_user_id, int $actor_user_id, string $event): void
    {
        if (! in_array($event, array('microsoft_linked', 'microsoft_unlinked', 'invitation_created', 'invitation_resent', 'invitation_canceled', 'invitation_activated', 'user_deleted'), true)) {
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
            'target_name' => self::user_name($target_user_id),
            'actor_name' => self::user_name($actor_user_id),
            'event' => $event,
            'groups_before' => array_values($before),
            'groups_after' => array_values($after),
            'timestamp' => gmdate('c'),
        );
        update_option(self::AUDIT_OPTION, array_slice($entries, -100), false);
    }

    private static function user_name(int $user_id): string
    {
        $user = $user_id > 0 ? get_userdata($user_id) : false;
        return $user instanceof WP_User ? (string) ($user->display_name ?: $user->user_login) : '';
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
            if (! $administrator->has_cap('ssf_inspect_v2')) {
                $administrator->add_cap('ssf_inspect_v2');
            }
            if (! $administrator->has_cap('ssf_manage_inspections')) {
                $administrator->add_cap('ssf_manage_inspections');
            }
            foreach (array('ssf_manage_news', 'ssf_news_view', 'ssf_news_edit', 'ssf_news_publish', 'ssf_news_suggestions_manage', 'ssf_news_sources_manage') as $capability) {
                if (! $administrator->has_cap($capability)) {
                    $administrator->add_cap($capability);
                }
            }
        }
    }
}

SSF_Access_Control::boot();
