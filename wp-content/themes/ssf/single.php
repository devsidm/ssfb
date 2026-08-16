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
    <article id="post-<?php the_ID(); ?>" <?php post_class('content-page content-page--single'); ?>>
        <?php if (has_post_thumbnail()) : ?>
            <div class="single-featured-image">
                <?php the_post_thumbnail('large'); ?>
            </div>
        <?php endif; ?>
        <p class="entry-date"><?php echo esc_html(get_the_date()); ?></p>
        <h1><?php the_title(); ?></h1>
        <div class="entry-content">
            <?php the_content(); ?>
        </div>
    </article>
<?php endwhile; ?>

<?php
get_footer();
