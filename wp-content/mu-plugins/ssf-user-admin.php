<?php
/**
 * SSF user administration. Microsoft Login continues to own OAuth and tokens.
 *
 * @package SSF
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_User_Admin
{
    public const PAGE = 'ssf-users';

    public static function boot(): void
    {
        add_action('admin_menu', array(__CLASS__, 'register_page'), 25);
        add_action('admin_post_ssf_user_save_groups', array(__CLASS__, 'save_groups'));
        add_action('admin_post_ssf_user_set_active', array(__CLASS__, 'set_active'));
        add_action('admin_post_ssf_user_disconnect', array(__CLASS__, 'disconnect'));
        add_action('admin_post_ssf_user_reactivate', array(__CLASS__, 'reactivate'));
        add_action('admin_post_ssf_user_delete', array(__CLASS__, 'delete_user'));
    }

    public static function register_page(): void
    {
        $parent = class_exists('SSF_Admin_Navigation') ? SSF_Admin_Navigation::ROOT : 'users.php';
        add_submenu_page($parent, 'Användare & behörigheter', 'Användare & behörigheter', SSF_Access_Control::MANAGE_USERS, self::PAGE, array(__CLASS__, 'render'), 80);
    }

    public static function url(string $tab = 'users', int $user_id = 0): string
    {
        $args = array('page' => self::PAGE, 'ssf_users_tab' => $tab);
        if ($user_id) {
            $args['user_id'] = $user_id;
        }
        return add_query_arg($args, admin_url('admin.php'));
    }

    public static function render(): void
    {
        if (! SSF_Access_Control::can_manage_users()) {
            wp_die('Du saknar behörighet att hantera SSF-användare.');
        }
        $tab = sanitize_key((string) ($_GET['ssf_users_tab'] ?? 'users'));
        $tabs = array('users' => 'Användare', 'add' => 'Lägg till användare', 'groups' => 'Behörighetsgrupper', 'invitations' => 'Inbjudningar');
        if (! isset($tabs[$tab])) {
            $tab = 'users';
        }
        echo '<div class="wrap"><h1>Användare &amp; behörigheter</h1><p>SSF-behörigheter styr vad användaren får göra. Microsoft-kopplingen verifierar identiteten separat.</p>';
        echo '<nav class="nav-tab-wrapper" aria-label="Användare och behörigheter">';
        foreach ($tabs as $key => $label) {
            echo '<a class="nav-tab ' . esc_attr($key === $tab ? 'nav-tab-active' : '') . '" href="' . esc_url(self::url($key)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        if (class_exists('SSF_Admin_Feedback')) {
            SSF_Admin_Feedback::render_inline('users');
        }
        if (isset($_GET['ssf_user_notice'])) {
            $notice = sanitize_key((string) wp_unslash($_GET['ssf_user_notice']));
            $messages = array('saved' => 'Behörigheterna har sparats.', 'inactive' => 'Användaren har kopplats bort och status är Avslutad.', 'active' => 'Användaren har återaktiverats.', 'deleted' => 'Användaren har tagits bort permanent.', 'assigned' => 'Användaren har öppna ärenden som bör omfördelas.');
            if (isset($messages[$notice])) {
                echo '<div class="notice ' . esc_attr('assigned' === $notice ? 'notice-error' : 'notice-success') . ' inline"><p>' . esc_html($messages[$notice]) . '</p></div>';
            }
        }
        if ('add' === $tab) {
            self::render_add();
        } elseif ('groups' === $tab) {
            self::render_groups();
        } elseif ('invitations' === $tab) {
            self::render_invitations();
        } else {
            $user_id = absint($_GET['user_id'] ?? 0);
            $user_id ? self::render_user($user_id) : self::render_users();
        }
        echo '</div>';
    }

    private static function render_users(): void
    {
        echo '<table class="widefat striped" style="margin-top:20px"><thead><tr><th>Namn</th><th>E-post</th><th>SSF-status</th><th>Behörighetsgrupper</th><th>Microsoft</th><th>Senaste inloggning</th></tr></thead><tbody>';
        foreach (get_users(array('orderby' => 'display_name', 'order' => 'ASC')) as $user) {
            $id = (int) $user->ID;
            $labels = array();
            foreach (SSF_Access_Control::user_groups($id) as $key) {
                $labels[] = SSF_Access_Control::groups()[$key]['label'];
            }
            $last_login = (int) get_user_meta($id, '_ssf_m365_last_login', true);
            echo '<tr><td><a href="' . esc_url(self::url('users', $id)) . '">' . esc_html($user->display_name ?: $user->user_login) . '</a></td><td>' . esc_html($user->user_email) . '</td><td>' . esc_html(SSF_Access_Control::status_label($id)) . '</td><td>' . esc_html($labels ? implode(', ', $labels) : 'Inga') . '</td><td>' . esc_html(self::is_linked($id) ? 'Kopplat' : 'Ej kopplat') . '</td><td>' . esc_html($last_login ? wp_date('Y-m-d H:i', $last_login) : '–') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_user(int $user_id): void
    {
        $user = get_userdata($user_id);
        if (! $user) {
            wp_die('Användaren finns inte.');
        }
        $active = SSF_Access_Control::is_active($user_id);
        echo '<p><a href="' . esc_url(self::url()) . '">← Alla användare</a></p><h2>' . esc_html($user->display_name ?: $user->user_login) . '</h2><p>' . esc_html($user->user_email) . '</p>';
        echo '<h3>SSF-status</h3><p><strong>' . esc_html(self::user_status($user_id)) . '</strong></p>';
        echo '<h3>Behörigheter</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_user_save_groups"><input type="hidden" name="user_id" value="' . esc_attr((string) $user_id) . '">';
        wp_nonce_field('ssf_user_save_groups_' . $user_id);
        foreach (SSF_Access_Control::groups() as $key => $group) {
            echo '<p><label><input type="checkbox" name="groups[]" value="' . esc_attr($key) . '" ' . checked(in_array($key, SSF_Access_Control::user_groups($user_id), true), true, false) . '> ' . esc_html($group['label']) . '</label></p>';
        }
        submit_button('Spara behörigheter');
        echo '</form><h3>Microsoft</h3><p>' . esc_html(self::is_linked($user_id) ? '✓ Microsoft-konto kopplat' : 'Inget Microsoft-konto kopplat') . '</p>';
        echo '<p><a class="button" href="' . esc_url(add_query_arg(array('page' => 'ssf-member-portal-microsoft365', 'm365_tab' => 'accounts', 'user_id' => $user_id), admin_url('admin.php'))) . '">Visa Microsoft-koppling</a></p>';
        echo '<h3>SSF-åtkomst</h3>';
        if ($active) {
            $open = self::open_assignments($user_id);
            if ($open) {
                echo '<div class="notice notice-warning inline"><p>Användaren är ansvarig för ' . esc_html((string) count($open)) . ' öppna ärenden. Omfördela dem innan åtkomsten tas bort.</p><ul>';
                foreach ($open as $post) {
                    echo '<li><a href="' . esc_url(get_edit_post_link($post->ID)) . '">' . esc_html(get_post_meta($post->ID, '_ssf_application_number', true) ?: $post->post_title) . '</a></li>';
                }
                echo '</ul></div>';
            } elseif ($user_id !== get_current_user_id() && ! user_can($user_id, 'manage_options')) {
                self::render_status_form($user_id, false, 'Ta bort SSF-åtkomst');
            }
        } else {
            self::render_status_form($user_id, true, 'Återaktivera SSF-åtkomst');
        }
    }

    private static function render_status_form(int $user_id, bool $active, string $label): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_user_set_active"><input type="hidden" name="user_id" value="' . esc_attr((string) $user_id) . '"><input type="hidden" name="active" value="' . ($active ? '1' : '0') . '">';
        wp_nonce_field('ssf_user_set_active_' . $user_id);
        submit_button($label, $active ? 'primary' : 'secondary');
        echo '</form>';
    }

    private static function render_add(): void
    {
        echo '<div class="postbox" style="max-width:760px;padding:20px;margin-top:20px"><h2>Lägg till SSF-användare</h2><p>Inbjudan använder det befintliga säkra Microsoft-aktiveringsflödet.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_m365_create_invitation">';
        wp_nonce_field('ssf_m365_create_invitation');
        echo '<p><label>Namn<br><input class="regular-text" name="display_name" required></label></p><p><label>SSF-e-post<br><input class="regular-text" type="email" name="email" required></label></p><fieldset><legend><strong>Behörighetsgrupper</strong></legend>';
        foreach (SSF_Access_Control::groups() as $key => $group) {
            echo '<p><label><input type="checkbox" name="groups[]" value="' . esc_attr($key) . '"> ' . esc_html($group['label']) . '</label></p>';
        }
        echo '</fieldset>';
        submit_button('Skapa och skicka inbjudan');
        echo '</form></div>';
    }

    private static function render_groups(): void
    {
        echo '<table class="widefat striped" style="max-width:920px;margin-top:20px"><thead><tr><th>Grupp</th><th>Ger åtkomst till</th></tr></thead><tbody>';
        foreach (SSF_Access_Control::groups() as $group) {
            echo '<tr><th>' . esc_html($group['label']) . '</th><td>' . esc_html($group['description']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_invitations(): void
    {
        $invitations = (array) get_option('ssf_microsoft_login_invitations', array());
        echo '<table class="widefat striped" style="margin-top:20px"><thead><tr><th>Användare</th><th>Status</th><th>Skapad</th><th>Åtgärder</th></tr></thead><tbody>';
        foreach (array_reverse($invitations) as $id => $invitation) {
            $user_id = (int) ($invitation['user_id'] ?? 0);
            $user = get_userdata($user_id);
            $status = ! empty($invitation['used_at']) ? 'Aktiverad' : (! empty($invitation['canceled_at']) ? 'Avbruten' : (strtotime((string) ($invitation['expires_at'] ?? '')) > time() ? 'Väntar på aktivering' : 'Utgången'));
            echo '<tr><td>' . esc_html($user ? $user->display_name . ' · ' . $user->user_email : 'Okänd användare') . '</td><td>' . esc_html($status) . '</td><td>' . esc_html((string) ($invitation['created_at'] ?? '')) . '</td><td>';
            if ($user_id && 'Aktiverad' !== $status) {
                echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_resend_invitation&user_id=' . $user_id), 'ssf_m365_resend_invitation_' . $user_id)) . '">Skicka igen</a> ';
            }
            if ('Väntar på aktivering' === $status) {
                echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_cancel_invitation&invite_id=' . rawurlencode((string) $id)), 'ssf_m365_cancel_invitation_' . $id)) . '">Avbryt</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function save_groups(): void
    {
        $user_id = absint($_POST['user_id'] ?? 0);
        if (! SSF_Access_Control::can_manage_users() || ! $user_id || ! get_userdata($user_id) || ! check_admin_referer('ssf_user_save_groups_' . $user_id)) {
            wp_die('Du saknar behörighet.');
        }
        if ($user_id === get_current_user_id() && ! current_user_can('manage_options')) {
            wp_die('Du kan inte ändra dina egna systembehörigheter.');
        }
        if (SSF_Access_Control::STATUS_TERMINATED === SSF_Access_Control::status($user_id)) {
            wp_die('Återaktivera användaren och välj nya behörigheter i samma steg.');
        }
        $groups = isset($_POST['groups']) && is_array($_POST['groups']) ? (array) wp_unslash($_POST['groups']) : array();
        SSF_Access_Control::save_groups($user_id, $groups, get_current_user_id());
        wp_safe_redirect(add_query_arg('ssf_user_notice', 'saved', self::return_url($user_id)));
        exit;
    }

    public static function set_active(): void
    {
        $user_id = absint($_POST['user_id'] ?? 0);
        if (! SSF_Access_Control::can_manage_users() || ! $user_id || ! get_userdata($user_id) || ! check_admin_referer('ssf_user_set_active_' . $user_id)) {
            wp_die('Du saknar behörighet.');
        }
        $active = '1' === (string) ($_POST['active'] ?? '0');
        if (! $active && ($user_id === get_current_user_id() || user_can($user_id, 'manage_options'))) {
            wp_die('Administratörskonton kan inte inaktiveras här.');
        }
        if ($active) {
            SSF_Access_Control::reactivate($user_id, array(), get_current_user_id());
        } else {
            SSF_Access_Control::disconnect($user_id, get_current_user_id());
            self::remove_invitations($user_id);
        }
        wp_safe_redirect(add_query_arg('ssf_user_notice', $active ? 'active' : 'inactive', self::return_url($user_id)));
        exit;
    }

    public static function disconnect(): void
    {
        $user_id = absint($_POST['user_id'] ?? 0);
        if (! SSF_Access_Control::can_manage_users() || ! $user_id || ! get_userdata($user_id) || ! check_admin_referer('ssf_user_disconnect_' . $user_id)) {
            wp_die('Du saknar behörighet.');
        }
        if ($user_id === get_current_user_id() || user_can($user_id, 'manage_options')) {
            wp_die('Administratörskonton kan inte kopplas bort här.');
        }
        if (! SSF_Access_Control::disconnect($user_id, get_current_user_id())) {
            wp_die('Användaren kunde inte kopplas bort.');
        }
        self::remove_invitations($user_id);
        wp_safe_redirect(add_query_arg('ssf_user_notice', 'inactive', self::return_url($user_id)));
        exit;
    }

    public static function reactivate(): void
    {
        $user_id = absint($_POST['user_id'] ?? 0);
        if (! SSF_Access_Control::can_manage_users() || ! $user_id || ! get_userdata($user_id) || ! check_admin_referer('ssf_user_reactivate_' . $user_id)) {
            wp_die('Du saknar behörighet.');
        }
        $groups = isset($_POST['groups']) && is_array($_POST['groups']) ? (array) wp_unslash($_POST['groups']) : array();
        if (! SSF_Access_Control::reactivate($user_id, $groups, get_current_user_id())) {
            wp_die('Endast en avslutad användare kan återaktiveras.');
        }
        wp_safe_redirect(add_query_arg('ssf_user_notice', 'active', self::return_url($user_id)));
        exit;
    }

    public static function delete_user(): void
    {
        $user_id = absint($_POST['user_id'] ?? 0);
        $target = $user_id ? get_userdata($user_id) : false;
        if (! SSF_Access_Control::can_manage_users() || ! $target instanceof WP_User || ! check_admin_referer('ssf_user_delete_' . $user_id)) {
            wp_die('Du saknar behörighet.');
        }
        if ($user_id === get_current_user_id()) {
            wp_die('Du kan inte ta bort ditt eget konto.');
        }
        if (user_can($user_id, 'manage_options')) {
            wp_die('Administratörskonton kan inte tas bort här.');
        }
        $confirmation = isset($_POST['delete_confirmation']) && is_scalar($_POST['delete_confirmation']) ? trim((string) wp_unslash($_POST['delete_confirmation'])) : '';
        if ('TA BORT' !== $confirmation) {
            wp_die('Skriv TA BORT exakt för att bekräfta permanent borttagning.');
        }
        $actor_id = get_current_user_id();
        self::snapshot_historical_references($user_id, (string) ($target->display_name ?: $target->user_login));
        SSF_Access_Control::destroy_sessions($user_id);
        self::remove_invitations($user_id);
        SSF_Access_Control::audit_identity_event($user_id, $actor_id, 'user_deleted');
        if (! function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        if (! wp_delete_user($user_id, $actor_id)) {
            wp_die('Användaren kunde inte tas bort. Inga verksamhetsposter har raderats.');
        }
        $return = class_exists('SSF_Workspace') ? SSF_Workspace::url('anvandare') : self::url();
        wp_safe_redirect(add_query_arg('ssf_user_notice', 'deleted', $return));
        exit;
    }

    private static function return_url(int $user_id): string
    {
        $workspace_url = class_exists('SSF_Workspace') ? SSF_Workspace::url('anvandare/' . $user_id) : '';
        $referer = wp_get_referer();
        return $workspace_url && $referer && 0 === strpos($referer, $workspace_url)
            ? $workspace_url
            : self::url('users', $user_id);
    }

    public static function open_assignments(int $user_id): array
    {
        if (! post_type_exists('ssf_application')) {
            return array();
        }
        $cases = get_posts(array('post_type' => 'ssf_application', 'post_status' => 'private', 'posts_per_page' => -1, 'meta_key' => '_ssf_assigned_user', 'meta_value' => $user_id));
        return array_values(array_filter($cases, static function ($case): bool {
            $membership = (string) get_post_meta($case->ID, '_ssf_membership_status', true);
            $status = (string) get_post_meta($case->ID, '_ssf_process_status', true);
            return ! in_array($membership, array('member_ship', 'closed'), true) && ! in_array($status, array('rejected', 'archived'), true);
        }));
    }

    public static function reference_summary(int $user_id): array
    {
        $summary = array('applications' => 0, 'inspections' => 0, 'news' => 0, 'history_events' => 0);
        if (post_type_exists('ssf_application')) {
            foreach (get_posts(array('post_type' => 'ssf_application', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids')) as $application_id) {
                $post = get_post($application_id);
                $history = (array) get_post_meta($application_id, '_ssf_application_history', true);
                $history_count = 0;
                foreach ($history as $entry) {
                    if ((int) ($entry['author'] ?? 0) === $user_id || (int) ($entry['actor_if_known'] ?? 0) === $user_id) {
                        ++$history_count;
                    }
                }
                $inspectors = array_map('intval', (array) get_post_meta($application_id, '_ssf_inspector_ids', true));
                $referenced = ($post instanceof WP_Post && (int) $post->post_author === $user_id)
                    || (int) get_post_meta($application_id, '_ssf_assigned_user', true) === $user_id
                    || in_array($user_id, $inspectors, true)
                    || $history_count > 0;
                if ($referenced) {
                    ++$summary['applications'];
                    $summary['history_events'] += $history_count;
                }
            }
        }
        if (post_type_exists('ssf_membership_insp')) {
            foreach (get_posts(array('post_type' => 'ssf_membership_insp', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids')) as $inspection_id) {
                $record = (array) get_post_meta($inspection_id, '_ssf_membership_inspection', true);
                $events = 0;
                foreach ((array) ($record['audit'] ?? array()) as $event) {
                    $events += (int) ((int) ($event['user_id'] ?? 0) === $user_id);
                }
                $photo_match = false;
                foreach ((array) ($record['photos'] ?? array()) as $photo) {
                    $photo_match = $photo_match || (int) ($photo['created_by'] ?? 0) === $user_id;
                }
                $referenced = (int) ($record['lead_inspector_user_id'] ?? 0) === $user_id
                    || (int) ($record['co_inspector_user_id'] ?? 0) === $user_id
                    || isset($record['confirmations'][$user_id]) || $events > 0 || $photo_match;
                if ($referenced) {
                    ++$summary['inspections'];
                    $summary['history_events'] += $events;
                }
            }
        }
        $summary['news'] = count(get_posts(array('post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'author' => $user_id)));
        foreach ((array) get_option(SSF_Access_Control::AUDIT_OPTION, array()) as $entry) {
            if ((int) ($entry['target_user_id'] ?? 0) === $user_id || (int) ($entry['actor_user_id'] ?? 0) === $user_id) {
                ++$summary['history_events'];
            }
        }
        return $summary;
    }

    private static function snapshot_historical_references(int $user_id, string $display_name): void
    {
        $snapshot = array('display_name' => sanitize_text_field($display_name), 'historical_user_id' => $user_id);
        foreach (get_posts(array('post_type' => array('post', 'ssf_application', 'ssf_membership_insp', 'medlemsfartyg'), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'author' => $user_id)) as $post_id) {
            update_post_meta($post_id, '_ssf_historical_author', $snapshot);
        }
        if (post_type_exists('ssf_application')) {
            foreach (get_posts(array('post_type' => 'ssf_application', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids')) as $application_id) {
                $history = (array) get_post_meta($application_id, '_ssf_application_history', true);
                $changed = false;
                foreach ($history as &$entry) {
                    if ((int) ($entry['author'] ?? 0) === $user_id || (int) ($entry['actor_if_known'] ?? 0) === $user_id) {
                        $entry['actor_name'] = $snapshot['display_name'];
                        $changed = true;
                    }
                }
                unset($entry);
                if ($changed) {
                    update_post_meta($application_id, '_ssf_application_history', $history);
                }
                $inspectors = array_map('intval', (array) get_post_meta($application_id, '_ssf_inspector_ids', true));
                if ((int) get_post_meta($application_id, '_ssf_assigned_user', true) === $user_id || in_array($user_id, $inspectors, true)) {
                    self::store_actor_snapshot($application_id, $user_id, $snapshot);
                }
            }
        }
        if (post_type_exists('ssf_membership_insp')) {
            foreach (get_posts(array('post_type' => 'ssf_membership_insp', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids')) as $inspection_id) {
                $record = (array) get_post_meta($inspection_id, '_ssf_membership_inspection', true);
                $changed = false;
                foreach ((array) ($record['audit'] ?? array()) as $index => $event) {
                    if ((int) ($event['user_id'] ?? 0) === $user_id) {
                        $record['audit'][$index]['actor_name'] = $snapshot['display_name'];
                        $changed = true;
                    }
                }
                foreach ((array) ($record['confirmations'] ?? array()) as $key => $confirmation) {
                    if ((int) ($confirmation['user_id'] ?? $key) === $user_id) {
                        $record['confirmations'][$key]['actor_name'] = $snapshot['display_name'];
                        $changed = true;
                    }
                }
                if ((int) ($record['lead_inspector_user_id'] ?? 0) === $user_id || (int) ($record['co_inspector_user_id'] ?? 0) === $user_id) {
                    $record['actor_snapshots'][(string) $user_id] = $snapshot;
                    $changed = true;
                }
                foreach ((array) ($record['photos'] ?? array()) as $index => $photo) {
                    if ((int) ($photo['created_by'] ?? 0) === $user_id) {
                        $record['photos'][$index]['creator_name'] = $snapshot['display_name'];
                        $changed = true;
                    }
                }
                if ($changed) {
                    update_post_meta($inspection_id, '_ssf_membership_inspection', $record);
                }
            }
        }
        $audit = (array) get_option(SSF_Access_Control::AUDIT_OPTION, array());
        foreach ($audit as &$entry) {
            if ((int) ($entry['target_user_id'] ?? 0) === $user_id) {
                $entry['target_name'] = $snapshot['display_name'];
            }
            if ((int) ($entry['actor_user_id'] ?? 0) === $user_id) {
                $entry['actor_name'] = $snapshot['display_name'];
            }
        }
        unset($entry);
        update_option(SSF_Access_Control::AUDIT_OPTION, $audit, false);
    }

    private static function store_actor_snapshot(int $post_id, int $user_id, array $snapshot): void
    {
        $snapshots = (array) get_post_meta($post_id, '_ssf_historical_actors', true);
        $snapshots[(string) $user_id] = $snapshot;
        update_post_meta($post_id, '_ssf_historical_actors', $snapshots);
    }

    private static function remove_invitations(int $user_id): void
    {
        $invitations = (array) get_option('ssf_microsoft_login_invitations', array());
        foreach ($invitations as $id => $invitation) {
            if ((int) ($invitation['user_id'] ?? 0) === $user_id) {
                unset($invitations[$id]);
            }
        }
        update_option('ssf_microsoft_login_invitations', $invitations, false);
    }

    private static function is_linked(int $user_id): bool
    {
        return '' !== (string) get_user_meta($user_id, '_ssf_m365_tid', true) && '' !== (string) get_user_meta($user_id, '_ssf_m365_oid', true);
    }

    private static function user_status(int $user_id): string
    {
        return SSF_Access_Control::status_label($user_id);
    }
}

SSF_User_Admin::boot();
