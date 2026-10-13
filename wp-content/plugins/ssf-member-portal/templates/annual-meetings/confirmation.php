<?php
/** @var array $meeting */
/** @var array $registration */
/** @var array $calendar */
/** @var string $token */
/** @var SSF\MemberPortal\Modules\AnnualMeetings\Frontend $this */
$cancelled = 'cancelled' === $registration['status'];
?>
<section class="ssf-am-page ssf-am-confirmation" aria-labelledby="ssf-am-confirmation-heading">
    <header class="ssf-am-confirmation__header">
        <p class="ssf-am-eyebrow"><?php esc_html_e('Årsmöteshelg', 'ssf-member-portal'); ?></p>
        <h1 id="ssf-am-confirmation-heading"><?php echo $cancelled ? esc_html__('Anmälan är avbokad', 'ssf-member-portal') : esc_html__('Tack, dina val är registrerade.', 'ssf-member-portal'); ?></h1>
        <p class="ssf-am-dates"><?php echo esc_html(get_the_title((int) $meeting['id'])); ?> · <?php echo esc_html($this->date_range($meeting)); ?></p>
    </header>

    <?php if (! $cancelled && 'sent' === $registration['confirmation_status']) : ?>
        <p class="ssf-am-message ssf-am-message--success" role="status"><?php esc_html_e('Ett bekräftelsemejl har skickats till den e-postadress du angav.', 'ssf-member-portal'); ?></p>
    <?php elseif (! $cancelled && 'failed' === $registration['confirmation_status']) : ?>
        <p class="ssf-am-message" role="status"><strong><?php esc_html_e('Din anmälan är sparad.', 'ssf-member-portal'); ?></strong> <?php esc_html_e('Bekräftelsemejlet kunde inte skickas just nu. Systemet försöker automatiskt igen.', 'ssf-member-portal'); ?></p>
    <?php endif; ?>

    <section class="ssf-am-confirmation__receipt" aria-labelledby="ssf-am-confirmation-summary">
        <div class="ssf-am-confirmation__receipt-heading"><div><p class="ssf-am-kicker"><?php esc_html_e('Din bekräftelse', 'ssf-member-portal'); ?></p><h2 id="ssf-am-confirmation-summary"><?php echo esc_html($registration['status_label']); ?></h2></div><span class="ssf-am-badge <?php echo $cancelled ? '' : 'ssf-am-badge--open'; ?>"><?php echo $cancelled ? esc_html__('Avbokad', 'ssf-member-portal') : esc_html__('Registrerad', 'ssf-member-portal'); ?></span></div>
        <dl class="ssf-am-summary"><div><dt><?php esc_html_e('Anmälnings-ID', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html($registration['registration_id']); ?></dd></div><div><dt><?php esc_html_e('Huvudanmälare', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html($registration['first_name'] . ' ' . $registration['last_name']); ?></dd></div><div><dt><?php esc_html_e('Antal deltagare', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html((string) $registration['participant_count']); ?></dd></div></dl>
        <div class="ssf-am-review-list"><?php foreach ($registration['participants'] as $participant) : ?><article class="ssf-am-review-person"><h3><?php echo esc_html(trim($participant['first_name'] . ' ' . $participant['last_name'])); ?></h3><p><?php echo esc_html(implode(' · ', $participant['activity_labels'])); ?></p><?php foreach ((array) ($participant['activity_answers'] ?? array()) as $answer) : ?><p><strong><?php echo esc_html($answer['activity'] . ' – ' . $answer['question']); ?>:</strong> <?php echo esc_html($answer['answer']); ?></p><?php endforeach; ?></article><?php endforeach; ?></div>
        <?php if (! empty($registration['registration_activity_answers'])) : ?><div class="ssf-am-review-person"><h3><?php esc_html_e('Gemensamma svar', 'ssf-member-portal'); ?></h3><?php foreach ($registration['registration_activity_answers'] as $answer) : ?><p><strong><?php echo esc_html($answer['activity'] . ' – ' . $answer['question']); ?>:</strong> <?php echo esc_html($answer['answer']); ?></p><?php endforeach; ?></div><?php endif; ?>
    </section>

    <?php if (! $cancelled) : ?>
        <section class="ssf-am-confirmation__next" aria-labelledby="ssf-am-confirmation-next">
            <div><p class="ssf-am-kicker"><?php esc_html_e('Nästa steg', 'ssf-member-portal'); ?></p><h2 id="ssf-am-confirmation-next"><?php esc_html_e('Spara helgen i kalendern', 'ssf-member-portal'); ?></h2><p><?php esc_html_e('Välj den kalender du använder. Alla alternativ innehåller årsmöteshelgens datum, plats och länk till mer information.', 'ssf-member-portal'); ?></p></div>
            <?php if ($calendar) : ?><details class="ssf-am-calendar-menu"><summary class="ssf-am-button"><?php esc_html_e('Lägg till i kalender', 'ssf-member-portal'); ?></summary><div class="ssf-am-calendar-menu__options" aria-label="<?php esc_attr_e('Välj kalender', 'ssf-member-portal'); ?>"><a href="<?php echo esc_url($calendar['outlook']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Outlook', 'ssf-member-portal'); ?></a><a href="<?php echo esc_url($calendar['google']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Google Kalender', 'ssf-member-portal'); ?></a><a href="<?php echo esc_url($calendar['apple']); ?>"><?php esc_html_e('Apple Kalender / iCal', 'ssf-member-portal'); ?></a><a href="<?php echo esc_url($calendar['ics']); ?>"><?php esc_html_e('Ladda ner kalenderfil (.ics)', 'ssf-member-portal'); ?></a></div></details><?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="ssf-am-confirmation__actions"><a class="ssf-am-button ssf-am-button--secondary" href="<?php echo esc_url($this->meetings->registration_url(array('token' => rawurlencode($token)))); ?>"><?php echo $cancelled ? esc_html__('Visa min anmälan', 'ssf-member-portal') : esc_html__('Visa eller ändra min anmälan', 'ssf-member-portal'); ?></a></div>
</section>
