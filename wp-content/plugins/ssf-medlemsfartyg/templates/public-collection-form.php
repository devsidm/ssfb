<?php
/**
 * Token-protected vessel profile update form.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

$route = (string) get_post_meta($ship_id, '_ssf_application_route', true);
$values = SSF_Medlemsfartyg_Profile::values($ship_id, $route, SSF_Medlemsfartyg_Profile::MODE_UPDATE);
$gallery = array_filter(array_map('intval', explode(',', (string) get_post_meta($ship_id, '_ssf_gallery_ids', true))));
$existing_images = array_values(array_unique(array_filter(array_merge(array((int) get_post_thumbnail_id($ship_id)), $gallery))));
?>
<form class="ssf-collection-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ssf-vessel-profile="update">
    <input type="hidden" name="action" value="ssf_submit_ship_collection">
    <input type="hidden" name="ssf_token" value="<?php echo esc_attr($token_value); ?>">
    <input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="ssf-honeypot" aria-hidden="true">
    <?php wp_nonce_field('ssf_collect_ship_' . $token->id); ?>
    <div class="ssf-collection-progress" aria-hidden="true"><span></span></div>
    <p class="ssf-collection-step-count" data-collection-count></p>

    <fieldset class="ssf-collection-step is-active">
        <h1><?php esc_html_e('Uppdatera fartygsuppgifter', 'ssf-medlemsfartyg'); ?></h1>
        <p><?php echo esc_html(sprintf(__('Du uppdaterar nu den gemensamma fartygsprofilen för %s.', 'ssf-medlemsfartyg'), get_the_title($ship_id))); ?></p>
        <?php if ($route && isset(SSF_Medlemsfartyg_Profile::routes()[$route])) : ?>
            <p class="ssf-help"><strong><?php esc_html_e('Ansökningsväg:', 'ssf-medlemsfartyg'); ?></strong> <?php echo esc_html(SSF_Medlemsfartyg_Profile::route_label($route)); ?></p>
        <?php endif; ?>
        <p><?php esc_html_e('Befintliga uppgifter är ifyllda. Ändringarna skickas till SSF för granskning innan de påverkar den publika presentationen.', 'ssf-medlemsfartyg'); ?></p>
    </fieldset>

    <?php SSF_Medlemsfartyg_Profile::render(SSF_Medlemsfartyg_Profile::MODE_UPDATE, $route, $values, true); ?>

    <fieldset class="ssf-collection-step">
        <h2><?php esc_html_e('Bilder', 'ssf-medlemsfartyg'); ?></h2>
        <?php if ($existing_images) : ?>
            <p><?php esc_html_e('Bilder som redan hör till profilen:', 'ssf-medlemsfartyg'); ?></p>
            <div class="ssf-upload-preview ssf-upload-preview--existing">
                <?php foreach ($existing_images as $image_id) : ?><label class="ssf-upload-preview__item"><input type="radio" name="existing_featured_image" value="<?php echo esc_attr((string) $image_id); ?>" <?php checked((int) get_post_thumbnail_id($ship_id), $image_id); ?> data-ssf-existing-featured> <?php echo wp_get_attachment_image($image_id, 'thumbnail'); ?><span><?php esc_html_e('Använd som huvudbild', 'ssf-medlemsfartyg'); ?></span></label><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p><?php echo esc_html(sprintf(__('Ladda upp upp till %d nya bilder. Max %d MB per bild.', 'ssf-medlemsfartyg'), (int) $settings['max_images'], (int) $settings['max_image_mb'])); ?></p>
        <label><?php esc_html_e('Nya bilder', 'ssf-medlemsfartyg'); ?><input type="file" name="ssf_ship_images[]" accept="image/jpeg,image/png,image/webp" multiple data-ssf-image-input></label>
        <div class="ssf-upload-preview" data-ssf-upload-preview></div>
        <input type="hidden" name="featured_image_index" value="0" data-ssf-featured-index>
        <p class="ssf-help"><?php esc_html_e('Välj huvudbild bland befintliga eller nya bilder.', 'ssf-medlemsfartyg'); ?></p>
        <label><input type="checkbox" name="_ssf_image_consent" value="1" required> <?php esc_html_e('Jag intygar att jag har rätt att ladda upp bilderna och godkänner att SSF får använda dem på förbundets webbplats.', 'ssf-medlemsfartyg'); ?></label>
    </fieldset>

    <fieldset class="ssf-collection-step">
        <h2><?php esc_html_e('Granska och skicka', 'ssf-medlemsfartyg'); ?></h2>
        <div class="ssf-collection-summary" aria-live="polite"></div>
        <p><?php echo esc_html($settings['privacy_text']); ?></p>
        <label><input type="checkbox" name="_ssf_gdpr_consent" value="1" required> <?php esc_html_e('Jag godkänner att SSF behandlar uppgifterna för att administrera och presentera medlemsfartyget.', 'ssf-medlemsfartyg'); ?></label>
    </fieldset>

    <div class="ssf-collection-nav">
        <button type="button" class="ssf-ship-button ssf-ship-button--ghost" data-collection-prev><?php esc_html_e('Tillbaka', 'ssf-medlemsfartyg'); ?></button>
        <button type="button" class="ssf-ship-button" data-collection-next><?php esc_html_e('Nästa', 'ssf-medlemsfartyg'); ?></button>
        <button type="submit" class="ssf-ship-button" data-collection-submit><?php esc_html_e('Skicka ändringar till SSF', 'ssf-medlemsfartyg'); ?></button>
    </div>
</form>
