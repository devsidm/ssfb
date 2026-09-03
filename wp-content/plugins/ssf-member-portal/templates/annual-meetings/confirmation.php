<?php
/** @var array $meeting */
/** @var array $registration */
/** @var array $calendar */
/** @var string $token */
/** @var SSF\MemberPortal\Modules\AnnualMeetings\Frontend $this */
$cancelled = 'cancelled' === $registration['status'];
$logo_url = get_template_directory_uri() . '/assets/images/ssf-logo.svg';
?>
<section class="ssf-am-page ssf-am-confirmation" aria-labelledby="ssf-am-confirmation-heading">
    <header class="ssf-am-confirmation__header">
        <div class="ssf-am-confirmation__brand"><img src="<?php echo esc_url($logo_url); ?>" alt="<?php esc_attr_e('Sveriges Segelfartygsförbund', 'ssf-member-portal'); ?>"><span><?php esc_html_e('Sveriges Segelfartygsförbund', 'ssf-member-portal'); ?></span></div>
        <p class="ssf-am-eyebrow"><?php esc_html_e('Årsmöteshelg', 'ssf-member-portal'); ?></p>
        <h1 id="ssf-am-confirmation-heading"><?php echo $cancelled ? esc_html__('Anmälan är avbokad', 'ssf-member-portal') : esc_html__('Tack, dina val är registrerade.', 'ssf-member-portal'); ?></h1>
        <p class="ssf-am-dates"><?php echo esc_html(get_the_title((int) $meeting['id'])); ?> · <?php echo esc_html($this->date_range($meeting)); ?></p>
    </header>

    <section class="ssf-am-confirmation__receipt" aria-labelledby="ssf-am-confirmation-summary">
        <div class="ssf-am-confirmation__receipt-heading"><div><p class="ssf-am-kicker"><?php esc_html_e('Din bekräftelse', 'ssf-member-portal'); ?></p><h2 id="ssf-am-confirmation-summary"><?php echo esc_html($registration['status_label']); ?></h2></div><span class="ssf-am-badge <?php echo $cancelled ? '' : 'ssf-am-badge--open'; ?>"><?php echo $cancelled ? esc_html__('Avbokad', 'ssf-member-portal') : esc_html__('Registrerad', 'ssf-member-portal'); ?></span></div>
        <dl class="ssf-am-summary"><div><dt><?php esc_html_e('Namn', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html($registration['first_name'] . ' ' . $registration['last_name']); ?></dd></div><div><dt><?php esc_html_e('Valda arrangemang', 'ssf-member-portal'); ?></dt><dd><?php echo $registration['program_labels'] ? esc_html(implode(', ', $registration['program_labels'])) : esc_html__('Inga valda arrangemang', 'ssf-member-portal'); ?></dd></div><?php if ($registration['food']) : ?><div><dt><?php esc_html_e('Mat', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html(implode(', ', $registration['food'])); ?></dd></div><?php endif; ?></dl>
    </section>

    <?php if (! $cancelled) : ?>
        <section class="ssf-am-confirmation__next" aria-labelledby="ssf-am-confirmation-next">
            <div><p class="ssf-am-kicker"><?php esc_html_e('Nästa steg', 'ssf-member-portal'); ?></p><h2 id="ssf-am-confirmation-next"><?php esc_html_e('Spara helgen i kalendern', 'ssf-member-portal'); ?></h2><p><?php esc_html_e('Välj den kalender du använder. Alla alternativ innehåller årsmöteshelgens datum, plats och länk till mer information.', 'ssf-member-portal'); ?></p></div>
            <?php if ($calendar) : ?><details class="ssf-am-calendar-menu"><summary class="ssf-am-button"><?php esc_html_e('Lägg till i kalender', 'ssf-member-portal'); ?></summary><div class="ssf-am-calendar-menu__options" aria-label="<?php esc_attr_e('Välj kalender', 'ssf-member-portal'); ?>"><a href="<?php echo esc_url($calendar['outlook']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Outlook', 'ssf-member-portal'); ?></a><a href="<?php echo esc_url($calendar['google']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Google Kalender', 'ssf-member-portal'); ?></a><a href="<?php echo esc_url($calendar['apple']); ?>"><?php esc_html_e('Apple Kalender / iCal', 'ssf-member-portal'); ?></a><a href="<?php echo esc_url($calendar['ics']); ?>"><?php esc_html_e('Ladda ner kalenderfil (.ics)', 'ssf-member-portal'); ?></a></div></details><?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="ssf-am-confirmation__actions"><a class="ssf-am-button ssf-am-button--secondary" href="<?php echo esc_url($this->meetings->registration_url(array('token' => rawurlencode($token)))); ?>"><?php echo $cancelled ? esc_html__('Visa min anmälan', 'ssf-member-portal') : esc_html__('Visa eller ändra min anmälan', 'ssf-member-portal'); ?></a></div>
</section>
