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
            $messages = array('saved' => 'Behörigheterna har sparats.', 'inactive' => 'SSF-åtkomsten har tagits bort.', 'active' => 'SSF-åtkomsten har återaktiverats.', 'assigned' => 'Åtkomsten kan inte tas bort förrän öppna ärenden har omfördelats.');
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
            echo '<tr><td><a href="' . esc_url(self::url('users', $id)) . '">' . esc_html($user->display_name ?: $user->user_login) . '</a></td><td>' . esc_html($user->user_email) . '</td><td>' . esc_html(self::user_status($id)) . '</td><td>' . esc_html($labels ? implode(', ', $labels) : 'Inga') . '</td><td>' . esc_html(self::is_linked($id) ? 'Kopplat' : 'Ej kopplat') . '</td><td>' . esc_html($last_login ? wp_date('Y-m-d H:i', $last_login) : '–') . '</td></tr>';
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
        if (! $active && self::open_assignments($user_id)) {
            wp_safe_redirect(add_query_arg('ssf_user_notice', 'assigned', self::return_url($user_id)));
            exit;
        }
        SSF_Access_Control::set_active($user_id, $active, get_current_user_id());
        wp_safe_redirect(add_query_arg('ssf_user_notice', $active ? 'active' : 'inactive', self::return_url($user_id)));
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

    private static function is_linked(int $user_id): bool
    {
        return '' !== (string) get_user_meta($user_id, '_ssf_m365_tid', true) && '' !== (string) get_user_meta($user_id, '_ssf_m365_oid', true);
    }

    private static function user_status(int $user_id): string
    {
        if (! SSF_Access_Control::is_active($user_id)) {
            return 'Inaktiv / åtkomst borttagen';
        }
        foreach ((array) get_option('ssf_microsoft_login_invitations', array()) as $invitation) {
            if ((int) ($invitation['user_id'] ?? 0) === $user_id && empty($invitation['used_at']) && empty($invitation['canceled_at']) && strtotime((string) ($invitation['expires_at'] ?? '')) > time()) {
                return 'Inviterad';
            }
        }
        return 'Aktiv';
    }
}

SSF_User_Admin::boot();
