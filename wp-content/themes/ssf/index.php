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
        <?php $is_document_page = is_page('stadgar'); ?>
        <?php $page_content = (string) get_post_field('post_content', get_the_ID()); ?>
        <?php $content_has_own_title = has_shortcode($page_content, 'ssf_member_portal_annual_meeting') || has_shortcode($page_content, 'ssf_member_portal_annual_meetings') || has_shortcode($page_content, 'ssf_member_portal_annual_meeting_registration'); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class($is_document_page ? 'content-page content-page--stadgar' : 'content-page'); ?>>
            <?php if (! is_page(array('kontakta-oss', 'stadgar')) && ! $content_has_own_title) : ?><h1><?php the_title(); ?></h1><?php endif; ?>
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
