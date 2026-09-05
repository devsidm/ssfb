<?php
/** @var array $period */
/** @var SSF\MemberPortal\Modules\Motions\MotionDeadline $deadline */
?>
<section class="ssf-motion-page" aria-labelledby="ssf-motion-heading">
    <?php if (! empty($period['meeting']['year'])) : ?>
        <header class="ssf-motion-page__intro">
            <p class="ssf-motion-page__eyebrow"><?php esc_html_e('SSF Medlemsportal', 'ssf-member-portal'); ?></p>
            <h1 id="ssf-motion-heading"><?php echo esc_html(sprintf(__('Motioner till årsmötet %d', 'ssf-member-portal'), (int) $period['meeting']['year'])); ?></h1>
            <?php if ($period['closes_at']) : ?>
                <div class="ssf-motion-deadline" role="status">
                    <span><?php esc_html_e('Sista dag att lämna motion', 'ssf-member-portal'); ?></span>
                    <strong><?php echo esc_html($deadline->format((int) $period['closes_at'])); ?></strong>
                    <small><?php esc_html_e('Svensk tid', 'ssf-member-portal'); ?></small>
                </div>
            <?php endif; ?>
        </header>
    <?php else : ?>
        <header class="ssf-motion-page__intro"><p class="ssf-motion-page__eyebrow"><?php esc_html_e('SSF Medlemsportal', 'ssf-member-portal'); ?></p><h1 id="ssf-motion-heading"><?php esc_html_e('Motioner', 'ssf-member-portal'); ?></h1></header>
    <?php endif; ?>

    <?php if ($error) : ?><p class="ssf-motion-message ssf-motion-message--error" role="alert"><?php echo esc_html($error); ?></p><?php endif; ?>
    <?php if (! empty($period['meeting']['motion_instructions'])) : ?><div class="ssf-motion-message"><?php echo wp_kses_post(wpautop($period['meeting']['motion_instructions'])); ?></div><?php endif; ?>

    <?php if ('upcoming' === $period['state']) : ?>
        <div class="ssf-motion-message"><h2><?php esc_html_e('Motionsperioden är ännu inte öppen.', 'ssf-member-portal'); ?></h2><p><?php esc_html_e('Du kan lämna motion från:', 'ssf-member-portal'); ?> <strong><?php echo esc_html($deadline->format((int) $period['opens_at'])); ?></strong></p><p><?php esc_html_e('Sista motionsdag:', 'ssf-member-portal'); ?> <strong><?php echo esc_html($deadline->format((int) $period['closes_at'])); ?></strong></p></div>
    <?php elseif ('closed' === $period['state']) : ?>
        <div class="ssf-motion-message"><h2><?php esc_html_e('Motionsperioden är avslutad', 'ssf-member-portal'); ?></h2><p><?php echo esc_html(sprintf(__('Motionsperioden inför SSF:s årsmöte %d stängde:', 'ssf-member-portal'), (int) $period['meeting']['year'])); ?> <strong><?php echo esc_html($deadline->format((int) $period['closes_at'])); ?></strong></p><p><?php esc_html_e('Det går inte längre att lämna motion via formuläret.', 'ssf-member-portal'); ?></p></div>
    <?php elseif ('not_configured' === $period['state']) : ?>
        <div class="ssf-motion-message"><h2><?php esc_html_e('Motionsperioden är inte konfigurerad.', 'ssf-member-portal'); ?></h2><p><?php esc_html_e('Information om nästa motionsperiod publiceras här.', 'ssf-member-portal'); ?></p></div>
    <?php else : ?>
        <?php if ('late' === $period['state']) : ?>
            <p class="ssf-motion-message ssf-motion-message--warning"><strong><?php esc_html_e('Ordinarie motionsfrist har passerat.', 'ssf-member-portal'); ?></strong> <?php esc_html_e('En motion som skickas nu registreras som inkommen efter motionsfrist.', 'ssf-member-portal'); ?></p>
        <?php elseif ($period['closes_at'] - $period['now'] < 7 * DAY_IN_SECONDS) : ?>
            <p class="ssf-motion-message"><strong><?php echo esc_html(sprintf(__('Motionsperioden stänger om %s.', 'ssf-member-portal'), $deadline->time_remaining((int) $period['closes_at']))); ?></strong></p>
        <?php endif; ?>
        <p><?php esc_html_e('Din motion måste vara inskickad senast vid den angivna tidpunkten.', 'ssf-member-portal'); ?></p>
        <form class="ssf-motion-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="ssf_member_portal_submit_motion">
            <input type="hidden" name="meeting_id" value="<?php echo esc_attr((string) $period['meeting']['id']); ?>">
            <?php wp_nonce_field('ssf_member_portal_submit_motion', 'ssf_member_portal_motion_nonce'); ?>
            <p class="ssf-motion-form__honeypot" aria-hidden="true"><label><?php esc_html_e('Företag', 'ssf-member-portal'); ?><input type="text" name="company" tabindex="-1" autocomplete="off"></label></p>
            <div class="ssf-motion-form__grid">
                <p><label for="ssf-motion-name"><?php esc_html_e('Namn', 'ssf-member-portal'); ?> <span aria-hidden="true">*</span></label><input id="ssf-motion-name" type="text" name="name" required autocomplete="name"></p>
                <p><label for="ssf-motion-email"><?php esc_html_e('E-postadress', 'ssf-member-portal'); ?> <span aria-hidden="true">*</span></label><input id="ssf-motion-email" type="email" name="email" required autocomplete="email"></p>
                <p><label for="ssf-motion-phone"><?php esc_html_e('Telefonnummer', 'ssf-member-portal'); ?></label><input id="ssf-motion-phone" type="tel" name="phone" autocomplete="tel"></p>
            </div>
            <p><label for="ssf-motion-title"><?php esc_html_e('Rubrik för motionen', 'ssf-member-portal'); ?> <span aria-hidden="true">*</span></label><input id="ssf-motion-title" type="text" name="title" required></p>
            <p><label for="ssf-motion-content"><?php esc_html_e('Kort beskrivning av motionen', 'ssf-member-portal'); ?> <span aria-hidden="true">*</span></label><textarea id="ssf-motion-content" name="content" rows="5" maxlength="<?php echo esc_attr((string) SSF\MemberPortal\Modules\Motions\MotionService::DESCRIPTION_MAX_LENGTH); ?>" required></textarea><span class="ssf-motion-form__help"><?php esc_html_e('Sammanfatta motionens förslag och syfte kort. Den fullständiga motionen bifogas som dokument nedan.', 'ssf-member-portal'); ?></span></p>
            <p><label for="ssf-motion-files"><?php esc_html_e('Motion som dokument', 'ssf-member-portal'); ?> <span aria-hidden="true">*</span></label><input id="ssf-motion-files" type="file" name="ssf_motion_files[]" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" multiple required><span class="ssf-motion-form__help"><?php echo esc_html(sprintf(__('Bifoga hela motionen som PDF, DOC eller DOCX. Högst %d MB per fil.', 'ssf-member-portal'), (int) SSF\MemberPortal\Core\Settings::all()['max_upload_mb'])); ?></span></p>
            <p><button type="submit" class="ssf-motion-button"><?php esc_html_e('Skicka motion', 'ssf-member-portal'); ?></button></p>
        </form>
    <?php endif; ?>
</section>
