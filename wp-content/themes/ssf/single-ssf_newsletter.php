<?php
/**
 * Newsletter single template.
 *
 * @package SSF
 */

get_header();
?>

<?php while (have_posts()) : ?>
    <?php the_post(); ?>
    <?php $data = ssf_site_newsletter_data(get_the_ID()); ?>
    <article id="post-<?php the_ID(); ?>" <?php post_class('ssf-newsletter-single'); ?>>
        <p class="ssf-eyebrow"><?php echo esc_html($data['series'] ?: __('SSF Nyhetsbrev', 'ssf')); ?></p>
        <h1><?php the_title(); ?></h1>
        <?php if ($data['issue']) : ?><p class="ssf-newsletter-card__issue"><?php printf(esc_html__('Nummer %s', 'ssf'), esc_html($data['issue'])); ?></p><?php endif; ?>
        <?php if ($data['year']) : ?><p class="ssf-newsletter-single__meta"><?php echo esc_html(ssf_site_newsletter_display_date(get_the_ID())); ?><?php if ($data['pdf_size']) : ?> <span>PDF · <?php echo esc_html($data['pdf_size']); ?></span><?php endif; ?></p><?php endif; ?>
        <div class="entry-content"><?php the_content(); ?></div>
        <div class="ssf-actions">
            <?php if ($data['pdf_url']) : ?>
                <a class="ssf-button" href="<?php echo esc_url($data['pdf_url']); ?>" target="_blank" rel="noopener"><?php printf(esc_html__('Läs %s', 'ssf'), esc_html(get_the_title())); ?></a>
                <a class="ssf-button ssf-button--ghost" href="<?php echo esc_url($data['pdf_url']); ?>" download><?php printf(esc_html__('Ladda ner %s (PDF)', 'ssf'), esc_html(get_the_title())); ?></a>
            <?php endif; ?>
            <a class="ssf-button ssf-button--ghost" href="<?php echo esc_url(get_post_type_archive_link(SSF_SITE_NEWSLETTER_POST_TYPE)); ?>"><?php esc_html_e('Tillbaka till alla nyhetsbrev', 'ssf'); ?></a>
        </div>
        <?php if ($data['pdf_url']) : ?>
            <div class="ssf-newsletter-single__viewer">
                <object data="<?php echo esc_url($data['pdf_url']); ?>" type="application/pdf">
                    <p><?php esc_html_e('Din webbläsare kan inte visa PDF-filen direkt.', 'ssf'); ?> <a href="<?php echo esc_url($data['pdf_url']); ?>" target="_blank" rel="noopener"><?php printf(esc_html__('Öppna %s', 'ssf'), esc_html(get_the_title())); ?></a>.</p>
                </object>
            </div>
        <?php endif; ?>
    </article>
<?php endwhile; ?>

<?php get_footer(); ?>
