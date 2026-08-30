<?php
/**
 * Plugin Name: SSF Admin Navigation
 * Description: Central verksamhetsorienterad navigation för SSF:s WordPress-admin.
 * Version: 1.0.0
 * Author: SIDM
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Admin_Navigation
{
    public const ROOT = 'ssf-overview';
    public const SYSTEM = 'ssf-system';
    public const CONTENT = 'ssf-content';
    public const MEMBERSHIP = 'ssf-membership';
    public const ANNUAL_MEETINGS = 'ssf';
    public const COMMUNICATION = 'ssf-communication';

    private const SYSTEM_PAGES = array(
        'ssf-system' => array('label' => 'Översikt', 'capability' => 'read'),
        'ssf-features' => array('label' => 'Funktioner', 'capability' => 'manage_ssf_features'),
        'ssf-member-portal-microsoft365' => array('label' => 'Microsoft 365', 'capability' => 'ssf_manage_motions'),
        'ssf-office365-mailer' => array('label' => 'E-post', 'capability' => 'manage_options'),
        'ssf-release' => array('label' => 'Release', 'capability' => 'manage_ssf_releases'),
        'ssf-member-portal-status' => array('label' => 'Systemstatus', 'capability' => 'ssf_manage_member_portal'),
    );

    public static function boot(): void
    {
        add_action('admin_menu', array(__CLASS__, 'register_roots'), 5);
        add_action('admin_menu', array(__CLASS__, 'register_core_links'), 20);
        add_action('admin_menu', array(__CLASS__, 'finalize_menu'), 999);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('load-edit.php', array(__CLASS__, 'redirect_legacy_edit_page'));
        add_action('load-options-general.php', array(__CLASS__, 'redirect_legacy_options_page'));
        add_filter('parent_file', array(__CLASS__, 'parent_file'));
        add_filter('submenu_file', array(__CLASS__, 'submenu_file'));
    }

    public static function register_roots(): void
    {
        if (self::can_access_system()) {
            add_menu_page('SSF', 'SSF', 'read', self::ROOT, array(__CLASS__, 'render_overview'), 'dashicons-admin-site-alt3', 23);
            add_submenu_page(self::ROOT, 'SSF Översikt', 'Översikt', 'read', self::ROOT, array(__CLASS__, 'render_overview'), 10);
            add_submenu_page(self::ROOT, 'SSF System', 'System', 'read', self::SYSTEM, array(__CLASS__, 'render_system'), 90);
        }

        if (current_user_can('edit_posts')) {
            add_menu_page('Innehåll', 'Innehåll', 'edit_posts', self::CONTENT, array(__CLASS__, 'render_content'), 'dashicons-welcome-write-blog', 24);
            add_submenu_page(self::CONTENT, 'Innehåll', 'Översikt', 'edit_posts', self::CONTENT, array(__CLASS__, 'render_content'), 10);
        }

        if (self::can_access_membership()) {
            add_menu_page('Medlemskap', 'Medlemskap', 'read', self::MEMBERSHIP, array(__CLASS__, 'render_membership'), 'dashicons-groups', 26);
            add_submenu_page(self::MEMBERSHIP, 'Medlemskap', 'Översikt', 'read', self::MEMBERSHIP, array(__CLASS__, 'render_membership'), 10);
        }

        if (current_user_can('edit_posts')) {
            add_menu_page('Kommunikation', 'Kommunikation', 'edit_posts', self::COMMUNICATION, array(__CLASS__, 'render_communication'), 'dashicons-email-alt', 27);
            add_submenu_page(self::COMMUNICATION, 'Kommunikation', 'Översikt', 'edit_posts', self::COMMUNICATION, array(__CLASS__, 'render_communication'), 10);
        }
    }

    public static function register_core_links(): void
    {
        if (current_user_can('edit_posts')) {
            add_submenu_page(self::CONTENT, 'Nyheter', 'Nyheter', 'edit_posts', 'edit.php', '', 30);
        }
    }

    public static function finalize_menu(): void
    {
        global $menu;

        remove_menu_page('edit.php');
        remove_menu_page('ssf-webbinnehall');
        remove_menu_page('ssf-calendar-events');
        remove_menu_page('edit.php?post_type=ssf_application');
        remove_menu_page('edit.php?post_type=medlemsfartyg');
        remove_menu_page('edit.php?post_type=ssf_ansokan');
        remove_menu_page('edit.php?post_type=ssf_kontakt');
        remove_menu_page('edit.php?post_type=ssf_newsletter');

        foreach ($menu as &$item) {
            if (($item[2] ?? '') === self::ANNUAL_MEETINGS) {
                $item[0] = 'Årsmöten';
                $item[3] = 'Årsmöten';
                $item[6] = 'dashicons-calendar-alt';
            }
        }
        unset($item);

        self::remove_submenus(
            self::MEMBERSHIP,
            array(
                'post-new.php?post_type=ssf_application',
                'post-new.php?post_type=ssf_ansokan',
                'post-new.php?post_type=medlemsfartyg',
                'edit-tags.php?taxonomy=fartygstyp&amp;post_type=medlemsfartyg',
                'edit-tags.php?taxonomy=fartygstyp&post_type=medlemsfartyg',
                'edit-tags.php?taxonomy=fartygsstatus&amp;post_type=medlemsfartyg',
                'edit-tags.php?taxonomy=fartygsstatus&post_type=medlemsfartyg',
                'edit-tags.php?taxonomy=fartygsanvandning&amp;post_type=medlemsfartyg',
                'edit-tags.php?taxonomy=fartygsanvandning&post_type=medlemsfartyg',
                'edit-tags.php?taxonomy=fartygsregion&amp;post_type=medlemsfartyg',
                'edit-tags.php?taxonomy=fartygsregion&post_type=medlemsfartyg',
                'ssf-medlemsfartyg-export',
            )
        );
        self::remove_submenus(self::COMMUNICATION, array('post-new.php?post_type=ssf_kontakt'));

        self::sort_submenus(
            self::CONTENT,
            array(
                self::CONTENT => 10,
                'ssf-webbinnehall' => 20,
                'edit.php' => 30,
                'edit.php?post_type=ssf_newsletter' => 40,
                'ssf-calendar-events' => 50,
                'edit.php?post_type=ssf_document' => 60,
            )
        );
        self::sort_submenus(
            self::MEMBERSHIP,
            array(
                self::MEMBERSHIP => 10,
                'edit.php?post_type=ssf_application' => 20,
                'edit.php?post_type=ssf_ansokan' => 30,
                'edit.php?post_type=medlemsfartyg' => 40,
                'edit.php?post_type=ssf_ship_submission' => 50,
                'ssf-mina-fartyg' => 60,
                'ssf-insamlingslankar' => 70,
                'ssf-medlemsprocess-settings' => 80,
                'ssf-medlemsfartyg-settings' => 90,
            )
        );
        self::sort_submenus(
            self::ANNUAL_MEETINGS,
            array(
                self::ANNUAL_MEETINGS => 10,
                'edit.php?post_type=ssf_annual_meeting' => 20,
                'post-new.php?post_type=ssf_annual_meeting' => 30,
                'ssf-member-portal-meeting-registrations' => 40,
                'edit.php?post_type=ssf_motion' => 50,
                'ssf-member-portal-settings' => 90,
            )
        );
        self::sort_submenus(
            self::COMMUNICATION,
            array(
                self::COMMUNICATION => 10,
                'edit.php?post_type=ssf_kontakt' => 20,
            )
        );
    }

    public static function render_overview(): void
    {
        if (! self::can_access_system()) {
            wp_die('Du saknar behörighet till SSF-översikten.');
        }

        $release = class_exists('SSF_Release_Manager') ? SSF_Release_Manager::get_display_string() : 'Ej tillgänglig';
        $environment = class_exists('SSF_Environment') ? SSF_Environment::label() : ucfirst((string) wp_get_environment_type());
        ?>
        <div class="wrap ssf-admin-hub">
            <h1>SSF Översikt</h1>
            <p class="ssf-admin-hub__intro">Snabb orientering för förbundets innehåll, medlemsarbete, årsmöten och tekniska status.</p>
            <div class="ssf-admin-grid">
                <?php self::render_membership_card(); ?>
                <?php self::render_annual_meeting_card(); ?>
                <?php self::render_content_card(); ?>
                <section class="ssf-admin-card">
                    <h2>System</h2>
                    <dl><div><dt>Miljö</dt><dd><?php echo esc_html($environment); ?></dd></div><div><dt>Release</dt><dd><?php echo esc_html($release); ?></dd></div></dl>
                    <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::SYSTEM)); ?>">Öppna System</a></p>
                </section>
            </div>
        </div>
        <?php
    }

    public static function render_system(): void
    {
        if (! self::can_access_system()) {
            wp_die('Du saknar behörighet till SSF System.');
        }
        ?>
        <div class="wrap ssf-admin-hub">
            <h1>SSF System</h1>
            <?php self::render_system_tabs(self::SYSTEM); ?>
            <p class="ssf-admin-hub__intro">Tekniska funktioner, integrationer, releaseinformation och diagnostik samlade på ett ställe.</p>
            <div class="ssf-admin-grid ssf-admin-grid--system">
                <?php foreach (self::visible_system_pages() as $slug => $page) : ?>
                    <?php if (self::SYSTEM === $slug) { continue; } ?>
                    <section class="ssf-admin-card">
                        <h2><?php echo esc_html($page['label']); ?></h2>
                        <p><?php echo esc_html(self::system_description($slug)); ?></p>
                        <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . $slug)); ?>">Öppna</a></p>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    public static function render_content(): void
    {
        if (! current_user_can('edit_posts')) {
            wp_die('Du saknar behörighet till Innehåll.');
        }
        ?>
        <div class="wrap ssf-admin-hub">
            <h1>Innehåll</h1>
            <p class="ssf-admin-hub__intro">Publicera och underhåll webbplatsens redaktionella innehåll.</p>
            <div class="ssf-admin-grid">
                <?php self::render_hub_link_card('Webbinnehåll', 'Startsida, kontakt och ansökningssida.', 'ssf-webbinnehall', 'manage_options'); ?>
                <?php self::render_hub_link_card('Nyheter', self::count_label('post', 'publicerad nyhet', 'publicerade nyheter'), 'edit.php', 'edit_posts', true); ?>
                <?php self::render_hub_link_card('Nyhetsbrev', self::count_label('ssf_newsletter', 'nyhetsbrev', 'nyhetsbrev'), 'edit.php?post_type=ssf_newsletter', 'edit_ssf_newsletters', true); ?>
                <?php self::render_hub_link_card('Kalender', self::count_label('ssf_event', 'event', 'event'), 'ssf-calendar-events', 'edit_posts'); ?>
                <?php self::render_hub_link_card('Stadgar & dokument', self::count_label('ssf_document', 'dokument', 'dokument'), 'edit.php?post_type=ssf_document', 'edit_posts', true); ?>
            </div>
            <?php self::render_content_tools(); ?>
        </div>
        <?php
    }

    public static function render_membership(): void
    {
        if (! self::can_access_membership()) {
            wp_die('Du saknar behörighet till Medlemskap.');
        }
        ?>
        <div class="wrap ssf-admin-hub">
            <h1>Medlemskap</h1>
            <p class="ssf-admin-hub__intro">Ansökningar, medlemsfartyg, inspektioner och insamlade fartygsuppgifter.</p>
            <div class="ssf-admin-grid">
                <?php self::render_membership_card(); ?>
                <?php self::render_hub_link_card('Äldre ansökningar', self::count_label('ssf_ansokan', 'äldre ansökan', 'äldre ansökningar'), 'edit.php?post_type=ssf_ansokan', 'edit_posts', true); ?>
                <?php self::render_hub_link_card('Inskickade fartygsuppgifter', self::count_label('ssf_ship_submission', 'inskickad uppgift', 'inskickade uppgifter'), 'edit.php?post_type=ssf_ship_submission', 'edit_posts', true); ?>
                <?php self::render_hub_link_card('Mina fartyg', 'Redigera fartyg som är kopplade till ditt konto.', 'ssf-mina-fartyg', 'read'); ?>
                <?php self::render_hub_link_card('Insamlingslänkar', 'Skapa och hantera personliga insamlingslänkar.', 'ssf-insamlingslankar', 'manage_options'); ?>
                <?php self::render_membership_settings_card(); ?>
            </div>
            <?php if (current_user_can('export_ssf_ships')) : ?><p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ssf-medlemsfartyg-export')); ?>">Exportera medlemsfartyg till CSV</a></p><?php endif; ?>
        </div>
        <?php
    }

    public static function render_communication(): void
    {
        if (! current_user_can('edit_posts')) {
            wp_die('Du saknar behörighet till Kommunikation.');
        }
        ?>
        <div class="wrap ssf-admin-hub">
            <h1>Kommunikation</h1>
            <p class="ssf-admin-hub__intro">Inkommande meddelanden och verksamhetskommunikation.</p>
            <div class="ssf-admin-grid">
                <?php self::render_hub_link_card('Kontaktmeddelanden', self::count_label('ssf_kontakt', 'meddelande', 'meddelanden'), 'edit.php?post_type=ssf_kontakt', 'edit_posts', true); ?>
            </div>
        </div>
        <?php
    }

    public static function render_system_tabs(string $active): void
    {
        $pages = self::visible_system_pages();
        if (! $pages) {
            return;
        }
        ?>
        <nav class="nav-tab-wrapper ssf-system-tabs" aria-label="SSF System">
            <?php foreach ($pages as $slug => $page) : ?>
                <a class="nav-tab <?php echo $slug === $active ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . $slug)); ?>"><?php echo esc_html($page['label']); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    public static function enqueue_assets(): void
    {
        if (! self::is_ssf_admin_screen()) {
            return;
        }
        wp_enqueue_style('common');
        wp_add_inline_style(
            'common',
            '.ssf-admin-hub{max-width:1180px}.ssf-admin-hub__intro{max-width:760px;font-size:14px;color:#50575e}.ssf-admin-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px;margin:20px 0}.ssf-admin-grid--system{grid-template-columns:repeat(auto-fit,minmax(210px,1fr))}.ssf-admin-card{box-sizing:border-box;min-width:0;padding:18px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1}.ssf-admin-card h2{margin:0 0 12px;font-size:16px}.ssf-admin-card p:last-child{margin-bottom:0}.ssf-admin-card dl{margin:0 0 14px}.ssf-admin-card dl div{display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px solid #f0f0f1}.ssf-admin-card dt{color:#50575e}.ssf-admin-card dd{margin:0;text-align:right;font-weight:600;overflow-wrap:anywhere}.ssf-admin-card__links,.ssf-admin-tools{display:flex;flex-wrap:wrap;gap:8px 14px}.ssf-admin-tools{margin:0 0 20px}.ssf-system-tabs{margin:16px 0 20px}.ssf-system-tabs .nav-tab{white-space:nowrap}@media(max-width:782px){.ssf-admin-grid{grid-template-columns:1fr}.ssf-system-tabs{display:flex;overflow-x:auto;padding-bottom:1px}.ssf-system-tabs .nav-tab{flex:0 0 auto}}'
        );
    }

    public static function parent_file(string $parent_file): string
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $post_type = self::current_post_type();

        if (isset(self::SYSTEM_PAGES[$page])) {
            return self::ROOT;
        }
        if (in_array($page, array('ssf-webbinnehall', 'ssf-calendar-events', 'ssf-calendar-settings', 'ssf-newsletter-import', 'ssf-newsletter-settings', 'ssf-stadgar-settings'), true) || in_array($post_type, array('post', 'ssf_newsletter', 'ssf_event', 'ssf_document'), true)) {
            return self::CONTENT;
        }
        if (in_array($page, array('ssf-medlemsprocess-overview', 'ssf-medlemsprocess-settings', 'ssf-mina-fartyg', 'ssf-insamlingslankar', 'ssf-medlemsfartyg-settings', 'ssf-medlemsfartyg-export'), true) || in_array($post_type, array('ssf_application', 'ssf_ansokan', 'medlemsfartyg', 'ssf_ship_submission'), true)) {
            return self::MEMBERSHIP;
        }
        if ('ssf_kontakt' === $post_type) {
            return self::COMMUNICATION;
        }
        return $parent_file;
    }

    public static function submenu_file(?string $submenu_file): ?string
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $post_type = self::current_post_type();

        if (isset(self::SYSTEM_PAGES[$page])) {
            return self::SYSTEM;
        }
        if (in_array($page, array('ssf-newsletter-import', 'ssf-newsletter-settings'), true) || 'ssf_newsletter' === $post_type) {
            return 'edit.php?post_type=ssf_newsletter';
        }
        if ('ssf-calendar-settings' === $page || 'ssf_event' === $post_type) {
            return 'ssf-calendar-events';
        }
        if ('ssf-stadgar-settings' === $page || 'ssf_document' === $post_type) {
            return 'edit.php?post_type=ssf_document';
        }
        return $submenu_file;
    }

    public static function redirect_legacy_edit_page(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $moved = array(
            'ssf-medlemsprocess-overview',
            'ssf-medlemsprocess-settings',
            'ssf-mina-fartyg',
            'ssf-insamlingslankar',
            'ssf-medlemsfartyg-settings',
            'ssf-medlemsfartyg-export',
            'ssf-newsletter-import',
            'ssf-newsletter-settings',
        );
        if (! in_array($page, $moved, true)) {
            return;
        }
        $args = array();
        foreach ($_GET as $key => $value) {
            if ('post_type' !== $key && is_scalar($value)) {
                $args[sanitize_key((string) $key)] = sanitize_text_field(wp_unslash((string) $value));
            }
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public static function redirect_legacy_options_page(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ('ssf-office365-mailer' !== $page) {
            return;
        }
        wp_safe_redirect(admin_url('admin.php?page=ssf-office365-mailer'));
        exit;
    }

    private static function render_membership_card(): void
    {
        if (current_user_can('ssf_view_applications') || current_user_can('edit_ssf_applications')) {
            self::render_hub_link_card('Ansökningar', self::count_label('ssf_application', 'ansökan', 'ansökningar'), 'edit.php?post_type=ssf_application', 'read', true);
        }
        if (current_user_can('edit_ssf_ships') || current_user_can('edit_own_ssf_ships')) {
            self::render_hub_link_card('Medlemsfartyg', self::count_label('medlemsfartyg', 'fartyg', 'fartyg'), 'edit.php?post_type=medlemsfartyg', 'read', true);
        }
    }

    private static function render_membership_settings_card(): void
    {
        if (! current_user_can('ssf_manage_application_settings') && ! current_user_can('manage_options')) {
            return;
        }
        ?>
        <section class="ssf-admin-card">
            <h2>Inställningar</h2>
            <p>Processinställningar, fartygsinställningar och kategorisering.</p>
            <p class="ssf-admin-card__links">
                <?php if (current_user_can('ssf_manage_application_settings')) : ?><a href="<?php echo esc_url(admin_url('admin.php?page=ssf-medlemsprocess-settings')); ?>">Medlemsprocess</a><?php endif; ?>
                <?php if (current_user_can('manage_options')) : ?><a href="<?php echo esc_url(admin_url('admin.php?page=ssf-medlemsfartyg-settings')); ?>">Medlemsfartyg</a><?php endif; ?>
                <?php if (current_user_can('manage_categories')) : ?>
                    <a href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=fartygstyp&post_type=medlemsfartyg')); ?>">Fartygstyper</a>
                    <a href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=fartygsstatus&post_type=medlemsfartyg')); ?>">Statusar</a>
                    <a href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=fartygsanvandning&post_type=medlemsfartyg')); ?>">Användning</a>
                    <a href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=fartygsregion&post_type=medlemsfartyg')); ?>">Regioner</a>
                <?php endif; ?>
            </p>
        </section>
        <?php
    }

    private static function render_annual_meeting_card(): void
    {
        if (! current_user_can('manage_ssf_annual_meetings') && ! current_user_can('ssf_manage_member_portal')) {
            return;
        }
        self::render_hub_link_card('Årsmöten', self::count_label('ssf_annual_meeting', 'årsmöte', 'årsmöten'), 'ssf', 'read');
    }

    private static function render_content_card(): void
    {
        if (! current_user_can('edit_posts')) {
            return;
        }
        self::render_hub_link_card('Innehåll', 'Nyheter, nyhetsbrev, kalender och dokument.', self::CONTENT, 'edit_posts');
    }

    private static function render_content_tools(): void
    {
        ?>
        <div class="ssf-admin-tools">
            <?php if (current_user_can('manage_ssf_newsletters')) : ?>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ssf-newsletter-import')); ?>">Importera nyhetsbrev</a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ssf-newsletter-settings')); ?>">Nyhetsbrevsinställningar</a>
            <?php endif; ?>
            <?php if (current_user_can('edit_posts')) : ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ssf-calendar-settings')); ?>">Kalenderinställningar</a><?php endif; ?>
            <?php if (current_user_can('manage_options')) : ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ssf-stadgar-settings')); ?>">Dokumentinställningar</a><?php endif; ?>
        </div>
        <?php
    }

    private static function render_hub_link_card(string $title, string $description, string $slug, string $capability, bool $direct = false): void
    {
        if (! current_user_can($capability)) {
            return;
        }
        $url = $direct ? admin_url($slug) : admin_url('admin.php?page=' . $slug);
        ?>
        <section class="ssf-admin-card">
            <h2><?php echo esc_html($title); ?></h2>
            <p><?php echo esc_html($description); ?></p>
            <p><a class="button" href="<?php echo esc_url($url); ?>">Öppna</a></p>
        </section>
        <?php
    }

    private static function count_label(string $post_type, string $singular, string $plural): string
    {
        if (! post_type_exists($post_type)) {
            return 'Funktionen är inte aktiv.';
        }
        $count = self::post_count($post_type);
        return sprintf('%d %s', $count, 1 === $count ? $singular : $plural);
    }

    private static function post_count(string $post_type): int
    {
        $counts = wp_count_posts($post_type);
        if (! is_object($counts)) {
            return 0;
        }
        $total = 0;
        foreach (get_post_stati(array('internal' => false)) as $status) {
            $total += isset($counts->{$status}) ? (int) $counts->{$status} : 0;
        }
        return $total;
    }

    private static function visible_system_pages(): array
    {
        $pages = array();
        foreach (self::SYSTEM_PAGES as $slug => $page) {
            if (self::SYSTEM === $slug || current_user_can($page['capability'])) {
                $pages[$slug] = $page;
            }
        }
        return $pages;
    }

    private static function system_description(string $slug): string
    {
        $descriptions = array(
            'ssf-features' => 'Styr vilka publika funktioner som är aktiva.',
            'ssf-member-portal-microsoft365' => 'Graph- och SharePointanslutning för årsmöten och motioner.',
            'ssf-office365-mailer' => 'Microsoft 365-transport för webbplatsens e-post.',
            'ssf-release' => 'Version, releasedatum, miljö och releasehistorik.',
            'ssf-member-portal-status' => 'Samlad miljö-, integrations- och diagnostikstatus.',
        );
        return $descriptions[$slug] ?? '';
    }

    private static function can_access_system(): bool
    {
        foreach (array('manage_options', 'manage_ssf_features', 'manage_ssf_releases', 'ssf_manage_member_portal', 'ssf_manage_motions') as $capability) {
            if (current_user_can($capability)) {
                return true;
            }
        }
        return false;
    }

    private static function can_access_membership(): bool
    {
        foreach (array('manage_options', 'edit_posts', 'ssf_view_applications', 'edit_ssf_applications', 'edit_ssf_ships', 'edit_own_ssf_ships', 'export_ssf_ships') as $capability) {
            if (current_user_can($capability)) {
                return true;
            }
        }
        return false;
    }

    private static function is_ssf_admin_screen(): bool
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (0 === strpos($page, 'ssf-') || in_array($page, array(self::ROOT, self::CONTENT, self::MEMBERSHIP, self::COMMUNICATION), true)) {
            return true;
        }
        return in_array(self::current_post_type(), array('ssf_newsletter', 'ssf_event', 'ssf_document', 'ssf_application', 'ssf_ansokan', 'medlemsfartyg', 'ssf_ship_submission', 'ssf_annual_meeting', 'ssf_meeting_registration', 'ssf_motion', 'ssf_kontakt'), true);
    }

    private static function current_post_type(): string
    {
        global $typenow;
        if (is_string($typenow) && $typenow) {
            return $typenow;
        }
        if (isset($_GET['post_type'])) {
            return sanitize_key(wp_unslash($_GET['post_type']));
        }
        if (isset($_GET['post'])) {
            return (string) get_post_type(absint($_GET['post']));
        }
        global $pagenow;
        return 'edit.php' === $pagenow ? 'post' : '';
    }

    private static function remove_submenus(string $parent, array $slugs): void
    {
        foreach ($slugs as $slug) {
            remove_submenu_page($parent, $slug);
        }
    }

    private static function sort_submenus(string $parent, array $order): void
    {
        global $submenu;
        if (empty($submenu[$parent]) || ! is_array($submenu[$parent])) {
            return;
        }
        usort(
            $submenu[$parent],
            static function (array $left, array $right) use ($order): int {
                return ($order[$left[2]] ?? 500) <=> ($order[$right[2]] ?? 500);
            }
        );
    }
}

SSF_Admin_Navigation::boot();
