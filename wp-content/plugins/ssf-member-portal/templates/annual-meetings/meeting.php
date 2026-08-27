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

    <dl class="ssf-am-facts">
        <div><dt><?php esc_html_e('Datum', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html($this->date_range($meeting)); ?></dd></div>
        <?php if ($meeting['location']) : ?><div><dt><?php esc_html_e('Plats', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html($meeting['location']); ?><?php if ($meeting['address']) : ?><br><?php echo nl2br(esc_html($meeting['address'])); ?><?php endif; ?></dd></div><?php endif; ?>
        <?php if ($meeting['registration_closes_at']) : ?><div><dt><?php esc_html_e('Sista anmälningsdag', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html(wp_date('j F Y, H:i', (int) $meeting['registration_closes_at'], wp_timezone())); ?></dd></div><?php endif; ?>
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

    <?php if ($meeting['contact_name'] || $meeting['contact_email']) : ?><p class="ssf-am-contact"><strong><?php esc_html_e('Kontakt', 'ssf-member-portal'); ?></strong><br><?php echo esc_html($meeting['contact_name']); ?> <?php if ($meeting['contact_email']) : ?><a href="mailto:<?php echo esc_attr(antispambot($meeting['contact_email'])); ?>"><?php echo esc_html(antispambot($meeting['contact_email'])); ?></a><?php endif; ?></p><?php endif; ?>

    <?php if ($this->meetings->is_registration_open($meeting)) : ?><p><a class="ssf-am-button" href="<?php echo esc_url($this->meetings->registration_url()); ?>"><?php esc_html_e('Anmäl dig', 'ssf-member-portal'); ?></a></p><?php else : ?><p class="ssf-am-message"><?php esc_html_e('Anmälan är stängd.', 'ssf-member-portal'); ?></p><?php endif; ?>
</section>
