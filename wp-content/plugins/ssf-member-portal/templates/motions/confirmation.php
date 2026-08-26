<?php
/** @var WP_Post $motion */
/** @var SSF\MemberPortal\Modules\Motions\MotionDeadline $deadline */
?>
<section class="ssf-motion-page" aria-labelledby="ssf-motion-confirmation-heading">
    <p class="ssf-motion-page__eyebrow"><?php esc_html_e('SSF Medlemsportal', 'ssf-member-portal'); ?></p>
    <h1 id="ssf-motion-confirmation-heading"><?php esc_html_e('Tack, din motion är inskickad', 'ssf-member-portal'); ?></h1>
    <div class="ssf-motion-message ssf-motion-message--success">
        <p><strong><?php echo esc_html(get_post_meta($motion->ID, '_ssf_mp_motion_number', true)); ?></strong></p>
        <p><?php esc_html_e('En bekräftelse och en personlig länk har skickats till din e-postadress.', 'ssf-member-portal'); ?></p>
        <?php if ((bool) get_post_meta($motion->ID, '_ssf_mp_submitted_after_deadline', true)) : ?><p><strong><?php esc_html_e('Motionen är registrerad som inkommen efter motionsfrist.', 'ssf-member-portal'); ?></strong></p><?php endif; ?>
    </div>
    <p><?php esc_html_e('Spara denna personliga länk om du vill följa din motion senare:', 'ssf-member-portal'); ?><br><a href="<?php echo esc_url($status_url); ?>"><?php echo esc_html($status_url); ?></a></p>
</section>
