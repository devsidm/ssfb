<?php
/**
 * CSV export.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsfartyg_Export
{
    public function __construct()
    {
        add_action('admin_post_ssf_export_medlemsfartyg', array($this, 'download_csv'));
    }

    public function render_page(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Exportera medlemsfartyg', 'ssf-medlemsfartyg'); ?></h1>
            <p><?php esc_html_e('Exporten innehåller grunddata och kontaktuppgifter. Endast administratörer ska hantera filen.', 'ssf-medlemsfartyg'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_export_medlemsfartyg">
                <?php wp_nonce_field('ssf_export_medlemsfartyg'); ?>
                <?php submit_button(__('Ladda ner CSV', 'ssf-medlemsfartyg')); ?>
            </form>
        </div>
        <?php
    }

    public function download_csv(): void
    {
        if (! current_user_can('export_ssf_ships') || ! check_admin_referer('ssf_export_medlemsfartyg')) {
            wp_die(esc_html__('Du saknar behörighet att exportera.', 'ssf-medlemsfartyg'));
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=medlemsfartyg-' . gmdate('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, array('ID', 'Namn', 'Status', 'Fartygstyp', 'Region', 'Hemmahamn', 'Byggår', 'Längd', 'Bredd', 'Ombud', 'Organisation', 'E-post', 'Telefon', 'Webbplats'));

        $query = new WP_Query(array('post_type' => 'medlemsfartyg', 'posts_per_page' => -1, 'post_status' => 'any'));
        while ($query->have_posts()) {
            $query->the_post();
            $id = get_the_ID();
            fputcsv(
                $out,
                array(
                    $id,
                    get_the_title(),
                    SSF_Medlemsfartyg_Shortcodes::terms_label($id, 'fartygsstatus'),
                    SSF_Medlemsfartyg_Shortcodes::terms_label($id, 'fartygstyp'),
                    SSF_Medlemsfartyg_Shortcodes::terms_label($id, 'fartygsregion'),
                    get_post_meta($id, '_ssf_home_port', true),
                    get_post_meta($id, '_ssf_build_year', true),
                    get_post_meta($id, '_ssf_length', true),
                    get_post_meta($id, '_ssf_beam', true),
                    get_post_meta($id, '_ssf_contact_name', true),
                    get_post_meta($id, '_ssf_organization', true),
                    get_post_meta($id, '_ssf_email', true),
                    get_post_meta($id, '_ssf_phone', true),
                    get_post_meta($id, '_ssf_website', true),
                )
            );
        }
        wp_reset_postdata();
        fclose($out);
        exit;
    }
}
