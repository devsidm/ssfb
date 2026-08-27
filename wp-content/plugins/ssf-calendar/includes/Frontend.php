<?php

namespace SSF\Calendar;

if (! defined('ABSPATH')) {
    exit;
}

final class Frontend
{
    private EventRepository $events;

    public function __construct(EventRepository $events)
    {
        $this->events = $events;
        add_shortcode('ssf_calendar', array($this, 'calendar_shortcode'));
        add_shortcode('ssf_calendar_upcoming', array($this, 'upcoming_shortcode'));
        add_filter('the_content', array($this, 'append_calendar_to_page'), 20);
        add_filter('single_template', array($this, 'single_template'));
        add_action('wp_enqueue_scripts', array($this, 'assets'));
    }

    public function assets(): void
    {
        if (! $this->feature_enabled()) {
            return;
        }
        if (! is_page('kalender') && ! is_singular(EventPostType::POST_TYPE)) {
            return;
        }
        wp_enqueue_style('ssf-calendar', SSF_CALENDAR_URL . 'assets/css/calendar.css', array(), SSF_CALENDAR_VERSION);
    }

    public function calendar_shortcode(): string
    {
        if (! $this->feature_enabled()) {
            return '';
        }
        return $this->render_calendar(true);
    }

    private function render_calendar(bool $show_intro): string
    {
        $upcoming = $this->events->events(array('range' => 'upcoming'));
        $past = $this->events->events(array('range' => 'past', 'limit' => 12));
        ob_start();
        include SSF_CALENDAR_PATH . 'templates/calendar.php';
        return (string) ob_get_clean();
    }

    public function upcoming_shortcode(array $atts): string
    {
        if (! $this->feature_enabled()) {
            return '';
        }
        $atts = shortcode_atts(array('count' => 3), $atts, 'ssf_calendar_upcoming');
        $events = $this->events->events(array('range' => 'upcoming', 'limit' => max(1, absint($atts['count']))));
        if (! $events) {
            return '';
        }
        ob_start();
        ?><section class="ssf-calendar-preview" aria-label="<?php esc_attr_e('Kommande aktiviteter', 'ssf-calendar'); ?>"><div class="ssf-calendar-grid ssf-calendar-grid--compact"><?php foreach ($events as $event) { $this->card($event); } ?></div><p><a class="ssf-calendar-link" href="<?php echo esc_url(home_url('/kalender/')); ?>"><?php esc_html_e('Se hela kalendern', 'ssf-calendar'); ?></a></p></section><?php
        return (string) ob_get_clean();
    }

    public function append_calendar_to_page(string $content): string
    {
        if (! $this->feature_enabled()) {
            return $content;
        }
        if (! is_main_query() || ! in_the_loop() || ! is_page('kalender')) {
            return $content;
        }
        $raw_content = (string) get_post_field('post_content', get_queried_object_id());
        if (has_shortcode($raw_content, 'ssf_calendar')) {
            return $content;
        }
        return $content . $this->render_calendar(! trim(wp_strip_all_tags($raw_content)));
    }

    public function single_template(string $template): string
    {
        if (! $this->feature_enabled()) {
            return $template;
        }
        if (is_singular(EventPostType::POST_TYPE)) {
            $candidate = SSF_CALENDAR_PATH . 'templates/event.php';
            if (is_readable($candidate)) {
                return $candidate;
            }
        }
        return $template;
    }

    public function card(array $event): void
    {
        $date_label = $this->events->date_label($event);
        ?>
        <article class="ssf-calendar-card ssf-calendar-card--<?php echo esc_attr($event['source']); ?>">
            <?php if ($event['image_id']) : ?><a class="ssf-calendar-card__image" href="<?php echo esc_url($event['permalink']); ?>"><?php echo wp_get_attachment_image((int) $event['image_id'], 'medium_large', false, array('loading' => 'lazy')); ?></a><?php endif; ?>
            <div class="ssf-calendar-card__body"><time datetime="<?php echo esc_attr($this->events->datetime_value($event)); ?>"><?php echo esc_html($date_label); ?></time><h3><a href="<?php echo esc_url($event['permalink']); ?>"><?php echo esc_html($event['title']); ?></a></h3><?php if ($event['location']) : ?><p class="ssf-calendar-card__location"><?php echo esc_html($event['location']); ?></p><?php endif; ?><?php if ($event['excerpt']) : ?><p><?php echo esc_html($event['excerpt']); ?></p><?php endif; ?><a class="ssf-calendar-link" href="<?php echo esc_url($event['permalink']); ?>"><?php esc_html_e('Läs mer', 'ssf-calendar'); ?></a></div>
        </article>
        <?php
    }

    private function feature_enabled(): bool
    {
        return ! class_exists('SSF_Features') || \SSF_Features::enabled('calendar');
    }
}
