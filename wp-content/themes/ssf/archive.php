<?php
/**
 * Archive template.
 *
 * @package SSF
 */

get_header();
?>

<section class="archive-header">
    <h1><?php the_archive_title(); ?></h1>
    <?php the_archive_description('<div class="archive-description">', '</div>'); ?>
</section>

<?php if (have_posts()) : ?>
    <div class="ssf-news-grid">
        <?php while (have_posts()) : ?>
            <?php the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class('ssf-news-card'); ?>>
                <a href="<?php the_permalink(); ?>" class="ssf-news-card__image">
                    <?php if (has_post_thumbnail()) : ?>
                        <?php the_post_thumbnail('medium_large'); ?>
                    <?php endif; ?>
                </a>
                <div class="ssf-news-card__body">
                    <time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date()); ?></time>
                    <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                    <p><?php echo esc_html(wp_trim_words(get_the_excerpt(), 28)); ?></p>
                    <a class="ssf-read-more" href="<?php the_permalink(); ?>"><?php esc_html_e('Las mer', 'ssf'); ?> <span aria-hidden="true">-&gt;</span></a>
                </div>
            </article>
        <?php endwhile; ?>
    </div>
    <?php the_posts_pagination(); ?>
<?php else : ?>
    <p><?php esc_html_e('Inget innehall hittades.', 'ssf'); ?></p>
<?php endif; ?>

<?php
get_footer();
