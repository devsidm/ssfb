<?php
/**
 * Plugin Name: SSF Email Template
 * Description: Central presentation layer for SSF transactional email.
 * Version: 1.2.0
 * Author: SIDM
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Email_Template
{
    private const OPTION = 'ssf_email_template_brand';
    private const NOTICE_PREFIX = 'ssf_email_template_notice_';
    private const ADMIN_PAGE = 'ssf-member-portal-microsoft365';

    public static function boot(): void
    {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
        add_action('admin_post_ssf_email_template_save', array(__CLASS__, 'handle_save'));
        add_action('admin_post_ssf_email_template_preview', array(__CLASS__, 'handle_preview'));
        add_action('admin_post_ssf_email_template_test', array(__CLASS__, 'handle_test'));
        add_action('admin_notices', array(__CLASS__, 'render_admin_notice'));
    }

    public static function templates(): array
    {
        return array(
            'motion_received' => array('label' => 'Motion mottagen', 'title' => 'Din motion har tagits emot', 'preheader' => 'Vi har registrerat din motion och du kan följa handläggningen online.'),
            'motion_status' => array('label' => 'Motion statusändrad', 'title' => 'Din motion har uppdaterats', 'preheader' => 'Statusen för din motion har ändrats.'),
            'application_received' => array('label' => 'Ansökan mottagen', 'title' => 'Vi har tagit emot din ansökan', 'preheader' => 'Din ansökan är registrerad och kommer att behandlas av SSF.'),
            'application_status' => array('label' => 'Ansökan statusändrad', 'title' => 'Din ansökan har uppdaterats', 'preheader' => 'Det finns en uppdatering i ditt ärende.'),
            'application_completion' => array('label' => 'Begär komplettering', 'title' => 'Vi behöver en komplettering till din ansökan', 'preheader' => 'Vi behöver ytterligare information för att behandla din ansökan.'),
            'annual_meeting_registration' => array('label' => 'Årsmötesanmälan', 'title' => 'Din anmälan är bekräftad', 'preheader' => 'Din anmälan till aktiviteter under SSF:s årsmöteshelg är registrerad.'),
            'annual_meeting_registration_updated' => array('label' => 'Ändrad årsmötesanmälan', 'title' => 'Din anmälan har uppdaterats', 'preheader' => 'Dina aktuella val för årsmöteshelgen finns i detta meddelande.'),
            'contact_confirmation' => array('label' => 'Kontaktbekräftelse', 'title' => 'Vi har tagit emot ditt meddelande', 'preheader' => 'Tack för att du kontaktat Sveriges Segelfartygsförbund.'),
            'vessel_update_invitation' => array('label' => 'Begäran om fartygsuppgifter', 'title' => 'Uppdatera uppgifter om ditt fartyg', 'preheader' => 'SSF behöver aktuella uppgifter om ditt fartyg.'),
            'vessel_update_received' => array('label' => 'Fartygsuppgifter mottagna', 'title' => 'Vi har tagit emot dina fartygsuppgifter', 'preheader' => 'Uppgifterna granskas före publicering.'),
            'inspector_assignment' => array('label' => 'Inspektörsuppdrag', 'title' => 'Du har fått ett nytt inspektörsuppdrag', 'preheader' => 'Ett nytt inspektionsärende har tilldelats dig.'),
        );
    }

    public static function brand(): array
    {
        $saved = (array) get_option(self::OPTION, array());
        $organization = SSF_Organization_Info::get();
        $logo_id = absint($saved['logo_id'] ?? 0);
        $logo_url = $logo_id ? (string) wp_get_attachment_image_url($logo_id, 'medium') : '';
        if (! $logo_url) {
            $logo_url = get_template_directory_uri() . '/assets/images/ssf-logo.svg';
        }

        return array(
            'name' => $organization['organization_name'],
            'website_url' => $organization['website_url'],
            'website_label' => $organization['website_label'],
            'address_lines' => SSF_Organization_Info::address_lines(),
            'organization_number' => $organization['organization_number'],
            'bankgiro' => $organization['bankgiro'],
            'swish' => $organization['swish'],
            'logo_id' => $logo_id,
            'logo_url' => set_url_scheme($logo_url, 'https'),
            'primary_color' => '#12324a',
            'accent_color' => '#16716a',
            'text_color' => '#182c3d',
            'muted_color' => '#526576',
            'background_color' => '#eef2f4',
            'content_width' => 620,
        );
    }

    public static function render(string $template, array $data = array()): string
    {
        $definitions = self::templates();
        $definition = $definitions[$template] ?? reset($definitions);
        $brand = self::brand();
        $title = self::text($data['title'] ?? '') ?: self::text($definition['title']);
        $preheader = self::text($data['preheader'] ?? '') ?: self::text($definition['preheader']);
        $greeting = self::greeting((string) ($data['recipient_name'] ?? ''));
        $paragraphs = self::paragraphs($data['body'] ?? array());
        $sections = self::sections($data['sections'] ?? array());
        $notice_title = self::text($data['notice_title'] ?? '');
        $notice = self::text($data['notice'] ?? '', true);
        $button_label = self::text($data['button_label'] ?? '');
        $button_url = esc_url((string) ($data['button_url'] ?? ''));
        $secondary_links = self::links($data['secondary_links'] ?? array());

        ob_start();
        ?>
<!doctype html>
<html lang="sv">
<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title><?php echo esc_html($title); ?></title></head>
<body style="margin:0;padding:0;background-color:<?php echo esc_attr($brand['background_color']); ?>;color:<?php echo esc_attr($brand['text_color']); ?>;font-family:Arial,Helvetica,sans-serif;">
<div style="display:none!important;max-height:0;max-width:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;"><?php echo esc_html($preheader); ?>&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background-color:<?php echo esc_attr($brand['background_color']); ?>;"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="<?php echo esc_attr((string) $brand['content_width']); ?>" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:<?php echo esc_attr((string) $brand['content_width']); ?>px;background-color:#ffffff;">
<tr><td style="padding:24px 32px;background-color:<?php echo esc_attr($brand['primary_color']); ?>;color:#ffffff;">
<?php if ($brand['logo_url']) : ?><img src="<?php echo esc_url($brand['logo_url']); ?>" width="145" alt="<?php echo esc_attr($brand['name']); ?>" style="display:block;width:145px;max-width:100%;height:auto;margin:0 0 12px;border:0;"><?php endif; ?>
<p style="margin:0;color:#ffffff;font-size:14px;font-weight:bold;line-height:20px;"><?php echo esc_html($brand['name']); ?></p>
</td></tr>
<tr><td style="padding:32px 32px 8px;"><h1 style="margin:0;color:<?php echo esc_attr($brand['primary_color']); ?>;font-size:27px;font-weight:bold;line-height:34px;"><?php echo esc_html($title); ?></h1>
<p style="margin:22px 0 0;color:#243b4d;font-size:16px;line-height:25px;"><?php echo esc_html($greeting); ?></p>
<?php foreach ($paragraphs as $paragraph) : ?><p style="margin:14px 0 0;color:#243b4d;font-size:16px;line-height:25px;"><?php echo nl2br(esc_html($paragraph)); ?></p><?php endforeach; ?>
</td></tr>
<?php foreach ($sections as $section) : ?><tr><td style="padding:26px 32px 0;"><?php if ($section['title']) : ?><h2 style="margin:0 0 12px;color:<?php echo esc_attr($brand['primary_color']); ?>;font-size:18px;font-weight:bold;line-height:24px;"><?php echo esc_html($section['title']); ?></h2><?php endif; ?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border:1px solid #d8e1e7;">
<?php foreach ($section['rows'] as $row) : ?><tr><td valign="top" width="36%" style="width:36%;padding:12px 14px;border-bottom:1px solid #d8e1e7;color:<?php echo esc_attr($brand['muted_color']); ?>;font-size:14px;font-weight:bold;line-height:20px;"><?php echo esc_html($row['label']); ?></td><td valign="top" style="padding:12px 14px;border-bottom:1px solid #d8e1e7;color:<?php echo esc_attr($brand['text_color']); ?>;font-size:16px;line-height:22px;overflow-wrap:anywhere;"><?php echo nl2br(esc_html($row['value'])); ?></td></tr><?php endforeach; ?>
</table></td></tr><?php endforeach; ?>
<?php if ($notice) : ?><tr><td style="padding:26px 32px 0;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background-color:#f2f7f6;border-left:4px solid <?php echo esc_attr($brand['accent_color']); ?>;"><tr><td style="padding:16px 18px;color:#243b4d;font-size:16px;line-height:24px;"><?php if ($notice_title) : ?><strong style="display:block;margin-bottom:6px;color:<?php echo esc_attr($brand['primary_color']); ?>;"><?php echo esc_html($notice_title); ?></strong><?php endif; ?><?php echo nl2br(esc_html($notice)); ?></td></tr></table></td></tr><?php endif; ?>
<?php if ($button_label && $button_url) : ?><tr><td align="center" style="padding:28px 32px 0;"><table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" bgcolor="<?php echo esc_attr($brand['accent_color']); ?>" style="background-color:<?php echo esc_attr($brand['accent_color']); ?>;"><a href="<?php echo esc_url($button_url); ?>" style="display:inline-block;padding:14px 24px;color:#ffffff;font-size:16px;font-weight:bold;line-height:20px;text-align:center;text-decoration:none;"><?php echo esc_html($button_label); ?></a></td></tr></table><p style="margin:12px 0 0;color:<?php echo esc_attr($brand['muted_color']); ?>;font-size:12px;line-height:18px;overflow-wrap:anywhere;">Om knappen inte fungerar: <a href="<?php echo esc_url($button_url); ?>" style="color:<?php echo esc_attr($brand['primary_color']); ?>;text-decoration:underline;"><?php echo esc_html($button_url); ?></a></p></td></tr><?php endif; ?>
<?php if ($secondary_links) : ?><tr><td style="padding:22px 32px 0;text-align:center;"><?php foreach ($secondary_links as $index => $link) : ?><?php if ($index) : ?><span style="color:#9aa8b3;"> &nbsp;|&nbsp; </span><?php endif; ?><a href="<?php echo esc_url($link['url']); ?>" style="color:<?php echo esc_attr($brand['primary_color']); ?>;font-size:14px;line-height:22px;text-decoration:underline;"><?php echo esc_html($link['label']); ?></a><?php endforeach; ?></td></tr><?php endif; ?>
<tr><td style="padding:30px 32px 8px;color:#243b4d;font-size:16px;line-height:24px;">Vänliga hälsningar<br><strong><?php echo esc_html($brand['name']); ?></strong></td></tr>
<tr><td style="padding:22px 32px 30px;border-top:1px solid #d8e1e7;color:<?php echo esc_attr($brand['muted_color']); ?>;font-size:13px;line-height:20px;"><strong style="color:<?php echo esc_attr($brand['primary_color']); ?>;"><?php echo esc_html($brand['name']); ?></strong><br><a href="<?php echo esc_url($brand['website_url']); ?>" style="color:<?php echo esc_attr($brand['primary_color']); ?>;text-decoration:underline;"><?php echo esc_html($brand['website_label']); ?></a><br><br>Postadress:<br><?php foreach ($brand['address_lines'] as $line) : ?><?php echo esc_html($line); ?><br><?php endforeach; ?></td></tr>
</table></td></tr></table></body></html>
        <?php
        return (string) ob_get_clean();
    }

    public static function render_text(string $template, array $data = array()): string
    {
        $definitions = self::templates();
        $definition = $definitions[$template] ?? reset($definitions);
        $brand = self::brand();
        $title = self::text($data['title'] ?? '') ?: self::text($definition['title']);
        $lines = array($brand['name'], '', $title, '', self::greeting((string) ($data['recipient_name'] ?? '')));
        foreach (self::paragraphs($data['body'] ?? array()) as $paragraph) {
            $lines[] = '';
            $lines[] = $paragraph;
        }
        foreach (self::sections($data['sections'] ?? array()) as $section) {
            $lines[] = '';
            if ($section['title']) {
                $lines[] = strtoupper($section['title']);
            }
            foreach ($section['rows'] as $row) {
                $lines[] = $row['label'] . ': ' . $row['value'];
            }
        }
        $notice = self::text($data['notice'] ?? '', true);
        if ($notice) {
            $lines[] = '';
            if (! empty($data['notice_title'])) {
                $lines[] = self::text($data['notice_title']);
            }
            $lines[] = $notice;
        }
        $button_url = esc_url_raw((string) ($data['button_url'] ?? ''));
        if ($button_url) {
            $lines[] = '';
            $lines[] = self::text($data['button_label'] ?? 'Öppna länken') . ':';
            $lines[] = $button_url;
        }
        foreach (self::links($data['secondary_links'] ?? array()) as $link) {
            $lines[] = $link['label'] . ': ' . $link['url'];
        }
        return implode("\n", array_merge($lines, array('', 'Vänliga hälsningar', $brand['name'], '', $brand['website_url'], '', 'Postadress:'), $brand['address_lines']));
    }

    public static function send(string $recipient, string $subject, string $template, array $data = array(), $headers = array(), array $attachments = array()): bool
    {
        $recipient = sanitize_email($recipient);
        if (! is_email($recipient) || ! isset(self::templates()[$template])) {
            return false;
        }
        $subject = self::prepare_subject(self::text($subject));
        $html = self::render($template, $data);
        $text = self::render_text($template, $data);
        $headers = is_array($headers) ? $headers : preg_split('/\r?\n/', (string) $headers);
        $headers = array_values(array_filter($headers, static function ($header): bool {
            return 0 !== stripos(trim((string) $header), 'Content-Type:');
        }));
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $alternative = static function ($phpmailer) use ($text): void {
            $phpmailer->isHTML(true);
            $phpmailer->CharSet = 'UTF-8';
            $phpmailer->AltBody = $text;
        };
        $from_name = static function (string $current_name): string {
            return self::brand()['name'];
        };
        add_action('phpmailer_init', $alternative);
        add_filter('wp_mail_from_name', $from_name);
        try {
            $sent = wp_mail($recipient, $subject, $html, $headers, $attachments);
        } finally {
            remove_action('phpmailer_init', $alternative);
            remove_filter('wp_mail_from_name', $from_name);
        }
        if (class_exists('SSF\\MemberPortal\\Core\\Logger')) {
            \SSF\MemberPortal\Core\Logger::add($sent ? 'external_email_sent' : 'external_email_failed', array('template' => $template));
        }
        return (bool) $sent;
    }

    public static function render_admin_section(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $brand = self::brand();
        $organization = SSF_Organization_Info::get();
        $preview_base = admin_url('admin-post.php?action=ssf_email_template_preview');
        ?>
        <div id="ssf-email-design" class="postbox" style="max-width:1180px;padding:20px">
            <h2>Organisationsuppgifter och e-postdesign</h2>
            <p>Gemensam organisationsinformation för webbplatsens sidfot, medlemssidor och externa användarmejl.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_email_template_save"><?php wp_nonce_field('ssf_email_template_save'); ?>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th><label for="ssf-email-brand-name">Avsändaridentitet</label></th><td><input id="ssf-email-brand-name" class="regular-text" name="brand[name]" value="<?php echo esc_attr($brand['name']); ?>" required></td></tr>
                    <tr><th>E-postlogotyp</th><td><img data-ssf-email-logo-preview src="<?php echo esc_url($brand['logo_url']); ?>" alt="Förhandsvisning av e-postlogotyp" style="display:block;max-width:180px;max-height:90px;margin-bottom:10px;background:#12324a;padding:10px;"><input data-ssf-email-logo-id type="hidden" name="brand[logo_id]" value="<?php echo esc_attr((string) $brand['logo_id']); ?>"><button data-ssf-email-logo-select type="button" class="button">Byt logotyp</button> <button data-ssf-email-logo-clear type="button" class="button">Använd standardlogotyp</button></td></tr>
                    <tr><th><label for="ssf-email-website-url">Webbplats</label></th><td><input id="ssf-email-website-url" class="regular-text code" type="url" name="brand[website_url]" value="<?php echo esc_attr($brand['website_url']); ?>" required> <input class="regular-text" name="brand[website_label]" value="<?php echo esc_attr($brand['website_label']); ?>" aria-label="Webbplatsens länktext" required></td></tr>
                    <tr><th>Postadress</th><td><input class="regular-text" name="brand[address_line_1]" value="<?php echo esc_attr($organization['address_line_1']); ?>" required><br><input class="regular-text" name="brand[address_line_2]" value="<?php echo esc_attr($organization['address_line_2']); ?>" required><br><input class="small-text" name="brand[postal_code]" value="<?php echo esc_attr($organization['postal_code']); ?>" inputmode="numeric" required> <input class="regular-text" name="brand[city]" value="<?php echo esc_attr($organization['city']); ?>" required></td></tr>
                    <tr><th><label for="ssf-organization-number">Organisationsnummer</label></th><td><input id="ssf-organization-number" class="regular-text" name="brand[organization_number]" value="<?php echo esc_attr($brand['organization_number']); ?>" inputmode="numeric" required></td></tr>
                    <tr><th>Betalning</th><td><label>Bankgiro <input class="regular-text" name="brand[bankgiro]" value="<?php echo esc_attr($brand['bankgiro']); ?>" required></label><br><label>Swishnummer <input class="regular-text" name="brand[swish]" value="<?php echo esc_attr($brand['swish']); ?>" inputmode="numeric" required></label></td></tr>
                </tbody></table>
                <?php submit_button('Spara organisationsuppgifter och e-postdesign'); ?>
            </form>
            <h3>Mallar</h3>
            <table class="widefat striped"><thead><tr><th>Malltyp</th><th>Standardrubrik</th><th>Förhandsvisning</th></tr></thead><tbody>
            <?php foreach (self::templates() as $key => $template) : ?><tr><th><?php echo esc_html($template['label']); ?></th><td><?php echo esc_html($template['title']); ?></td><td><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url(wp_nonce_url(add_query_arg('template', $key, $preview_base), 'ssf_email_template_preview_' . $key)); ?>">Förhandsvisa</a></td></tr><?php endforeach; ?>
            </tbody></table>
            <h3>Skicka testmejl</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_email_template_test"><?php wp_nonce_field('ssf_email_template_test'); ?>
                <label for="ssf-email-test-template"><strong>Mall</strong></label> <select id="ssf-email-test-template" name="template"><?php foreach (self::templates() as $key => $template) : ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($template['label']); ?></option><?php endforeach; ?></select>
                <label for="ssf-email-test-recipient"><strong>Mottagare</strong></label> <input id="ssf-email-test-recipient" type="email" name="recipient" value="<?php echo esc_attr((string) wp_get_current_user()->user_email); ?>" required>
                <?php submit_button('Skicka testmejl', 'secondary', 'submit', false); ?>
            </form>
            <p class="description">Förhandsvisning och test använder exakt samma renderer som de riktiga utskicken. DEV får automatiskt prefixet [DEV].</p>
        </div>
        <?php
    }

    public static function enqueue_admin_assets(string $hook): void
    {
        if (self::ADMIN_PAGE !== sanitize_key((string) ($_GET['page'] ?? ''))) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script('ssf-email-template-admin', content_url('/mu-plugins/assets/ssf-email-template-admin.js'), array('media-editor'), '1.0.0', true);
        wp_localize_script('ssf-email-template-admin', 'ssfEmailTemplateAdmin', array('defaultLogo' => set_url_scheme(get_template_directory_uri() . '/assets/images/ssf-logo.svg', 'https')));
    }

    public static function handle_save(): void
    {
        self::guard('ssf_email_template_save');
        $input = isset($_POST['brand']) && is_array($_POST['brand']) ? wp_unslash($_POST['brand']) : array();
        SSF_Organization_Info::save(array(
            'organization_name' => $input['name'] ?? '',
            'website_url' => $input['website_url'] ?? '',
            'website_label' => $input['website_label'] ?? '',
            'address_line_1' => $input['address_line_1'] ?? '',
            'address_line_2' => $input['address_line_2'] ?? '',
            'postal_code' => $input['postal_code'] ?? '',
            'city' => $input['city'] ?? '',
            'organization_number' => $input['organization_number'] ?? '',
            'bankgiro' => $input['bankgiro'] ?? '',
            'swish' => $input['swish'] ?? '',
        ));
        update_option(self::OPTION, array('logo_id' => absint($input['logo_id'] ?? 0)), false);
        self::notice('success', 'Organisationsuppgifterna och e-postdesignen har sparats.');
        self::redirect();
    }

    public static function handle_preview(): void
    {
        $template = sanitize_key((string) ($_GET['template'] ?? ''));
        if (! current_user_can('manage_options') || ! isset(self::templates()[$template]) || ! check_admin_referer('ssf_email_template_preview_' . $template)) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-email-template'));
        }
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        echo self::render($template, self::sample_data($template)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public static function handle_test(): void
    {
        self::guard('ssf_email_template_test');
        $template = sanitize_key((string) ($_POST['template'] ?? ''));
        $recipient = sanitize_email((string) ($_POST['recipient'] ?? ''));
        if (! isset(self::templates()[$template]) || ! is_email($recipient)) {
            self::notice('error', 'Välj en giltig mall och mottagare.');
            self::redirect();
        }
        $label = self::templates()[$template]['label'];
        $sent = self::send($recipient, 'SSF test – ' . $label, $template, self::sample_data($template));
        self::notice($sent ? 'success' : 'error', $sent ? 'Testmejlet accepterades av den aktiva e-posttransporten.' : 'Testmejlet kunde inte skickas. Kontrollera Microsoft 365-anslutningen.');
        self::redirect();
    }

    public static function render_admin_notice(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $notice = get_transient(self::NOTICE_PREFIX . get_current_user_id());
        if (! is_array($notice)) {
            return;
        }
        delete_transient(self::NOTICE_PREFIX . get_current_user_id());
        printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr((string) $notice['type']), esc_html((string) $notice['message']));
    }

    private static function sample_data(string $template): array
    {
        $organization = SSF_Organization_Info::get();
        $fees = SSF_Organization_Info::membership_fees();
        $data = array(
            'recipient_name' => 'Anna Andersson',
            'body' => array('Det här är en förhandsvisning av ett automatiskt meddelande från Sveriges Segelfartygsförbund.'),
            'sections' => array(array('title' => 'Ärende', 'rows' => array('Fartyg' => 'Exempelskutan med ett ovanligt långt och tydligt fartygsnamn', 'Referens' => 'SSF-ANS-2026-004', 'Status' => 'Under granskning'))),
            'notice_title' => 'Meddelande från SSF',
            'notice' => 'Styrelsen har granskat underlaget. Detta exempel visar hur en längre statuskommentar bryts över flera rader utan att påverka layouten.',
            'button_label' => 'Följ ditt ärende',
            'button_url' => home_url('/ansokan/status/?token=test-token-som-inte-ar-giltig'),
        );
        if ('annual_meeting_registration' === $template || 'annual_meeting_registration_updated' === $template) {
            $data['sections'] = array(array('title' => 'Årsmöteshelg', 'rows' => array('Datum' => '18–20 oktober 2026', 'Plats' => 'Marstrand')), array('title' => 'Dina val', 'rows' => array('Middag' => 'Ja', 'Aktivitet' => 'Guidad stadsvandring')));
            $data['button_label'] = 'Visa eller ändra min anmälan';
        }
        if ('application_received' === $template) {
            $data['body'] = array('Tack för din ansökan om medlemskap för Exempelskutan. För att vi ska börja behandla ansökan behöver medlemsavgiften betalas in.');
            $data['sections'] = array(
                array('title' => 'Ansökan', 'rows' => array('Fartyg' => 'Exempelskutan', 'Ansökningsnummer' => 'SSF-2026-0004', 'Status' => 'Inkommen')),
                array('title' => 'Betalning', 'rows' => array('Fartygskategori' => $fees['leisure']['label'], 'Årsavgift' => $fees['leisure']['amount'], 'Bankgiro' => $organization['bankgiro'], 'Swish' => $organization['swish'])),
            );
            $data['notice_title'] = 'Viktigt om betalningen';
            $data['notice'] = 'Betala in årsavgiften och ange ansökningsnummer SSF-2026-0004 som meddelande i betalningen.';
            $data['button_label'] = 'Följ din ansökan';
        }
        if ('contact_confirmation' === $template) {
            $data['sections'] = array(array('title' => 'Ditt meddelande', 'rows' => array('Ämne' => 'Fråga om medlemskap')));
            $data['notice'] = '';
            $data['button_label'] = '';
            $data['button_url'] = '';
        }
        return $data;
    }

    private static function sections($sections): array
    {
        $clean = array();
        foreach ((array) $sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $rows = array();
            foreach ((array) ($section['rows'] ?? array()) as $key => $value) {
                if (is_array($value)) {
                    $label = self::text($value['label'] ?? '');
                    $row_value = self::text($value['value'] ?? '', true);
                } else {
                    $label = self::text($key);
                    $row_value = self::text($value, true);
                }
                if ($label && $row_value) {
                    $rows[] = array('label' => $label, 'value' => $row_value);
                }
            }
            if ($rows) {
                $clean[] = array('title' => self::text($section['title'] ?? ''), 'rows' => $rows);
            }
        }
        return $clean;
    }

    private static function paragraphs($body): array
    {
        $body = is_array($body) ? $body : array($body);
        return array_values(array_filter(array_map(static function ($paragraph): string {
            return self::text($paragraph, true);
        }, $body)));
    }

    private static function links($links): array
    {
        $clean = array();
        foreach ((array) $links as $link) {
            if (! is_array($link)) {
                continue;
            }
            $label = self::text($link['label'] ?? '');
            $url = esc_url_raw((string) ($link['url'] ?? ''));
            if ($label && $url) {
                $clean[] = array('label' => $label, 'url' => $url);
            }
        }
        return $clean;
    }

    private static function greeting(string $name): string
    {
        $name = self::text($name);
        if (! $name) {
            return 'Hej,';
        }
        $parts = preg_split('/\s+/u', $name);
        return 'Hej ' . ($parts[0] ?? $name) . ',';
    }

    private static function text($value, bool $multiline = false): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        return trim($multiline ? sanitize_textarea_field((string) $value) : sanitize_text_field((string) $value));
    }

    private static function prepare_subject(string $subject): string
    {
        if (class_exists('SSF_Email_Router')) {
            return SSF_Email_Router::prepare_subject($subject);
        }
        return 'production' === wp_get_environment_type() || 0 === strpos($subject, '[DEV] ') ? $subject : '[DEV] ' . $subject;
    }

    private static function guard(string $nonce): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer($nonce)) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-email-template'));
        }
    }

    private static function notice(string $type, string $message): void
    {
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), array('type' => $type, 'message' => $message), MINUTE_IN_SECONDS);
    }

    private static function redirect(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::ADMIN_PAGE . '#ssf-email-design'));
        exit;
    }
}

SSF_Email_Template::boot();
