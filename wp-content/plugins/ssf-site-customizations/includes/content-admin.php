<?php
/**
 * Editorial content controls for the public SSF pages.
 *
 * @package SSF_Site
 */

if (! defined('ABSPATH')) {
    exit;
}

function ssf_site_content_defaults(): array
{
    return array(
        'home' => array(
            'hero_image_id' => 0,
            'hero_alt' => 'Traditionella segelfartyg på vattnet',
            'intro_title' => 'Vi samlar Sveriges seglande kulturarv',
            'intro_text' => 'SSF är förbundet för traditionella segelfartyg, fartygsombud och personer som vill bevara, bruka och utveckla Sveriges segelfartygsarv.',
            'primary_label' => 'Bli stödmedlem',
            'primary_url' => '/medlemskap/',
            'secondary_label' => 'Ansök som fartygsombud',
            'secondary_url' => '/ansokan/',
            'choices_title' => 'Vad vill du göra?',
            'choice_application_title' => 'Ansöka som fartygsombud',
            'choice_application_text' => 'Testa om ditt fartyg kan gå vidare till ansökan',
            'choice_application_url' => '/ansokan/',
            'choice_member_title' => 'Bli stödmedlem',
            'choice_member_text' => 'Stöd arbetet för Sveriges segelfartyg',
            'choice_member_url' => '/medlemskap/',
            'choice_contact_title' => 'Kontakta SSF',
            'choice_contact_text' => 'Ställ en fråga till förbundet',
            'choice_contact_url' => '/kontakta-oss/',
            'about_title' => 'Förbundet för traditionella segelfartyg',
            'about_text' => 'Sveriges Segelfartygsförbund arbetar för att stärka förutsättningarna för traditionella segelfartyg i Sverige. Vi samlar fartygsombud, stödmedlemmar och andra som vill att segelfartygen fortsatt ska brukas, underhållas och synas.',
            'value_one_title' => 'Fartyg',
            'value_one_text' => 'Traditionsfartyg som brukas, vårdas och utvecklas.',
            'value_two_title' => 'Gemenskap',
            'value_two_text' => 'Ett nätverk för fartygsombud, stödmedlemmar och engagerade.',
            'value_three_title' => 'Kunskap',
            'value_three_text' => 'Erfarenhet, stadgar och samverkan för maritimt kulturarv.',
            'membership_title' => 'Två sätt att vara med i SSF',
            'membership_text' => 'I SSF är medlemmen en person. Du kan vara stödmedlem eller fartygsombud för ett eller flera godkända fartyg.',
            'support_title' => 'Stödmedlem',
            'support_text' => 'För dig som vill stödja SSF:s arbete för att bevara och utveckla Sveriges seglande kulturarv.',
            'support_items' => "Stödjer förbundets arbete\nPassar privatpersoner och familjer\nBidrar till segelfartygens framtid",
            'support_label' => 'Bli stödmedlem',
            'support_url' => '/medlemskap/',
            'ship_title' => 'Fartygsombud',
            'ship_text' => 'För dig som företräder ett fartyg som vill anslutas till SSF. Ett fartygsombud kan företräda ett eller flera fartyg.',
            'ship_items' => "Ombudet är medlemmen\nFartyget prövas enligt stadgarna\nAvgift baseras på fartygstyp",
            'ship_label' => 'Ansök som fartygsombud',
            'ship_url' => '/ansokan/',
            'process_title' => 'Så fungerar aspirantprocessen',
            'process_text' => 'Ett fartyg som vill anslutas till SSF prövas av förbundet. Ansökan görs av fartygsombudet och leder till en första bedömning.',
            'step_one_title' => 'Testa fartyget',
            'step_one_text' => 'Kontrollera om fartyget uppfyller grundkraven eller behöver särskild prövning.',
            'step_two_title' => 'Skicka ansökan',
            'step_two_text' => 'Fartygsombudet lämnar uppgifter om sig själv och om fartyget.',
            'step_three_title' => 'Styrelsen prövar',
            'step_three_text' => 'SSF:s styrelse gör bedömning enligt stadgar och medlemskriterier.',
            'step_four_title' => 'Aspirantår',
            'step_four_text' => 'Under aspirantprocessen kan fartyget besiktigas och följas upp.',
            'news_title' => 'Nyheter från SSF',
            'news_text' => 'Här hittar du information från förbundet, nyheter, evenemang och sådant som är viktigt för våra medlemmar.',
            'contact_title' => 'Har du frågor?',
            'contact_text' => 'Hör av dig om medlemskap, fartygsombud, ansökan eller om du vill veta mer om SSF:s arbete.',
            'contact_label' => 'Kontakta oss',
            'contact_url' => '/kontakta-oss/',
            'final_title' => 'Var med och håll segelfartygen levande',
            'final_text' => 'Som stödmedlem eller fartygsombud bidrar du till att Sveriges segelfartygsarv kan leva vidare.',
            'final_primary_label' => 'Bli stödmedlem',
            'final_primary_url' => '/medlemskap/',
            'final_secondary_label' => 'Ansök som fartygsombud',
            'final_secondary_url' => '/ansokan/',
        ),
        'contact' => array(
            'image_id' => 0,
            'image_alt' => 'Segelfartyg vid kaj',
            'title' => 'Kontakta oss',
            'intro' => 'Hör av dig om medlemskap, fartygsombud, ansökan eller om du vill veta mer om SSF:s arbete.',
            'form_title' => 'Skicka ett meddelande',
        ),
        'application' => array(
            'image_id' => 0,
            'image_alt' => 'Traditionellt segelfartyg',
            'eyebrow' => 'Sveriges Segelfartygsförbund',
            'title' => 'Ansök om medlemskap',
            'intro' => 'Berätta om fartyget och ert fartygsombud. Du kan spara informationen i lugn takt och får en personlig länk för att följa ärendet när ansökan är inskickad.',
        ),
    );
}

function ssf_site_content(): array
{
    return array_replace_recursive(ssf_site_content_defaults(), (array) get_option('ssf_site_content', array()));
}

function ssf_site_page_content(string $page): array
{
    $content = ssf_site_content();
    return (array) ($content[$page] ?? array());
}

function ssf_site_content_url(string $value): string
{
    if (! $value) {
        return home_url('/');
    }
    return 0 === strpos($value, '/') ? home_url($value) : $value;
}

function ssf_site_content_image_url(int $attachment_id, string $fallback = ''): string
{
    $url = $attachment_id ? wp_get_attachment_image_url($attachment_id, 'full') : false;
    return $url ?: $fallback;
}

function ssf_site_content_lines(string $value): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value))));
}

function ssf_site_content_text(string $value): string
{
    return wpautop(esc_html($value));
}

function ssf_site_content_groups(): array
{
    return array(
        'home' => array(
            'Startbild och introduktion' => array(
                'hero_image_id' => array('label' => 'Startbild', 'type' => 'image'),
                'hero_alt' => array('label' => 'Bildbeskrivning', 'type' => 'text'),
                'intro_title' => array('label' => 'Huvudrubrik', 'type' => 'text'),
                'intro_text' => array('label' => 'Inledning', 'type' => 'textarea'),
                'primary_label' => array('label' => 'Första knappens text', 'type' => 'text'),
                'primary_url' => array('label' => 'Första knappens länk', 'type' => 'url'),
                'secondary_label' => array('label' => 'Andra knappens text', 'type' => 'text'),
                'secondary_url' => array('label' => 'Andra knappens länk', 'type' => 'url'),
            ),
            'Vägar in' => array(
                'choices_title' => array('label' => 'Rubrik', 'type' => 'text'),
                'choice_application_title' => array('label' => 'Kort 1: rubrik', 'type' => 'text'),
                'choice_application_text' => array('label' => 'Kort 1: text', 'type' => 'textarea'),
                'choice_application_url' => array('label' => 'Kort 1: länk', 'type' => 'url'),
                'choice_member_title' => array('label' => 'Kort 2: rubrik', 'type' => 'text'),
                'choice_member_text' => array('label' => 'Kort 2: text', 'type' => 'textarea'),
                'choice_member_url' => array('label' => 'Kort 2: länk', 'type' => 'url'),
                'choice_contact_title' => array('label' => 'Kort 3: rubrik', 'type' => 'text'),
                'choice_contact_text' => array('label' => 'Kort 3: text', 'type' => 'textarea'),
                'choice_contact_url' => array('label' => 'Kort 3: länk', 'type' => 'url'),
            ),
            'Om förbundet' => array(
                'about_title' => array('label' => 'Rubrik', 'type' => 'text'),
                'about_text' => array('label' => 'Text', 'type' => 'textarea'),
                'value_one_title' => array('label' => 'Värde 1: rubrik', 'type' => 'text'),
                'value_one_text' => array('label' => 'Värde 1: text', 'type' => 'textarea'),
                'value_two_title' => array('label' => 'Värde 2: rubrik', 'type' => 'text'),
                'value_two_text' => array('label' => 'Värde 2: text', 'type' => 'textarea'),
                'value_three_title' => array('label' => 'Värde 3: rubrik', 'type' => 'text'),
                'value_three_text' => array('label' => 'Värde 3: text', 'type' => 'textarea'),
            ),
            'Medlemskap' => array(
                'membership_title' => array('label' => 'Rubrik', 'type' => 'text'),
                'membership_text' => array('label' => 'Inledning', 'type' => 'textarea'),
                'support_title' => array('label' => 'Stödmedlem: rubrik', 'type' => 'text'),
                'support_text' => array('label' => 'Stödmedlem: text', 'type' => 'textarea'),
                'support_items' => array('label' => 'Stödmedlem: punkter, en per rad', 'type' => 'textarea'),
                'support_label' => array('label' => 'Stödmedlem: knapptext', 'type' => 'text'),
                'support_url' => array('label' => 'Stödmedlem: länk', 'type' => 'url'),
                'ship_title' => array('label' => 'Fartygsombud: rubrik', 'type' => 'text'),
                'ship_text' => array('label' => 'Fartygsombud: text', 'type' => 'textarea'),
                'ship_items' => array('label' => 'Fartygsombud: punkter, en per rad', 'type' => 'textarea'),
                'ship_label' => array('label' => 'Fartygsombud: knapptext', 'type' => 'text'),
                'ship_url' => array('label' => 'Fartygsombud: länk', 'type' => 'url'),
            ),
            'Aspirantprocess och nyheter' => array(
                'process_title' => array('label' => 'Process: rubrik', 'type' => 'text'),
                'process_text' => array('label' => 'Process: inledning', 'type' => 'textarea'),
                'step_one_title' => array('label' => 'Steg 1: rubrik', 'type' => 'text'),
                'step_one_text' => array('label' => 'Steg 1: text', 'type' => 'textarea'),
                'step_two_title' => array('label' => 'Steg 2: rubrik', 'type' => 'text'),
                'step_two_text' => array('label' => 'Steg 2: text', 'type' => 'textarea'),
                'step_three_title' => array('label' => 'Steg 3: rubrik', 'type' => 'text'),
                'step_three_text' => array('label' => 'Steg 3: text', 'type' => 'textarea'),
                'step_four_title' => array('label' => 'Steg 4: rubrik', 'type' => 'text'),
                'step_four_text' => array('label' => 'Steg 4: text', 'type' => 'textarea'),
                'news_title' => array('label' => 'Nyheter: rubrik', 'type' => 'text'),
                'news_text' => array('label' => 'Nyheter: text', 'type' => 'textarea'),
            ),
            'Avslutande uppmaningar' => array(
                'contact_title' => array('label' => 'Kontakt: rubrik', 'type' => 'text'),
                'contact_text' => array('label' => 'Kontakt: text', 'type' => 'textarea'),
                'contact_label' => array('label' => 'Kontakt: knapptext', 'type' => 'text'),
                'contact_url' => array('label' => 'Kontakt: länk', 'type' => 'url'),
                'final_title' => array('label' => 'Slutlig uppmaning: rubrik', 'type' => 'text'),
                'final_text' => array('label' => 'Slutlig uppmaning: text', 'type' => 'textarea'),
                'final_primary_label' => array('label' => 'Första knappens text', 'type' => 'text'),
                'final_primary_url' => array('label' => 'Första knappens länk', 'type' => 'url'),
                'final_secondary_label' => array('label' => 'Andra knappens text', 'type' => 'text'),
                'final_secondary_url' => array('label' => 'Andra knappens länk', 'type' => 'url'),
            ),
        ),
        'contact' => array(
            'Kontakt' => array(
                'image_id' => array('label' => 'Toppbild', 'type' => 'image'),
                'image_alt' => array('label' => 'Bildbeskrivning', 'type' => 'text'),
                'title' => array('label' => 'Rubrik', 'type' => 'text'),
                'intro' => array('label' => 'Inledning', 'type' => 'textarea'),
                'form_title' => array('label' => 'Formulärets rubrik', 'type' => 'text'),
            ),
        ),
        'application' => array(
            'Ansökan' => array(
                'image_id' => array('label' => 'Toppbild', 'type' => 'image'),
                'image_alt' => array('label' => 'Bildbeskrivning', 'type' => 'text'),
                'eyebrow' => array('label' => 'Överrubrik', 'type' => 'text'),
                'title' => array('label' => 'Rubrik', 'type' => 'text'),
                'intro' => array('label' => 'Inledning', 'type' => 'textarea'),
            ),
        ),
    );
}

function ssf_site_content_admin_menu(): void
{
    if (class_exists('SSF_Admin_Navigation')) {
        add_submenu_page(SSF_Admin_Navigation::CONTENT, 'Webbinnehåll', 'Webbinnehåll', 'manage_options', 'ssf-webbinnehall', 'ssf_site_content_admin_page', 20);
        return;
    }

    add_menu_page('Webbinnehåll', 'Webbinnehåll', 'manage_options', 'ssf-webbinnehall', 'ssf_site_content_admin_page', 'dashicons-admin-customizer', 24);
}
add_action('admin_menu', 'ssf_site_content_admin_menu');

function ssf_site_content_admin_assets(string $hook): void
{
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ('ssf-webbinnehall' !== $page) {
        return;
    }
    wp_enqueue_media();
    wp_enqueue_style('ssf-site-content-admin', SSF_SITE_URL . 'assets/css/ssf-content-admin.css', array(), SSF_SITE_VERSION);
    wp_enqueue_script('ssf-site-content-admin', SSF_SITE_URL . 'assets/js/ssf-content-admin.js', array('jquery'), SSF_SITE_VERSION, true);
}
add_action('admin_enqueue_scripts', 'ssf_site_content_admin_assets');

function ssf_site_content_admin_page(): void
{
    if (! current_user_can('manage_options')) {
        wp_die('Du saknar behörighet att ändra webbinnehållet.');
    }
    $groups = ssf_site_content_groups();
    $tab = sanitize_key(wp_unslash($_GET['tab'] ?? 'home'));
    if (! isset($groups[$tab])) {
        $tab = 'home';
    }
    $content = ssf_site_content();
    ?>
    <div class="wrap ssf-content-admin">
        <h1>Webbinnehåll</h1>
        <p>Ändra texter, länkar och bilder för de viktigaste publika sidorna. Formulärens fält och funktioner påverkas inte.</p>
        <nav class="nav-tab-wrapper" aria-label="Redigera sida">
            <?php foreach (array('home' => 'Startsida', 'contact' => 'Kontakt', 'application' => 'Ansökan') as $key => $label) : ?>
                <a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('page' => 'ssf-webbinnehall', 'tab' => $key), admin_url('admin.php'))); ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ('1' === ($_GET['updated'] ?? '')) : ?><div class="notice notice-success is-dismissible"><p>Innehållet har sparats.</p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ssf-content-admin-form">
            <input type="hidden" name="action" value="ssf_site_save_content"><input type="hidden" name="content_tab" value="<?php echo esc_attr($tab); ?>">
            <?php wp_nonce_field('ssf_site_save_content_' . $tab); ?>
            <?php foreach ($groups[$tab] as $heading => $fields) : ?>
                <section class="ssf-content-admin-section"><h2><?php echo esc_html($heading); ?></h2><div class="ssf-content-admin-fields">
                <?php foreach ($fields as $key => $field) : $value = $content[$tab][$key] ?? ''; ?>
                    <div class="ssf-content-admin-field ssf-content-admin-field--<?php echo esc_attr($field['type']); ?>"><label for="ssf-content-<?php echo esc_attr($key); ?>"><?php echo esc_html($field['label']); ?></label>
                    <?php if ('textarea' === $field['type']) : ?><textarea id="ssf-content-<?php echo esc_attr($key); ?>" name="ssf_site_content[<?php echo esc_attr($key); ?>]" rows="4"><?php echo esc_textarea($value); ?></textarea>
                    <?php elseif ('image' === $field['type']) : ?>
                        <div class="ssf-content-image-control"><input id="ssf-content-<?php echo esc_attr($key); ?>" type="hidden" name="ssf_site_content[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) absint($value)); ?>"><div class="ssf-content-image-preview"><?php echo $value ? wp_get_attachment_image((int) $value, 'medium') : '<span>Ingen egen bild vald</span>'; ?></div><p><button type="button" class="button" data-ssf-select-image>Välj bild</button> <button type="button" class="button-link-delete" data-ssf-remove-image>Ta bort bild</button></p></div>
                    <?php else : ?><input id="ssf-content-<?php echo esc_attr($key); ?>" type="<?php echo 'url' === $field['type'] ? 'url' : 'text'; ?>" name="ssf_site_content[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>"<?php echo 'url' === $field['type'] ? ' placeholder="/sida/ eller https://..."' : ''; ?>><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div></section>
            <?php endforeach; ?>
            <?php submit_button('Spara ' . ('home' === $tab ? 'startsidan' : ('contact' === $tab ? 'kontaktsidan' : 'ansökningssidan'))); ?>
        </form>
    </div>
    <?php
}

function ssf_site_save_content(): void
{
    $tab = sanitize_key(wp_unslash($_POST['content_tab'] ?? ''));
    $groups = ssf_site_content_groups();
    if (! current_user_can('manage_options') || ! isset($groups[$tab]) || ! check_admin_referer('ssf_site_save_content_' . $tab)) {
        wp_die('Du saknar behörighet att spara innehållet.');
    }
    $content = ssf_site_content();
    $submitted = (array) wp_unslash($_POST['ssf_site_content'] ?? array());
    foreach ($groups[$tab] as $fields) {
        foreach ($fields as $key => $field) {
            $value = $submitted[$key] ?? '';
            if ('image' === $field['type']) {
                $content[$tab][$key] = absint($value);
            } elseif ('url' === $field['type']) {
                $content[$tab][$key] = esc_url_raw($value);
            } elseif ('textarea' === $field['type']) {
                $content[$tab][$key] = sanitize_textarea_field($value);
            } else {
                $content[$tab][$key] = sanitize_text_field($value);
            }
        }
    }
    update_option('ssf_site_content', $content, false);
    wp_safe_redirect(add_query_arg(array('page' => 'ssf-webbinnehall', 'tab' => $tab, 'updated' => '1'), admin_url('admin.php')));
    exit;
}
add_action('admin_post_ssf_site_save_content', 'ssf_site_save_content');
