<?php
if (! defined('ABSPATH')) { exit; }
$application_content = wp_parse_args((array) ($application_content ?? array()), array(
    'image_id' => 0,
    'image_alt' => 'Traditionellt segelfartyg',
    'eyebrow' => 'Sveriges Segelfartygsförbund',
));
$image_url = function_exists('ssf_site_content_image_url') ? ssf_site_content_image_url((int) $application_content['image_id']) : '';
$routes = class_exists('SSF_Medlemsfartyg_Profile') ? SSF_Medlemsfartyg_Profile::routes() : array();
?>
<section class="ssf-process-shell">
    <?php if ($image_url) : ?><figure class="ssf-process-page-image"><img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($application_content['image_alt']); ?>"></figure><?php endif; ?>
    <div class="ssf-process-heading">
        <p class="ssf-process-eyebrow"><?php echo esc_html($application_content['eyebrow']); ?></p>
        <h1>Ansök om medlemskap för fartyg</h1>
        <p>Börja med att välja vilket alternativ som bäst beskriver fartyget. Därefter fyller du i fartygsuppgifter som används i medlemsprövningen och senare kan ligga till grund för fartygets presentation på SSF:s webbplats.</p>
    </div>
    <form class="ssf-process-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ssf-application-form>
        <input type="hidden" name="action" value="ssf_submit_application">
        <input class="ssf-process-honeypot" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
        <?php wp_nonce_field('ssf_application_submit'); ?>

        <ol class="ssf-process-primary-steps" aria-label="Ansökans huvudsteg">
            <li class="is-current" data-primary-indicator="0"><span>1</span><strong>Välj fartygstyp</strong><small>Steg 1 av 2</small></li>
            <li data-primary-indicator="1"><span>2</span><strong>Fartygsuppgifter</strong><small>Steg 2 av 2</small></li>
        </ol>
        <div class="ssf-process-progress" aria-hidden="true"><span data-ssf-progress></span></div>
        <p class="ssf-process-step-count" data-ssf-step-count>Steg 1 av 2: Välj fartygstyp</p>

        <fieldset class="ssf-process-step is-active" data-primary-step="route">
            <legend>På vilken grund söker fartyget medlemskap?</legend>
            <p>Välj det alternativ som bäst beskriver fartyget. Den fullständiga beskrivningen finns under Läs mer.</p>
            <div class="ssf-route-grid">
                <?php foreach ($routes as $route => $route_data) : ?>
                    <label class="ssf-route-card">
                        <input type="radio" name="application_route" value="<?php echo esc_attr($route); ?>" required>
                        <span class="ssf-route-card__number"><?php echo esc_html((string) $route_data['number']); ?></span>
                        <strong><?php echo esc_html($route_data['title']); ?></strong>
                        <span><?php echo esc_html($route_data['summary']); ?></span>
                        <details><summary>Läs mer</summary><p><?php echo esc_html($route_data['description']); ?></p></details>
                        <span class="ssf-route-card__action">Välj detta</span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <fieldset class="ssf-process-step" data-primary-step="profile" hidden>
            <legend>Vad behöver SSF veta om fartyget?</legend>
            <p class="ssf-route-context" data-route-context aria-live="polite"></p>
            <div class="ssf-vessel-section-status">
                <strong data-vessel-section-title>Grunduppgifter</strong>
                <span data-vessel-section-count></span>
            </div>

            <div data-vessel-sections>
                <?php if (class_exists('SSF_Medlemsfartyg_Profile')) { SSF_Medlemsfartyg_Profile::render(SSF_Medlemsfartyg_Profile::MODE_APPLICATION); } ?>

                <section class="ssf-vessel-profile-section" data-vessel-section="representative">
                    <div class="ssf-vessel-section-heading"><h3>Fartygsombud</h3><p>Kontaktuppgifterna används för ansökan och visas inte automatiskt publikt.</p></div>
                    <div class="ssf-vessel-fields">
                        <label class="ssf-vessel-field"><span>Namn <em>Obligatorisk</em></span><input type="text" name="applicant_name" required></label>
                        <label class="ssf-vessel-field"><span>E-post <em>Obligatorisk</em></span><input type="email" name="applicant_email" required></label>
                        <label class="ssf-vessel-field"><span>Telefon <em>Obligatorisk</em></span><input type="tel" name="applicant_phone" required></label>
                        <label class="ssf-vessel-field"><span>Organisation, förening eller rederi</span><input type="text" name="applicant_organization"></label>
                        <label class="ssf-vessel-field"><span>Adress</span><input type="text" name="applicant_address"></label>
                        <label class="ssf-vessel-field"><span>Webbplats</span><input type="url" name="applicant_website"></label>
                    </div>
                </section>

                <section class="ssf-vessel-profile-section" data-vessel-section="images">
                    <div class="ssf-vessel-section-heading"><h3>Bilder och underlag</h3><p>Huvudbilden kan senare användas i fartygslistan och på fartygets publika sida.</p></div>
                    <div class="ssf-vessel-fields ssf-vessel-fields--single">
                        <label class="ssf-vessel-field"><span>Huvudbild</span><input type="file" name="ssf_application_main_image" accept="image/jpeg,image/png,image/webp"></label>
                        <label class="ssf-vessel-field"><span>Fler bilder</span><input type="file" name="ssf_application_gallery[]" accept="image/jpeg,image/png,image/webp" multiple></label>
                        <label class="ssf-vessel-field"><span>Dokument och underlag</span><input type="file" name="ssf_application_documents[]" accept="application/pdf,image/jpeg,image/png,image/webp" multiple></label>
                        <p class="ssf-process-help">Bilder får vara högst <?php echo esc_html((string) $settings['max_image_mb']); ?> MB och PDF-filer högst <?php echo esc_html((string) $settings['max_file_mb']); ?> MB.</p>
                    </div>
                </section>

                <section class="ssf-vessel-profile-section" data-vessel-section="review">
                    <div class="ssf-vessel-section-heading"><h3>Granska och skicka</h3><p>Kontrollera sammanfattningen innan ansökan skickas.</p></div>
                    <div class="ssf-process-review" data-ssf-review aria-live="polite"></div>
                    <label class="ssf-process-choice"><input type="checkbox" name="confirm_accuracy" value="1" required> Jag intygar att uppgifterna är korrekta.</label>
                    <label class="ssf-process-choice"><input type="checkbox" name="privacy_consent" value="1" required> Jag godkänner att SSF behandlar uppgifterna för att hantera ansökan.</label>
                    <label class="ssf-process-choice"><input type="checkbox" name="upload_rights" value="1" required> Jag intygar att jag har rätt att ladda upp bilder och bilagor.</label>
                </section>
            </div>
        </fieldset>

        <div class="ssf-process-actions">
            <button type="button" class="ssf-process-button ssf-process-button--secondary" data-ssf-prev hidden>Tillbaka</button>
            <button type="button" class="ssf-process-button" data-ssf-next>Nästa</button>
            <button type="submit" class="ssf-process-button" data-ssf-submit hidden>Skicka ansökan</button>
        </div>
    </form>
</section>
