<?php
/**
 * Ship card.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

$post_id = isset($post_id) ? (int) $post_id : get_the_ID();
$large = ! empty($large);
$status = SSF_Medlemsfartyg_Shortcodes::terms_label($post_id, 'fartygsstatus');
$type = SSF_Medlemsfartyg_Shortcodes::terms_label($post_id, 'fartygstyp');
$region = SSF_Medlemsfartyg_Shortcodes::terms_label($post_id, 'fartygsregion');
$organization = SSF_Medlemsfartyg_Shortcodes::field($post_id, '_ssf_organization');
$show_contact = '1' === SSF_Medlemsfartyg_Shortcodes::field($post_id, '_ssf_public_contact');
?>
<article class="ssf-ship-card <?php echo $large ? 'ssf-ship-card--large' : ''; ?>">
    <a class="ssf-ship-card__image" href="<?php echo esc_url(get_permalink($post_id)); ?>">
        <?php if (has_post_thumbnail($post_id)) : ?>
            <?php echo get_the_post_thumbnail($post_id, 'ssf_ship_card', array('alt' => esc_attr(get_the_title($post_id)))); ?>
        <?php endif; ?>
    </a>
    <div class="ssf-ship-card__body">
        <?php if ($status) : ?><span class="ssf-ship-badge"><?php echo esc_html($status); ?></span><?php endif; ?>
        <h3><a href="<?php echo esc_url(get_permalink($post_id)); ?>"><?php echo esc_html(get_the_title($post_id)); ?></a></h3>
        <p class="ssf-ship-meta"><?php echo esc_html(trim($type . ($region ? ' · ' . $region : ''))); ?></p>
        <?php if ($show_contact && $organization) : ?><p class="ssf-ship-org"><?php echo esc_html($organization); ?></p><?php endif; ?>
        <p><?php echo esc_html(get_the_excerpt($post_id) ?: wp_trim_words(wp_strip_all_tags(get_post_field('post_content', $post_id)), 24)); ?></p>
        <a class="ssf-ship-read-more" href="<?php echo esc_url(get_permalink($post_id)); ?>"><?php esc_html_e('Läs mer', 'ssf-medlemsfartyg'); ?> <span aria-hidden="true">-&gt;</span></a>
    </div>
</article>
