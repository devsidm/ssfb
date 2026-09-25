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
        <?php
        $own_title_shortcodes = array(
            'ssf_application_form',
            'ssf_application_status',
            'ssf_inspector_portal',
            'ssf_membership_review_portal',
            'ssf_fartygsuppgifter_form',
            'ssf_medlemsfartyg',
            'ssf_member_vessels',
            'ssf_home',
            'ssf_stadgar',
            'ssf_contact_form',
            'ssf_member_portal_annual_meeting',
            'ssf_member_portal_annual_meeting_registration',
            'ssf_member_portal_motion_hub',
            'ssf_member_portal_motions',
            'ssf_member_portal_motion_status',
        );
        $content_has_own_title = false;
        foreach ($own_title_shortcodes as $shortcode) {
            if (has_shortcode($page_content, $shortcode)) {
                $content_has_own_title = true;
                break;
            }
        }
        ?>
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
