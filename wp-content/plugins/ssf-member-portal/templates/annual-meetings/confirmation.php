<?php
/** @var array $meeting */
/** @var array $registration */
/** @var string $token */
/** @var SSF\MemberPortal\Modules\AnnualMeetings\Frontend $this */
$calendar_url = $this->meetings->calendar_url((int) $meeting['id']);
?>
<section class="ssf-am-page" aria-labelledby="ssf-am-confirmation-heading">
    <header class="ssf-am-intro"><p class="ssf-am-eyebrow"><?php esc_html_e('SSF:s årsmöteshelg', 'ssf-member-portal'); ?></p><h1 id="ssf-am-confirmation-heading"><?php echo 'cancelled' === $registration['status'] ? esc_html__('Anmälan är avbokad', 'ssf-member-portal') : esc_html__('Tack, dina val är registrerade.', 'ssf-member-portal'); ?></h1><p class="ssf-am-dates"><?php echo esc_html(get_the_title((int) $meeting['id'])); ?> · <?php echo esc_html($this->date_range($meeting)); ?></p></header>
    <div class="ssf-am-callout"><strong><?php esc_html_e('Själva årsmötet kräver ingen anmälan.', 'ssf-member-portal'); ?></strong><span><?php esc_html_e('Bekräftelsen nedan gäller middag och aktiviteter.', 'ssf-member-portal'); ?></span></div>
    <dl class="ssf-am-summary"><div><dt><?php esc_html_e('Namn', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html($registration['first_name'] . ' ' . $registration['last_name']); ?></dd></div><div><dt><?php esc_html_e('Status', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html($registration['status_label']); ?></dd></div><div><dt><?php esc_html_e('Valda arrangemang', 'ssf-member-portal'); ?></dt><dd><?php echo $registration['program_labels'] ? esc_html(implode(', ', $registration['program_labels'])) : esc_html__('Inga valda arrangemang', 'ssf-member-portal'); ?></dd></div><?php if ($registration['food']) : ?><div><dt><?php esc_html_e('Mat', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html(implode(', ', $registration['food'])); ?></dd></div><?php endif; ?></dl>
    <?php if ('cancelled' !== $registration['status']) : ?><p><a class="ssf-am-button" href="<?php echo esc_url($calendar_url); ?>"><?php esc_html_e('Ladda ner kalenderfil', 'ssf-member-portal'); ?></a></p><?php endif; ?>
    <p><a href="<?php echo esc_url($this->meetings->registration_url(array('token' => rawurlencode($token)))); ?>"><?php esc_html_e('Visa eller ändra min anmälan', 'ssf-member-portal'); ?></a></p>
</section>
