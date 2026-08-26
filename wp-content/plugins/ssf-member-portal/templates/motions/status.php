<?php
/** @var WP_Post|null $motion */
/** @var SSF\MemberPortal\Modules\Motions\MotionDeadline $deadline */
?>
<section class="ssf-motion-page" aria-labelledby="ssf-motion-status-heading">
    <?php if (! $motion) : ?>
        <h1 id="ssf-motion-status-heading"><?php esc_html_e('Motionen hittades inte', 'ssf-member-portal'); ?></h1>
        <p><?php esc_html_e('Kontrollera att du använder hela den personliga länken från bekräftelsen.', 'ssf-member-portal'); ?></p>
    <?php else : ?>
        <p class="ssf-motion-page__eyebrow"><?php esc_html_e('SSF Medlemsportal', 'ssf-member-portal'); ?></p>
        <h1 id="ssf-motion-status-heading"><?php echo esc_html('Motion ' . get_post_meta($motion->ID, '_ssf_mp_motion_number', true)); ?></h1>
        <dl class="ssf-motion-status-list">
            <div><dt><?php esc_html_e('Inskickad', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html($deadline->format((int) get_post_meta($motion->ID, '_ssf_mp_submitted_at', true))); ?></dd></div>
            <div><dt><?php esc_html_e('Motionsfrist', 'ssf-member-portal'); ?></dt><dd><?php echo esc_html($deadline->format((int) get_post_meta($motion->ID, '_ssf_mp_submission_deadline_at', true))); ?></dd></div>
            <div><dt><?php esc_html_e('Status', 'ssf-member-portal'); ?></dt><dd><strong><?php echo esc_html(SSF\MemberPortal\Modules\Motions\MotionStatus::label((string) get_post_meta($motion->ID, '_ssf_mp_status', true))); ?></strong></dd></div>
        </dl>
        <?php if ((bool) get_post_meta($motion->ID, '_ssf_mp_submitted_after_deadline', true)) : ?><p class="ssf-motion-message ssf-motion-message--warning"><strong><?php esc_html_e('Inkommen efter motionsfrist', 'ssf-member-portal'); ?></strong></p><?php endif; ?>
    <?php endif; ?>
</section>
