<?php
/**
 * Roles and capabilities.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsfartyg_Roles
{
    public function __construct()
    {
        add_filter('map_meta_cap', array($this, 'map_ship_caps'), 10, 4);
        add_action('admin_init', array(__CLASS__, 'add_role'));
    }

    public static function add_role(): void
    {
        add_role(
            'ssf_fartygsombud',
            __('Fartygsombud', 'ssf-medlemsfartyg'),
            array(
                'read' => true,
                'edit_own_ssf_ships' => true,
                'upload_files' => true,
            )
        );

        $admin = get_role('administrator');
        if ($admin) {
            $caps = array(
                'edit_ssf_ship',
                'read_ssf_ship',
                'delete_ssf_ship',
                'edit_ssf_ships',
                'edit_others_ssf_ships',
                'publish_ssf_ships',
                'read_private_ssf_ships',
                'delete_ssf_ships',
                'delete_private_ssf_ships',
                'delete_published_ssf_ships',
                'delete_others_ssf_ships',
                'edit_private_ssf_ships',
                'edit_published_ssf_ships',
                'manage_ssf_ships',
                'export_ssf_ships',
            );

            foreach ($caps as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    public function map_ship_caps(array $caps, string $cap, int $user_id, array $args): array
    {
        if (! in_array($cap, array('edit_ssf_ship', 'read_ssf_ship', 'delete_ssf_ship'), true)) {
            return $caps;
        }

        $post_id = isset($args[0]) ? (int) $args[0] : 0;
        $post = $post_id ? get_post($post_id) : null;
        if (! $post || 'medlemsfartyg' !== $post->post_type) {
            return $caps;
        }

        if (user_can($user_id, 'manage_options')) {
            return array('manage_options');
        }

        if ('edit_ssf_ship' === $cap && self::user_can_edit_ship($user_id, $post_id)) {
            return array('edit_own_ssf_ships');
        }

        if ('read_ssf_ship' === $cap && ('publish' === $post->post_status || self::user_can_edit_ship($user_id, $post_id))) {
            return array('read');
        }

        return array('do_not_allow');
    }

    public static function user_can_edit_ship(int $user_id, int $ship_id): bool
    {
        $owners = array_map('intval', (array) get_post_meta($ship_id, '_ssf_ship_owner_users', true));
        return in_array($user_id, $owners, true);
    }
}
