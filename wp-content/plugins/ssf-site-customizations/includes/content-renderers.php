<?php
/**
 * Public renderers backed by the Webbinnehåll settings screen.
 *
 * @package SSF_Site
 */

if (! defined('ABSPATH')) {
    exit;
}

function ssf_site_editorial_home_shortcode(): string
{
    $home = ssf_site_page_content('home');
    $hero_url = ssf_site_content_image_url((int) $home['hero_image_id'], SSF_SITE_URL . 'assets/images/ssf-hero.jpg');
    ob_start();
    ?>
    <section class="ssf-hero" aria-label="Sveriges Segelfartygsförbund"><img src="<?php echo esc_url($hero_url); ?>" alt="<?php echo esc_attr($home['hero_alt']); ?>"></section>
    <section class="ssf-section ssf-intro"><div class="ssf-wrap"><h1><?php echo esc_html($home['intro_title']); ?></h1><?php echo wp_kses_post(ssf_site_content_text($home['intro_text'])); ?><div class="ssf-actions"><?php echo ssf_site_button($home['primary_label'], ssf_site_content_url($home['primary_url'])); ?><?php echo ssf_site_button($home['secondary_label'], ssf_site_content_url($home['secondary_url']), 'ssf-button--ghost'); ?></div></div></section>
    <section class="ssf-section"><div class="ssf-wrap"><h2><?php echo esc_html($home['choices_title']); ?></h2><div class="ssf-card-grid ssf-card-grid--three"><?php echo ssf_site_feature_card($home['choice_application_title'], $home['choice_application_text'], ssf_site_content_url($home['choice_application_url'])); ?><?php echo ssf_site_feature_card($home['choice_member_title'], $home['choice_member_text'], ssf_site_content_url($home['choice_member_url'])); ?><?php echo ssf_site_feature_card($home['choice_contact_title'], $home['choice_contact_text'], ssf_site_content_url($home['choice_contact_url'])); ?></div></div></section>
    <section class="ssf-section ssf-section--blue"><div class="ssf-wrap"><h2><?php echo esc_html($home['about_title']); ?></h2><?php echo wp_kses_post(ssf_site_content_text($home['about_text'])); ?><div class="ssf-card-grid ssf-card-grid--three"><?php echo ssf_site_plain_card($home['value_one_title'], $home['value_one_text']); ?><?php echo ssf_site_plain_card($home['value_two_title'], $home['value_two_text']); ?><?php echo ssf_site_plain_card($home['value_three_title'], $home['value_three_text']); ?></div></div></section>
    <section class="ssf-section"><div class="ssf-wrap"><h2><?php echo esc_html($home['membership_title']); ?></h2><?php echo wp_kses_post(ssf_site_content_text($home['membership_text'])); ?><div class="ssf-card-grid ssf-card-grid--two"><?php echo ssf_site_membership_card($home['support_title'], $home['support_text'], ssf_site_content_lines($home['support_items']), $home['support_label'], ssf_site_content_url($home['support_url'])); ?><?php echo ssf_site_membership_card($home['ship_title'], $home['ship_text'], ssf_site_content_lines($home['ship_items']), $home['ship_label'], ssf_site_content_url($home['ship_url'])); ?></div></div></section>
    <section class="ssf-section ssf-section--gold"><div class="ssf-wrap"><h2><?php echo esc_html($home['process_title']); ?></h2><?php echo wp_kses_post(ssf_site_content_text($home['process_text'])); ?><div class="ssf-steps"><?php echo ssf_site_step('1', $home['step_one_title'], $home['step_one_text']); ?><?php echo ssf_site_step('2', $home['step_two_title'], $home['step_two_text']); ?><?php echo ssf_site_step('3', $home['step_three_title'], $home['step_three_text']); ?><?php echo ssf_site_step('4', $home['step_four_title'], $home['step_four_text']); ?></div></div></section>
    <section class="ssf-section"><div class="ssf-wrap"><h2><?php echo esc_html($home['news_title']); ?></h2><?php echo wp_kses_post(ssf_site_content_text($home['news_text'])); ?><?php echo do_shortcode('[ssf_news_cards count="4"]'); ?></div></section>
    <section class="ssf-section ssf-section--blue"><div class="ssf-wrap ssf-split"><div><h2><?php echo esc_html($home['contact_title']); ?></h2><?php echo wp_kses_post(ssf_site_content_text($home['contact_text'])); ?></div><?php echo ssf_site_button($home['contact_label'], ssf_site_content_url($home['contact_url'])); ?></div></section>
    <section class="ssf-section ssf-final-cta"><div class="ssf-wrap"><h2><?php echo esc_html($home['final_title']); ?></h2><?php echo wp_kses_post(ssf_site_content_text($home['final_text'])); ?><div class="ssf-actions"><?php echo ssf_site_button($home['final_primary_label'], ssf_site_content_url($home['final_primary_url'])); ?><?php echo ssf_site_button($home['final_secondary_label'], ssf_site_content_url($home['final_secondary_url']), 'ssf-button--ghost'); ?></div></div></section>
    <?php
    return (string) ob_get_clean();
}

function ssf_site_editorial_contact_shortcode(): string
{
    $contact = ssf_site_page_content('contact');
    $image_url = ssf_site_content_image_url((int) $contact['image_id']);
    ob_start();
    ssf_site_status_notice();
    ?>
    <section class="ssf-page-content">
        <?php if ($image_url) : ?><figure class="ssf-page-content__image"><img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($contact['image_alt']); ?>"></figure><?php endif; ?>
        <div class="ssf-page-content__heading"><p class="ssf-process-eyebrow"><?php echo esc_html($contact['eyebrow']); ?></p><h1><?php echo esc_html($contact['title']); ?></h1><?php echo wp_kses_post(ssf_site_content_text($contact['intro'])); ?></div>
        <form class="ssf-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><h2><?php echo esc_html($contact['form_title']); ?></h2><input type="hidden" name="action" value="ssf_contact"><?php wp_nonce_field('ssf_contact', 'ssf_contact_nonce'); ?><label>Namn<input name="namn" required></label><label>E-post<input type="email" name="epost" required></label><label>Telefon<input name="telefon"></label><label>Ämne<input name="amne" required></label><label>Meddelande<textarea name="meddelande" rows="6" required></textarea></label><button class="ssf-button" type="submit">Skicka</button></form>
    </section>
    <?php
    return (string) ob_get_clean();
}

remove_shortcode('ssf_home');
add_shortcode('ssf_home', 'ssf_site_editorial_home_shortcode');
remove_shortcode('ssf_contact_form');
add_shortcode('ssf_contact_form', 'ssf_site_editorial_contact_shortcode');
