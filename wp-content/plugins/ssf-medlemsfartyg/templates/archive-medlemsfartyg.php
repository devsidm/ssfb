<?php
/**
 * Archive template.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! isset($query)) {
    $settings = SSF_Medlemsfartyg_Plugin::settings();
    $query = (new SSF_Medlemsfartyg_Shortcodes())->build_query($settings);
}

$shortcodes = new SSF_Medlemsfartyg_Shortcodes();
if (empty($is_shortcode)) {
    get_header();
    if (class_exists('SSF_Feature_Manager')) {
        echo SSF_Feature_Manager::test_mode_banner('member_vessels'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
?>
<main class="ssf-ships-page">
    <section class="ssf-ships-hero">
        <div class="ssf-ships-wrap">
            <p class="ssf-kicker"><?php esc_html_e('Sveriges Segelfartygsförbund', 'ssf-medlemsfartyg'); ?></p>
            <h1><?php esc_html_e('Våra medlemsfartyg', 'ssf-medlemsfartyg'); ?></h1>
            <p><?php esc_html_e('SSF:s medlemsfartyg är hjärtat i vårt förbund. Här kan du läsa om fartygen, deras historia, vad de används till idag och vem som är fartygsombud.', 'ssf-medlemsfartyg'); ?></p>
        </div>
    </section>

    <section class="ssf-ships-wrap ssf-ships-section">
        <?php if ('yes' === SSF_Medlemsfartyg_Plugin::settings()['enable_filters']) : ?>
            <form class="ssf-ship-filters" method="get">
                <label><?php esc_html_e('Sök fartyg', 'ssf-medlemsfartyg'); ?><input type="search" name="ssf_search" value="<?php echo esc_attr(sanitize_text_field(wp_unslash($_GET['ssf_search'] ?? ''))); ?>"></label>
                <?php foreach (array('fartygstyp' => 'Fartygstyp', 'fartygsstatus' => 'Status', 'fartygsregion' => 'Region / hemmahamn', 'fartygsanvandning' => 'Användning') as $taxonomy => $label) : ?>
                    <label><?php echo esc_html($label); ?>
                        <select name="<?php echo esc_attr($taxonomy); ?>">
                            <option value=""><?php esc_html_e('Alla', 'ssf-medlemsfartyg'); ?></option>
                            <?php foreach (get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false)) as $term) : ?>
                                <option value="<?php echo esc_attr($term->slug); ?>" <?php selected(sanitize_text_field(wp_unslash($_GET[$taxonomy] ?? '')), $term->slug); ?>><?php echo esc_html($term->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
                <label><?php esc_html_e('Sortering', 'ssf-medlemsfartyg'); ?>
                    <select name="ssf_sort">
                        <option value="name" <?php selected(sanitize_text_field(wp_unslash($_GET['ssf_sort'] ?? '')), 'name'); ?>><?php esc_html_e('Namn A-Ö', 'ssf-medlemsfartyg'); ?></option>
                        <option value="newest" <?php selected(sanitize_text_field(wp_unslash($_GET['ssf_sort'] ?? '')), 'newest'); ?>><?php esc_html_e('Nyast', 'ssf-medlemsfartyg'); ?></option>
                        <option value="build_year" <?php selected(sanitize_text_field(wp_unslash($_GET['ssf_sort'] ?? '')), 'build_year'); ?>><?php esc_html_e('Byggår', 'ssf-medlemsfartyg'); ?></option>
                        <option value="featured" <?php selected(sanitize_text_field(wp_unslash($_GET['ssf_sort'] ?? '')), 'featured'); ?>><?php esc_html_e('Prioriterade först', 'ssf-medlemsfartyg'); ?></option>
                    </select>
                </label>
                <button class="ssf-ship-button" type="submit"><?php esc_html_e('Filtrera', 'ssf-medlemsfartyg'); ?></button>
            </form>
        <?php endif; ?>

        <p class="ssf-ship-count"><?php echo esc_html(sprintf(_n('Visar %d fartyg', 'Visar %d fartyg', (int) $query->found_posts, 'ssf-medlemsfartyg'), (int) $query->found_posts)); ?></p>

        <?php if ($query->have_posts()) : ?>
            <div class="ssf-ships-grid">
                <?php while ($query->have_posts()) : $query->the_post(); ?>
                    <?php $shortcodes->render_card(get_the_ID()); ?>
                <?php endwhile; ?>
            </div>
            <?php
            echo wp_kses_post(
                paginate_links(
                    array(
                        'total' => $query->max_num_pages,
                        'current' => max(1, (int) ($_GET['ssf_page'] ?? 1), (int) get_query_var('paged')),
                        'format' => '?ssf_page=%#%',
                    )
                )
            );
            ?>
        <?php else : ?>
            <p class="ssf-empty"><?php esc_html_e('Inga fartyg matchar dina filter. Prova att ändra sökningen.', 'ssf-medlemsfartyg'); ?></p>
        <?php endif; ?>
        <?php wp_reset_postdata(); ?>
    </section>

    <section class="ssf-ships-cta">
        <div class="ssf-ships-wrap">
            <h2><?php esc_html_e('Vill du att ditt fartyg ska bli en del av SSF?', 'ssf-medlemsfartyg'); ?></h2>
            <p><?php esc_html_e('Läs mer om medlemskap och ansökningsprocessen.', 'ssf-medlemsfartyg'); ?></p>
            <a class="ssf-ship-button" href="<?php echo esc_url(home_url('/ansokan/')); ?>"><?php esc_html_e('Ansök som fartygsombud', 'ssf-medlemsfartyg'); ?></a>
        </div>
    </section>
</main>
<?php
if (empty($is_shortcode)) {
    get_footer();
}
