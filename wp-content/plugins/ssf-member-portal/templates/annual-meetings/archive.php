<?php
/** @var array $upcoming */
/** @var array $past */
/** @var SSF\MemberPortal\Modules\AnnualMeetings\Frontend $this */
?>
<section class="ssf-am-page ssf-am-archive" aria-label="<?php esc_attr_e('Årsmötesarkiv', 'ssf-member-portal'); ?>">
    <header class="ssf-am-intro"><p class="ssf-am-eyebrow"><?php esc_html_e('För medlemmar', 'ssf-member-portal'); ?></p><p class="ssf-am-lead"><?php esc_html_e('Här finns aktuella och tidigare årsmöten med program, aktiviteter och handlingar.', 'ssf-member-portal'); ?></p></header>
    <section aria-labelledby="ssf-am-upcoming-heading"><h2 id="ssf-am-upcoming-heading"><?php esc_html_e('Kommande årsmöte', 'ssf-member-portal'); ?></h2>
    <?php if ($upcoming) : foreach ($upcoming as $item) : $post = $item['post']; $meeting = $item['meeting']; ?><article class="ssf-am-archive-item"><p class="ssf-am-dates"><?php echo esc_html($this->date_range($meeting)); ?></p><h3><a href="<?php echo esc_url($this->meetings->meeting_url(array('meeting' => $post->ID))); ?>"><?php echo esc_html(get_the_title($post)); ?></a></h3><?php if ($meeting['location']) : ?><p><?php echo esc_html($meeting['location']); ?></p><?php endif; ?><a class="ssf-am-button" href="<?php echo esc_url($this->meetings->meeting_url(array('meeting' => $post->ID))); ?>"><?php esc_html_e('Läs mer', 'ssf-member-portal'); ?></a></article><?php endforeach; else : ?><p class="ssf-am-message"><?php esc_html_e('Information om nästa årsmöte publiceras här.', 'ssf-member-portal'); ?></p><?php endif; ?></section>
    <?php if ($past) : ?><section class="ssf-am-archive__past" aria-labelledby="ssf-am-past-heading"><h2 id="ssf-am-past-heading"><?php esc_html_e('Tidigare årsmöten', 'ssf-member-portal'); ?></h2><ul><?php foreach ($past as $item) : $post = $item['post']; $meeting = $item['meeting']; ?><li><a href="<?php echo esc_url($this->meetings->meeting_url(array('meeting' => $post->ID))); ?>"><?php echo esc_html(get_the_title($post)); ?></a><span><?php echo esc_html($this->date_range($meeting)); ?></span></li><?php endforeach; ?></ul></section><?php endif; ?>
</section>
