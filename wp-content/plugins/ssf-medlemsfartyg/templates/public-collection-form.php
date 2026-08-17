<?php
/**
 * Public collection form.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! empty($_GET['ssf_sent'])) {
    echo '<div class="ssf-collection-form"><h1>' . esc_html__('Tack!', 'ssf-medlemsfartyg') . '</h1><p>' . esc_html(SSF_Medlemsfartyg_Plugin::settings()['thank_you_text']) . '</p></div>';
    return;
}

$term_select = static function (string $taxonomy, string $label, bool $multiple = false) use ($ship_id): void {
    $current = wp_get_object_terms($ship_id, $taxonomy, array('fields' => 'names'));
    echo '<label>' . esc_html($label) . '<select name="tax_' . esc_attr($taxonomy) . ($multiple ? '[]' : '') . '" ' . ($multiple ? 'multiple' : '') . '>';
    if (! $multiple) {
        echo '<option value="">' . esc_html__('Välj', 'ssf-medlemsfartyg') . '</option>';
    }
    foreach (get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false)) as $term) {
        echo '<option value="' . esc_attr($term->name) . '" ' . selected(in_array($term->name, $current, true), true, false) . '>' . esc_html($term->name) . '</option>';
    }
    echo '</select></label>';
};
?>
<form class="ssf-collection-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="ssf_submit_ship_collection">
    <input type="hidden" name="ssf_token" value="<?php echo esc_attr($token_value); ?>">
    <input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="ssf-honeypot" aria-hidden="true">
    <?php wp_nonce_field('ssf_collect_ship_' . $token->id); ?>
    <div class="ssf-collection-progress"><span></span></div>

    <fieldset class="ssf-collection-step is-active">
        <h1><?php esc_html_e('Uppgifter om ert medlemsfartyg', 'ssf-medlemsfartyg'); ?></h1>
        <p><?php esc_html_e('SSF samlar in uppgifter om medlemsfartygen för att kunna presentera fartygen på förbundets webbplats. Fyll i uppgifterna nedan och ladda gärna upp bilder som visar fartyget.', 'ssf-medlemsfartyg'); ?></p>
        <p><strong><?php echo esc_html(get_the_title($ship_id)); ?></strong></p>
        <p><?php esc_html_e('Uppgifterna granskas av SSF innan de publiceras. Du väljer själv vilka kontaktuppgifter som får visas publikt.', 'ssf-medlemsfartyg'); ?></p>
    </fieldset>

    <fieldset class="ssf-collection-step">
        <h2><?php esc_html_e('Grunduppgifter', 'ssf-medlemsfartyg'); ?></h2>
        <label><?php esc_html_e('Fartygets namn', 'ssf-medlemsfartyg'); ?><input name="post_title" required value="<?php echo esc_attr(get_the_title($ship_id)); ?>"></label>
        <label><?php esc_html_e('Kortnamn', 'ssf-medlemsfartyg'); ?><input name="_ssf_short_name" value="<?php echo esc_attr(get_post_meta($ship_id, '_ssf_short_name', true)); ?>"></label>
        <?php $term_select('fartygstyp', 'Fartygstyp'); ?>
        <?php $term_select('fartygsstatus', 'Status'); ?>
        <label><?php esc_html_e('Hemmahamn', 'ssf-medlemsfartyg'); ?><input name="_ssf_home_port" value="<?php echo esc_attr(get_post_meta($ship_id, '_ssf_home_port', true)); ?>"></label>
        <?php $term_select('fartygsregion', 'Region'); ?>
        <label><?php esc_html_e('Registernummer', 'ssf-medlemsfartyg'); ?><input name="_ssf_registry_number" value="<?php echo esc_attr(get_post_meta($ship_id, '_ssf_registry_number', true)); ?>"></label>
        <label><?php esc_html_e('Signalbokstäver', 'ssf-medlemsfartyg'); ?><input name="_ssf_call_sign" value="<?php echo esc_attr(get_post_meta($ship_id, '_ssf_call_sign', true)); ?>"></label>
        <label><?php esc_html_e('MMSI', 'ssf-medlemsfartyg'); ?><input name="_ssf_mmsi" value="<?php echo esc_attr(get_post_meta($ship_id, '_ssf_mmsi', true)); ?>"></label>
    </fieldset>

    <fieldset class="ssf-collection-step">
        <h2><?php esc_html_e('Mått och teknisk information', 'ssf-medlemsfartyg'); ?></h2>
        <?php foreach (array('_ssf_build_year' => 'Byggår', '_ssf_shipyard' => 'Byggplats / varv', '_ssf_length' => 'Längd', '_ssf_beam' => 'Bredd', '_ssf_draft' => 'Djupgående', '_ssf_rig' => 'Rigtyp', '_ssf_material' => 'Material', '_ssf_engine' => 'Motor', '_ssf_sail_area' => 'Segelyta', '_ssf_passengers' => 'Antal passagerare') as $key => $label) : ?>
            <label><?php echo esc_html($label); ?><input name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr(get_post_meta($ship_id, $key, true)); ?>"></label>
        <?php endforeach; ?>
    </fieldset>

    <fieldset class="ssf-collection-step">
        <h2><?php esc_html_e('Presentation', 'ssf-medlemsfartyg'); ?></h2>
        <label><?php esc_html_e('Kort presentation', 'ssf-medlemsfartyg'); ?><textarea name="post_excerpt" rows="3"><?php echo esc_textarea(get_post_field('post_excerpt', $ship_id)); ?></textarea></label>
        <label><?php esc_html_e('Om fartyget', 'ssf-medlemsfartyg'); ?><textarea name="post_content" rows="6"><?php echo esc_textarea(get_post_field('post_content', $ship_id)); ?></textarea></label>
        <?php foreach (array('_ssf_today' => 'Vad gör fartyget idag?', '_ssf_activity' => 'Verksamhet', '_ssf_future' => 'Kommande planer', '_ssf_other_info' => 'Övrig information') as $key => $label) : ?>
            <label><?php echo esc_html($label); ?><textarea name="<?php echo esc_attr($key); ?>" rows="5"><?php echo esc_textarea(get_post_meta($ship_id, $key, true)); ?></textarea></label>
        <?php endforeach; ?>
    </fieldset>

    <fieldset class="ssf-collection-step">
        <h2><?php esc_html_e('Historia', 'ssf-medlemsfartyg'); ?></h2>
        <?php foreach (array('_ssf_history' => 'Historia om fartyget', '_ssf_previous_use' => 'Tidigare användning', '_ssf_restorations' => 'Restaureringar eller större händelser', '_ssf_cultural_value' => 'Kulturhistorisk betydelse') as $key => $label) : ?>
            <label><?php echo esc_html($label); ?><textarea name="<?php echo esc_attr($key); ?>" rows="5"><?php echo esc_textarea(get_post_meta($ship_id, $key, true)); ?></textarea></label>
        <?php endforeach; ?>
    </fieldset>

    <fieldset class="ssf-collection-step">
        <h2><?php esc_html_e('Kontakt och länkar', 'ssf-medlemsfartyg'); ?></h2>
        <?php foreach (array('_ssf_contact_name' => 'Namn på fartygsombud', '_ssf_organization' => 'Organisation / förening / rederi', '_ssf_email' => 'E-post', '_ssf_phone' => 'Telefon', '_ssf_website' => 'Webbplats', '_ssf_facebook' => 'Facebook', '_ssf_instagram' => 'Instagram', '_ssf_booking_link' => 'Bokningslänk eller seglingsprogram', '_ssf_other_link' => 'Annan länk') as $key => $label) : ?>
            <label><?php echo esc_html($label); ?><input name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr(get_post_meta($ship_id, $key, true)); ?>"></label>
        <?php endforeach; ?>
        <p class="ssf-help"><?php esc_html_e('Du väljer själv vilka kontaktuppgifter som får visas publikt på fartygets sida. SSF kan ändå behöva kontaktuppgifterna internt för medlemsadministration.', 'ssf-medlemsfartyg'); ?></p>
        <label><input type="checkbox" name="_ssf_public_contact" value="1" <?php checked('1', get_post_meta($ship_id, '_ssf_public_contact', true)); ?>> <?php esc_html_e('Visa kontaktuppgifter publikt', 'ssf-medlemsfartyg'); ?></label>
        <label><input type="checkbox" name="_ssf_public_website" value="1" checked> <?php esc_html_e('Visa webbplats publikt', 'ssf-medlemsfartyg'); ?></label>
        <label><input type="checkbox" name="_ssf_public_phone" value="1"> <?php esc_html_e('Visa telefon publikt', 'ssf-medlemsfartyg'); ?></label>
        <label><input type="checkbox" name="_ssf_public_email" value="1"> <?php esc_html_e('Visa e-post publikt', 'ssf-medlemsfartyg'); ?></label>
    </fieldset>

    <fieldset class="ssf-collection-step">
        <h2><?php esc_html_e('Bilder', 'ssf-medlemsfartyg'); ?></h2>
        <p><?php echo esc_html(sprintf(__('Ladda upp upp till %d bilder. Tillåtna format: %s. Max %d MB per bild.', 'ssf-medlemsfartyg'), (int) $settings['max_images'], esc_html($settings['allowed_image_types']), (int) $settings['max_image_mb'])); ?></p>
        <input type="file" name="ssf_ship_images[]" accept="image/jpeg,image/png,image/webp" multiple data-ssf-image-input>
        <div class="ssf-upload-preview" data-ssf-upload-preview></div>
        <div class="ssf-image-meta">
            <?php for ($i = 0; $i < (int) $settings['max_images']; $i++) : ?>
                <div><label><?php echo esc_html(sprintf(__('Bild %d bildtext', 'ssf-medlemsfartyg'), $i + 1)); ?><input name="image_caption[]"></label><label><?php esc_html_e('Alt-text', 'ssf-medlemsfartyg'); ?><input name="image_alt[]"></label></div>
            <?php endfor; ?>
        </div>
        <input type="hidden" name="featured_image_index" value="0" data-ssf-featured-index>
        <p class="ssf-help"><?php esc_html_e('Om ingen huvudbild väljs föreslås den första uppladdade bilden som huvudbild.', 'ssf-medlemsfartyg'); ?></p>
        <label><input type="checkbox" name="_ssf_image_consent" value="1" required> <?php esc_html_e('Jag intygar att jag har rätt att ladda upp bilderna och godkänner att SSF får använda dem på förbundets webbplats.', 'ssf-medlemsfartyg'); ?></label>
    </fieldset>

    <fieldset class="ssf-collection-step">
        <h2><?php esc_html_e('Förhandsgranska', 'ssf-medlemsfartyg'); ?></h2>
        <div class="ssf-collection-summary" aria-live="polite"></div>
        <p><?php echo esc_html($settings['privacy_text']); ?></p>
        <label><input type="checkbox" name="_ssf_gdpr_consent" value="1" required> <?php esc_html_e('Jag godkänner att SSF behandlar uppgifterna för att administrera och presentera medlemsfartyget.', 'ssf-medlemsfartyg'); ?></label>
    </fieldset>

    <div class="ssf-collection-nav">
        <button type="button" class="ssf-ship-button ssf-ship-button--ghost" data-collection-prev><?php esc_html_e('Tillbaka', 'ssf-medlemsfartyg'); ?></button>
        <button type="button" class="ssf-ship-button" data-collection-next><?php esc_html_e('Nästa', 'ssf-medlemsfartyg'); ?></button>
        <button type="submit" class="ssf-ship-button" data-collection-submit><?php esc_html_e('Skicka in till SSF', 'ssf-medlemsfartyg'); ?></button>
    </div>
</form>
