<?php
/** @var array $upcoming */
/** @var array $past */
/** @var bool $show_intro */
/** @var SSF\Calendar\Frontend $this */
?>
<section class="ssf-calendar" aria-label="<?php esc_attr_e('Kalender', 'ssf-calendar'); ?>">
    <?php if ($show_intro) : ?><header class="ssf-calendar__intro"><p><?php esc_html_e('Här hittar du kommande möten, seminarier, årsmöten och andra aktiviteter inom Sveriges Segelfartygsförbund.', 'ssf-calendar'); ?></p></header><?php endif; ?>
    <section aria-labelledby="ssf-calendar-upcoming"><h2 id="ssf-calendar-upcoming"><?php esc_html_e('Kommande', 'ssf-calendar'); ?></h2><?php if ($upcoming) : ?><div class="ssf-calendar-grid"><?php foreach ($upcoming as $event) { $this->card($event); } ?></div><?php else : ?><p class="ssf-calendar-empty"><?php esc_html_e('Inga kommande aktiviteter är publicerade just nu.', 'ssf-calendar'); ?></p><?php endif; ?></section>
    <?php if ($past) : ?><section class="ssf-calendar__past" aria-labelledby="ssf-calendar-past"><h2 id="ssf-calendar-past"><?php esc_html_e('Tidigare event', 'ssf-calendar'); ?></h2><div class="ssf-calendar-grid"><?php foreach ($past as $event) { $this->card($event); } ?></div></section><?php endif; ?>
</section>
