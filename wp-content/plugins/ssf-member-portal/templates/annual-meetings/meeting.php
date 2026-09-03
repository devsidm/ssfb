<?php
/** @var array $meeting */
/** @var WP_Post $meeting_post */
/** @var array $choices */
/** @var array $choice_states */
/** @var array $calendar */
/** @var array $registration_state */
/** @var string $page_title */
/** @var string $location_summary */
/** @var string $location_address */
/** @var string $maps_url */
/** @var string $contact_url */
/** @var SSF\MemberPortal\Modules\AnnualMeetings\Frontend $this */
$invitation = (array) $meeting['invitation'];
$now = current_datetime()->getTimestamp();
$invitation_is_published = empty($invitation['publish_at']) || $now >= (int) $invitation['publish_at'];
$show_invitation = $this->meetings->module_enabled($meeting, 'invitation') && ! empty($invitation['visible']) && $invitation_is_published && (! empty($invitation['text']) || ! empty($invitation['pdf_id']));
$visible_program = array_values(array_filter((array) $meeting['program'], static function (array $item): bool { return ! empty($item['visible']); }));
$show_dinner = $this->meetings->module_enabled($meeting, 'dinner') && ! empty($meeting['dinner']['start_at']) && ! array_filter($visible_program, static function (array $item): bool { return 'dinner' === ($item['type'] ?? '') || 'dinner' === ($item['key'] ?? ''); });
$show_day2 = $this->meetings->module_enabled($meeting, 'day2') && ($visible_program || ! empty($meeting['program_pdf_id']));
$show_motions = $this->meetings->module_enabled($meeting, 'motions') && ! empty($meeting['motions_public']);
$documents = array_values(array_filter((array) $meeting['documents'], static function (array $item): bool { return ! empty($item['visible']) && ! empty($item['attachment_id']); }));
$show_documents = $this->meetings->module_enabled($meeting, 'documents') && $documents;
$document_types = array('agenda' => 'Dagordning', 'annual_report' => 'Verksamhetsberättelse', 'financial_report' => 'Ekonomisk rapport', 'budget' => 'Budget', 'motions' => 'Motioner', 'board_response' => 'Styrelsens yttranden', 'minutes' => 'Protokoll', 'other' => 'Övrigt');
$dinner_choice = null;
foreach ($choices as $choice) {
    if ('dinner' === ($choice['source'] ?? '')) {
        $dinner_choice = $choice;
        break;
    }
}
$format_time = static function (int $timestamp): string {
    return $timestamp ? wp_date('l j F Y, H:i', $timestamp, wp_timezone()) : '';
};
?>
<section class="ssf-am-page" aria-labelledby="ssf-am-heading">
    <header class="ssf-am-intro">
        <h1 id="ssf-am-heading"><?php echo esc_html($page_title); ?></h1>
        <nav class="ssf-am-section-nav ssf-am-local-nav" aria-label="<?php esc_attr_e('Innehåll på årsmötessidan', 'ssf-member-portal'); ?>">
            <a href="#ssf-am-overview"><?php esc_html_e('Översikt', 'ssf-member-portal'); ?></a>
            <?php if ($show_dinner || $show_day2) : ?><a href="#ssf-am-program-heading"><?php esc_html_e('Program', 'ssf-member-portal'); ?></a><?php endif; ?>
            <a href="#ssf-am-registration"><?php esc_html_e('Anmälan', 'ssf-member-portal'); ?></a>
            <?php if ($show_motions) : ?><a href="#ssf-am-motions"><?php esc_html_e('Motioner', 'ssf-member-portal'); ?></a><?php endif; ?>
        </nav>
        <p class="ssf-am-dates"><?php echo esc_html($this->date_range($meeting)); ?></p>
        <?php if ($location_summary) : ?>
            <div class="ssf-am-location">
                <span class="ssf-am-label"><?php esc_html_e('Plats:', 'ssf-member-portal'); ?></span>
                <strong><?php echo esc_html($location_summary); ?></strong>
                <?php if ($location_address) : ?><span class="ssf-am-address"><?php echo esc_html($location_address); ?></span><?php endif; ?>
                <?php if ($maps_url) : ?><a class="ssf-am-map-link" href="<?php echo esc_url($maps_url); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr(sprintf(__('Vägbeskrivning till %s i Google Maps', 'ssf-member-portal'), $location_summary)); ?>"><?php esc_html_e('Vägbeskrivning', 'ssf-member-portal'); ?></a><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($meeting['intro']) : ?><p class="ssf-am-lead"><?php echo esc_html($meeting['intro']); ?></p><?php endif; ?>
        <div class="ssf-am-actions">
            <?php if ($this->meetings->module_enabled($meeting, 'calendar') && $calendar) : ?>
                <details class="ssf-am-calendar-menu">
                    <summary class="ssf-am-button ssf-am-button--secondary"><?php esc_html_e('Lägg till i kalender', 'ssf-member-portal'); ?></summary>
                    <div class="ssf-am-calendar-menu__options" aria-label="<?php esc_attr_e('Välj kalender', 'ssf-member-portal'); ?>">
                        <a href="<?php echo esc_url($calendar['outlook']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Outlook', 'ssf-member-portal'); ?></a>
                        <a href="<?php echo esc_url($calendar['google']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Google Kalender', 'ssf-member-portal'); ?></a>
                        <a href="<?php echo esc_url($calendar['apple']); ?>"><?php esc_html_e('Apple Kalender / iCal', 'ssf-member-portal'); ?></a>
                        <a href="<?php echo esc_url($calendar['ics']); ?>"><?php esc_html_e('Ladda ner kalenderfil (.ics)', 'ssf-member-portal'); ?></a>
                    </div>
                </details>
            <?php endif; ?>
            <?php if ($show_invitation && ! empty($invitation['pdf_id'])) : ?><a class="ssf-am-button ssf-am-button--secondary" href="<?php echo esc_url(wp_get_attachment_url((int) $invitation['pdf_id'])); ?>"><?php esc_html_e('Läs kallelsen', 'ssf-member-portal'); ?></a><?php elseif ($show_invitation) : ?><a class="ssf-am-button ssf-am-button--secondary" href="#ssf-am-invitation"><?php esc_html_e('Läs kallelsen', 'ssf-member-portal'); ?></a><?php endif; ?>
            <a class="ssf-am-button ssf-am-button--secondary" href="<?php echo esc_url($contact_url); ?>"><?php esc_html_e('Kontakt', 'ssf-member-portal'); ?></a>
        </div>
    </header>

    <?php do_action('ssf_annual_meeting_after_header', $meeting_post, $meeting); ?>

    <?php if (has_post_thumbnail($meeting_post)) : ?><div class="ssf-am-hero-image"><?php echo get_the_post_thumbnail($meeting_post, 'large'); ?></div><?php endif; ?>

    <section id="ssf-am-overview" class="ssf-am-section ssf-am-overview" aria-labelledby="ssf-am-overview-heading">
        <h2 id="ssf-am-overview-heading"><?php esc_html_e('Översikt', 'ssf-member-portal'); ?></h2>
        <dl class="ssf-am-facts">
            <?php if ($meeting['motion_closes_at'] && $show_motions) : ?><div><dt><?php esc_html_e('Sista motionsdag', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html(wp_date('j F Y, H:i', (int) $meeting['motion_closes_at'], wp_timezone())); ?></dd></div><?php endif; ?>
        </dl>
        <p><?php esc_html_e('Alla medlemmar är välkomna till själva årsmötet. Ingen anmälan krävs för mötet.', 'ssf-member-portal'); ?></p>
    </section>

    <section id="ssf-am-registration" class="ssf-am-section">
        <div class="ssf-am-section-heading"><div><p class="ssf-am-kicker"><?php esc_html_e('Middag och aktiviteter', 'ssf-member-portal'); ?></p><h2><?php esc_html_e('Anmälan', 'ssf-member-portal'); ?></h2></div><span class="ssf-am-badge <?php echo ! empty($registration_state['can_register']) ? 'ssf-am-badge--open' : ''; ?>"><?php echo esc_html((string) ($registration_state['label'] ?? '')); ?></span></div>
        <p><?php esc_html_e('Du behöver inte anmäla dig till själva årsmötet. Anmälan gäller endast middag och valfria aktiviteter.', 'ssf-member-portal'); ?></p>
        <?php if (! empty($registration_state['message'])) : ?><p class="ssf-am-message" role="status"><?php echo esc_html((string) $registration_state['message']); ?></p><?php endif; ?>
        <?php if (! empty($registration_state['can_register'])) : ?><p><a class="ssf-am-button" href="<?php echo esc_url($this->meetings->registration_url()); ?>"><?php esc_html_e('Anmäl dig till middag och aktiviteter', 'ssf-member-portal'); ?></a></p><?php endif; ?>
    </section>

    <?php if ($meeting_post->post_content) : ?><div class="ssf-am-content"><?php echo apply_filters('the_content', $meeting_post->post_content); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div><?php endif; ?>

    <?php if ($show_invitation) : ?>
        <section id="ssf-am-invitation" class="ssf-am-section">
            <h2><?php echo esc_html($invitation['title'] ?: __('Kallelse', 'ssf-member-portal')); ?></h2>
            <?php if ($invitation['text']) : ?><div class="ssf-am-content"><?php echo wp_kses_post(wpautop((string) $invitation['text'])); ?></div><?php endif; ?>
            <?php if ($invitation['pdf_id']) : ?><p><a class="ssf-am-document-link" href="<?php echo esc_url(wp_get_attachment_url((int) $invitation['pdf_id'])); ?>"><?php esc_html_e('Öppna kallelsen som PDF', 'ssf-member-portal'); ?></a></p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($show_dinner) : ?><span id="ssf-am-program-heading" class="ssf-am-anchor" aria-hidden="true"></span><?php endif; ?>

    <?php if ($show_dinner) : $dinner = $meeting['dinner']; $state = $dinner_choice ? ($choice_states[$dinner_choice['key']] ?? array()) : array(); ?>
        <section id="ssf-am-dinner" class="ssf-am-section">
            <div class="ssf-am-section-heading"><div><p class="ssf-am-kicker"><?php esc_html_e('Praktiskt arrangemang', 'ssf-member-portal'); ?></p><h2><?php echo esc_html($dinner['title'] ?: __('Middag', 'ssf-member-portal')); ?></h2></div><span class="ssf-am-badge"><?php esc_html_e('Anmälan krävs', 'ssf-member-portal'); ?></span></div>
            <p class="ssf-am-meta"><strong><?php echo esc_html($format_time((int) $dinner['start_at'])); ?></strong><?php if ($dinner['location']) : ?> · <?php echo esc_html($dinner['location']); ?><?php endif; ?><?php if ($dinner['price']) : ?> · <?php echo esc_html($dinner['price']); ?><?php endif; ?></p>
            <?php if ($dinner['description']) : ?><div class="ssf-am-content"><?php echo wp_kses_post(wpautop((string) $dinner['description'])); ?></div><?php endif; ?>
            <?php if ($dinner['deadline']) : ?><p><?php esc_html_e('Sista anmälningsdag:', 'ssf-member-portal'); ?> <strong><?php echo esc_html(wp_date('j F Y, H:i', (int) $dinner['deadline'], wp_timezone())); ?></strong></p><?php endif; ?>
            <?php if (! empty($state['capacity'])) : ?><p><?php echo esc_html(sprintf(__('%1$d av %2$d platser bokade', 'ssf-member-portal'), (int) $state['count'], (int) $state['capacity'])); ?></p><?php endif; ?>
            <?php if (! empty($registration_state['can_register']) && ! empty($state['available'])) : ?><p><a class="ssf-am-button" href="<?php echo esc_url($this->meetings->registration_url()); ?>"><?php esc_html_e('Anmäl mig till middag eller aktivitet', 'ssf-member-portal'); ?></a></p><?php elseif (! empty($state['message'])) : ?><p class="ssf-am-message"><?php echo esc_html((string) $state['message']); ?></p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($show_day2) : ?>
        <span id="ssf-am-day2" class="ssf-am-anchor" aria-hidden="true"></span>
        <section id="<?php echo $show_dinner ? 'ssf-am-program-section' : 'ssf-am-program-heading'; ?>" class="ssf-am-section ssf-am-program" aria-labelledby="ssf-am-program-title">
            <h2 id="ssf-am-program-title"><?php esc_html_e('Program', 'ssf-member-portal'); ?></h2>
            <?php if ($visible_program) : ?><div class="ssf-am-activity-list">
                <?php $program_date = ''; foreach ($visible_program as $item) : $requires_registration = ! empty($item['requires_registration']); $state = $requires_registration ? ($choice_states[$item['key']] ?? array()) : array(); ?>
                    <?php if ($program_date !== $item['date']) : $program_date = $item['date']; ?><h3 class="ssf-am-program-day"><?php echo esc_html(sprintf(__('Dag %1$d · %2$s', 'ssf-member-portal'), (int) ($item['day'] ?? 1), $program_date ? wp_date('j F', strtotime($program_date . ' 12:00:00')) : '')); ?></h3><?php endif; ?>
                    <article class="ssf-am-activity">
                        <div class="ssf-am-activity__main"><div class="ssf-am-section-heading"><h4><?php echo esc_html($item['title']); ?></h4><?php if ($requires_registration) : ?><span class="ssf-am-badge"><?php esc_html_e('Anmälan krävs', 'ssf-member-portal'); ?></span><?php else : ?><span class="ssf-am-badge ssf-am-badge--open"><?php esc_html_e('Ingen anmälan', 'ssf-member-portal'); ?></span><?php endif; ?></div><?php if ($item['description']) : ?><p><?php echo esc_html($item['description']); ?></p><?php endif; ?></div>
                        <p class="ssf-am-activity__meta"><?php if ($item['start']) : ?><?php echo esc_html($item['start']); ?><?php if ($item['end']) : ?>–<?php echo esc_html($item['end']); ?><?php endif; ?><?php endif; ?><?php if ($item['location']) : ?><?php if ($item['start']) : ?><br><?php endif; ?><?php echo esc_html($item['location']); ?><?php endif; ?><?php if ($item['price']) : ?><br><?php echo esc_html($item['price']); ?><?php endif; ?><?php if ($requires_registration && ! empty($state['full'])) : ?><br><strong><?php esc_html_e('Fullbokad', 'ssf-member-portal'); ?></strong><?php elseif ($requires_registration && empty($state['registration_open'])) : ?><br><strong><?php echo esc_html((string) ($state['message'] ?? __('Anmälan är inte öppen', 'ssf-member-portal'))); ?></strong><?php elseif ($requires_registration && ! empty($state['capacity'])) : ?><br><?php echo esc_html(sprintf(__('%1$d av %2$d platser bokade', 'ssf-member-portal'), (int) $state['count'], (int) $state['capacity'])); ?><?php endif; ?></p>
                    </article>
                <?php endforeach; ?>
            </div><?php endif; ?>
            <?php if ($meeting['program_pdf_id']) : ?><p><a class="ssf-am-document-link" href="<?php echo esc_url(wp_get_attachment_url((int) $meeting['program_pdf_id'])); ?>"><?php esc_html_e('Öppna hela programmet som PDF', 'ssf-member-portal'); ?></a></p><?php endif; ?>
            <?php if (! empty($registration_state['can_register']) && array_filter($choices, static function (array $choice): bool { return 'activity' === ($choice['source'] ?? ''); })) : ?><p><a class="ssf-am-button" href="<?php echo esc_url($this->meetings->registration_url()); ?>"><?php esc_html_e('Välj middag och aktiviteter', 'ssf-member-portal'); ?></a></p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($show_motions) : ?><section id="ssf-am-motions" class="ssf-am-section"><h2><?php esc_html_e('Motioner', 'ssf-member-portal'); ?></h2><?php if ($meeting['motion_instructions']) : ?><div class="ssf-am-content"><?php echo wp_kses_post(wpautop($meeting['motion_instructions'])); ?></div><?php endif; ?><?php if ('upcoming' === $motion['state']) : ?><p><?php echo esc_html(sprintf(__('Motionstiden öppnar %s.', 'ssf-member-portal'), wp_date('j F Y, H:i', (int) $motion['opens_at'], wp_timezone()))); ?></p><?php elseif (in_array($motion['state'], array('open', 'late'), true)) : ?><p><?php esc_html_e('Motionstiden är öppen.', 'ssf-member-portal'); ?></p><p><a class="ssf-am-button" href="<?php echo esc_url($this->meetings->motion_url(array('meeting' => $meeting_post->ID))); ?>"><?php esc_html_e('Skicka motion', 'ssf-member-portal'); ?></a></p><?php elseif ('closed' === $motion['state']) : ?><p><?php echo esc_html(sprintf(__('Motionstiden stängde %s.', 'ssf-member-portal'), wp_date('j F Y, H:i', (int) $motion['closes_at'], wp_timezone()))); ?></p><?php else : ?><p class="ssf-am-message"><?php esc_html_e('Motionstiden har inte konfigurerats ännu.', 'ssf-member-portal'); ?></p><?php endif; ?></section><?php endif; ?>

    <?php if ($show_documents) : ?><section id="ssf-am-documents" class="ssf-am-section"><h2><?php esc_html_e('Handlingar', 'ssf-member-portal'); ?></h2><ul class="ssf-am-documents"><?php foreach ($documents as $document) : $file = get_attached_file((int) $document['attachment_id']); $size = $file && file_exists($file) ? size_format(filesize($file)) : ''; ?><li><a href="<?php echo esc_url(wp_get_attachment_url((int) $document['attachment_id'])); ?>"><strong><?php echo esc_html($document['title'] ?: get_the_title((int) $document['attachment_id'])); ?></strong><span><?php echo esc_html($document_types[$document['type']] ?? $document_types['other']); ?><?php if ($size) : ?> · <?php echo esc_html($size); ?><?php endif; ?></span></a></li><?php endforeach; ?></ul></section><?php endif; ?>

</section>
