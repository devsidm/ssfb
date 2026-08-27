<?php
/** @var WP_Post $post */
get_header();
while (have_posts()) : the_post();
    $repository = new \SSF\Calendar\EventRepository();
    $event = $repository->manual_event(get_post());
?>
<article id="post-<?php the_ID(); ?>" <?php post_class('ssf-calendar-single'); ?>>
    <?php if ($event['image_id']) : ?><div class="ssf-calendar-single__image"><?php echo wp_get_attachment_image((int) $event['image_id'], 'large'); ?></div><?php endif; ?>
    <header><time datetime="<?php echo esc_attr($repository->datetime_value($event)); ?>"><?php echo esc_html($repository->date_label($event)); ?></time><h1><?php the_title(); ?></h1><?php if ($event['location']) : ?><p class="ssf-calendar-single__location"><?php echo esc_html($event['location']); ?></p><?php endif; ?></header>
    <div class="entry-content"><?php the_content(); ?></div>
    <?php if ($event['event_url']) : ?><p class="ssf-calendar-single__action"><a class="ssf-calendar-button" href="<?php echo esc_url($event['event_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Till evenemanget', 'ssf-calendar'); ?></a></p><?php endif; ?>
</article>
<?php endwhile; get_footer();
