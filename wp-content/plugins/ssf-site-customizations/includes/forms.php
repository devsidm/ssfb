<?php
/**
 * Form handling for SSF.
 *
 * @package SSF_Site
 */

if (! defined('ABSPATH')) {
    exit;
}

function ssf_site_clean_field(string $key): string
{
    return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
}

function ssf_site_clean_textarea(string $key): string
{
    return isset($_POST[$key]) ? sanitize_textarea_field(wp_unslash($_POST[$key])) : '';
}

function ssf_site_application_result(array $data): array
{
    $is_sail = 'ja' === $data['segelfartyg'];
    $has_history = 'ja' === $data['yrkeshistorik'];
    $traditional_newbuild = 'ja' === $data['traditionell_nybyggnad'];
    $length = (float) str_replace(',', '.', $data['langd']);
    $width = (float) str_replace(',', '.', $data['bredd']);
    $registered = 'ja' === $data['svenskt_register'];

    if (! $is_sail) {
        return array(
            'title' => 'Fartyget uppfyller inte grundkraven',
            'text'  => 'Fartyget behöver vara ett segelfartyg eller segelfartyg med hjälpmotor.',
        );
    }

    if (! $has_history && ! $traditional_newbuild) {
        return array(
            'title' => 'Fartyget behöver kompletterande bedömning',
            'text'  => 'Fartyget saknar angiven yrkeshistorik och är inte markerat som nybyggt i traditionell stil.',
        );
    }

    if ($length > 12 && $width >= 4) {
        return array(
            'title' => 'Fartyget kan gå vidare till ansökan som aspirant',
            'text'  => 'Utifrån svaren uppfyller fartyget måttkraven. Ansökan kan skickas in för styrelsens prövning.',
        );
    }

    if ($registered) {
        return array(
            'title' => 'Särskild prövning kan vara möjlig',
            'text'  => 'Fartyget uppfyller inte måttkraven, men är registrerat i svenskt skeppsregister. Ansökan kan skickas in för särskild prövning.',
        );
    }

    if ($length > 0 || $width > 0) {
        return array(
            'title' => 'Fartyget uppfyller inte kraven för särskild prövning',
            'text'  => 'Fartyg som understiger måttkraven behöver vara registrerade i svenskt skeppsregister för att kunna prövas särskilt.',
        );
    }

    return array(
        'title' => 'Ansökan behöver granskas av styrelsen',
        'text'  => 'Svaren ger inte ett entydigt resultat. Skicka gärna in uppgifterna så kan styrelsen göra en bedömning enligt SSF:s stadgar.',
    );
}

function ssf_site_handle_application(): void
{
    if (! ssf_site_applications_enabled()) {
        wp_safe_redirect(add_query_arg('ssf_status', 'application_closed', home_url('/ansokan/')));
        exit;
    }
    if (! isset($_POST['ssf_application_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_application_nonce'])), 'ssf_application')) {
        wp_safe_redirect(add_query_arg('ssf_status', 'invalid', wp_get_referer() ?: home_url('/ansokan/')));
        exit;
    }

    $data = array(
        'ansokningsvag' => ssf_site_clean_field('ansokningsvag'),
        'segelfartyg' => ssf_site_clean_field('segelfartyg'),
        'yrkeshistorik' => ssf_site_clean_field('yrkeshistorik'),
        'traditionell_nybyggnad' => ssf_site_clean_field('traditionell_nybyggnad'),
        'langd' => ssf_site_clean_field('langd'),
        'bredd' => ssf_site_clean_field('bredd'),
        'svenskt_register' => ssf_site_clean_field('svenskt_register'),
        'fartygsnamn' => ssf_site_clean_field('fartygsnamn'),
        'fartygstyp' => ssf_site_clean_field('fartygstyp'),
        'restaurering' => ssf_site_clean_field('restaurering'),
        'fartyg_beskrivning' => ssf_site_clean_textarea('fartyg_beskrivning'),
        'fartyg_lank' => esc_url_raw(ssf_site_clean_field('fartyg_lank')),
        'ombud_namn' => ssf_site_clean_field('ombud_namn'),
        'ombud_epost' => sanitize_email(ssf_site_clean_field('ombud_epost')),
        'ombud_telefon' => ssf_site_clean_field('ombud_telefon'),
        'organisation' => ssf_site_clean_field('organisation'),
        'ovrigt' => ssf_site_clean_textarea('ovrigt'),
        'korrekt' => ssf_site_clean_field('korrekt'),
        'gdpr' => ssf_site_clean_field('gdpr'),
    );

    if ('1' !== $data['korrekt'] || '1' !== $data['gdpr']) {
        wp_safe_redirect(add_query_arg('ssf_status', 'consent', wp_get_referer() ?: home_url('/ansokan/')));
        exit;
    }

    $result = ssf_site_application_result($data);
    $title = sprintf('Ansökan: %s', $data['fartygsnamn'] ?: current_time('Y-m-d H:i'));
    $content = ssf_site_format_application_body($data, $result);

    $submission_id = wp_insert_post(
        array(
            'post_type' => 'ssf_ansokan',
            'post_status' => 'private',
            'post_title' => $title,
            'post_content' => $content,
        )
    );

    if ($submission_id && ! is_wp_error($submission_id)) {
        foreach ($data as $key => $value) {
            update_post_meta($submission_id, $key, $value);
        }
        update_post_meta($submission_id, 'resultat', $result['title']);
    }

    $headers = array('Content-Type: text/plain; charset=UTF-8');
    if (is_email($data['ombud_epost'])) {
        $headers[] = 'Reply-To: ' . $data['ombud_namn'] . ' <' . $data['ombud_epost'] . '>';
    }

    wp_mail('medlemskap@ssfb.se', 'Ny ansökan som fartygsombud - ' . ($data['fartygsnamn'] ?: 'utan fartygsnamn'), $content, $headers);

    if (is_email($data['ombud_epost'])) {
        wp_mail(
            $data['ombud_epost'],
            'Tack för din ansökan till SSF',
            "Tack för din ansökan som fartygsombud. SSF återkommer när ansökan har granskats.\n\n" . $result['title'] . "\n" . $result['text'],
            array('Content-Type: text/plain; charset=UTF-8')
        );
    }

    wp_safe_redirect(add_query_arg('ssf_status', 'application_sent', wp_get_referer() ?: home_url('/ansokan/')));
    exit;
}
add_action('admin_post_nopriv_ssf_application', 'ssf_site_handle_application');
add_action('admin_post_ssf_application', 'ssf_site_handle_application');

function ssf_site_format_application_body(array $data, array $result): string
{
    $lines = array(
        'Resultat/förhandsbedömning',
        $result['title'],
        $result['text'],
        '',
        'Uppgifter om fartyg',
        'Ansökningsväg: ' . $data['ansokningsvag'],
        'Fartygsnamn: ' . $data['fartygsnamn'],
        'Fartygstyp: ' . $data['fartygstyp'],
        'Segelfartyg: ' . $data['segelfartyg'],
        'Yrkeshistorik: ' . $data['yrkeshistorik'],
        'Traditionell nybyggnad: ' . $data['traditionell_nybyggnad'],
        'Längd: ' . $data['langd'],
        'Bredd: ' . $data['bredd'],
        'Svenskt skeppsregister: ' . $data['svenskt_register'],
        'Under restaurering: ' . $data['restaurering'],
        'Länk: ' . $data['fartyg_lank'],
        'Beskrivning: ' . $data['fartyg_beskrivning'],
        '',
        'Uppgifter om fartygsombud',
        'Namn: ' . $data['ombud_namn'],
        'E-post: ' . $data['ombud_epost'],
        'Telefon: ' . $data['ombud_telefon'],
        'Organisation: ' . $data['organisation'],
        'Övriga upplysningar: ' . $data['ovrigt'],
    );

    return implode("\n", $lines);
}

function ssf_site_handle_contact(): void
{
    if (! isset($_POST['ssf_contact_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_contact_nonce'])), 'ssf_contact')) {
        wp_safe_redirect(add_query_arg('ssf_status', 'invalid', wp_get_referer() ?: home_url('/kontakta-oss/')));
        exit;
    }

    $name = ssf_site_clean_field('namn');
    $email = sanitize_email(ssf_site_clean_field('epost'));
    $phone = ssf_site_clean_field('telefon');
    $subject = ssf_site_clean_field('amne') ?: 'Kontakt från ssfb.se';
    $message = ssf_site_clean_textarea('meddelande');
    $body = "Namn: $name\nE-post: $email\nTelefon: $phone\nÄmne: $subject\n\nMeddelande:\n$message";

    $post_id = wp_insert_post(
        array(
            'post_type' => 'ssf_kontakt',
            'post_status' => 'private',
            'post_title' => 'Kontakt: ' . ($name ?: current_time('Y-m-d H:i')),
            'post_content' => $body,
        )
    );

    if ($post_id && ! is_wp_error($post_id)) {
        update_post_meta($post_id, 'namn', $name);
        update_post_meta($post_id, 'epost', $email);
        update_post_meta($post_id, 'telefon', $phone);
        update_post_meta($post_id, 'amne', $subject);
    }

    $headers = array('Content-Type: text/plain; charset=UTF-8');
    if (is_email($email)) {
        $headers[] = 'Reply-To: ' . $name . ' <' . $email . '>';
    }

    wp_mail('info@ssfb.se', 'Kontaktformulär: ' . $subject, $body, $headers);

    wp_safe_redirect(add_query_arg('ssf_status', 'contact_sent', wp_get_referer() ?: home_url('/kontakta-oss/')));
    exit;
}
add_action('admin_post_nopriv_ssf_contact', 'ssf_site_handle_contact');
add_action('admin_post_ssf_contact', 'ssf_site_handle_contact');
