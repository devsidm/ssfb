<?php
/**
 * Newsletter archive template.
 *
 * @package SSF
 */

get_header();

$settings = ssf_site_newsletter_settings();
$selected_year = isset($_GET['ar']) ? sanitize_text_field(wp_unslash($_GET['ar'])) : '';
$selected_series = isset($_GET['serie']) ? sanitize_key(wp_unslash($_GET['serie'])) : '';
if (! ssf_site_is_valid_newsletter_year($selected_year)) {
    $selected_year = '';
}
$series = get_terms(array('taxonomy' => SSF_SITE_NEWSLETTER_SERIES_TAXONOMY, 'hide_empty' => true));
if (! term_exists($selected_series, SSF_SITE_NEWSLETTER_SERIES_TAXONOMY)) { $selected_series = ''; }
$latest = ssf_site_get_latest_newsletter();
$newsletters = ssf_site_get_newsletters($selected_year, 0, $selected_series);
$years = ssf_site_get_newsletter_years();
$grouped = ssf_site_group_newsletters_by_year($newsletters);
?>

<section class="ssf-newsletter-archive">
    <header class="ssf-newsletter-archive__header">
        <h1><?php esc_html_e('Nyhetsbrev', 'ssf'); ?></h1>
        <p><?php echo esc_html($settings['archive_intro']); ?></p>
    </header>

    <nav class="ssf-newsletter-filter" aria-label="<?php esc_attr_e('Filtrera nyhetsbrev efter serie', 'ssf'); ?>">
        <a href="<?php echo esc_url(get_post_type_archive_link(SSF_SITE_NEWSLETTER_POST_TYPE)); ?>" <?php echo $selected_series ? '' : 'aria-current="page"'; ?>><?php esc_html_e('Alla', 'ssf'); ?></a>
        <?php foreach ($series as $term) : ?><a href="<?php echo esc_url(add_query_arg('serie', $term->slug, get_post_type_archive_link(SSF_SITE_NEWSLETTER_POST_TYPE))); ?>" <?php echo $selected_series === $term->slug ? 'aria-current="page"' : ''; ?>><?php echo esc_html($term->name); ?></a><?php endforeach; ?>
    </nav>

    <?php if ($latest && ! $selected_year && ! $selected_series) : ?>
        <section class="ssf-newsletter-latest" aria-labelledby="ssf-newsletter-latest-heading">
            <h2 id="ssf-newsletter-latest-heading"><?php esc_html_e('Senaste utgåvor', 'ssf'); ?></h2>
            <div class="ssf-newsletter-grid"><?php foreach ($series as $term) : $items = ssf_site_get_newsletters('', 0, $term->slug); if ($items) { echo ssf_site_render_newsletter_card($items[0], true); } endforeach; ?></div>
        </section>
    <?php endif; ?>

    <?php if ($years) : ?>
        <nav class="ssf-newsletter-filter" aria-label="<?php esc_attr_e('Hoppa till år i nyhetsbrevsarkivet', 'ssf'); ?>">
            <a href="<?php echo esc_url(get_post_type_archive_link(SSF_SITE_NEWSLETTER_POST_TYPE)); ?>" <?php echo $selected_year ? '' : 'aria-current="page"'; ?>><?php esc_html_e('Alla', 'ssf'); ?></a>
            <?php foreach ($years as $year) : ?>
                <a href="<?php echo esc_url(add_query_arg(array_filter(array('ar' => $year, 'serie' => $selected_series)), get_post_type_archive_link(SSF_SITE_NEWSLETTER_POST_TYPE))); ?>" <?php echo $selected_year === $year ? 'aria-current="page"' : ''; ?>><?php echo esc_html($year); ?></a>
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
