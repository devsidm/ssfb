<?php
/**
 * Single post template.
 *
 * @package SSF
 */

get_header();
?>

<?php while (have_posts()) : ?>
    <?php the_post(); ?>
    <?php ssf_render_news_article(get_post()); ?>
<?php endwhile; ?>

<?php
get_footer();
