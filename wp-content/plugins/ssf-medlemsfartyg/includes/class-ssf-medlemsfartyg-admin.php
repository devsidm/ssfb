<?php
/**
 * Admin settings and columns.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsfartyg_Admin
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_filter('manage_medlemsfartyg_posts_columns', array($this, 'columns'));
        add_action('manage_medlemsfartyg_posts_custom_column', array($this, 'column_content'), 10, 2);
        add_action('restrict_manage_posts', array($this, 'admin_filters'));
        add_action('pre_get_posts', array($this, 'apply_admin_filters'));
    }

    public function admin_menu(): void
    {
        add_submenu_page('edit.php?post_type=medlemsfartyg', __('Mina fartyg', 'ssf-medlemsfartyg'), __('Mina fartyg', 'ssf-medlemsfartyg'), 'read', 'ssf-mina-fartyg', array(SSF_Medlemsfartyg_Plugin::instance()->owner_dashboard, 'render'));
        add_submenu_page('edit.php?post_type=medlemsfartyg', __('Inställningar', 'ssf-medlemsfartyg'), __('Inställningar', 'ssf-medlemsfartyg'), 'manage_options', 'ssf-medlemsfartyg-settings', array($this, 'settings_page'));
        add_submenu_page('edit.php?post_type=medlemsfartyg', __('Exportera CSV', 'ssf-medlemsfartyg'), __('Exportera CSV', 'ssf-medlemsfartyg'), 'export_ssf_ships', 'ssf-medlemsfartyg-export', array(SSF_Medlemsfartyg_Plugin::instance()->export, 'render_page'));

        if (! current_user_can('edit_ssf_ships')) {
            add_menu_page(__('Mina fartyg', 'ssf-medlemsfartyg'), __('Mina fartyg', 'ssf-medlemsfartyg'), 'read', 'ssf-mina-fartyg', array(SSF_Medlemsfartyg_Plugin::instance()->owner_dashboard, 'render'), 'dashicons-sos', 26);
        }
    }

    public function register_settings(): void
    {
        register_setting('ssf_medlemsfartyg_settings', 'ssf_medlemsfartyg_settings', array($this, 'sanitize_settings'));
    }

    public function sanitize_settings(array $input): array
    {
        return array(
            'admin_email' => sanitize_email($input['admin_email'] ?? get_option('admin_email')),
            'require_review' => ! empty($input['require_review']) ? 'yes' : 'no',
            'default_status' => in_array($input['default_status'] ?? 'draft', array('draft', 'publish', 'pending'), true) ? $input['default_status'] : 'draft',
            'per_page' => max(1, min(60, (int) ($input['per_page'] ?? 12))),
            'public_contact_default' => ! empty($input['public_contact_default']) ? 'yes' : 'no',
            'enable_filters' => ! empty($input['enable_filters']) ? 'yes' : 'no',
            'enable_map' => ! empty($input['enable_map']) ? 'yes' : 'no',
            'primary_color' => sanitize_hex_color($input['primary_color'] ?? '#3163B7') ?: '#3163B7',
            'archive_slug' => sanitize_title($input['archive_slug'] ?? 'medlemsfartyg') ?: 'medlemsfartyg',
        );
    }

    public function settings_page(): void
    {
        $settings = SSF_Medlemsfartyg_Plugin::settings();
        ?>
        <div class="wrap ssf-admin-page">
            <h1><?php esc_html_e('Inställningar för medlemsfartyg', 'ssf-medlemsfartyg'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('ssf_medlemsfartyg_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="admin_email"><?php esc_html_e('E-post för ändringsnotiser', 'ssf-medlemsfartyg'); ?></label></th><td><input class="regular-text" id="admin_email" type="email" name="ssf_medlemsfartyg_settings[admin_email]" value="<?php echo esc_attr($settings['admin_email']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Granskning', 'ssf-medlemsfartyg'); ?></th><td><label><input type="checkbox" name="ssf_medlemsfartyg_settings[require_review]" value="1" <?php checked('yes', $settings['require_review']); ?>> <?php esc_html_e('Ändringar från fartygsombud kräver granskning', 'ssf-medlemsfartyg'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Standardstatus för nya fartyg', 'ssf-medlemsfartyg'); ?></th><td><select name="ssf_medlemsfartyg_settings[default_status]"><option value="draft" <?php selected('draft', $settings['default_status']); ?>>Utkast</option><option value="pending" <?php selected('pending', $settings['default_status']); ?>>Väntar på granskning</option><option value="publish" <?php selected('publish', $settings['default_status']); ?>>Publicerad</option></select></td></tr>
                    <tr><th><?php esc_html_e('Antal fartyg per sida', 'ssf-medlemsfartyg'); ?></th><td><input type="number" min="1" max="60" name="ssf_medlemsfartyg_settings[per_page]" value="<?php echo esc_attr((string) $settings['per_page']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Kontaktuppgifter', 'ssf-medlemsfartyg'); ?></th><td><label><input type="checkbox" name="ssf_medlemsfartyg_settings[public_contact_default]" value="1" <?php checked('yes', $settings['public_contact_default']); ?>> <?php esc_html_e('Visa kontaktuppgifter publikt som standard', 'ssf-medlemsfartyg'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Filter', 'ssf-medlemsfartyg'); ?></th><td><label><input type="checkbox" name="ssf_medlemsfartyg_settings[enable_filters]" value="1" <?php checked('yes', $settings['enable_filters']); ?>> <?php esc_html_e('Aktivera sök och filter på frontend', 'ssf-medlemsfartyg'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Karta', 'ssf-medlemsfartyg'); ?></th><td><label><input type="checkbox" name="ssf_medlemsfartyg_settings[enable_map]" value="1" <?php checked('yes', $settings['enable_map']); ?>> <?php esc_html_e('Förbered kartfunktion senare', 'ssf-medlemsfartyg'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Primär färg', 'ssf-medlemsfartyg'); ?></th><td><input type="text" name="ssf_medlemsfartyg_settings[primary_color]" value="<?php echo esc_attr($settings['primary_color']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Slug för samlingssida', 'ssf-medlemsfartyg'); ?></th><td><input type="text" name="ssf_medlemsfartyg_settings[archive_slug]" value="<?php echo esc_attr($settings['archive_slug']); ?>"><p class="description"><?php esc_html_e('Standard är /medlemsfartyg/. Spara permalänkar efter ändring.', 'ssf-medlemsfartyg'); ?></p></td></tr>
                </table>
                <?php submit_button(__('Spara inställningar', 'ssf-medlemsfartyg')); ?>
            </form>
        </div>
        <?php
    }

    public function columns(array $columns): array
    {
        $columns['ssf_status'] = __('Status', 'ssf-medlemsfartyg');
        $columns['ssf_region'] = __('Region', 'ssf-medlemsfartyg');
        $columns['ssf_review'] = __('Granskning', 'ssf-medlemsfartyg');
        return $columns;
    }

    public function column_content(string $column, int $post_id): void
    {
        if ('ssf_status' === $column) {
            echo esc_html(SSF_Medlemsfartyg_Shortcodes::terms_label($post_id, 'fartygsstatus'));
        } elseif ('ssf_region' === $column) {
            echo esc_html(SSF_Medlemsfartyg_Shortcodes::terms_label($post_id, 'fartygsregion'));
        } elseif ('ssf_review' === $column) {
            echo esc_html(get_post_meta($post_id, '_ssf_review_status', true) ?: __('Publicerad', 'ssf-medlemsfartyg'));
        }
    }

    public function admin_filters(): void
    {
        global $typenow;
        if ('medlemsfartyg' !== $typenow) {
            return;
        }

        foreach (array('fartygsstatus', 'fartygstyp', 'fartygsregion') as $taxonomy) {
            wp_dropdown_categories(
                array(
                    'show_option_all' => get_taxonomy($taxonomy)->labels->name,
                    'taxonomy' => $taxonomy,
                    'name' => $taxonomy,
                    'orderby' => 'name',
                    'selected' => isset($_GET[$taxonomy]) ? (int) $_GET[$taxonomy] : 0,
                    'hierarchical' => true,
                    'hide_empty' => false,
                )
            );
        }
    }

    public function apply_admin_filters(WP_Query $query): void
    {
        global $pagenow;
        if (! is_admin() || 'edit.php' !== $pagenow || 'medlemsfartyg' !== ($query->query['post_type'] ?? '')) {
            return;
        }

        foreach (array('fartygsstatus', 'fartygstyp', 'fartygsregion') as $taxonomy) {
            if (! empty($_GET[$taxonomy])) {
                $tax_query = (array) $query->get('tax_query');
                $tax_query[] = array(
                    'taxonomy' => $taxonomy,
                    'field' => 'term_id',
                    'terms' => (int) $_GET[$taxonomy],
                );
                $query->set('tax_query', $tax_query);
            }
        }
    }
}
