<?php
/**
 * Main template file.
 *
 * @package SSF
 */

get_header();
?>

<?php if (have_posts()) : ?>
    <?php while (have_posts()) : ?>
        <?php the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class('content-page'); ?>>
            <?php if (! is_page('kontakta-oss')) : ?><h1><?php the_title(); ?></h1><?php endif; ?>
            <div class="entry-content">
                <?php the_content(); ?>
            </div>
        </article>
    <?php endwhile; ?>
<?php else : ?>
    <section class="hero">
        <h1><?php esc_html_e('SSF', 'ssf'); ?></h1>
        <p><?php esc_html_e('A new WordPress website is taking shape.', 'ssf'); ?></p>
        <a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type=page')); ?>">
            <?php esc_html_e('Create a page', 'ssf'); ?>
        </a>
    </section>
<?php endif; ?>

<?php
get_footer();
