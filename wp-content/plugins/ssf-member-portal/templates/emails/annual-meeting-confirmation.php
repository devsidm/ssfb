<?php
/** @var array $data */
?>
<!doctype html>
<html lang="sv">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($data['subject']); ?></title>
</head>
<body style="margin:0; padding:0; background-color:#eef2f4; color:#182c3d; font-family:Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; background-color:#eef2f4;">
        <tr><td align="center" style="padding:24px 12px;">
            <table role="presentation" width="620" cellspacing="0" cellpadding="0" border="0" style="width:100%; max-width:620px; background-color:#ffffff;">
                <tr><td style="padding:26px 32px; background-color:#12324a; color:#ffffff;">
                    <?php if (! empty($data['logo_url'])) : ?><img src="<?php echo esc_url($data['logo_url']); ?>" width="150" alt="Sveriges Segelfartygsförbund" style="display:block; width:150px; max-width:100%; height:auto; margin:0 0 14px; border:0;"><?php endif; ?>
                    <p style="margin:0; color:#ffffff; font-size:14px; font-weight:bold; letter-spacing:0; line-height:20px;">SVERIGES SEGELFARTYGSFÖRBUND</p>
                </td></tr>
                <tr><td style="padding:34px 32px 8px;">
                    <p style="margin:0 0 10px; color:#16716a; font-size:16px; font-weight:bold; line-height:24px;">&#10003; Din anmälan är bekräftad</p>
                    <h1 style="margin:0; color:#12324a; font-size:27px; font-weight:bold; line-height:34px;">SSF:s årsmöteshelg <?php echo esc_html((string) $data['year']); ?></h1>
                    <p style="margin:22px 0 0; color:#243b4d; font-size:16px; line-height:25px;"><?php echo $data['greeting'] ? esc_html(sprintf(__('Hej %s!', 'ssf-member-portal'), $data['greeting'])) : esc_html__('Hej!', 'ssf-member-portal'); ?><br>Tack för din anmälan till Sveriges Segelfartygsförbunds årsmöteshelg.<br>Vi ser fram emot att träffa dig!</p>
                </td></tr>
                <tr><td style="padding:28px 32px 0;">
                    <h2 style="margin:0 0 12px; color:#12324a; font-size:18px; font-weight:bold; line-height:24px;">Årsmöteshelgen</h2>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; border:1px solid #d8e1e7;">
                        <?php foreach ($data['meeting_rows'] as $row) : ?><tr><td valign="top" style="width:34%; padding:13px 14px; border-bottom:1px solid #d8e1e7; color:#526576; font-size:14px; font-weight:bold; line-height:20px;"><?php echo esc_html($row['label']); ?></td><td valign="top" style="padding:13px 14px; border-bottom:1px solid #d8e1e7; color:#182c3d; font-size:16px; line-height:22px;"><?php echo esc_html($row['value']); ?></td></tr><?php endforeach; ?>
                    </table>
                </td></tr>
                <?php if ($data['registration_rows']) : ?><tr><td style="padding:28px 32px 0;">
                    <h2 style="margin:0 0 12px; color:#12324a; font-size:18px; font-weight:bold; line-height:24px;">Din anmälan</h2>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; border:1px solid #d8e1e7;">
                        <?php foreach ($data['registration_rows'] as $row) : ?><tr><td valign="top" style="width:34%; padding:13px 14px; border-bottom:1px solid #d8e1e7; color:#526576; font-size:14px; font-weight:bold; line-height:20px;"><?php echo esc_html($row['label']); ?></td><td valign="top" style="padding:13px 14px; border-bottom:1px solid #d8e1e7; color:#182c3d; font-size:16px; line-height:22px; white-space:pre-line;"><?php echo esc_html($row['value']); ?></td></tr><?php endforeach; ?>
                    </table>
                </td></tr><?php endif; ?>
                <tr><td align="center" style="padding:30px 32px 0;">
                    <?php if ($data['calendar_url']) : ?><a href="<?php echo esc_url($data['calendar_url']); ?>" style="display:block; box-sizing:border-box; width:100%; padding:14px 18px; background-color:#16716a; color:#ffffff; font-size:16px; font-weight:bold; line-height:20px; text-align:center; text-decoration:none;">Lägg till i kalender</a><?php endif; ?>
                    <?php if ($data['meeting_url']) : ?><a href="<?php echo esc_url($data['meeting_url']); ?>" style="display:block; box-sizing:border-box; width:100%; margin-top:12px; padding:13px 18px; border:1px solid #12324a; color:#12324a; font-size:16px; font-weight:bold; line-height:20px; text-align:center; text-decoration:none;">Visa information om årsmötet</a><?php endif; ?>
                </td></tr>
                <?php if ($data['practical_information']) : ?><tr><td style="padding:28px 32px 0;"><h2 style="margin:0 0 8px; color:#12324a; font-size:18px; font-weight:bold; line-height:24px;">Praktisk information</h2><p style="margin:0; color:#243b4d; font-size:16px; line-height:24px;"><?php echo esc_html($data['practical_information']); ?></p></td></tr><?php endif; ?>
                <?php if ($data['manage_url']) : ?><tr><td style="padding:28px 32px 0;"><h2 style="margin:0 0 8px; color:#12324a; font-size:18px; font-weight:bold; line-height:24px;">Behöver du ändra något?</h2><p style="margin:0 0 14px; color:#243b4d; font-size:16px; line-height:24px;">Du kan använda länken nedan för att se eller ändra din anmälan.</p><a href="<?php echo esc_url($data['manage_url']); ?>" style="display:block; box-sizing:border-box; width:100%; padding:13px 18px; border:1px solid #6b7d8c; color:#12324a; font-size:16px; font-weight:bold; line-height:20px; text-align:center; text-decoration:none;">Visa eller ändra min anmälan</a></td></tr><?php endif; ?>
                <tr><td style="padding:30px 32px; color:#526576; font-size:13px; line-height:20px;"><strong style="color:#12324a;">Sveriges Segelfartygsförbund</strong><br><a href="<?php echo esc_url($data['site_url']); ?>" style="color:#12324a; text-decoration:underline;">ssfb.se</a><br><br>Detta är ett automatiskt meddelande från SSF:s medlemssystem. <span style="white-space:nowrap;">system@ssfb.se</span> är en systemadress.</td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
