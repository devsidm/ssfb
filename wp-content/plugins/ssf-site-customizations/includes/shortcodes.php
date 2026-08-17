<?php
/**
 * Shortcodes for SSF pages.
 *
 * @package SSF_Site
 */

if (! defined('ABSPATH')) {
    exit;
}

function ssf_site_button(string $label, string $url, string $class = ''): string
{
    return sprintf('<a class="ssf-button %s" href="%s">%s</a>', esc_attr($class), esc_url($url), esc_html($label));
}

function ssf_site_home_shortcode(): string
{
    ob_start();
    ?>
    <section class="ssf-hero" aria-label="Sveriges Segelfartygsforbund">
        <img src="<?php echo esc_url(SSF_SITE_URL . 'assets/images/ssf-hero.jpg'); ?>" alt="Traditionella segelfartyg pa vattnet">
    </section>

    <section class="ssf-section ssf-intro">
        <div class="ssf-wrap">
            <h1>Vi samlar Sveriges seglande kulturarv</h1>
            <p>SSF ar forbundet for traditionella segelfartyg, fartygsombud och personer som vill bevara, bruka och utveckla Sveriges segelfartygsarv.</p>
            <div class="ssf-actions">
                <?php echo ssf_site_button('Bli stodmedlem', home_url('/medlemskap/')); ?>
                <?php echo ssf_site_button('Ansok som fartygsombud', home_url('/ansokan/'), 'ssf-button--ghost'); ?>
            </div>
        </div>
    </section>

    <section class="ssf-section">
        <div class="ssf-wrap">
            <h2>Vad vill du gora?</h2>
            <div class="ssf-card-grid ssf-card-grid--three">
                <?php echo ssf_site_feature_card('Ansoka som fartygsombud', 'Testa om ditt fartyg kan ga vidare till ansokan', home_url('/ansokan/')); ?>
                <?php echo ssf_site_feature_card('Bli stodmedlem', 'Stod arbetet for Sveriges segelfartyg', home_url('/medlemskap/')); ?>
                <?php echo ssf_site_feature_card('Kontakta SSF', 'Stall en fraga till forbundet', home_url('/kontakta-oss/')); ?>
            </div>
        </div>
    </section>

    <section class="ssf-section ssf-section--blue">
        <div class="ssf-wrap">
            <h2>Forbundet for traditionella segelfartyg</h2>
            <p>Sveriges Segelfartygsforbund arbetar for att starka forutsattningarna for traditionella segelfartyg i Sverige. Vi samlar fartygsombud, stodmedlemmar och andra som vill att segelfartygen fortsatt ska brukas, underhallas och synas.</p>
            <div class="ssf-card-grid ssf-card-grid--three">
                <?php echo ssf_site_plain_card('Fartyg', 'Traditionsfartyg som brukas, vardas och utvecklas.'); ?>
                <?php echo ssf_site_plain_card('Gemenskap', 'Ett natverk for fartygsombud, stodmedlemmar och engagerade.'); ?>
                <?php echo ssf_site_plain_card('Kunskap', 'Erfarenhet, stadgar och samverkan for maritimt kulturarv.'); ?>
            </div>
        </div>
    </section>

    <section class="ssf-section">
        <div class="ssf-wrap">
            <h2>Tva satt att vara med i SSF</h2>
            <p>I SSF ar medlemmen en person. Du kan vara stodmedlem eller fartygsombud for ett eller flera godkanda fartyg.</p>
            <div class="ssf-card-grid ssf-card-grid--two">
                <?php echo ssf_site_membership_card('Stodmedlem', 'For dig som vill stodja SSF:s arbete for att bevara och utveckla Sveriges seglande kulturarv.', array('Stodjer forbundets arbete', 'Passar privatpersoner och familjer', 'Bidrar till segelfartygens framtid'), 'Bli stodmedlem', home_url('/medlemskap/')); ?>
                <?php echo ssf_site_membership_card('Fartygsombud', 'For dig som foretrader ett fartyg som vill anslutas till SSF. Ett fartygsombud kan foretrada ett eller flera fartyg.', array('Ombudet ar medlemmen', 'Fartyget provas enligt stadgarna', 'Avgift baseras pa fartygstyp'), 'Ansok som fartygsombud', home_url('/ansokan/')); ?>
            </div>
        </div>
    </section>

    <section class="ssf-section ssf-section--gold">
        <div class="ssf-wrap">
            <h2>Sa fungerar aspirantprocessen</h2>
            <p>Ett fartyg som vill anslutas till SSF provas av forbundet. Ansokan gors av fartygsombudet och leder till en forsta bedomning.</p>
            <div class="ssf-steps">
                <?php echo ssf_site_step('1', 'Testa fartyget', 'Kontrollera om fartyget uppfyller grundkraven eller behover sarskild provning.'); ?>
                <?php echo ssf_site_step('2', 'Skicka ansokan', 'Fartygsombudet lamnar uppgifter om sig sjalv och om fartyget.'); ?>
                <?php echo ssf_site_step('3', 'Styrelsen provar', 'SSF:s styrelse gor bedomning enligt stadgar och medlemskriterier.'); ?>
                <?php echo ssf_site_step('4', 'Aspirantar', 'Under aspirantprocessen kan fartyget besiktigas och foljas upp.'); ?>
            </div>
        </div>
    </section>

    <section class="ssf-section">
        <div class="ssf-wrap">
            <h2>Nyheter fran SSF</h2>
            <p>Har hittar du information fran forbundet, nyheter, evenemang och sadant som ar viktigt for vara medlemmar.</p>
            <?php echo do_shortcode('[ssf_news_cards count="4"]'); ?>
        </div>
    </section>

    <section class="ssf-section ssf-section--blue">
        <div class="ssf-wrap ssf-split">
            <div>
                <h2>Har du fragor?</h2>
                <p>Hor av dig om medlemskap, fartygsombud, ansokan eller om du vill veta mer om SSF:s arbete.</p>
            </div>
            <?php echo ssf_site_button('Kontakta oss', home_url('/kontakta-oss/')); ?>
        </div>
    </section>

    <section class="ssf-section ssf-final-cta">
        <div class="ssf-wrap">
            <h2>Var med och hall segelfartygen levande</h2>
            <p>Som stodmedlem eller fartygsombud bidrar du till att Sveriges segelfartygsarv kan leva vidare.</p>
            <div class="ssf-actions">
                <?php echo ssf_site_button('Bli stodmedlem', home_url('/medlemskap/')); ?>
                <?php echo ssf_site_button('Ansok som fartygsombud', home_url('/ansokan/'), 'ssf-button--ghost'); ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}
add_shortcode('ssf_home', 'ssf_site_home_shortcode');

function ssf_site_feature_card(string $title, string $text, string $url): string
{
    return sprintf('<a class="ssf-card ssf-card--link" href="%s"><h3>%s</h3><p>%s</p><span>Las mer</span></a>', esc_url($url), esc_html($title), esc_html($text));
}

function ssf_site_plain_card(string $title, string $text): string
{
    return sprintf('<div class="ssf-card"><h3>%s</h3><p>%s</p></div>', esc_html($title), esc_html($text));
}

function ssf_site_membership_card(string $title, string $text, array $items, string $button, string $url): string
{
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
        return '<p class="ssf-empty">Inga nyheter publicerade annu.</p>';
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
                <a class="ssf-read-more" href="<?php the_permalink(); ?>">Las mer <span aria-hidden="true">-&gt;</span></a>
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
    ob_start();
    ssf_site_status_notice();
    ?>
    <form class="ssf-form ssf-application-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="ssf_application">
        <?php wp_nonce_field('ssf_application', 'ssf_application_nonce'); ?>
        <div class="ssf-progress"><span></span></div>
        <?php
        ssf_site_form_step('Valj ansokningsvag', array(
            ssf_site_radio_card('ansokningsvag', 'mattkrav', 'Fartyget uppfyller mattkraven', 'Over 12 meter i huvuddack och minst 4 meter brett.'),
            ssf_site_radio_card('ansokningsvag', 'sarskild', 'Fartyget uppfyller inte mattkraven', 'Fartyget behover sarskild provning.'),
        ));
        ssf_site_form_step('Ar fartyget ett segelfartyg eller segelfartyg med hjalpmotor?', array(ssf_site_yes_no('segelfartyg')));
        ssf_site_form_step('Har fartyget anvants eller anvands det som seglande yrkesfartyg?', array(ssf_site_yes_no('yrkeshistorik')));
        ssf_site_form_step('Ar fartyget nybyggt i traditionell stil?', array(ssf_site_yes_no('traditionell_nybyggnad')));
        ssf_site_form_step('Ange fartygets matt', array('<label>Langd i huvuddack, meter<input type="number" step="0.1" min="0" name="langd" required></label>', '<label>Bredd, meter<input type="number" step="0.1" min="0" name="bredd" required></label>'));
        ssf_site_form_step('Ar fartyget registrerat i svenskt skeppsregister?', array(ssf_site_yes_no('svenskt_register')));
        ssf_site_form_step('Uppgifter om fartyget', array('<label>Fartygets namn<input name="fartygsnamn" required></label>', '<label>Fartygstyp<select name="fartygstyp" required><option value="">Valj</option><option>fritidsfartyg</option><option>handelsfartyg</option></select></label>', '<label>Ar fartyget under restaurering?<select name="restaurering" required><option value="">Valj</option><option value="ja">Ja</option><option value="nej">Nej</option></select></label>', '<label>Kort beskrivning av fartyget<textarea name="fartyg_beskrivning" rows="5" required></textarea></label>', '<label>Lank till webbplats eller mer information<input type="url" name="fartyg_lank"></label>'));
        ssf_site_form_step('Uppgifter om fartygsombud', array('<label>Namn<input name="ombud_namn" required></label>', '<label>E-post<input type="email" name="ombud_epost" required></label>', '<label>Telefon<input name="ombud_telefon" required></label>', '<label>Organisation/forening/rederi<input name="organisation"></label>', '<label>Ovriga upplysningar<textarea name="ovrigt" rows="4"></textarea></label>'));
        ssf_site_form_step('Resultat och bekraftelse', array('<div class="ssf-form-result" aria-live="polite"><h3>Forhandsbedomning visas har</h3><p>Fyll i stegen sa visas en forsta bedomning innan du skickar ansokan.</p></div>', '<label class="ssf-check"><input type="checkbox" name="korrekt" value="1" required> Jag intygar att uppgifterna ar korrekta och vill skicka ansokan till SSF.</label>', '<label class="ssf-check"><input type="checkbox" name="gdpr" value="1" required> Jag godkanner att SSF behandlar mina uppgifter for att hantera ansokan.</label>', '<p class="ssf-privacy">Uppgifterna sparas i WordPress och anvands endast for att hantera ansokan. Atkomst till submissions ska begransas till administratorer.</p>'));
        ?>
        <div class="ssf-form-nav">
            <button type="button" class="ssf-button ssf-button--ghost" data-ssf-prev>Tillbaka</button>
            <button type="button" class="ssf-button" data-ssf-next>Nasta</button>
            <button type="submit" class="ssf-button" data-ssf-submit>Skicka ansokan</button>
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
        <label>Amne<input name="amne" required></label>
        <label>Meddelande<textarea name="meddelande" rows="6" required></textarea></label>
        <button class="ssf-button" type="submit">Skicka</button>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('ssf_contact_form', 'ssf_site_contact_form_shortcode');

function ssf_site_member_vessels_shortcode(): string
{
    $query = new WP_Query(array('post_type' => 'medlemsfartyg', 'posts_per_page' => 24, 'post_status' => 'publish'));
    if (! $query->have_posts()) {
        return '<p class="ssf-empty">Medlemsfartyg kommer att visas har.</p>';
    }

    ob_start();
    echo '<div class="ssf-card-grid ssf-card-grid--three">';
    while ($query->have_posts()) {
        $query->the_post();
        echo '<article class="ssf-card ssf-vessel-card">';
        if (has_post_thumbnail()) {
            echo '<a href="' . esc_url(get_permalink()) . '" class="ssf-card-image">' . get_the_post_thumbnail(get_the_ID(), 'medium_large') . '</a>';
        }
        echo '<h3><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h3>';
        echo '<p>' . esc_html(wp_trim_words(get_the_excerpt(), 26)) . '</p>';
        echo '</article>';
    }
    echo '</div>';
    wp_reset_postdata();

    return ob_get_clean();
}
add_shortcode('ssf_member_vessels', 'ssf_site_member_vessels_shortcode');

function ssf_site_status_notice(): void
{
    $status = isset($_GET['ssf_status']) ? sanitize_text_field(wp_unslash($_GET['ssf_status'])) : '';
    $messages = array(
        'application_sent' => 'Tack. Din ansokan har skickats till SSF.',
        'contact_sent' => 'Tack. Ditt meddelande har skickats.',
        'invalid' => 'Formularet kunde inte verifieras. Forsok igen.',
        'consent' => 'Du behover godkanna intygande och behandling av uppgifter for att skicka formularet.',
    );
    if (isset($messages[$status])) {
        echo '<div class="ssf-notice">' . esc_html($messages[$status]) . '</div>';
    }
}
