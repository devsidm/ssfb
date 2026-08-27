<?php
/**
 * Shortcodes for SSF pages.
 *
 * @package SSF_Site
 */

if (! defined('ABSPATH')) {
    exit;
}

function ssf_site_applications_enabled(): bool
{
    return ! class_exists('SSF_Features') || SSF_Features::enabled('applications');
}

function ssf_site_application_destination(string $title, string $text, string $url): array
{
    $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
    if (! ssf_site_applications_enabled() && false !== strpos($path, 'ansokan')) {
        return array('Medlemskap', 'Läs om medlemskap och hur du kan engagera dig i SSF.', home_url('/medlemskap/'));
    }
    return array($title, $text, $url);
}

function ssf_site_button(string $label, string $url, string $class = ''): string
{
    list($label, , $url) = ssf_site_application_destination($label, '', $url);
    return sprintf('<a class="ssf-button %s" href="%s">%s</a>', esc_attr($class), esc_url($url), esc_html($label));
}

function ssf_site_home_shortcode(): string
{
    ob_start();
    ?>
    <section class="ssf-hero" aria-label="Sveriges Segelfartygsförbund">
        <img src="<?php echo esc_url(SSF_SITE_URL . 'assets/images/ssf-hero.jpg'); ?>" alt="Traditionella segelfartyg på vattnet">
    </section>

    <section class="ssf-section ssf-intro">
        <div class="ssf-wrap">
            <h1>Vi samlar Sveriges seglande kulturarv</h1>
            <p>SSF är förbundet för traditionella segelfartyg, fartygsombud och personer som vill bevara, bruka och utveckla Sveriges segelfartygsarv.</p>
            <div class="ssf-actions">
                <?php echo ssf_site_button('Bli stödmedlem', home_url('/medlemskap/')); ?>
                <?php echo ssf_site_button('Ansök som fartygsombud', home_url('/ansokan/'), 'ssf-button--ghost'); ?>
            </div>
        </div>
    </section>

    <section class="ssf-section">
        <div class="ssf-wrap">
            <h2>Vad vill du gora?</h2>
            <div class="ssf-card-grid ssf-card-grid--three">
                <?php echo ssf_site_feature_card('Ansöka som fartygsombud', 'Testa om ditt fartyg kan gå vidare till ansökan', home_url('/ansokan/')); ?>
                <?php echo ssf_site_feature_card('Bli stödmedlem', 'Stöd arbetet för Sveriges segelfartyg', home_url('/medlemskap/')); ?>
                <?php echo ssf_site_feature_card('Kontakta SSF', 'Ställ en fråga till förbundet', home_url('/kontakta-oss/')); ?>
            </div>
        </div>
    </section>

    <section class="ssf-section ssf-section--blue">
        <div class="ssf-wrap">
            <h2>Förbundet för traditionella segelfartyg</h2>
            <p>Sveriges Segelfartygsförbund arbetar för att stärka förutsättningarna för traditionella segelfartyg i Sverige. Vi samlar fartygsombud, stödmedlemmar och andra som vill att segelfartygen fortsatt ska brukas, underhållas och synas.</p>
            <div class="ssf-card-grid ssf-card-grid--three">
                <?php echo ssf_site_plain_card('Fartyg', 'Traditionsfartyg som brukas, vårdas och utvecklas.'); ?>
                <?php echo ssf_site_plain_card('Gemenskap', 'Ett nätverk för fartygsombud, stödmedlemmar och engagerade.'); ?>
                <?php echo ssf_site_plain_card('Kunskap', 'Erfarenhet, stadgar och samverkan för maritimt kulturarv.'); ?>
            </div>
        </div>
    </section>

    <section class="ssf-section">
        <div class="ssf-wrap">
            <h2>Två sätt att vara med i SSF</h2>
            <p>I SSF är medlemmen en person. Du kan vara stödmedlem eller fartygsombud för ett eller flera godkända fartyg.</p>
            <div class="ssf-card-grid ssf-card-grid--two">
                <?php echo ssf_site_membership_card('Stödmedlem', 'För dig som vill stödja SSF:s arbete för att bevara och utveckla Sveriges seglande kulturarv.', array('Stödjer förbundets arbete', 'Passar privatpersoner och familjer', 'Bidrar till segelfartygens framtid'), 'Bli stödmedlem', home_url('/medlemskap/')); ?>
                <?php echo ssf_site_membership_card('Fartygsombud', 'För dig som företräder ett fartyg som vill anslutas till SSF. Ett fartygsombud kan företräda ett eller flera fartyg.', array('Ombudet är medlemmen', 'Fartyget prövas enligt stadgarna', 'Avgift baseras på fartygstyp'), 'Ansök som fartygsombud', home_url('/ansokan/')); ?>
            </div>
        </div>
    </section>

    <section class="ssf-section ssf-section--gold">
        <div class="ssf-wrap">
            <h2>Så fungerar aspirantprocessen</h2>
            <p>Ett fartyg som vill anslutas till SSF prövas av förbundet. Ansökan görs av fartygsombudet och leder till en första bedömning.</p>
            <div class="ssf-steps">
                <?php echo ssf_site_step('1', 'Testa fartyget', 'Kontrollera om fartyget uppfyller grundkraven eller behöver särskild prövning.'); ?>
                <?php echo ssf_site_step('2', 'Skicka ansökan', 'Fartygsombudet lämnar uppgifter om sig själv och om fartyget.'); ?>
                <?php echo ssf_site_step('3', 'Styrelsen prövar', 'SSF:s styrelse gör bedömning enligt stadgar och medlemskriterier.'); ?>
                <?php echo ssf_site_step('4', 'Aspirantår', 'Under aspirantprocessen kan fartyget besiktigas och följas upp.'); ?>
            </div>
        </div>
    </section>

    <section class="ssf-section">
        <div class="ssf-wrap">
            <h2>Nyheter från SSF</h2>
            <p>Här hittar du information från förbundet, nyheter, evenemang och sådant som är viktigt för våra medlemmar.</p>
            <?php echo do_shortcode('[ssf_news_cards count="4"]'); ?>
        </div>
    </section>

    <section class="ssf-section ssf-section--blue">
        <div class="ssf-wrap ssf-split">
            <div>
                <h2>Har du frågor?</h2>
                <p>Hör av dig om medlemskap, fartygsombud, ansökan eller om du vill veta mer om SSF:s arbete.</p>
            </div>
            <?php echo ssf_site_button('Kontakta oss', home_url('/kontakta-oss/')); ?>
        </div>
    </section>

    <section class="ssf-section ssf-final-cta">
        <div class="ssf-wrap">
            <h2>Var med och håll segelfartygen levande</h2>
            <p>Som stödmedlem eller fartygsombud bidrar du till att Sveriges segelfartygsarv kan leva vidare.</p>
            <div class="ssf-actions">
                <?php echo ssf_site_button('Bli stödmedlem', home_url('/medlemskap/')); ?>
                <?php echo ssf_site_button('Ansök som fartygsombud', home_url('/ansokan/'), 'ssf-button--ghost'); ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}
add_shortcode('ssf_home', 'ssf_site_home_shortcode');

function ssf_site_feature_card(string $title, string $text, string $url): string
{
    list($title, $text, $url) = ssf_site_application_destination($title, $text, $url);
    return sprintf('<a class="ssf-card ssf-card--link" href="%s"><h3>%s</h3><p>%s</p><span>Läs mer</span></a>', esc_url($url), esc_html($title), esc_html($text));
}

function ssf_site_plain_card(string $title, string $text): string
{
    return sprintf('<div class="ssf-card"><h3>%s</h3><p>%s</p></div>', esc_html($title), esc_html($text));
}

function ssf_site_membership_card(string $title, string $text, array $items, string $button, string $url): string
{
    list($title, $text, $url) = ssf_site_application_destination($title, $text, $url);
    if (! ssf_site_applications_enabled() && home_url('/medlemskap/') === $url) {
        $button = 'Läs om medlemskap';
    }
    $list = '';
    foreach ($items as $item) {
        $list .= '<li>' . esc_html($item) . '</li>';
    }

    return sprintf('<div class="ssf-card ssf-card--membership"><h3>%s</h3><p>%s</p><ul>%s</ul>%s</div>', esc_html($title), esc_html($text), $list, ssf_site_button($button, $url));
}

function ssf_site_step(string $number, string $title, string $text): string
{
    return sprintf('<div class="ssf-step"><span>%s</span><h3>%s</h3><p>%s</p></div>', esc_html($number), esc_html($title), esc_html($text));
}

function ssf_site_news_cards_shortcode(array $atts): string
{
    $atts = shortcode_atts(array('count' => 3), $atts);
    $query = new WP_Query(
        array(
            'post_type' => 'post',
            'posts_per_page' => (int) $atts['count'],
            'post_status' => 'publish',
        )
    );

    if (! $query->have_posts()) {
        return '<p class="ssf-empty">Inga nyheter publicerade ännu.</p>';
    }

    ob_start();
    echo '<div class="ssf-news-grid">';
    while ($query->have_posts()) {
        $query->the_post();
        ?>
        <article class="ssf-news-card">
            <a href="<?php the_permalink(); ?>" class="ssf-news-card__image">
                <?php if (has_post_thumbnail()) : ?>
                    <?php the_post_thumbnail('medium_large'); ?>
                <?php endif; ?>
            </a>
            <div class="ssf-news-card__body">
                <time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date()); ?></time>
                <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                <p><?php echo esc_html(wp_trim_words(get_the_excerpt(), 24)); ?></p>
                <a class="ssf-read-more" href="<?php the_permalink(); ?>">Läs mer <span aria-hidden="true">-&gt;</span></a>
            </div>
        </article>
        <?php
    }
    echo '</div>';
    wp_reset_postdata();

    return ob_get_clean();
}
add_shortcode('ssf_news_cards', 'ssf_site_news_cards_shortcode');

function ssf_site_application_form_shortcode(): string
{
    if (! ssf_site_applications_enabled()) {
        return '<section class="ssf-page-content"><div class="ssf-page-content__heading"><h1>Ansökan</h1><p>Den digitala ansökningsfunktionen är tillfälligt stängd medan vi färdigställer den nya medlemsprocessen.</p><p>För frågor om medlemskap, <a href="' . esc_url(home_url('/kontakta-oss/')) . '">kontakta SSF</a>.</p></div></section>';
    }
    ob_start();
    ssf_site_status_notice();
    ?>
    <form class="ssf-form ssf-application-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="ssf_application">
        <?php wp_nonce_field('ssf_application', 'ssf_application_nonce'); ?>
        <div class="ssf-progress"><span></span></div>
        <?php
        ssf_site_form_step('Välj ansökningsväg', array(
            ssf_site_radio_card('ansokningsvag', 'mattkrav', 'Fartyget uppfyller måttkraven', 'Över 12 meter i huvuddäck och minst 4 meter brett.'),
            ssf_site_radio_card('ansokningsvag', 'sarskild', 'Fartyget uppfyller inte måttkraven', 'Fartyget behöver särskild prövning.'),
        ));
        ssf_site_form_step('Är fartyget ett segelfartyg eller segelfartyg med hjälpmotor?', array(ssf_site_yes_no('segelfartyg')));
        ssf_site_form_step('Har fartyget använts eller används det som seglande yrkesfartyg?', array(ssf_site_yes_no('yrkeshistorik')));
        ssf_site_form_step('Är fartyget nybyggt i traditionell stil?', array(ssf_site_yes_no('traditionell_nybyggnad')));
        ssf_site_form_step('Ange fartygets mått', array('<label>Längd i huvuddäck, meter<input type="number" step="0.1" min="0" name="langd" required></label>', '<label>Bredd, meter<input type="number" step="0.1" min="0" name="bredd" required></label>'));
        ssf_site_form_step('Är fartyget registrerat i svenskt skeppsregister?', array(ssf_site_yes_no('svenskt_register')));
        ssf_site_form_step('Uppgifter om fartyget', array('<label>Fartygets namn<input name="fartygsnamn" required></label>', '<label>Fartygstyp<select name="fartygstyp" required><option value="">Välj</option><option>fritidsfartyg</option><option>handelsfartyg</option></select></label>', '<label>Är fartyget under restaurering?<select name="restaurering" required><option value="">Välj</option><option value="ja">Ja</option><option value="nej">Nej</option></select></label>', '<label>Kort beskrivning av fartyget<textarea name="fartyg_beskrivning" rows="5" required></textarea></label>', '<label>Länk till webbplats eller mer information<input type="url" name="fartyg_lank"></label>'));
        ssf_site_form_step('Uppgifter om fartygsombud', array('<label>Namn<input name="ombud_namn" required></label>', '<label>E-post<input type="email" name="ombud_epost" required></label>', '<label>Telefon<input name="ombud_telefon" required></label>', '<label>Organisation/förening/rederi<input name="organisation"></label>', '<label>Övriga upplysningar<textarea name="ovrigt" rows="4"></textarea></label>'));
        ssf_site_form_step('Resultat och bekräftelse', array('<div class="ssf-form-result" aria-live="polite"><h3>Förhandsbedömning visas här</h3><p>Fyll i stegen så visas en första bedömning innan du skickar ansökan.</p></div>', '<label class="ssf-check"><input type="checkbox" name="korrekt" value="1" required> Jag intygar att uppgifterna är korrekta och vill skicka ansökan till SSF.</label>', '<label class="ssf-check"><input type="checkbox" name="gdpr" value="1" required> Jag godkänner att SSF behandlar mina uppgifter för att hantera ansökan.</label>', '<p class="ssf-privacy">Uppgifterna sparas i WordPress och används endast för att hantera ansökan. Åtkomst till submissions ska begränsas till administratörer.</p>'));
        ?>
        <div class="ssf-form-nav">
            <button type="button" class="ssf-button ssf-button--ghost" data-ssf-prev>Tillbaka</button>
            <button type="button" class="ssf-button" data-ssf-next>Nästa</button>
            <button type="submit" class="ssf-button" data-ssf-submit>Skicka ansökan</button>
        </div>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('ssf_application_form', 'ssf_site_application_form_shortcode');

function ssf_site_form_step(string $title, array $fields): void
{
    echo '<fieldset class="ssf-form-step"><legend>' . esc_html($title) . '</legend>';
    foreach ($fields as $field) {
        echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '</fieldset>';
}

function ssf_site_radio_card(string $name, string $value, string $title, string $text): string
{
    return sprintf('<label class="ssf-choice"><input type="radio" name="%s" value="%s" required><span><strong>%s</strong>%s</span></label>', esc_attr($name), esc_attr($value), esc_html($title), esc_html($text));
}

function ssf_site_yes_no(string $name): string
{
    return ssf_site_radio_card($name, 'ja', 'Ja', '') . ssf_site_radio_card($name, 'nej', 'Nej', '');
}

function ssf_site_contact_form_shortcode(): string
{
    ob_start();
    ssf_site_status_notice();
    ?>
    <form class="ssf-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="ssf_contact">
        <?php wp_nonce_field('ssf_contact', 'ssf_contact_nonce'); ?>
        <label>Namn<input name="namn" required></label>
        <label>E-post<input type="email" name="epost" required></label>
        <label>Telefon<input name="telefon"></label>
        <label>Ämne<input name="amne" required></label>
        <label>Meddelande<textarea name="meddelande" rows="6" required></textarea></label>
        <button class="ssf-button" type="submit">Skicka</button>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('ssf_contact_form', 'ssf_site_contact_form_shortcode');

function ssf_site_member_vessels_shortcode(): string
{
    if (shortcode_exists('ssf_medlemsfartyg')) {
        return do_shortcode('[ssf_medlemsfartyg]');
    }

    return '<p class="ssf-empty">Medlemsfartyg kommer att visas här.</p>';
}
add_shortcode('ssf_member_vessels', 'ssf_site_member_vessels_shortcode');

function ssf_site_status_notice(): void
{
    $status = isset($_GET['ssf_status']) ? sanitize_text_field(wp_unslash($_GET['ssf_status'])) : '';
    $messages = array(
        'application_sent' => 'Tack. Din ansökan har skickats till SSF.',
        'application_closed' => 'Den digitala ansökningsfunktionen är tillfälligt stängd.',
        'contact_sent' => 'Tack. Ditt meddelande har skickats.',
        'invalid' => 'Formuläret kunde inte verifieras. Försök igen.',
        'consent' => 'Du behöver godkänna intygande och behandling av uppgifter för att skicka formuläret.',
    );
    if (isset($messages[$status])) {
        echo '<div class="ssf-notice">' . esc_html($messages[$status]) . '</div>';
    }
}
