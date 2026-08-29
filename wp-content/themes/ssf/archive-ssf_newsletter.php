<?php
/**
 * Newsletter archive template.
 *
 * @package SSF
 */

get_header();

$settings = ssf_site_newsletter_settings();
$selected_year = isset($_GET['ar']) ? sanitize_text_field(wp_unslash($_GET['ar'])) : '';
if (! ssf_site_is_valid_newsletter_year($selected_year)) {
    $selected_year = '';
}
$latest = ssf_site_get_latest_newsletter();
$newsletters = ssf_site_get_newsletters($selected_year, $selected_year ? 0 : ($latest ? $latest->ID : 0));
$years = ssf_site_get_newsletter_years();
$grouped = ssf_site_group_newsletters_by_year($newsletters);
?>

<section class="ssf-newsletter-archive">
    <header class="ssf-newsletter-archive__header">
        <h1><?php esc_html_e('Nyhetsbrev', 'ssf'); ?></h1>
        <p><?php echo esc_html($settings['archive_intro']); ?></p>
    </header>

    <?php if ($latest && ! $selected_year) : ?>
        <section class="ssf-newsletter-latest" aria-labelledby="ssf-newsletter-latest-heading">
            <h2 id="ssf-newsletter-latest-heading"><?php esc_html_e('Senaste numret', 'ssf'); ?></h2>
            <?php echo ssf_site_render_newsletter_card($latest, true); ?>
        </section>
    <?php endif; ?>

    <?php if ($years) : ?>
        <nav class="ssf-newsletter-filter" aria-label="<?php esc_attr_e('Hoppa till år i nyhetsbrevsarkivet', 'ssf'); ?>">
            <a href="<?php echo esc_url(get_post_type_archive_link(SSF_SITE_NEWSLETTER_POST_TYPE)); ?>" <?php echo $selected_year ? '' : 'aria-current="page"'; ?>><?php esc_html_e('Alla', 'ssf'); ?></a>
            <?php foreach ($years as $year) : ?>
                <a href="<?php echo esc_url($selected_year ? add_query_arg('ar', $year, get_post_type_archive_link(SSF_SITE_NEWSLETTER_POST_TYPE)) : '#year-' . $year); ?>" <?php echo $selected_year === $year ? 'aria-current="page"' : ''; ?>><?php echo esc_html($year); ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <section aria-labelledby="ssf-newsletter-archive-heading">
        <h2 id="ssf-newsletter-archive-heading"><?php echo esc_html($selected_year ? sprintf(__('Nyhetsbrev från %s', 'ssf'), $selected_year) : __('Arkiv', 'ssf')); ?></h2>
        <?php if ($grouped) : ?>
            <?php foreach ($grouped as $year => $year_newsletters) : ?>
                <h2 class="ssf-newsletter-year" id="year-<?php echo esc_attr($year); ?>"><?php echo esc_html($year); ?></h2>
                <div class="ssf-newsletter-grid">
                    <?php foreach ($year_newsletters as $newsletter) : ?><?php echo ssf_site_render_newsletter_card($newsletter); ?><?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <p><?php esc_html_e('Det finns inga nyhetsbrev att visa ännu.', 'ssf'); ?></p>
        <?php endif; ?>
    </section>
</section>

<?php get_footer(); ?>
