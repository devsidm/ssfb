<?php
if (! defined('ABSPATH')) { exit; }
$application_content = wp_parse_args((array) ($application_content ?? array()), array(
    'image_id' => 0,
    'image_alt' => 'Traditionellt segelfartyg',
));
$image_url = function_exists('ssf_site_content_image_url') ? ssf_site_content_image_url((int) $application_content['image_id']) : '';
$routes = class_exists('SSF_Medlemsfartyg_Profile') ? SSF_Medlemsfartyg_Profile::routes() : array();
$route_presentation = array(
    'normal' => array(
        'title' => 'Kategori 1',
        'subtitle' => 'Seglande yrkesfartyg som uppfyller måttkraven',
        'description' => 'Segelfartyg eller segelfartyg med hjälpmotor som används eller tidigare har använts som yrkesfartyg. Längd i huvuddäck över 12 meter och bredd minst 4 meter.',
        'action' => 'Välj kategori 1',
    ),
    'small_registered' => array(
        'title' => 'Kategori 2',
        'subtitle' => 'Mindre registrerat fartyg',
        'description' => 'Fartyget understiger ett eller båda måttkraven i kategori 1 men är registrerat i svenskt skepps- eller fartygsregister och kan därför prövas särskilt.',
        'action' => 'Välj kategori 2',
    ),
    'restoration' => array(
        'title' => 'Kategori 3',
        'subtitle' => 'Fartyg under restaurering',
        'description' => 'Fartyget är av den typ stadgarna avser och restaureras med en trovärdig plan för att bevaras eller återföras som segelfartyg.',
        'action' => 'Välj kategori 3',
    ),
    'new_traditional' => array(
        'title' => 'Kategori 4',
        'subtitle' => 'Nybyggt traditionsfartyg',
        'description' => 'Fartyget är nybyggt men byggt och utformat enligt vedertagna traditioner för äldre seglande yrkesfartyg.',
        'action' => 'Välj kategori 4',
    ),
);
$submission_key = wp_generate_uuid4();
$steps = array('Medlemsväg', 'Fartygsombud', 'Fartyget', 'Historia', 'Filer', 'Granska');
?>
<section class="ssf-process-shell">
    <?php if ($image_url) : ?><figure class="ssf-process-page-image"><img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($application_content['image_alt']); ?>"></figure><?php endif; ?>
    <div class="ssf-process-heading">
        <h1>Ansök om medlemskap för fartyg</h1>
        <p>Välj först den medlemsväg som beskriver fartyget bäst. Uppgifterna sparas som en strukturerad fartygsprofil och används i SSF:s medlemsprövning.</p>
    </div>
    <form class="ssf-process-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ssf-application-form>
        <input type="hidden" name="action" value="ssf_submit_application">
        <input type="hidden" name="submission_key" value="<?php echo esc_attr($submission_key); ?>">
        <input class="ssf-process-honeypot" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
        <?php wp_nonce_field('ssf_application_submit'); ?>

        <ol class="ssf-process-primary-steps" aria-label="Ansökans steg">
            <?php foreach ($steps as $index => $label) : ?>
                <li class="<?php echo 0 === $index ? 'is-current' : ''; ?>" data-step-indicator="<?php echo esc_attr((string) $index); ?>"><span><?php echo esc_html((string) ($index + 1)); ?></span><strong><?php echo esc_html($label); ?></strong></li>
            <?php endforeach; ?>
        </ol>
        <div class="ssf-process-progress" aria-hidden="true"><span data-ssf-progress></span></div>
        <p class="ssf-process-step-count" data-ssf-step-count>Steg 1 av 6: Medlemsväg</p>

        <fieldset class="ssf-process-step is-active" data-application-step="Medlemsväg">
            <legend>På vilken grund söker fartyget medlemskap?</legend>
            <p>Välj det alternativ som bäst beskriver fartyget. Du får rätt följdfrågor i steg 4.</p>
            <div class="ssf-route-grid">
                <?php foreach ($routes as $route => $route_data) : ?>
                    <?php $display = $route_presentation[$route] ?? array('title' => $route_data['title'], 'subtitle' => $route_data['title'], 'description' => $route_data['description'], 'action' => 'Välj detta'); ?>
                    <label class="ssf-route-card">
                        <input type="radio" name="application_route" value="<?php echo esc_attr($route); ?>" required>
                        <span class="ssf-route-card__number"><?php echo esc_html((string) $route_data['number']); ?></span>
                        <strong class="ssf-route-card__title"><?php echo esc_html($display['title']); ?></strong>
                        <span class="ssf-route-card__subtitle"><?php echo esc_html($display['subtitle']); ?></span>
                        <span class="ssf-route-card__description"><?php echo esc_html($display['description']); ?></span>
                        <span class="ssf-route-card__action"><?php echo esc_html($display['action']); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <fieldset class="ssf-process-step" data-application-step="Fartygsombud" hidden>
            <legend>Kontaktperson</legend>
            <p>Kontaktuppgifter för fartygsombud</p>
            <div class="ssf-vessel-fields">
                <label class="ssf-vessel-field"><span>Förnamn <em>Obligatorisk</em></span><input type="text" name="applicant_first_name" autocomplete="given-name" required></label>
                <label class="ssf-vessel-field"><span>Efternamn <em>Obligatorisk</em></span><input type="text" name="applicant_last_name" autocomplete="family-name" required></label>
                <label class="ssf-vessel-field"><span>Postadress</span><input type="text" name="applicant_street" autocomplete="street-address"></label>
                <label class="ssf-vessel-field"><span>Postnummer</span><input type="text" name="applicant_postal_code" autocomplete="postal-code"></label>
                <label class="ssf-vessel-field"><span>Ort</span><input type="text" name="applicant_city" autocomplete="address-level2"></label>
                <label class="ssf-vessel-field"><span>Telefon / mobil <em>Obligatorisk</em></span><input type="tel" name="applicant_phone" autocomplete="tel" required></label>
                <label class="ssf-vessel-field"><span>E-postadress <em>Obligatorisk</em></span><input type="email" name="applicant_email" autocomplete="email" required></label>
                <label class="ssf-vessel-field"><span>Faktura-e-post</span><input type="email" name="applicant_invoice_email" autocomplete="email"><small>Ange adressen som ska användas för fakturor.</small></label>
                <label class="ssf-vessel-field"><span>Organisation, förening eller rederi</span><input type="text" name="applicant_organization" autocomplete="organization"></label>
                <label class="ssf-vessel-field"><span>Hemsida</span><input type="url" name="applicant_website" inputmode="url"></label>
            </div>
        </fieldset>

        <fieldset class="ssf-process-step" data-application-step="Fartyget" hidden>
            <legend>Fakta om fartyget</legend>
            <p class="ssf-route-context" data-route-context aria-live="polite"></p>
            <?php if (class_exists('SSF_Medlemsfartyg_Profile')) { SSF_Medlemsfartyg_Profile::render(SSF_Medlemsfartyg_Profile::MODE_APPLICATION, '', array(), false, array('basic', 'dimensions', 'rig')); } ?>
        </fieldset>

        <fieldset class="ssf-process-step" data-application-step="Historia och särskilda uppgifter" hidden>
            <legend>Historia och särskilda uppgifter</legend>
            <p>Beskriv fartygets historik, tidigare användning, större ombyggnader eller restaureringar och hur fartyget används idag.</p>
            <?php if (class_exists('SSF_Medlemsfartyg_Profile')) { SSF_Medlemsfartyg_Profile::render(SSF_Medlemsfartyg_Profile::MODE_APPLICATION, '', array(), false, array('history', 'registration', 'restoration', 'traditional', 'presentation')); } ?>
        </fieldset>

        <fieldset class="ssf-process-step" data-application-step="Bilder och dokument" hidden>
            <legend>Bilder och dokument</legend>
            <p>Originalfilerna sparas i ansökans arkiv. Filer kan tas bort från listan innan du skickar.</p>
            <div class="ssf-vessel-fields ssf-vessel-fields--single">
                <label class="ssf-vessel-field"><span>Huvudbild</span><input type="file" name="ssf_application_main_image" accept="image/jpeg,image/png,image/webp" data-file-input></label>
                <label class="ssf-vessel-field"><span>Övriga bilder, högst 10</span><input type="file" name="ssf_application_gallery[]" accept="image/jpeg,image/png,image/webp" multiple data-file-input></label>
                <label class="ssf-vessel-field"><span>Bilagor</span><input type="file" name="ssf_application_documents[]" accept="application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png,image/webp" multiple data-file-input></label>
                <p class="ssf-process-help">Bilder får vara högst <?php echo esc_html((string) $settings['max_image_mb']); ?> MB. PDF-, Word- och dokumentfiler får vara högst <?php echo esc_html((string) $settings['max_file_mb']); ?> MB.</p>
            </div>
        </fieldset>

        <fieldset class="ssf-process-step" data-application-step="Granska och skicka" hidden>
            <legend>Granska och skicka</legend>
            <p>Kontrollera sammanfattningen innan ansökan skickas.</p>
            <div class="ssf-process-review" data-ssf-review aria-live="polite"></div>
            <label class="ssf-process-choice"><input type="checkbox" name="confirm_accuracy" value="1" required> Jag intygar att uppgifterna är korrekta.</label>
            <label class="ssf-process-choice"><input type="checkbox" name="privacy_consent" value="1" required> Jag godkänner att SSF behandlar uppgifterna för att hantera ansökan.</label>
            <label class="ssf-process-choice"><input type="checkbox" name="upload_rights" value="1" required> Jag intygar att jag har rätt att ladda upp bilder och bilagor.</label>
            <?php if (class_exists('SSF_Antispam')) { SSF_Antispam::render('membership_application'); } ?>
        </fieldset>

        <div class="ssf-process-actions">
            <button type="button" class="ssf-process-button ssf-process-button--secondary" data-ssf-prev hidden>Tillbaka</button>
            <button type="button" class="ssf-process-button" data-ssf-next>Nästa</button>
            <button type="submit" class="ssf-process-button" data-ssf-submit hidden>Skicka ansökan</button>
        </div>
    </form>
</section>
