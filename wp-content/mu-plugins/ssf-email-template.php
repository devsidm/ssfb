<?php
/**
 * Plugin Name: SSF Email Template
 * Description: Central presentation layer for SSF transactional email.
 * Version: 1.3.0
 * Author: SIDM
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Email_Template
{
    private const OPTION = 'ssf_email_template_brand';
    private const CATEGORY_OPTION = 'ssf_email_footer_contacts';
    private const UNKNOWN_TYPES_OPTION = 'ssf_email_unknown_template_types';
    private const LOGO_WARNING_OPTION = 'ssf_email_header_logo_warning';
    private const NOTICE_PREFIX = 'ssf_email_template_notice_';
    private const ADMIN_PAGE = 'ssf-member-portal-microsoft365';
    private const GENERAL_CATEGORY = 'general';

    private static array $reported_unknown_types = array();

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
            'motion_received' => array('label' => 'Motion mottagen', 'title' => 'Din motion har tagits emot', 'preheader' => 'Vi har registrerat din motion och du kan följa handläggningen online.', 'category' => 'annual_meeting'),
            'motion_status' => array('label' => 'Motion statusändrad', 'title' => 'Din motion har uppdaterats', 'preheader' => 'Statusen för din motion har ändrats.', 'category' => 'annual_meeting'),
            'application_received' => array('label' => 'Ansökan mottagen', 'title' => 'Vi har tagit emot din ansökan', 'preheader' => 'Din ansökan är registrerad och kommer att behandlas av SSF.', 'category' => 'membership'),
            'application_admin_notice' => array('label' => 'Ny medlemsansökan - admin', 'title' => 'Ny medlemsansökan har inkommit', 'preheader' => 'En ny medlemsansökan väntar på handläggning i portalen.', 'category' => 'membership'),
            'application_status' => array('label' => 'Ansökan statusändrad', 'title' => 'Din ansökan har uppdaterats', 'preheader' => 'Det finns en uppdatering i ditt ärende.', 'category' => 'membership'),
            'application_completion' => array('label' => 'Begär komplettering', 'title' => 'Vi behöver en komplettering till din ansökan', 'preheader' => 'Vi behöver ytterligare information för att behandla din ansökan.', 'category' => 'membership'),
            'annual_meeting_registration' => array('label' => 'Årsmötesanmälan', 'title' => 'Din anmälan är bekräftad', 'preheader' => 'Din anmälan till aktiviteter under SSF:s årsmöteshelg är registrerad.', 'category' => 'annual_meeting'),
            'annual_meeting_registration_updated' => array('label' => 'Ändrad årsmötesanmälan', 'title' => 'Din anmälan har uppdaterats', 'preheader' => 'Dina aktuella val för årsmöteshelgen finns i detta meddelande.', 'category' => 'annual_meeting'),
            'contact_confirmation' => array('label' => 'Kontaktbekräftelse', 'title' => 'Vi har tagit emot ditt meddelande', 'preheader' => 'Tack för att du kontaktat Sveriges Segelfartygsförbund.', 'category' => 'general'),
            'ssf_account_invitation' => array('label' => 'Inbjudan till SSF-konto', 'title' => 'Aktivera ditt SSF-konto', 'preheader' => 'Du har fått tillgång till SSF:s administrativa system.', 'category' => 'general'),
            'vessel_update_invitation' => array('label' => 'Begäran om fartygsuppgifter', 'title' => 'Uppdatera uppgifter om ditt fartyg', 'preheader' => 'SSF behöver aktuella uppgifter om ditt fartyg.', 'category' => 'membership'),
            'vessel_update_received' => array('label' => 'Fartygsuppgifter mottagna', 'title' => 'Vi har tagit emot dina fartygsuppgifter', 'preheader' => 'Uppgifterna granskas före publicering.', 'category' => 'membership'),
            'inspector_assignment' => array('label' => 'Inspektörsuppdrag', 'title' => 'Du har fått ett nytt inspektörsuppdrag', 'preheader' => 'Ett nytt inspektionsärende har tilldelats dig.', 'category' => 'membership'),
        );
    }

    public static function category_definitions(): array
    {
        return array(
            'annual_meeting' => array('label' => 'Årsmöte', 'contact_label' => 'Frågor om årsmötet?', 'contact_email' => 'styrelsen@ssfb.se'),
            'membership' => array('label' => 'Medlem', 'contact_label' => 'Frågor om medlemskap?', 'contact_email' => 'medlem@ssfb.se'),
            self::GENERAL_CATEGORY => array('label' => 'Allmänt', 'contact_label' => 'Frågor?', 'contact_email' => 'info@ssfb.se'),
        );
    }

    public static function categories(): array
    {
        $definitions = self::category_definitions();
        $saved = (array) get_option(self::CATEGORY_OPTION, array());
        foreach ($definitions as $key => &$definition) {
            $row = isset($saved[$key]) && is_array($saved[$key]) ? $saved[$key] : array();
            $label = self::text($row['contact_label'] ?? '');
            $email = sanitize_email((string) ($row['contact_email'] ?? ''));
            if ($label) {
                $definition['contact_label'] = $label;
            }
            if (is_email($email)) {
                $definition['contact_email'] = $email;
            }
        }
        unset($definition);
        return (array) apply_filters('ssf_email_categories', $definitions);
    }

    public static function category_for_template(string $template): string
    {
        $templates = self::templates();
        if (isset($templates[$template])) {
            $category = sanitize_key((string) ($templates[$template]['category'] ?? ''));
            if (isset(self::category_definitions()[$category])) {
                return $category;
            }
        }
        self::report_unknown_template($template);
        return self::GENERAL_CATEGORY;
    }

    public static function contact_for_template(string $template, string $category_override = ''): array
    {
        $categories = self::categories();
        $category = sanitize_key($category_override);
        if (! isset($categories[$category])) {
            $category = self::category_for_template($template);
        }
        return array_merge(array('key' => $category), $categories[$category] ?? $categories[self::GENERAL_CATEGORY]);
    }

    public static function brand(): array
    {
        $saved = (array) get_option(self::OPTION, array());
        $organization = SSF_Organization_Info::get();
        $logo_id = absint($saved['logo_id'] ?? 0);
        $logo_url = $logo_id && wp_attachment_is_image($logo_id) ? (string) wp_get_attachment_url($logo_id) : '';
        if ($logo_id && ! $logo_url) {
            self::report_logo_warning($logo_id);
        } else {
            delete_option(self::LOGO_WARNING_OPTION);
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
            'logo_url' => $logo_url ? set_url_scheme($logo_url, 'https') : '',
            'header_line_1' => 'SVERIGES',
            'header_line_2' => 'SEGELFARTYGSFÖRBUND',
            'email_tagline' => 'SVERIGES SEGLANDE KULTURARV',
            'primary_color' => '#12324a',
            'header_separator_color' => '#8fb8d2',
            'header_tagline_color' => '#b9d4e4',
            'accent_color' => '#16716a',
            'text_color' => '#182c3d',
            'muted_color' => '#526576',
            'background_color' => '#eef2f4',
            'content_width' => 620,
        );
    }

    public static function render(string $template, array $data = array()): string
    {
        $definition = self::template_definition($template);
        $brand = self::brand();
        $contact = self::contact_for_template($template, (string) ($data['category'] ?? ''));
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
<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title><?php echo esc_html($title); ?></title><style type="text/css">@media only screen and (max-width:480px){.ssf-email-header-logo,.ssf-email-header-copy{display:block!important;width:100%!important;text-align:center!important}.ssf-email-header-logo{padding:26px 24px 14px!important}.ssf-email-header-copy{padding:12px 24px 28px!important}.ssf-email-header-logo img{margin:0 auto!important}.ssf-email-header-separator{display:none!important;width:0!important;height:0!important;overflow:hidden!important}.ssf-email-header-name{font-size:22px!important;line-height:27px!important}.ssf-email-header-tagline{font-size:12px!important;line-height:18px!important}}</style></head>
<body style="margin:0;padding:0;background-color:<?php echo esc_attr($brand['background_color']); ?>;color:<?php echo esc_attr($brand['text_color']); ?>;font-family:Arial,Helvetica,sans-serif;">
<div style="display:none!important;max-height:0;max-width:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;"><?php echo esc_html($preheader); ?>&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background-color:<?php echo esc_attr($brand['background_color']); ?>;"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="<?php echo esc_attr((string) $brand['content_width']); ?>" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:<?php echo esc_attr((string) $brand['content_width']); ?>px;background-color:#ffffff;">
<?php echo self::render_header($brand); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<tr><td style="padding:28px 32px 8px;"><h1 style="margin:0;color:<?php echo esc_attr($brand['primary_color']); ?>;font-size:27px;font-weight:bold;line-height:34px;"><?php echo esc_html($title); ?></h1>
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
<tr><td style="padding:22px 32px 30px;border-top:1px solid #d8e1e7;color:<?php echo esc_attr($brand['muted_color']); ?>;font-size:13px;line-height:20px;"><strong style="color:<?php echo esc_attr($brand['primary_color']); ?>;"><?php echo esc_html($brand['name']); ?></strong><br><br><?php echo esc_html($contact['contact_label']); ?><br><a href="mailto:<?php echo esc_attr($contact['contact_email']); ?>" style="color:<?php echo esc_attr($brand['primary_color']); ?>;font-weight:bold;text-decoration:underline;"><?php echo esc_html($contact['contact_email']); ?></a><br><br><a href="<?php echo esc_url($brand['website_url']); ?>" style="color:<?php echo esc_attr($brand['primary_color']); ?>;text-decoration:underline;"><?php echo esc_html($brand['website_label']); ?></a><br><br>Postadress:<br><?php foreach ($brand['address_lines'] as $line) : ?><?php echo esc_html($line); ?><br><?php endforeach; ?></td></tr>
</table></td></tr></table></body></html>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_header(array $brand): string
    {
        ob_start();
        ?>
<tr><td bgcolor="<?php echo esc_attr($brand['primary_color']); ?>" style="padding:0;background-color:<?php echo esc_attr($brand['primary_color']); ?>;color:#ffffff;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background-color:<?php echo esc_attr($brand['primary_color']); ?>;"><tr>
<?php if ($brand['logo_url']) : ?><td class="ssf-email-header-logo" width="38%" align="center" valign="middle" style="width:38%;padding:30px 28px;"><img src="<?php echo esc_url($brand['logo_url']); ?>" width="145" alt="Sveriges Segelfartygsförbund" style="display:block;width:145px;max-width:100%;height:auto;margin:0 auto;border:0;outline:none;text-decoration:none;"></td><td class="ssf-email-header-separator" width="1" bgcolor="<?php echo esc_attr($brand['header_separator_color']); ?>" style="width:1px;background-color:<?php echo esc_attr($brand['header_separator_color']); ?>;font-size:0;line-height:0;">&nbsp;</td><?php endif; ?>
<td class="ssf-email-header-copy" align="<?php echo $brand['logo_url'] ? 'left' : 'center'; ?>" valign="middle" style="padding:30px 34px;text-align:<?php echo $brand['logo_url'] ? 'left' : 'center'; ?>;">
<p class="ssf-email-header-name" style="margin:0;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:24px;font-weight:700;line-height:29px;letter-spacing:0;"><?php echo esc_html($brand['header_line_1']); ?><br><?php echo esc_html($brand['header_line_2']); ?></p>
<p class="ssf-email-header-tagline" style="margin:16px 0 0;color:<?php echo esc_attr($brand['header_tagline_color']); ?>;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;line-height:19px;letter-spacing:0;"><?php echo esc_html($brand['email_tagline']); ?></p>
</td></tr></table>
</td></tr>
        <?php
        return (string) ob_get_clean();
    }

    public static function render_text(string $template, array $data = array()): string
    {
        $definition = self::template_definition($template);
        $brand = self::brand();
        $contact = self::contact_for_template($template, (string) ($data['category'] ?? ''));
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
        return implode("\n", array_merge($lines, array('', 'Vänliga hälsningar', $brand['name'], '', $contact['contact_label'], $contact['contact_email'], '', $brand['website_url'], '', 'Postadress:'), $brand['address_lines']));
    }

    public static function send(string $recipient, string $subject, string $template, array $data = array(), $headers = array(), array $attachments = array()): bool
    {
        $recipient = sanitize_email($recipient);
        if (! is_email($recipient)) {
            return false;
        }
        $contact = self::contact_for_template($template, (string) ($data['category'] ?? ''));
        $subject = self::prepare_subject(self::text($subject));
        $html = self::render($template, $data);
        $text = self::render_text($template, $data);
        $headers = is_array($headers) ? $headers : preg_split('/\r?\n/', (string) $headers);
        $headers = array_values(array_filter($headers, static function ($header): bool {
            return 0 !== stripos(trim((string) $header), 'Content-Type:');
        }));
        $has_reply_to = (bool) array_filter($headers, static function ($header): bool {
            return 0 === stripos(trim((string) $header), 'Reply-To:');
        });
        if (! $has_reply_to) {
            $headers[] = 'Reply-To: ' . $contact['contact_email'];
        }
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
        if (! current_user_can('ssf_manage_microsoft_login') && ! current_user_can('manage_options')) {
            return;
        }
        $brand = self::brand();
        $organization = SSF_Organization_Info::get();
        $categories = self::categories();
        $types_by_category = array_fill_keys(array_keys($categories), array());
        foreach (self::templates() as $key => $template) {
            $category = self::category_for_template($key);
            $types_by_category[$category][$key] = $template['label'];
        }
        ?>
        <div id="ssf-email-design" class="postbox" style="max-width:1180px;padding:20px">
            <h2>Organisationsuppgifter och e-postdesign</h2>
            <p>Gemensam organisationsinformation för webbplatsens sidfot, medlemssidor och externa användarmejl.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_email_template_save"><?php wp_nonce_field('ssf_email_template_save'); ?>
                <h3>Visuell identitet</h3>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th><label for="ssf-email-brand-name">Avsändaridentitet</label></th><td><input id="ssf-email-brand-name" class="regular-text" name="brand[name]" value="<?php echo esc_attr($brand['name']); ?>" required></td></tr>
                    <tr><th>E-postlogotyp</th><td><div style="display:inline-block;min-width:220px;margin-bottom:10px;padding:18px;background:<?php echo esc_attr($brand['primary_color']); ?>;text-align:center;"><img data-ssf-email-logo-preview <?php if (! $brand['logo_url']) : ?>hidden<?php else : ?>src="<?php echo esc_url($brand['logo_url']); ?>"<?php endif; ?> alt="Förhandsvisning av e-postlogotyp" style="display:block;max-width:180px;max-height:120px;margin:0 auto;"><strong data-ssf-email-logo-fallback <?php if ($brand['logo_url']) : ?>hidden<?php endif; ?> style="color:#ffffff;">Ingen särskild e-postlogga vald</strong></div><br><input data-ssf-email-logo-id type="hidden" name="brand[logo_id]" value="<?php echo esc_attr((string) $brand['logo_id']); ?>"><button data-ssf-email-logo-select type="button" class="button">Välj eller byt logga</button> <button data-ssf-email-logo-clear type="button" class="button">Ta bort vald logga</button><p class="description">Använd en vit PNG-logga med transparent bakgrund. Rekommenderad bredd minst 500 px för skarp rendering på Retina-skärmar.</p><?php $logo_warning = (array) get_option(self::LOGO_WARNING_OPTION, array()); if ($logo_warning) : ?><div class="notice notice-warning inline"><p>Den valda e-postloggan kunde inte läsas. Mail skickas med organisationens namn som säker fallback. Välj loggan på nytt.</p></div><?php endif; ?></td></tr>
                    <tr><th>Headeridentitet</th><td><strong>SVERIGES SEGELFARTYGSFÖRBUND</strong><br><span>Sveriges seglande kulturarv</span><p><span style="display:inline-block;width:18px;height:18px;margin-right:6px;vertical-align:middle;background:<?php echo esc_attr($brand['primary_color']); ?>;"></span><code><?php echo esc_html($brand['primary_color']); ?></code></p></td></tr>
                    <tr><th><label for="ssf-email-website-url">Webbplats</label></th><td><input id="ssf-email-website-url" class="regular-text code" type="url" name="brand[website_url]" value="<?php echo esc_attr($brand['website_url']); ?>" required> <input class="regular-text" name="brand[website_label]" value="<?php echo esc_attr($brand['website_label']); ?>" aria-label="Webbplatsens länktext" required></td></tr>
                    <tr><th>Postadress</th><td><input class="regular-text" name="brand[address_line_1]" value="<?php echo esc_attr($organization['address_line_1']); ?>" required><br><input class="regular-text" name="brand[address_line_2]" value="<?php echo esc_attr($organization['address_line_2']); ?>" required><br><input class="small-text" name="brand[postal_code]" value="<?php echo esc_attr($organization['postal_code']); ?>" inputmode="numeric" required> <input class="regular-text" name="brand[city]" value="<?php echo esc_attr($organization['city']); ?>" required></td></tr>
                    <tr><th><label for="ssf-organization-number">Organisationsnummer</label></th><td><input id="ssf-organization-number" class="regular-text" name="brand[organization_number]" value="<?php echo esc_attr($brand['organization_number']); ?>" inputmode="numeric" required></td></tr>
                    <tr><th>Betalning</th><td><label>Bankgiro <input class="regular-text" name="brand[bankgiro]" value="<?php echo esc_attr($brand['bankgiro']); ?>" required></label><br><label>Swishnummer <input class="regular-text" name="brand[swish]" value="<?php echo esc_attr($brand['swish']); ?>" inputmode="numeric" required></label></td></tr>
                </tbody></table>
                <h3>E-postkategorier</h3>
                <p>Kontakten visas i sidfoten och används som Reply-To för externa användarmejl. Interna mottagare konfigureras separat under E-postmottagare.</p>
                <table class="widefat striped"><thead><tr><th>Kategori</th><th>Kontakttext</th><th>Kontakt i sidfot</th><th>Används av</th></tr></thead><tbody>
                <?php foreach ($categories as $key => $category) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($category['label']); ?><br><code><?php echo esc_html($key); ?></code></th>
                        <td><input class="regular-text" name="categories[<?php echo esc_attr($key); ?>][contact_label]" value="<?php echo esc_attr($category['contact_label']); ?>" required></td>
                        <td><input class="regular-text" type="email" name="categories[<?php echo esc_attr($key); ?>][contact_email]" value="<?php echo esc_attr($category['contact_email']); ?>" required></td>
                        <td><?php echo esc_html(implode(', ', $types_by_category[$key])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php submit_button('Spara organisationsuppgifter och e-postdesign'); ?>
            </form>
            <h3>Mallar</h3>
            <table class="widefat striped"><thead><tr><th>Malltyp</th><th>Kategori</th><th>Standardrubrik</th></tr></thead><tbody>
            <?php foreach (self::templates() as $key => $template) : ?><tr><th><?php echo esc_html($template['label']); ?><br><code><?php echo esc_html($key); ?></code></th><td><?php echo esc_html($categories[self::category_for_template($key)]['label']); ?></td><td><?php echo esc_html($template['title']); ?></td></tr><?php endforeach; ?>
            </tbody></table>
            <h3>Förhandsvisa mall</h3>
            <form method="get" target="_blank" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_email_template_preview"><?php wp_nonce_field('ssf_email_template_preview'); ?>
                <label for="ssf-email-preview-template"><strong>Mall</strong></label> <select id="ssf-email-preview-template" name="template" data-ssf-email-template><?php foreach (self::templates() as $key => $template) : ?><option value="<?php echo esc_attr($key); ?>" data-category="<?php echo esc_attr(self::category_for_template($key)); ?>"><?php echo esc_html($template['label']); ?></option><?php endforeach; ?></select>
                <label for="ssf-email-preview-category"><strong>Kategori</strong></label> <select id="ssf-email-preview-category" name="category" data-ssf-email-category><?php foreach ($categories as $key => $category) : ?><option value="<?php echo esc_attr($key); ?>" data-contact-label="<?php echo esc_attr($category['contact_label']); ?>" data-contact-email="<?php echo esc_attr($category['contact_email']); ?>"><?php echo esc_html($category['label']); ?></option><?php endforeach; ?></select>
                <?php submit_button('Förhandsvisa', 'secondary', 'submit', false); ?>
                <p class="description" data-ssf-email-contact-summary></p>
            </form>
            <h3>Skicka testmejl</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_email_template_test"><?php wp_nonce_field('ssf_email_template_test'); ?>
                <label for="ssf-email-test-template"><strong>Mall</strong></label> <select id="ssf-email-test-template" name="template" data-ssf-email-template><?php foreach (self::templates() as $key => $template) : ?><option value="<?php echo esc_attr($key); ?>" data-category="<?php echo esc_attr(self::category_for_template($key)); ?>"><?php echo esc_html($template['label']); ?></option><?php endforeach; ?></select>
                <label for="ssf-email-test-category"><strong>Kategori</strong></label> <select id="ssf-email-test-category" name="category" data-ssf-email-category><?php foreach ($categories as $key => $category) : ?><option value="<?php echo esc_attr($key); ?>" data-contact-label="<?php echo esc_attr($category['contact_label']); ?>" data-contact-email="<?php echo esc_attr($category['contact_email']); ?>"><?php echo esc_html($category['label']); ?></option><?php endforeach; ?></select>
                <label for="ssf-email-test-recipient"><strong>Mottagare</strong></label> <input id="ssf-email-test-recipient" type="email" name="recipient" value="<?php echo esc_attr((string) wp_get_current_user()->user_email); ?>" required>
                <?php submit_button('Skicka testmejl', 'secondary', 'submit', false); ?>
                <p class="description" data-ssf-email-contact-summary></p>
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
        wp_enqueue_script('ssf-email-template-admin', content_url('/mu-plugins/assets/ssf-email-template-admin.js'), array('media-editor'), '1.2.0', true);
    }

    public static function handle_save(): void
    {
        self::guard('ssf_email_template_save');
        $input = isset($_POST['brand']) && is_array($_POST['brand']) ? wp_unslash($_POST['brand']) : array();
        $category_input = isset($_POST['categories']) && is_array($_POST['categories']) ? wp_unslash($_POST['categories']) : array();
        $category_settings = array();
        $invalid = array();
        foreach (self::category_definitions() as $key => $definition) {
            $row = isset($category_input[$key]) && is_array($category_input[$key]) ? $category_input[$key] : array();
            $contact_label = self::text($row['contact_label'] ?? '') ?: $definition['contact_label'];
            $raw_email = isset($row['contact_email']) && is_scalar($row['contact_email']) ? trim((string) $row['contact_email']) : '';
            $contact_email = sanitize_email($raw_email);
            if (! is_email($contact_email)) {
                $invalid[] = $definition['label'];
            }
            $category_settings[$key] = array('contact_label' => $contact_label, 'contact_email' => $contact_email);
        }
        if ($invalid) {
            self::notice('error', 'Inställningarna sparades inte. Kontrollera kontaktadressen för: ' . implode(', ', $invalid) . '.');
            self::redirect();
        }
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
        update_option(self::CATEGORY_OPTION, $category_settings, false);
        self::notice('success', 'Organisationsuppgifterna och e-postdesignen har sparats.');
        self::redirect();
    }

    public static function handle_preview(): void
    {
        $template = sanitize_key((string) ($_GET['template'] ?? ''));
        $category = sanitize_key((string) ($_GET['category'] ?? ''));
        if ((! current_user_can('ssf_manage_microsoft_login') && ! current_user_can('manage_options')) || ! isset(self::templates()[$template]) || ! isset(self::categories()[$category]) || ! check_admin_referer('ssf_email_template_preview')) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-email-template'));
        }
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        echo self::render($template, array_merge(self::sample_data($template), array('category' => $category))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public static function handle_test(): void
    {
        self::guard('ssf_email_template_test');
        $template = sanitize_key((string) ($_POST['template'] ?? ''));
        $category = sanitize_key((string) ($_POST['category'] ?? ''));
        $recipient = sanitize_email((string) ($_POST['recipient'] ?? ''));
        if (! isset(self::templates()[$template]) || ! isset(self::categories()[$category]) || ! is_email($recipient)) {
            self::notice('error', 'Välj en giltig mall, kategori och mottagare.');
            self::redirect();
        }
        $label = self::templates()[$template]['label'];
        $sent = self::send($recipient, 'SSF test – ' . $label, $template, array_merge(self::sample_data($template), array('category' => $category)));
        self::notice($sent ? 'success' : 'error', $sent ? 'Testmejlet accepterades av den aktiva e-posttransporten.' : 'Testmejlet kunde inte skickas. Kontrollera Microsoft 365-anslutningen.');
        self::redirect();
    }

    public static function render_admin_notice(): void
    {
        if (! current_user_can('ssf_manage_microsoft_login') && ! current_user_can('manage_options')) {
            return;
        }
        $stored_unknown_types = (array) get_option(self::UNKNOWN_TYPES_OPTION, array());
        $unknown_types = array_diff_key($stored_unknown_types, self::templates());
        if ($unknown_types !== $stored_unknown_types) {
            update_option(self::UNKNOWN_TYPES_OPTION, $unknown_types, false);
        }
        if ($unknown_types) {
            printf('<div class="notice notice-warning"><p>%s</p></div>', esc_html('SSF har använt kategorin Allmänt för okända e-posttyper: ' . implode(', ', array_keys($unknown_types)) . '. Kontrollera den centrala typmappningen.'));
        }
        $notice = get_transient(self::NOTICE_PREFIX . get_current_user_id());
        if (! is_array($notice)) {
            return;
        }
        delete_transient(self::NOTICE_PREFIX . get_current_user_id());
        printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr((string) $notice['type']), esc_html((string) $notice['message']));
    }

    private static function template_definition(string $template): array
    {
        $templates = self::templates();
        if (isset($templates[$template])) {
            return $templates[$template];
        }
        self::report_unknown_template($template);
        return array(
            'label' => 'Allmänt meddelande',
            'title' => 'Meddelande från SSF',
            'preheader' => 'Ett meddelande från Sveriges Segelfartygsförbund.',
            'category' => self::GENERAL_CATEGORY,
        );
    }

    private static function report_unknown_template(string $template): void
    {
        $template = sanitize_key($template) ?: '(tom typ)';
        if (isset(self::$reported_unknown_types[$template])) {
            return;
        }
        self::$reported_unknown_types[$template] = true;
        $unknown_types = (array) get_option(self::UNKNOWN_TYPES_OPTION, array());
        $unknown_types[$template] = current_time('mysql');
        update_option(self::UNKNOWN_TYPES_OPTION, array_slice($unknown_types, -10, null, true), false);
        if (class_exists('SSF\\MemberPortal\\Core\\Logger')) {
            \SSF\MemberPortal\Core\Logger::add('email_template_unknown_type', array('template' => $template, 'fallback_category' => self::GENERAL_CATEGORY));
        }
        do_action('ssf_email_template_unknown_type', $template, self::GENERAL_CATEGORY);
    }

    private static function report_logo_warning(int $logo_id): void
    {
        $reported = (array) get_option(self::LOGO_WARNING_OPTION, array());
        if ((int) ($reported['logo_id'] ?? 0) === $logo_id) {
            return;
        }
        update_option(self::LOGO_WARNING_OPTION, array('logo_id' => $logo_id, 'time' => current_time('mysql')), false);
        if (class_exists('SSF\\MemberPortal\\Core\\Logger')) {
            \SSF\MemberPortal\Core\Logger::add('email_header_logo_missing', array('attachment_id' => $logo_id));
        }
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
        if ('application_admin_notice' === $template) {
            $data['recipient_name'] = 'Medlemsgruppen';
            $data['body'] = array('En ny ansökan om medlemskap för fartyg har skickats in. Granska ärendet i handläggningsportalen för att se uppgifter, bilagor, status och SharePoint-synk.');
            $data['sections'] = array(array('title' => 'Ansökan', 'rows' => array(
                'Ansökningsnummer' => 'SSF-2026-0004',
                'Fartyg' => 'Exempelskutan',
                'Sökande' => 'Anna Andersson',
                'E-post' => 'anna@example.se',
                'Ansökningsväg' => 'Normalfallet',
                'Ansökningsstatus' => 'Inkommen',
                'Medlemsstatus' => 'Ej medlem',
                'Inkommen' => '13 september 2026, 14:32',
                'SharePoint-synk' => 'Väntar',
            )));
            $data['notice_title'] = '';
            $data['notice'] = '';
            $data['button_label'] = 'Granska ansökan';
            $data['button_url'] = home_url('/medlemskap/handlaggning/SSF-2026-0004/');
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
        if ((! current_user_can('ssf_manage_microsoft_login') && ! current_user_can('manage_options')) || ! check_admin_referer($nonce)) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-email-template'));
        }
    }

    private static function notice(string $type, string $message): void
    {
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), array('type' => $type, 'message' => $message), MINUTE_IN_SECONDS);
    }

    private static function redirect(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::ADMIN_PAGE . '&m365_tab=email#ssf-email-design'));
        exit;
    }
}

SSF_Email_Template::boot();
