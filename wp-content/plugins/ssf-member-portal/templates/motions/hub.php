<?php
/** @var array $period */
/** @var SSF\MemberPortal\Modules\Motions\MotionDeadline $deadline */
/** @var string $form_url */
/** @var string $status_url */
?>
<section class="ssf-motion-page" aria-labelledby="ssf-motion-hub-heading">
    <header class="ssf-motion-page__intro">
        <p class="ssf-motion-page__eyebrow"><?php esc_html_e('För medlemmar', 'ssf-member-portal'); ?></p>
        <h1 id="ssf-motion-hub-heading"><?php esc_html_e('Motioner till årsmötet', 'ssf-member-portal'); ?></h1>
        <p><?php esc_html_e('Här kan du skicka in en motion till Sveriges Segelfartygsförbunds kommande årsmöte och följa statusen för en redan inskickad motion.', 'ssf-member-portal'); ?></p>
    </header>

    <?php if (! empty($period['meeting']['year'])) : ?>
        <div class="ssf-motion-message">
            <h2><?php echo esc_html(sprintf(__('Motioner till SSF:s årsmöte %d', 'ssf-member-portal'), (int) $period['meeting']['year'])); ?></h2>
            <?php if ($period['closes_at']) : ?><p><?php esc_html_e('Motionstiden är öppen till:', 'ssf-member-portal'); ?> <strong><?php echo esc_html($deadline->format((int) $period['closes_at'])); ?></strong></p><?php endif; ?>
            <?php if (in_array($period['state'], array('open', 'late'), true)) : ?>
                <p><a class="ssf-motion-button" href="<?php echo esc_url($form_url); ?>"><?php esc_html_e('Skicka in motion', 'ssf-member-portal'); ?></a></p>
            <?php elseif ('upcoming' === $period['state']) : ?>
                <p><?php esc_html_e('Motionstiden öppnar:', 'ssf-member-portal'); ?> <strong><?php echo esc_html($deadline->format((int) $period['opens_at'])); ?></strong></p>
            <?php else : ?>
                <p><?php esc_html_e('Motionsperioden är för närvarande stängd.', 'ssf-member-portal'); ?></p>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="ssf-motion-message"><p><?php esc_html_e('Motionsperioden är för närvarande stängd. Information om nästa motionsperiod publiceras här.', 'ssf-member-portal'); ?></p></div>
    <?php endif; ?>

    <p class="ssf-motion-message"><strong><?php esc_html_e('Har du redan skickat in en motion?', 'ssf-member-portal'); ?></strong> <?php esc_html_e('Använd den personliga länk som skickades till din e-post för att följa statusen.', 'ssf-member-portal'); ?> <a href="<?php echo esc_url($status_url); ?>"><?php esc_html_e('Följ min motion', 'ssf-member-portal'); ?></a></p>
</section>
