<?php
/** @var array $meeting */
/** @var WP_Post $meeting_post */
/** @var array $choices */
/** @var array $choice_states */
/** @var array $calendar */
/** @var array $registration_state */
/** @var array $motion */
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
$program = array_values(array_filter((array) $meeting['program'], static function (array $item): bool {
    return ! empty($item['visible']);
}));
$has_program_dinner = (bool) array_filter($program, static function (array $item): bool {
    return 'dinner' === ($item['type'] ?? '') || 'dinner' === ($item['key'] ?? '');
});

// Older meetings may store dinner separately. Present it in the same chronological program.
if ($this->meetings->module_enabled($meeting, 'dinner') && ! $has_program_dinner && ! empty($meeting['dinner']['start_at'])) {
    $dinner = (array) $meeting['dinner'];
    $dinner_date = wp_date('Y-m-d', (int) $dinner['start_at'], wp_timezone());
    $meeting_date = ! empty($meeting['start_at']) ? wp_date('Y-m-d', (int) $meeting['start_at'], wp_timezone()) : $dinner_date;
    $day = max(1, (int) round((strtotime($dinner_date . ' 12:00:00') - strtotime($meeting_date . ' 12:00:00')) / DAY_IN_SECONDS) + 1);
    $program[] = array(
        'key' => 'dinner',
        'day' => $day,
        'date' => $dinner_date,
        'start' => wp_date('H:i', (int) $dinner['start_at'], wp_timezone()),
        'end' => ! empty($dinner['end_at']) ? wp_date('H:i', (int) $dinner['end_at'], wp_timezone()) : '',
        'title' => $dinner['title'] ?: __('Middag', 'ssf-member-portal'),
        'description' => (string) ($dinner['description'] ?? ''),
        'location' => (string) ($dinner['location'] ?? ''),
        'price' => (string) ($dinner['price'] ?? ''),
        'requires_registration' => 1,
        'order' => count($program),
    );
}

usort($program, static function (array $a, array $b): int {
    return ((int) ($a['day'] ?? 1) <=> (int) ($b['day'] ?? 1))
        ?: strcmp((string) ($a['start'] ?? ''), (string) ($b['start'] ?? ''))
        ?: ((int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0));
});

$show_program = $this->meetings->module_enabled($meeting, 'day2') && $program;
$show_motions = $this->meetings->module_enabled($meeting, 'motions') && ! empty($meeting['motions_public']);
$documents = array_values(array_filter((array) $meeting['documents'], static function (array $item): bool {
    return ! empty($item['visible']) && ! empty($item['attachment_id']);
}));
$show_documents = $this->meetings->module_enabled($meeting, 'documents') && $documents;
$document_types = array('agenda' => 'Dagordning', 'annual_report' => 'Verksamhetsberättelse', 'financial_report' => 'Ekonomisk rapport', 'budget' => 'Budget', 'motions' => 'Motioner', 'board_response' => 'Styrelsens yttranden', 'minutes' => 'Protokoll', 'other' => 'Dokument');
$resources = array();

if ($show_invitation && ! empty($invitation['pdf_id'])) {
    $resources[] = array('id' => (int) $invitation['pdf_id'], 'title' => $invitation['title'] ?: __('Kallelse', 'ssf-member-portal'), 'type' => __('Kallelse', 'ssf-member-portal'));
}
if ($this->meetings->module_enabled($meeting, 'day2') && ! empty($meeting['program_pdf_id'])) {
    $resources[] = array('id' => (int) $meeting['program_pdf_id'], 'title' => __('Program', 'ssf-member-portal'), 'type' => __('Program som PDF', 'ssf-member-portal'));
}
if ($show_documents) {
    foreach ($documents as $document) {
        $resources[] = array(
            'id' => (int) $document['attachment_id'],
            'title' => $document['title'] ?: get_the_title((int) $document['attachment_id']),
            'type' => $document_types[$document['type']] ?? $document_types['other'],
        );
    }
}

$seen_resource_ids = array();
$resources = array_values(array_filter($resources, static function (array $resource) use (&$seen_resource_ids): bool {
    if (empty($resource['id']) || isset($seen_resource_ids[$resource['id']])) {
        return false;
    }
    $seen_resource_ids[$resource['id']] = true;
    return true;
}));
?>
<section class="ssf-am-page" aria-labelledby="ssf-am-heading">
    <header class="ssf-am-intro">
        <h1 id="ssf-am-heading"><?php echo esc_html($page_title); ?></h1>
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
        <?php if ($this->meetings->module_enabled($meeting, 'calendar') && $calendar) : ?>
            <div class="ssf-am-actions">
                <details class="ssf-am-calendar-menu">
                    <summary class="ssf-am-button ssf-am-button--secondary"><?php esc_html_e('Lägg till i kalender', 'ssf-member-portal'); ?></summary>
                    <div class="ssf-am-calendar-menu__options" aria-label="<?php esc_attr_e('Välj kalender', 'ssf-member-portal'); ?>">
                        <a href="<?php echo esc_url($calendar['outlook']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Outlook', 'ssf-member-portal'); ?></a>
                        <a href="<?php echo esc_url($calendar['google']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Google Kalender', 'ssf-member-portal'); ?></a>
                        <a href="<?php echo esc_url($calendar['apple']); ?>"><?php esc_html_e('Apple Kalender / iCal', 'ssf-member-portal'); ?></a>
                        <a href="<?php echo esc_url($calendar['ics']); ?>"><?php esc_html_e('Ladda ner kalenderfil (.ics)', 'ssf-member-portal'); ?></a>
                    </div>
                </details>
            </div>
        <?php endif; ?>
    </header>

    <?php do_action('ssf_annual_meeting_after_header', $meeting_post, $meeting); ?>

    <?php if (has_post_thumbnail($meeting_post)) : ?><div class="ssf-am-hero-image"><?php echo get_the_post_thumbnail($meeting_post, 'large'); ?></div><?php endif; ?>

    <?php if ($meeting_post->post_content) : ?><div class="ssf-am-content ssf-am-content--intro"><?php echo apply_filters('the_content', $meeting_post->post_content); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div><?php endif; ?>

    <?php if ($show_invitation && ! empty($invitation['text'])) : ?><div class="ssf-am-invitation-text"><?php echo wp_kses_post(wpautop((string) $invitation['text'])); ?></div><?php endif; ?>

    <?php if ($resources) : ?>
        <div class="ssf-am-document-grid" aria-label="<?php esc_attr_e('Dokument för årsmötet', 'ssf-member-portal'); ?>">
            <?php foreach ($resources as $resource) : ?>
                <a class="ssf-am-document-card" href="<?php echo esc_url(wp_get_attachment_url((int) $resource['id'])); ?>">
                    <span class="ssf-am-document-card__type"><?php echo esc_html($resource['type']); ?></span>
                    <strong><?php echo esc_html($resource['title']); ?></strong>
                    <span class="ssf-am-document-card__action"><?php esc_html_e('Öppna dokument', 'ssf-member-portal'); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($show_motions) : ?>
        <div class="ssf-am-motion-row" id="ssf-am-motions">
            <?php if (! empty($meeting['motion_closes_at'])) : ?><span><?php esc_html_e('Sista motionsdag:', 'ssf-member-portal'); ?> <strong><?php echo esc_html(wp_date('j F Y, H:i', (int) $meeting['motion_closes_at'], wp_timezone())); ?></strong></span><?php endif; ?>
            <?php if (in_array($motion['state'], array('open', 'late'), true)) : ?>
                <a href="<?php echo esc_url($this->meetings->motion_url(array('meeting' => $meeting_post->ID))); ?>"><?php esc_html_e('Lägg motion', 'ssf-member-portal'); ?></a>
            <?php elseif ('upcoming' === $motion['state']) : ?>
                <span><?php echo esc_html(sprintf(__('Motioner kan lämnas från %s.', 'ssf-member-portal'), wp_date('j F Y, H:i', (int) $motion['opens_at'], wp_timezone()))); ?></span>
            <?php elseif ('closed' === $motion['state']) : ?>
                <span><?php esc_html_e('Motionstiden har gått ut.', 'ssf-member-portal'); ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($show_program) : ?>
        <section id="ssf-am-program" class="ssf-am-program ssf-am-program--classic" aria-labelledby="ssf-am-program-heading">
            <h2 id="ssf-am-program-heading"><?php esc_html_e('Program', 'ssf-member-portal'); ?></h2>
            <?php if ($program) : ?><div class="ssf-am-activity-list">
                <?php $program_day = ''; foreach ($program as $item) : $day_key = (string) ($item['day'] ?? 1) . '|' . (string) ($item['date'] ?? ''); $requires_registration = ! empty($item['requires_registration']); $state = $requires_registration ? ($choice_states[$item['key']] ?? array()) : array(); ?>
                    <?php if ($program_day !== $day_key) : $program_day = $day_key; ?><h3 class="ssf-am-program-day"><?php echo esc_html(sprintf(__('Dag %1$d%2$s', 'ssf-member-portal'), (int) ($item['day'] ?? 1), ! empty($item['date']) ? ' · ' . wp_date('j F', strtotime($item['date'] . ' 12:00:00')) : '')); ?></h3><?php endif; ?>
                    <article class="ssf-am-activity">
                        <div class="ssf-am-activity__time"><?php if (! empty($item['start'])) : ?><time><?php echo esc_html($item['start']); ?><?php if (! empty($item['end'])) : ?>–<?php echo esc_html($item['end']); ?><?php endif; ?></time><?php endif; ?></div>
                        <div class="ssf-am-activity__main">
                            <div class="ssf-am-activity__title"><h4><?php echo esc_html($item['title']); ?></h4><?php if ($requires_registration) : ?><span class="ssf-am-badge"><?php esc_html_e('Anmälan krävs', 'ssf-member-portal'); ?></span><?php endif; ?></div>
                            <?php if (! empty($item['description'])) : ?><p><?php echo esc_html($item['description']); ?></p><?php endif; ?>
                            <?php $meta = array_filter(array((string) ($item['location'] ?? ''), (string) ($item['price'] ?? ''))); if ($meta) : ?><p class="ssf-am-activity__details"><?php echo esc_html(implode(' · ', $meta)); ?></p><?php endif; ?>
                            <?php if ($requires_registration && ! empty($state['full'])) : ?><p class="ssf-am-activity__status"><?php esc_html_e('Fullbokad', 'ssf-member-portal'); ?></p><?php elseif ($requires_registration && empty($state['registration_open']) && ! empty($state['message'])) : ?><p class="ssf-am-activity__status"><?php echo esc_html((string) $state['message']); ?></p><?php elseif ($requires_registration && ! empty($state['capacity'])) : ?><p class="ssf-am-activity__status"><?php echo esc_html(sprintf(__('%1$d av %2$d platser bokade', 'ssf-member-portal'), (int) $state['count'], (int) $state['capacity'])); ?></p><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div><?php endif; ?>
        </section>
    <?php endif; ?>

    <section id="ssf-am-registration" class="ssf-am-registration-cta" aria-label="<?php esc_attr_e('Anmälan', 'ssf-member-portal'); ?>">
        <div><strong><?php esc_html_e('Anmälan till middag och aktiviteter', 'ssf-member-portal'); ?></strong><p><?php esc_html_e('Du behöver inte anmäla dig till själva årsmötet. Anmälan gäller endast middag och valfria aktiviteter.', 'ssf-member-portal'); ?></p></div>
        <?php if (! empty($registration_state['can_register'])) : ?>
            <a class="ssf-am-button" href="<?php echo esc_url($this->meetings->registration_url()); ?>"><?php esc_html_e('Gå till anmälan', 'ssf-member-portal'); ?></a>
        <?php else : ?>
            <span class="ssf-am-badge"><?php echo esc_html((string) ($registration_state['label'] ?? '')); ?></span>
        <?php endif; ?>
    </section>
    <?php if (empty($registration_state['can_register']) && ! empty($registration_state['message'])) : ?><p class="ssf-am-message" role="status"><?php echo esc_html((string) $registration_state['message']); ?></p><?php endif; ?>

    <p class="ssf-am-contact"><strong><?php esc_html_e('Har du frågor om årsmötet?', 'ssf-member-portal'); ?></strong><br><a class="ssf-am-button ssf-am-button--secondary" href="<?php echo esc_url($contact_url); ?>"><?php esc_html_e('Kontakta styrelsen', 'ssf-member-portal'); ?></a></p>
</section>
