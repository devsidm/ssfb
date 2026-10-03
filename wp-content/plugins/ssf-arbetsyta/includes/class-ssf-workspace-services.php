<?php
/** Thin adapters to existing SSF services. No separate business data is stored here. */
if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Workspace_Services
{
    public static function boot(): void
    {
        add_action('ssf_workspace_register_services', array(__CLASS__, 'register'));
    }

    public static function register(): void
    {
        if (class_exists('SSF_Medlemsprocess_Plugin')) {
            SSF_Workspace::register_service(array(
                'id' => 'membership', 'label' => 'Ansökningar', 'description' => 'Handlägg medlemsansökningar',
                'route' => 'ansokningar', 'capability' => 'ssf_view_applications', 'order' => 10,
                'owns_heading' => true,
                'render' => array(__CLASS__, 'membership'), 'tasks' => array(__CLASS__, 'membership_tasks'),
            ));
            SSF_Workspace::register_service(array(
                'id' => 'inspections', 'label' => 'Inspektioner', 'description' => 'Mina tilldelade inspektioner',
                'route' => 'inspektioner', 'capability' => 'ssf_view_assigned_applications', 'order' => 20,
                'owns_heading' => true,
                'render' => array(__CLASS__, 'inspections'), 'tasks' => array(__CLASS__, 'inspection_tasks'),
            ));
        }
        if (post_type_exists('post')) {
            SSF_Workspace::register_service(array(
                'id' => 'news', 'label' => 'Nyheter', 'description' => 'Utkast, granskning och publicering',
                'icon' => 'news', 'route' => 'nyheter', 'capability' => 'ssf_news_view', 'nav_group' => 'arbete', 'order' => 30,
                'owns_heading' => true, 'render' => array('SSF_News_Service', 'render'),
                'tasks' => array('SSF_News_Service', 'tasks'), 'badge_provider' => array('SSF_News_Service', 'badge'),
                'quick_actions' => array(
                    array('label' => 'Ny artikel', 'url' => add_query_arg('new', '1', SSF_Workspace::url('nyheter/egna'))),
                    array('label' => 'Lägg till extern artikel', 'url' => SSF_Workspace::url('nyheter/extern')),
                ),
            ));
        }
        if (post_type_exists('medlemsfartyg')) {
            SSF_Workspace::register_service(array(
                'id' => 'news-tip', 'label' => 'Tipsa om artikel', 'description' => 'Skicka en länk till SSF:s webbredaktion',
                'icon' => 'link', 'route' => 'tipsa-om-artikel', 'capability' => 'ssf_news_tip', 'nav_group' => 'arbete', 'order' => 31,
                'render' => array(__CLASS__, 'member_tip'), 'owns_heading' => true,
            ));
        }
        if (class_exists('SSF\MemberPortal\Core\Plugin')) {
            SSF_Workspace::register_service(array(
                'id' => 'annual-meetings', 'label' => 'Årsmöte', 'description' => 'Anmälningar och motioner',
                'route' => 'arsmote', 'capability' => 'manage_ssf_annual_meetings', 'order' => 40,
                'render' => array(__CLASS__, 'annual_meetings'),
            ));
        }
        if (class_exists('SSF_Access_Control')) {
            SSF_Workspace::register_service(array(
                'id' => 'users', 'label' => 'Användare & behörigheter', 'description' => 'Hantera SSF-åtkomst',
                'icon' => 'users', 'route' => 'anvandare', 'capability' => SSF_Access_Control::MANAGE_USERS,
                'nav_group' => 'administration', 'order' => 70, 'render' => array(__CLASS__, 'users'),
                'badge_provider' => array(__CLASS__, 'users_badge'),
                'quick_actions' => array(array('label' => 'Bjud in användare', 'url' => SSF_Workspace::url('anvandare/ny'))),
            ));
            SSF_Workspace::register_service(array(
                'id' => 'microsoft', 'label' => 'Microsoft-konfiguration', 'description' => 'Katalog, inloggning, SharePoint och e-post',
                'route' => 'system/microsoft', 'capability' => 'ssf_manage_microsoft_login',
                'group' => 'administration', 'order' => 80, 'render' => array(__CLASS__, 'microsoft'),
            ));
        }
    }

    public static function member_tip(string $tail)
    {
        if ('' !== $tail) {
            return self::error('Sidan kunde inte hittas.');
        }
        return SSF_News_Service::render('tips');
    }

    private static function error(string $message, int $status = 404): WP_Error
    {
        return new WP_Error('ssf_workspace_access', $message, array('status' => $status));
    }

    public static function membership(string $tail)
    {
        $plugin = SSF_Medlemsprocess_Plugin::instance();
        $id = 0;
        if ('aspiranter' === $tail) {
            $_GET['view'] = 'aspiranter';
        } elseif ('' !== $tail) {
            if (! ctype_digit($tail)) {
                return self::error('Ärendet kunde inte hittas.');
            }
            $id = (int) $tail;
            if (SSF_Medlemsprocess_Application::POST_TYPE !== get_post_type($id)
                || ! current_user_can('edit_ssf_application', $id)) {
                return self::error('Du har inte åtkomst till ärendet.', 403);
            }
            $_GET['application'] = (string) $id;
        }
        self::membership_assets('ssf-membership-portal');
        return $plugin->portal->shortcode();
    }

    public static function inspections(string $tail)
    {
        if ('' !== $tail && ! ctype_digit($tail)) {
            return self::error('Inspektionen kunde inte hittas.');
        }
        if ('' !== $tail) {
            if (! SSF_Medlemsprocess_Plugin::instance()->inspector->can_view_case((int) $tail, get_current_user_id())) {
                return self::error('Du har inte åtkomst till inspektionen.', 403);
            }
            $_GET['case'] = $tail;
        }
        self::membership_assets('ssf-inspector-portal');
        return SSF_Medlemsprocess_Plugin::instance()->inspector->portal();
    }

    private static function membership_assets(string $portal): void
    {
        wp_enqueue_style('ssf-medlemsprocess', SSF_MEDLEMSPROCESS_URL . 'assets/css/ssf-medlemsprocess.css', array(), SSF_MEDLEMSPROCESS_VERSION);
        wp_enqueue_script('ssf-medlemsprocess', SSF_MEDLEMSPROCESS_URL . 'assets/js/ssf-medlemsprocess.js', array(), SSF_MEDLEMSPROCESS_VERSION, true);
        $file = 'ssf-inspector-portal' === $portal ? 'ssf-inspector-portal' : 'ssf-membership-portal';
        wp_enqueue_style($portal, SSF_MEDLEMSPROCESS_URL . 'assets/css/' . $file . '.css', array('ssf-medlemsprocess'), SSF_MEDLEMSPROCESS_VERSION);
        wp_enqueue_script($portal, SSF_MEDLEMSPROCESS_URL . 'assets/js/' . $file . '.js', array(), SSF_MEDLEMSPROCESS_VERSION, true);
    }

    public static function membership_tasks(int $user_id): array
    {
        $ids = get_posts(array(
            'post_type' => SSF_Medlemsprocess_Application::POST_TYPE, 'post_status' => 'private',
            'posts_per_page' => 20, 'fields' => 'ids', 'orderby' => 'modified', 'order' => 'DESC',
            'meta_key' => '_ssf_assigned_user', 'meta_value' => $user_id,
        ));
        $tasks = array();
        foreach ($ids as $id) {
            if (! user_can($user_id, 'edit_ssf_application', $id)) {
                continue;
            }
            $status = SSF_Medlemsprocess_Application::status((int) $id);
            if ('rejected' === $status || in_array(SSF_Medlemsprocess_Application::membership_status((int) $id), array('member_ship', 'closed'), true)) {
                continue;
            }
            $tasks[] = array(
                'id' => 'application-' . $id, 'entity_id' => (int) $id,
                'title' => (string) get_post_meta($id, '_ssf_application_number', true) . ' · ' . get_the_title($id),
                'description' => SSF_Medlemsprocess_Application::status_label($status),
                'status' => $status, 'updated_at' => get_post_modified_time('c', true, $id),
                'url' => SSF_Workspace::url('ansokningar/' . $id),
            );
        }
        return $tasks;
    }

    public static function inspection_tasks(int $user_id): array
    {
        $ids = array_slice(SSF_Medlemsprocess_Plugin::instance()->inspector->assigned_applications($user_id), 0, 20);
        $tasks = array();
        foreach ($ids as $id) {
            $tasks[] = array(
                'id' => 'inspection-' . $id, 'entity_id' => (int) $id, 'title' => get_the_title($id),
                'description' => 'Tilldelad inspektion', 'updated_at' => get_post_modified_time('c', true, $id),
                'url' => SSF_Workspace::url('inspektioner/' . $id),
            );
        }
        return $tasks;
    }

    public static function news(string $tail)
    {
        if ('ny' === $tail) {
            return self::news_form(null);
        }
        if ('' !== $tail) {
            if (! ctype_digit($tail)) {
                return self::error('Artikeln kunde inte hittas.');
            }
            $post = get_post((int) $tail);
            if (! $post || 'post' !== $post->post_type || ! current_user_can('edit_post', $post->ID)) {
                return self::error('Du har inte åtkomst till artikeln.', 403);
            }
            return self::news_form($post);
        }
        $posts = get_posts(array('post_type' => 'post', 'post_status' => array('draft', 'pending', 'future', 'publish'), 'posts_per_page' => 40, 'orderby' => 'modified', 'order' => 'DESC'));
        $html = '<p><a class="ssf-workspace-button" href="' . esc_url(SSF_Workspace::url('nyheter/ny')) . '">Ny artikel</a></p><ul class="ssf-workspace-list">';
        foreach ($posts as $post) {
            if (! current_user_can('edit_post', $post->ID)) {
                continue;
            }
            $html .= '<li><div><strong>' . esc_html(get_the_title($post)) . '</strong><span>' . esc_html(self::news_status($post->post_status)) . ' · ' . esc_html(get_the_modified_date('j F Y', $post)) . '</span></div><a href="' . esc_url(SSF_Workspace::url('nyheter/' . $post->ID)) . '">Öppna</a></li>';
        }
        return $html . '</ul>';
    }

    private static function news_status(string $status): string
    {
        return array('draft' => 'Utkast', 'pending' => 'Väntar på granskning', 'future' => 'Schemalagd', 'publish' => 'Publicerad')[$status] ?? $status;
    }

    private static function news_form(?WP_Post $post): string
    {
        $id = $post ? (int) $post->ID : 0;
        $can_publish = current_user_can('publish_posts');
        $html = '<p><a href="' . esc_url(SSF_Workspace::url('nyheter')) . '">← Till nyheter</a></p>';
        if (isset($_GET['saved'])) {
            $html .= '<p role="status" class="ssf-workspace-confirmation">Artikeln sparades.</p>';
        }
        if (isset($_GET['image_error'])) {
            $html .= '<p role="alert">Artikeln sparades, men bilden kunde inte laddas upp. Försök igen.</p>';
        }
        $html .= '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="ssf-workspace-form">'
            . '<input type="hidden" name="action" value="ssf_workspace_news_save"><input type="hidden" name="post_id" value="' . esc_attr((string) $id) . '">'
            . wp_nonce_field('ssf_workspace_news_' . $id, '_wpnonce', true, false)
            . '<label for="ssf-news-title">Rubrik</label><input id="ssf-news-title" name="title" type="text" required maxlength="200" value="' . esc_attr($post ? $post->post_title : '') . '">'
            . '<label for="ssf-news-content">Artikeltext</label><textarea id="ssf-news-content" name="content" rows="16">' . esc_textarea($post ? $post->post_content : '') . '</textarea>'
            . '<label for="ssf-news-image">Utvald bild (valfri)</label><input id="ssf-news-image" name="featured_image" type="file" accept="image/*">'
            . '<div class="ssf-workspace-form-actions"><button class="ssf-workspace-button" name="intent" value="draft" type="submit">Spara utkast</button>';
        if ($can_publish) {
            $html .= '<button class="ssf-workspace-button" name="intent" value="publish" type="submit">Publicera</button>';
        } else {
            $html .= '<button class="ssf-workspace-button" name="intent" value="pending" type="submit">Skicka till granskning</button>';
        }
        if ($post) {
            $html .= '<a href="' . esc_url(get_preview_post_link($post)) . '" target="_blank" rel="noopener">Förhandsgranska</a>';
            if (has_post_thumbnail($post)) {
                $html .= '<p>Utvald bild: ' . get_the_post_thumbnail($post, 'thumbnail') . '</p>';
            }
        }
        return $html . '</div></form>';
    }

    public static function save_news(): void
    {
        if (! SSF_Workspace::active_user() || (! current_user_can('ssf_manage_news') && ! current_user_can('manage_options'))) {
            wp_die('Du saknar behörighet.', '', array('response' => 403));
        }
        $id = absint($_POST['post_id'] ?? 0);
        if (! check_admin_referer('ssf_workspace_news_' . $id)) {
            wp_die('Ogiltig säkerhetskontroll.', '', array('response' => 403));
        }
        $post = $id ? get_post($id) : null;
        if ($id && (! $post || 'post' !== $post->post_type || ! current_user_can('edit_post', $id))) {
            wp_die('Du saknar åtkomst till artikeln.', '', array('response' => 403));
        }
        if (! $id && ! current_user_can('edit_posts')) {
            wp_die('Du kan inte skapa nyheter.', '', array('response' => 403));
        }
        $intent = sanitize_key((string) wp_unslash($_POST['intent'] ?? 'draft'));
        $status = 'publish' === $intent && current_user_can('publish_posts') ? 'publish' : ('pending' === $intent ? 'pending' : 'draft');
        $title = sanitize_text_field((string) wp_unslash($_POST['title'] ?? ''));
        if ('' === $title) {
            wp_die('Rubrik saknas.', '', array('response' => 400));
        }
        $args = array('post_type' => 'post', 'post_title' => $title, 'post_content' => wp_kses_post((string) wp_unslash($_POST['content'] ?? '')), 'post_status' => $status);
        if ($id) {
            $args['ID'] = $id;
            $result = wp_update_post($args, true);
        } else {
            $result = wp_insert_post($args, true);
        }
        if (is_wp_error($result)) {
            wp_die('Artikeln kunde inte sparas.', '', array('response' => 500));
        }
        if (! empty($_FILES['featured_image']['name'])) {
            if (! current_user_can('upload_files') || ! current_user_can('edit_post', (int) $result)) {
                wp_die('Du kan inte ladda upp bilder.', '', array('response' => 403));
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            $attachment_id = media_handle_upload('featured_image', (int) $result);
            if (is_wp_error($attachment_id)) {
                wp_safe_redirect(add_query_arg('image_error', '1', SSF_Workspace::url('nyheter/' . (int) $result)));
                exit;
            }
            set_post_thumbnail((int) $result, (int) $attachment_id);
        }
        wp_safe_redirect(add_query_arg('saved', '1', SSF_Workspace::url('nyheter/' . (int) $result)));
        exit;
    }

    public static function news_tasks(int $user_id): array
    {
        $posts = get_posts(array('post_type' => 'post', 'post_status' => 'pending', 'posts_per_page' => 10, 'orderby' => 'modified', 'order' => 'DESC'));
        $tasks = array();
        foreach ($posts as $post) {
            if (! user_can($user_id, 'edit_post', $post->ID)) {
                continue;
            }
            $tasks[] = array('id' => 'news-' . $post->ID, 'entity_id' => $post->ID, 'title' => $post->post_title,
                'description' => 'Väntar på granskning', 'updated_at' => $post->post_modified_gmt,
                'url' => SSF_Workspace::url('nyheter/' . $post->ID));
        }
        return $tasks;
    }

    public static function annual_meetings(string $tail)
    {
        $plugin = \SSF\MemberPortal\Core\Plugin::instance();
        $meetings = $plugin->workspace_meetings();
        if ('' === $tail) {
            $html = '<ul class="ssf-workspace-list">';
            foreach ($meetings->all() as $post) {
                $html .= '<li><strong>' . esc_html(get_the_title($post)) . '</strong><a href="' . esc_url(SSF_Workspace::url('arsmote/' . $post->ID)) . '">Öppna</a></li>';
            }
            return $html . '</ul>';
        }
        $parts = explode('/', $tail);
        $id = ctype_digit($parts[0]) ? (int) $parts[0] : 0;
        $post = $id ? get_post($id) : null;
        if (! $post || \SSF\MemberPortal\Modules\AnnualMeetings\Module::POST_TYPE !== $post->post_type) {
            return self::error('Årsmötet kunde inte hittas.');
        }
        $base = SSF_Workspace::url('arsmote/' . $id);
        $html = '<p><a href="' . esc_url(SSF_Workspace::url('arsmote')) . '">← Alla årsmöten</a></p><h2>' . esc_html(get_the_title($post)) . '</h2><nav class="ssf-workspace-tabs" aria-label="Årsmötets delar"><a href="' . esc_url($base) . '">Översikt</a><a href="' . esc_url(SSF_Workspace::url('arsmote/' . $id . '/anmalningar')) . '">Anmälningar</a><a href="' . esc_url(SSF_Workspace::url('arsmote/' . $id . '/motioner')) . '">Motioner</a></nav>';
        $section = $parts[1] ?? '';
        if (count($parts) > 2) {
            return self::error('Sidan kunde inte hittas.');
        }
        if ('anmalningar' === $section) {
            $service = $meetings->workspace_registration_service();
            $rows = $service->registrations($id);
            $html .= '<h3>Anmälningar</h3><p>' . esc_html((string) count($rows)) . ' anmälningar</p><div class="ssf-workspace-table-wrap"><table><thead><tr><th>Namn</th><th>E-post</th><th>Status</th><th>Program</th><th>Mat</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $entry = $service->details((int) $row->ID);
                $html .= '<tr><td>' . esc_html(trim($entry['first_name'] . ' ' . $entry['last_name'])) . '</td><td>' . esc_html($entry['email']) . '</td><td>' . esc_html($entry['status_label']) . '</td><td>' . esc_html(implode(', ', $entry['program_labels'])) . '</td><td>' . esc_html(implode(', ', $entry['food'])) . '</td></tr>';
            }
            $exports = '';
            foreach (array('csv' => 'Exportera CSV', 'xlsx' => 'Exportera Excel') as $format => $label) {
                $url = wp_nonce_url(add_query_arg(array('action' => 'ssf_member_portal_export_meeting_registrations', 'meeting_id' => $id, 'format' => $format), admin_url('admin-post.php')), 'ssf_member_portal_export_meeting_registrations');
                $exports .= '<a class="ssf-workspace-button" href="' . esc_url($url) . '">' . esc_html($label) . '</a> ';
            }
            return $html . '</tbody></table></div><p>' . $exports . '</p><p><a href="' . esc_url(admin_url('admin.php?page=ssf-member-portal-meeting-registrations')) . '">Avancerad hantering</a></p>';
        }
        if ('motioner' === $section) {
            $year = (int) ($meetings->data($id)['year'] ?? 0);
            $result = $plugin->workspace_sharepoint()->read_motion_folder($year);
            if (is_wp_error($result)) {
                return $html . '<h3>Motioner från SharePoint</h3><p role="status">Motionerna kunde inte uppdateras från SharePoint just nu. Försök igen senare.</p>';
            }
            $html .= '<h3>Motioner från SharePoint</h3><p>Endast läsning. Källa: SharePoint. Senast uppdaterat: ' . esc_html(wp_date('j F Y H:i', (int) ($result['fetched_at'] ?? 0))) . '.</p>';
            if (! empty($result['stale'])) {
                $html .= '<p role="status">Motionerna kunde inte uppdateras från SharePoint. Senast uppdaterade information visas.</p>';
            }
            $html .= '<ul class="ssf-workspace-list">';
            foreach ((array) ($result['items'] ?? array()) as $item) {
                $html .= '<li><div><strong>' . esc_html($item['name']) . '</strong><span>' . esc_html($item['modified']) . '</span></div><a href="' . esc_url($item['url']) . '" target="_blank" rel="noopener noreferrer">Läs i SharePoint</a></li>';
            }
            return $html . '</ul>' . (! empty($result['items']) ? '' : '<p>Inga motioner hittades.</p>');
        }
        if ('' !== $section) {
            return self::error('Sidan kunde inte hittas.');
        }
        $data = $meetings->data($id);
        $count = count($meetings->workspace_registration_service()->registrations($id));
        return $html . '<div class="ssf-workspace-cards"><div class="ssf-workspace-card"><strong>' . esc_html((string) $count) . '</strong><span>Anmälningar</span></div><div class="ssf-workspace-card"><strong>' . (! empty($data['registration_open']) ? 'Öppen' : 'Stängd') . '</strong><span>Anmälan</span></div></div>';
    }

    public static function users_badge(): int
    {
        $count = 0;
        foreach (get_users(array('fields' => 'ids')) as $user_id) {
            if (in_array(SSF_Access_Control::status((int) $user_id), array(SSF_Access_Control::STATUS_INVITED, SSF_Access_Control::STATUS_TERMINATED), true)) {
                ++$count;
            }
        }
        return $count;
    }

    public static function users(string $tail)
    {
        $groups = SSF_Access_Control::groups();
        if ('ny' === $tail) {
            $html = '<p><a href="' . esc_url(SSF_Workspace::url('anvandare')) . '">← Alla användare</a></p><h2>Bjud in SSF-användare</h2><p>Inbjudan använder den säkra Microsoft-aktiveringen. E-postadressen måste sluta på @ssfb.se.</p>';
            $html .= '<form class="ssf-workspace-form ssf-user-panel" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_m365_create_invitation">' . wp_nonce_field('ssf_m365_create_invitation', '_wpnonce', true, false);
            $html .= '<label for="ssf-workspace-invite-name">Namn</label><input id="ssf-workspace-invite-name" name="display_name" required><label for="ssf-workspace-invite-email">SSF-e-post</label><input id="ssf-workspace-invite-email" type="email" name="email" required><fieldset><legend><h3>Behörighetsgrupper</h3></legend>';
            foreach ($groups as $key => $group) {
                $html .= '<label class="ssf-workspace-check"><input type="checkbox" name="groups[]" value="' . esc_attr($key) . '"> <span><strong>' . esc_html($group['label']) . '</strong><small>' . esc_html($group['description']) . '</small></span></label>';
            }
            return $html . '</fieldset><button class="ssf-workspace-button" type="submit">Skapa och skicka inbjudan</button></form>';
        }
        if ('inbjudningar' === $tail) {
            $html = '<p><a href="' . esc_url(SSF_Workspace::url('anvandare')) . '">← Alla användare</a></p><h2>Inbjudningar</h2><ul class="ssf-workspace-list">';
            foreach ((array) get_option('ssf_microsoft_login_invitations', array()) as $invite_id => $invite) {
                $user = get_userdata((int) ($invite['user_id'] ?? 0));
                if (! $user) { continue; }
                $status = ! empty($invite['used_at']) ? 'Aktiverad' : (! empty($invite['canceled_at']) ? 'Avbruten' : (strtotime((string) ($invite['expires_at'] ?? '')) > time() ? 'Väntar på aktivering' : 'Utgången'));
                $html .= '<li><div><strong>' . esc_html($user->display_name) . '</strong><span>' . esc_html($status) . ' · ' . esc_html($user->user_email) . '</span></div><div class="ssf-user-row-actions">';
                if ('Aktiverad' !== $status) {
                    $html .= '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_resend_invitation&user_id=' . (int) $user->ID), 'ssf_m365_resend_invitation_' . (int) $user->ID)) . '">Skicka igen</a>';
                }
                if ('Väntar på aktivering' === $status) {
                    $html .= '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_cancel_invitation&invite_id=' . rawurlencode((string) $invite_id)), 'ssf_m365_cancel_invitation_' . $invite_id)) . '">Avbryt</a>';
                }
                $html .= '</div></li>';
            }
            return $html . '</ul>';
        }
        if ('' !== $tail) {
            return self::user_detail($tail, $groups);
        }
        return self::users_list($groups);
    }

    private static function users_list(array $groups): string
    {
        $filter = sanitize_key((string) ($_GET['status'] ?? 'all'));
        if (! in_array($filter, array('all', 'active', 'invited', 'terminated', 'blocked'), true)) { $filter = 'all'; }
        $search = isset($_GET['s']) && is_scalar($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $args = array('number' => 200, 'orderby' => 'display_name', 'order' => 'ASC');
        if ('' !== $search) {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = array('display_name', 'user_email', 'user_login');
        }
        $all_users = get_users(array('number' => 200, 'orderby' => 'display_name', 'order' => 'ASC'));
        $users = get_users($args);
        $counts = array('all' => count($all_users), 'active' => 0, 'invited' => 0, 'terminated' => 0, 'blocked' => 0);
        foreach ($all_users as $candidate) { ++$counts[SSF_Access_Control::status((int) $candidate->ID)]; }
        if ('all' !== $filter) {
            $users = array_values(array_filter($users, static fn(WP_User $user): bool => SSF_Access_Control::status((int) $user->ID) === $filter));
        }
        $labels = array('all' => 'Alla', 'active' => 'Aktiva', 'invited' => 'Inbjudna', 'terminated' => 'Avslutade');
        $html = '<div class="ssf-user-heading"><div><p class="ssf-workspace-lead">Hantera åtkomst, behörigheter och avslutade konton.</p></div><div class="ssf-workspace-form-actions"><a class="ssf-workspace-button" href="' . esc_url(SSF_Workspace::url('anvandare/ny')) . '">Bjud in användare</a><a class="ssf-workspace-button ssf-workspace-button--secondary" href="' . esc_url(SSF_Workspace::url('anvandare/inbjudningar')) . '">Inbjudningar</a></div></div>';
        if (isset($_GET['ssf_user_notice'])) {
            $notice = sanitize_key((string) wp_unslash($_GET['ssf_user_notice']));
            $html .= '<p role="status" class="ssf-workspace-confirmation">' . ('deleted' === $notice ? 'Användaren har tagits bort permanent. Historiska verksamhetsposter är bevarade.' : 'Ändringen har behandlats.') . '</p>';
        }
        $html .= '<div class="ssf-user-stats">';
        foreach ($labels as $key => $label) {
            $url = add_query_arg('status', $key, SSF_Workspace::url('anvandare'));
            $html .= '<a href="' . esc_url($url) . '"' . ($filter === $key ? ' aria-current="page"' : '') . '><strong>' . esc_html((string) $counts[$key]) . '</strong><span>' . esc_html($label) . '</span></a>';
        }
        $html .= '</div><form class="ssf-user-search" method="get" action="' . esc_url(SSF_Workspace::url('anvandare')) . '"><label for="ssf-user-search">Sök namn eller e-post</label><div><input id="ssf-user-search" type="search" name="s" value="' . esc_attr($search) . '"><input type="hidden" name="status" value="' . esc_attr($filter) . '"><button class="ssf-workspace-button" type="submit">Sök</button></div></form>';
        $html .= '<div class="ssf-workspace-table-wrap ssf-user-table"><table><thead><tr><th>Namn</th><th>E-post</th><th>Status</th><th>Behörigheter</th><th>Senast aktiv</th><th>Åtgärd</th></tr></thead><tbody>';
        foreach ($users as $user) {
            $id = (int) $user->ID;
            $names = array_map(static fn(string $key): string => (string) ($groups[$key]['label'] ?? $key), SSF_Access_Control::user_groups($id));
            $last = (int) get_user_meta($id, '_ssf_m365_last_login', true);
            $status = SSF_Access_Control::status($id);
            $html .= '<tr><td data-label="Namn"><strong>' . esc_html($user->display_name ?: $user->user_login) . '</strong></td><td data-label="E-post">' . esc_html($user->user_email) . '</td><td data-label="Status"><span class="ssf-user-status ssf-user-status--' . esc_attr($status) . '"><span aria-hidden="true">●</span> ' . esc_html(SSF_Access_Control::status_label($id)) . '</span></td><td data-label="Behörigheter">' . esc_html($names ? implode(', ', $names) : 'Inga') . '</td><td data-label="Senast aktiv">' . esc_html($last ? wp_date('Y-m-d H:i', $last) : '–') . '</td><td data-label="Åtgärd"><a href="' . esc_url(SSF_Workspace::url('anvandare/' . $id)) . '">Hantera</a></td></tr>';
        }
        if (! $users) { $html .= '<tr><td colspan="6">Inga användare matchar filtret.</td></tr>'; }
        return $html . '</tbody></table></div>';
    }

    private static function user_detail(string $tail, array $groups)
    {
        if (! ctype_digit($tail)) { return self::error('Användaren kunde inte hittas.'); }
        $user = get_userdata((int) $tail);
        if (! $user instanceof WP_User) { return self::error('Användaren kunde inte hittas.'); }
        $id = (int) $user->ID;
        $status = SSF_Access_Control::status($id);
        $selected = SSF_Access_Control::user_groups($id);
        $previous = SSF_Access_Control::previous_groups($id);
        $linked = get_user_meta($id, '_ssf_m365_tid', true) && get_user_meta($id, '_ssf_m365_oid', true);
        $last = (int) get_user_meta($id, '_ssf_m365_last_login', true);
        $refs = SSF_User_Admin::reference_summary($id);
        $protected = $id === get_current_user_id() || user_can($id, 'manage_options');
        $html = '<p><a href="' . esc_url(SSF_Workspace::url('anvandare')) . '">← Alla användare</a></p><div class="ssf-user-heading"><div><h2>' . esc_html($user->display_name ?: $user->user_login) . '</h2><p>' . esc_html($user->user_email) . '</p></div><span class="ssf-user-status ssf-user-status--' . esc_attr($status) . '"><span aria-hidden="true">●</span> ' . esc_html(SSF_Access_Control::status_label($id)) . '</span></div>';
        if (isset($_GET['ssf_user_notice'])) { $html .= '<p role="status" class="ssf-workspace-confirmation">Ändringen har genomförts.</p>'; }
        $html .= '<dl class="ssf-workspace-account"><dt>Status</dt><dd>' . esc_html(SSF_Access_Control::status_label($id)) . '</dd><dt>Microsoft-konto</dt><dd>' . esc_html($linked ? $user->user_email : 'Ej kopplat') . '</dd><dt>Senast aktiv</dt><dd>' . esc_html($last ? wp_date('Y-m-d H:i', $last) : '–') . '</dd></dl>';
        $html .= '<section class="ssf-user-section"><h2>Aktivitet / historik</h2><div class="ssf-user-stats ssf-user-stats--detail"><div><strong>' . esc_html((string) $refs['applications']) . '</strong><span>Ansökningar</span></div><div><strong>' . esc_html((string) $refs['inspections']) . '</strong><span>Inspektioner</span></div><div><strong>' . esc_html((string) $refs['news']) . '</strong><span>Nyheter</span></div><div><strong>' . esc_html((string) $refs['history_events']) . '</strong><span>Historikhändelser</span></div></div></section>';
        if (SSF_Access_Control::STATUS_TERMINATED === $status) {
            $previous_names = array_map(static fn(string $key): string => (string) ($groups[$key]['label'] ?? $key), $previous);
            $html .= '<section class="ssf-user-section"><h2>Avslutad användare</h2><p>Användaren kan inte logga in. Aktiva sessioner och behörigheter är avstängda.</p><h3>Tidigare behörigheter</h3><p>' . esc_html($previous_names ? implode(', ', $previous_names) : 'Inga') . '</p>';
            if (! $protected) { $html .= '<button class="ssf-workspace-button" type="button" data-ssf-dialog-open="ssf-reactivate-dialog">Återaktivera</button>'; }
            $html .= '</section>';
        } else {
            $html .= '<section class="ssf-user-section"><h2>Behörigheter</h2><form class="ssf-workspace-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_user_save_groups"><input type="hidden" name="user_id" value="' . esc_attr((string) $id) . '">' . wp_nonce_field('ssf_user_save_groups_' . $id, '_wpnonce', true, false) . '<fieldset>';
            foreach ($groups as $key => $group) {
                $html .= '<label class="ssf-workspace-check"><input type="checkbox" name="groups[]" value="' . esc_attr($key) . '"' . checked(in_array($key, $selected, true), true, false) . '> <span><strong>' . esc_html($group['label']) . '</strong><small>' . esc_html($group['description']) . '</small></span></label>';
            }
            $html .= '</fieldset><button class="ssf-workspace-button" type="submit">Spara behörigheter</button></form></section>';
            if (! $protected) {
                $open = SSF_User_Admin::open_assignments($id);
                $html .= '<section class="ssf-user-section"><h2>Konto</h2>' . ($open ? '<p class="ssf-workspace-error">Användaren ansvarar för ' . esc_html((string) count($open)) . ' öppna ärenden. Omfördela dem snarast efter frånkoppling.</p>' : '') . '<button class="ssf-workspace-button ssf-workspace-button--secondary" type="button" data-ssf-dialog-open="ssf-disconnect-dialog">Koppla bort användare</button></section>';
            }
        }
        if (! $protected) {
            $html .= '<section class="ssf-user-risk"><h2>Riskzon</h2><h3>Ta bort användaren permanent</h3><p>Kontot, inloggningskopplingen och den personliga systemprofilen tas bort. Historiska verksamhetsposter bevaras.</p><button class="ssf-workspace-button ssf-workspace-button--danger" type="button" data-ssf-dialog-open="ssf-delete-dialog">Ta bort permanent</button></section>';
            $html .= self::user_dialogs($user, $groups, $previous, $refs, $status);
        } else {
            $html .= '<p class="ssf-workspace-help">Det här kontot skyddas eftersom det är ditt eget konto eller ett lokalt administratörskonto.</p>';
        }
        return $html;
    }

    private static function user_dialogs(WP_User $user, array $groups, array $previous, array $refs, string $status): string
    {
        $id = (int) $user->ID;
        $name = (string) ($user->display_name ?: $user->user_login);
        $html = '';
        if (SSF_Access_Control::STATUS_TERMINATED !== $status) {
            $html .= '<dialog id="ssf-disconnect-dialog" class="ssf-user-dialog"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_user_disconnect"><input type="hidden" name="user_id" value="' . esc_attr((string) $id) . '">' . wp_nonce_field('ssf_user_disconnect_' . $id, '_wpnonce', true, false) . '<h2>Koppla bort ' . esc_html($name) . '?</h2><p>Användaren kommer inte längre kunna logga in i SSF:s system.</p><p>Tidigare historik, ansökningar, inspektioner och andra aktiviteter behålls. Status ändras till <strong>Avslutad</strong>.</p><div class="ssf-workspace-form-actions"><button class="ssf-workspace-button ssf-workspace-button--secondary" type="button" data-ssf-dialog-close>Avbryt</button><button class="ssf-workspace-button ssf-workspace-button--danger" type="submit">Koppla bort användare</button></div></form></dialog>';
        } else {
            $html .= '<dialog id="ssf-reactivate-dialog" class="ssf-user-dialog"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ssf-workspace-form"><input type="hidden" name="action" value="ssf_user_reactivate"><input type="hidden" name="user_id" value="' . esc_attr((string) $id) . '">' . wp_nonce_field('ssf_user_reactivate_' . $id, '_wpnonce', true, false) . '<h2>Återaktivera ' . esc_html($name) . '</h2><p>Välj vilka behörigheter användaren ska få. Tidigare behörigheter är markerade med text men aktiveras inte automatiskt.</p><fieldset>';
            foreach ($groups as $key => $group) {
                $html .= '<label class="ssf-workspace-check"><input type="checkbox" name="groups[]" value="' . esc_attr($key) . '"> <span><strong>' . esc_html($group['label']) . '</strong>' . (in_array($key, $previous, true) ? '<small>Tidigare behörighet</small>' : '') . '</span></label>';
            }
            $html .= '</fieldset><div class="ssf-workspace-form-actions"><button class="ssf-workspace-button ssf-workspace-button--secondary" type="button" data-ssf-dialog-close>Avbryt</button><button class="ssf-workspace-button" type="submit">Återaktivera</button></div></form></dialog>';
        }
        $html .= '<dialog id="ssf-delete-dialog" class="ssf-user-dialog ssf-user-dialog--danger"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ssf-workspace-form"><input type="hidden" name="action" value="ssf_user_delete"><input type="hidden" name="user_id" value="' . esc_attr((string) $id) . '">' . wp_nonce_field('ssf_user_delete_' . $id, '_wpnonce', true, false) . '<h2>Ta bort ' . esc_html($name) . ' permanent?</h2><p><strong>Detta går inte att ångra.</strong> Verksamhetsposter raderas inte.</p><dl class="ssf-user-reference-list"><dt>Ansökningar</dt><dd>' . esc_html((string) $refs['applications']) . '</dd><dt>Inspektioner</dt><dd>' . esc_html((string) $refs['inspections']) . '</dd><dt>Nyheter</dt><dd>' . esc_html((string) $refs['news']) . '</dd><dt>Historikhändelser</dt><dd>' . esc_html((string) $refs['history_events']) . '</dd></dl><p>Rekommenderad åtgärd är normalt att koppla bort användaren i stället.</p><label for="ssf-delete-confirmation">Skriv <strong>TA BORT</strong></label><input id="ssf-delete-confirmation" name="delete_confirmation" autocomplete="off" required pattern="TA BORT"><div class="ssf-workspace-form-actions"><button class="ssf-workspace-button ssf-workspace-button--secondary" type="button" data-ssf-dialog-close>Avbryt</button><button class="ssf-workspace-button ssf-workspace-button--danger" type="submit">Ta bort permanent</button></div></form></dialog>';
        return $html;
    }

    public static function microsoft(string $tail)
    {
        if ('' !== $tail) {
            return self::error('Sidan kunde inte hittas.');
        }
        $tabs = array('overview' => 'Översikt', 'directory' => 'Microsoft-katalog', 'login' => 'Inloggning', 'sharepoint' => 'SharePoint', 'email' => 'E-post', 'accounts' => 'Kontokopplingar', 'diagnostics' => 'Diagnostik');
        $html = '<p>Öppna den befintliga skyddade Microsoft-konfigurationen. Inga hemligheter visas i Arbetsytan.</p><div class="ssf-workspace-cards">';
        foreach ($tabs as $key => $label) {
            $url = add_query_arg(array('page' => 'ssf-member-portal-microsoft365', 'm365_tab' => $key), admin_url('admin.php'));
            $html .= '<a class="ssf-workspace-card" href="' . esc_url($url) . '"><strong>' . esc_html($label) . '</strong><span>Öppna konfiguration</span></a>';
        }
        return $html . '</div>';
    }
}
