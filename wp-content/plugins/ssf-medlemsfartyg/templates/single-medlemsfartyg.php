<?php
/**
 * Single ship template.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

get_header();
the_post();
$id = get_the_ID();
$status = SSF_Medlemsfartyg_Shortcodes::terms_label($id, 'fartygsstatus');
$type = SSF_Medlemsfartyg_Shortcodes::terms_label($id, 'fartygstyp');
$region = SSF_Medlemsfartyg_Shortcodes::terms_label($id, 'fartygsregion');
$show_contact = '1' === SSF_Medlemsfartyg_Shortcodes::field($id, '_ssf_public_contact');
$facts = array(
    'Byggår' => '_ssf_build_year',
    'Byggplats / varv' => '_ssf_shipyard',
    'Längd' => '_ssf_length',
    'Bredd' => '_ssf_beam',
    'Djupgående' => '_ssf_draft',
    'Rigtyp' => '_ssf_rig',
    'Material' => '_ssf_material',
    'Hemmahamn' => '_ssf_home_port',
);
?>
<main class="ssf-ship-single">
    <section class="ssf-ship-single-hero">
        <?php if (has_post_thumbnail()) : ?>
            <?php the_post_thumbnail('ssf_ship_hero'); ?>
        <?php endif; ?>
        <div class="ssf-ship-single-hero__content">
            <?php if ($status) : ?><span class="ssf-ship-badge"><?php echo esc_html($status); ?></span><?php endif; ?>
            <h1><?php the_title(); ?></h1>
            <p><?php echo esc_html(trim($type . ($region ? ' · ' . $region : ''))); ?></p>
        </div>
    </section>

    <div class="ssf-ships-wrap ssf-ship-layout">
        <article class="ssf-ship-main">
            <section><h2><?php esc_html_e('Om fartyget', 'ssf-medlemsfartyg'); ?></h2><?php the_content(); ?></section>
            <?php foreach (array('_ssf_today' => 'Vad gör fartyget idag?', '_ssf_history' => 'Historia', '_ssf_activity' => 'Verksamhet', '_ssf_future' => 'Kommande planer') as $key => $heading) : ?>
                <?php $value = SSF_Medlemsfartyg_Shortcodes::field($id, $key); ?>
                <?php if ($value) : ?><section><h2><?php echo esc_html($heading); ?></h2><?php echo wp_kses_post(wpautop($value)); ?></section><?php endif; ?>
            <?php endforeach; ?>
            <?php $gallery = array_filter(array_map('intval', explode(',', SSF_Medlemsfartyg_Shortcodes::field($id, '_ssf_gallery_ids')))); ?>
            <?php if ($gallery) : ?>
                <section><h2><?php esc_html_e('Galleri', 'ssf-medlemsfartyg'); ?></h2><div class="ssf-ship-gallery">
                    <?php foreach ($gallery as $image_id) : ?>
                        <a href="<?php echo esc_url(wp_get_attachment_image_url($image_id, 'large')); ?>"><?php echo wp_get_attachment_image($image_id, 'ssf_ship_gallery_thumb'); ?></a>
                    <?php endforeach; ?>
                </div></section>
            <?php endif; ?>
        </article>

        <aside class="ssf-ship-sidebar">
            <section class="ssf-ship-facts"><h2><?php esc_html_e('Snabbfakta', 'ssf-medlemsfartyg'); ?></h2>
                <?php foreach ($facts as $label => $key) : $value = SSF_Medlemsfartyg_Shortcodes::field($id, $key); ?>
                    <?php if ($value) : ?><div><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html($value); ?></dd></div><?php endif; ?>
                <?php endforeach; ?>
                <?php if ($status) : ?><div><dt><?php esc_html_e('Status', 'ssf-medlemsfartyg'); ?></dt><dd><?php echo esc_html($status); ?></dd></div><?php endif; ?>
            </section>
            <section class="ssf-ship-contact"><h2><?php esc_html_e('Fartygsombud', 'ssf-medlemsfartyg'); ?></h2>
                <?php if ($show_contact) : ?>
                    <p><?php echo esc_html(SSF_Medlemsfartyg_Shortcodes::field($id, '_ssf_contact_name')); ?><br><?php echo esc_html(SSF_Medlemsfartyg_Shortcodes::field($id, '_ssf_organization')); ?></p>
                    <?php if (SSF_Medlemsfartyg_Shortcodes::field($id, '_ssf_email')) : ?><p><a href="mailto:<?php echo esc_attr(SSF_Medlemsfartyg_Shortcodes::field($id, '_ssf_email')); ?>"><?php echo esc_html(SSF_Medlemsfartyg_Shortcodes::field($id, '_ssf_email')); ?></a></p><?php endif; ?>
                    <?php if (SSF_Medlemsfartyg_Shortcodes::field($id, '_ssf_phone')) : ?><p><?php echo esc_html(SSF_Medlemsfartyg_Shortcodes::field($id, '_ssf_phone')); ?></p><?php endif; ?>
                <?php else : ?>
                    <p><?php echo esc_html(SSF_Medlemsfartyg_Shortcodes::field($id, '_ssf_organization')); ?></p>
                    <a class="ssf-ship-button" href="<?php echo esc_url(home_url('/kontakta-oss/')); ?>"><?php esc_html_e('Kontakta SSF om fartyget', 'ssf-medlemsfartyg'); ?></a>
                <?php endif; ?>
                <?php if (SSF_Medlemsfartyg_Shortcodes::field($id, '_ssf_website')) : ?><a class="ssf-ship-button ssf-ship-button--ghost" href="<?php echo esc_url(SSF_Medlemsfartyg_Shortcodes::field($id, '_ssf_website')); ?>"><?php esc_html_e('Besök hemsida', 'ssf-medlemsfartyg'); ?></a><?php endif; ?>
            </section>
        </aside>
    </div>
</main>
<?php
get_footer();
