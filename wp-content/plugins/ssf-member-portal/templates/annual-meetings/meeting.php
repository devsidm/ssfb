<?php
/** @var array $meeting */
/** @var WP_Post $meeting_post */
/** @var SSF\MemberPortal\Modules\AnnualMeetings\Frontend $this */
?>
<section class="ssf-am-page" aria-labelledby="ssf-am-heading">
    <header class="ssf-am-intro">
        <p class="ssf-am-eyebrow"><?php esc_html_e('SSF Årsmöte', 'ssf-member-portal'); ?></p>
        <h1 id="ssf-am-heading"><?php echo esc_html(get_the_title($meeting_post)); ?></h1>
        <p class="ssf-am-dates"><?php echo esc_html($this->date_range($meeting)); ?></p>
        <?php if ($meeting['location']) : ?><p class="ssf-am-location"><?php echo esc_html($meeting['location']); ?></p><?php endif; ?>
        <?php if ($meeting['intro']) : ?><p class="ssf-am-lead"><?php echo esc_html($meeting['intro']); ?></p><?php endif; ?>
    </header>
    <?php if (has_post_thumbnail($meeting_post)) : ?><div class="ssf-am-hero-image"><?php echo get_the_post_thumbnail($meeting_post, 'large'); ?></div><?php endif; ?>

    <nav class="ssf-am-local-nav" aria-label="<?php esc_attr_e('Årsmötets innehåll', 'ssf-member-portal'); ?>"><a href="#ssf-am-overview"><?php esc_html_e('Översikt', 'ssf-member-portal'); ?></a><?php if ($meeting['program']) : ?><a href="#ssf-am-program-heading"><?php esc_html_e('Program', 'ssf-member-portal'); ?></a><?php endif; ?><a href="#ssf-am-registration"><?php esc_html_e('Anmälan', 'ssf-member-portal'); ?></a><?php if ($meeting['motions_public']) : ?><a href="#ssf-am-motions"><?php esc_html_e('Motioner', 'ssf-member-portal'); ?></a><?php endif; ?></nav>

    <dl id="ssf-am-overview" class="ssf-am-facts">
        <div><dt><?php esc_html_e('Datum', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html($this->date_range($meeting)); ?></dd></div>
        <?php if ($meeting['location']) : ?><div><dt><?php esc_html_e('Plats', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html($meeting['location']); ?><?php if ($meeting['address']) : ?><br><?php echo nl2br(esc_html($meeting['address'])); ?><?php endif; ?></dd></div><?php endif; ?>
        <?php if ($meeting['registration_closes_at']) : ?><div><dt><?php esc_html_e('Sista anmälningsdag', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html(wp_date('j F Y, H:i', (int) $meeting['registration_closes_at'], wp_timezone())); ?></dd></div><?php endif; ?>
        <?php if ($meeting['motion_closes_at'] && $meeting['motions_public']) : ?><div><dt><?php esc_html_e('Sista motionsdag', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html(wp_date('j F Y, H:i', (int) $meeting['motion_closes_at'], wp_timezone())); ?></dd></div><?php endif; ?>
    </dl>

    <?php if ($meeting_post->post_content) : ?><div class="ssf-am-content"><?php echo apply_filters('the_content', $meeting_post->post_content); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div><?php endif; ?>

    <?php if ($meeting['program']) : ?>
        <section class="ssf-am-program" aria-labelledby="ssf-am-program-heading">
            <h2 id="ssf-am-program-heading"><?php esc_html_e('Program', 'ssf-member-portal'); ?></h2>
            <ol>
            <?php foreach ($meeting['program'] as $item) : ?>
                <li><div><strong><?php echo esc_html($item['title']); ?></strong><?php if ($item['description']) : ?><p><?php echo esc_html($item['description']); ?></p><?php endif; ?></div><span><?php echo esc_html(trim($item['date'] . ' ' . $item['start'])); ?><?php if ($item['location']) : ?><br><?php echo esc_html($item['location']); ?><?php endif; ?></span></li>
            <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>

    <section id="ssf-am-registration" class="ssf-am-section"><h2><?php esc_html_e('Anmälan', 'ssf-member-portal'); ?></h2><?php if ($meeting['registration_closes_at']) : ?><p><?php esc_html_e('Sista anmälningsdag:', 'ssf-member-portal'); ?> <strong><?php echo esc_html(wp_date('j F Y, H:i', (int) $meeting['registration_closes_at'], wp_timezone())); ?></strong></p><?php endif; ?><?php if ($can_register) : ?><p><a class="ssf-am-button" href="<?php echo esc_url($this->meetings->registration_url()); ?>"><?php esc_html_e('Anmäl dig', 'ssf-member-portal'); ?></a></p><?php elseif ($meeting_post->ID === (int) get_option('ssf_member_portal_active_meeting_id', 0) && $meeting['registration_opens_at'] && time() < (int) $meeting['registration_opens_at']) : ?><p class="ssf-am-message"><?php echo esc_html(sprintf(__('Anmälan öppnar %s.', 'ssf-member-portal'), wp_date('j F Y, H:i', (int) $meeting['registration_opens_at'], wp_timezone()))); ?></p><?php elseif ($meeting_post->ID === (int) get_option('ssf_member_portal_active_meeting_id', 0)) : ?><p class="ssf-am-message"><?php esc_html_e('Anmälan är stängd.', 'ssf-member-portal'); ?></p><?php endif; ?></section>

    <?php if ($meeting['motions_public']) : ?><section id="ssf-am-motions" class="ssf-am-section"><h2><?php esc_html_e('Motioner', 'ssf-member-portal'); ?></h2><?php if ($meeting['motion_instructions']) : ?><div class="ssf-am-content"><?php echo wp_kses_post(wpautop($meeting['motion_instructions'])); ?></div><?php endif; ?><?php if ('upcoming' === $motion['state']) : ?><p><?php echo esc_html(sprintf(__('Motionstiden öppnar %s.', 'ssf-member-portal'), wp_date('j F Y, H:i', (int) $motion['opens_at'], wp_timezone()))); ?></p><?php elseif (in_array($motion['state'], array('open', 'late'), true)) : ?><p><?php esc_html_e('Motionstiden är öppen.', 'ssf-member-portal'); ?></p><p><a class="ssf-am-button" href="<?php echo esc_url($this->meetings->motion_url(array('meeting' => $meeting_post->ID))); ?>"><?php esc_html_e('Skicka motion', 'ssf-member-portal'); ?></a></p><?php elseif ('closed' === $motion['state']) : ?><p><?php echo esc_html(sprintf(__('Motionstiden stängde %s.', 'ssf-member-portal'), wp_date('j F Y, H:i', (int) $motion['closes_at'], wp_timezone()))); ?></p><?php else : ?><p class="ssf-am-message"><?php esc_html_e('Motionstiden har inte konfigurerats ännu.', 'ssf-member-portal'); ?></p><?php endif; ?><?php if ($meeting['motion_contact_email']) : ?><p><?php esc_html_e('Frågor om motioner:', 'ssf-member-portal'); ?> <a href="mailto:<?php echo esc_attr(antispambot($meeting['motion_contact_email'])); ?>"><?php echo esc_html(antispambot($meeting['motion_contact_email'])); ?></a></p><?php endif; ?></section><?php endif; ?>

    <?php if ($meeting['contact_name'] || $meeting['contact_email']) : ?><p class="ssf-am-contact"><strong><?php esc_html_e('Kontakt', 'ssf-member-portal'); ?></strong><br><?php echo esc_html($meeting['contact_name']); ?> <?php if ($meeting['contact_email']) : ?><a href="mailto:<?php echo esc_attr(antispambot($meeting['contact_email'])); ?>"><?php echo esc_html(antispambot($meeting['contact_email'])); ?></a><?php endif; ?></p><?php endif; ?>
</section>
