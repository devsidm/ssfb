<?php

namespace SSF\Calendar;

if (! defined('ABSPATH')) {
    exit;
}

final class EventRepository
{
    public function events(array $args = array()): array
    {
        $args = wp_parse_args($args, array('range' => 'upcoming', 'limit' => 0));
        $range = 'past' === $args['range'] ? 'past' : 'upcoming';
        $sources = (array) apply_filters('ssf_calendar_event_sources', array('manual', 'annual_meeting'), $args);
        $events = array();
        if (in_array('manual', $sources, true)) {
            $events = array_merge($events, $this->manual_events($range));
        }
        if (in_array('annual_meeting', $sources, true)) {
            $events = array_merge($events, $this->annual_meeting_events($range));
        }
        usort($events, static function (array $a, array $b) use ($range): int {
            $comparison = strcmp($a['start_date'], $b['start_date']);
            return 'past' === $range ? -$comparison : $comparison;
        });
        if (! empty($args['limit'])) {
            $events = array_slice($events, 0, max(0, (int) $args['limit']));
        }
        return apply_filters('ssf_calendar_get_events', $events, $args);
    }

    public function manual_event(\WP_Post $post): array
    {
        $start_date = (string) get_post_meta($post->ID, '_ssf_calendar_start_date', true);
        $end_date = (string) get_post_meta($post->ID, '_ssf_calendar_end_date', true) ?: $start_date;
        return array(
            'id' => 'event-' . $post->ID,
            'post_id' => $post->ID,
            'source' => 'manual',
            'source_id' => $post->ID,
            'type' => (string) get_post_meta($post->ID, '_ssf_calendar_event_type', true) ?: 'event',
            'title' => get_the_title($post),
            'start_date' => $start_date,
            'start_time' => (string) get_post_meta($post->ID, '_ssf_calendar_start_time', true),
            'end_date' => $end_date,
            'end_time' => (string) get_post_meta($post->ID, '_ssf_calendar_end_time', true),
            'location' => (string) get_post_meta($post->ID, '_ssf_calendar_location', true),
            'excerpt' => $this->excerpt($post),
            'image_id' => (int) get_post_thumbnail_id($post),
            'permalink' => get_permalink($post),
            'event_url' => esc_url_raw((string) get_post_meta($post->ID, '_ssf_calendar_event_url', true)),
        );
    }

    public function date_label(array $event): string
    {
        $start = $this->date($event['start_date']);
        $end = $this->date($event['end_date'] ?: $event['start_date']);
        if (! $start || ! $end || $start->format('Y-m-d') === $end->format('Y-m-d')) {
            return $start ? wp_date('j F Y', $start->getTimestamp(), wp_timezone()) : '';
        }
        if ($start->format('Y') === $end->format('Y') && $start->format('m') === $end->format('m')) {
            return wp_date('j', $start->getTimestamp(), wp_timezone()) . '–' . wp_date('j F Y', $end->getTimestamp(), wp_timezone());
        }
        if ($start->format('Y') === $end->format('Y')) {
            return wp_date('j F', $start->getTimestamp(), wp_timezone()) . '–' . wp_date('j F Y', $end->getTimestamp(), wp_timezone());
        }
        return wp_date('j F Y', $start->getTimestamp(), wp_timezone()) . '–' . wp_date('j F Y', $end->getTimestamp(), wp_timezone());
    }

    public function datetime_value(array $event): string
    {
        return $event['start_date'] . ($event['start_time'] ? 'T' . $event['start_time'] : '');
    }

    private function manual_events(string $range): array
    {
        $today = current_time('Y-m-d');
        $query = new \WP_Query(array(
            'post_type' => EventPostType::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_key' => '_ssf_calendar_start_date',
            'orderby' => 'meta_value',
            'order' => 'past' === $range ? 'DESC' : 'ASC',
            'meta_query' => array(array(
                'key' => '_ssf_calendar_start_date',
                'value' => $today,
                'compare' => 'past' === $range ? '<' : '>=',
                'type' => 'DATE',
            )),
        ));
        $events = array();
        foreach ($query->posts as $post) {
            $events[] = $this->manual_event($post);
        }
        return $events;
    }

    private function annual_meeting_events(string $range): array
    {
        if (class_exists('SSF_Feature_Manager') && ! \SSF_Feature_Manager::can_access('annual_meetings')) {
            return array();
        }
        if (! post_type_exists('ssf_annual_meeting')) {
            return array();
        }
        $page_id = (int) get_option('ssf_member_portal_annual_meeting_page_id', 0);
        $meetings = get_posts(array('post_type' => 'ssf_annual_meeting', 'post_status' => 'publish', 'posts_per_page' => -1, 'meta_key' => '_ssf_am_start_at', 'orderby' => 'meta_value_num', 'order' => 'ASC'));
        $events = array();
        foreach ($meetings as $post) {
            if (! trim($post->post_title)) {
                continue;
            }
            $start_timestamp = (int) get_post_meta($post->ID, '_ssf_am_start_at', true);
            if (! $start_timestamp) {
                continue;
            }
            $start_date = wp_date('Y-m-d', $start_timestamp, wp_timezone());
            $today = current_time('Y-m-d');
            if (('past' === $range && $start_date >= $today) || ('upcoming' === $range && $start_date < $today)) {
                continue;
            }
            $end_timestamp = (int) get_post_meta($post->ID, '_ssf_am_end_at', true);
            $calendar_title = (string) get_post_meta($post->ID, '_ssf_am_calendar_title', true);
            $calendar_description = (string) get_post_meta($post->ID, '_ssf_am_calendar_description', true);
            $events[] = array(
                'id' => 'annual-meeting-' . $post->ID,
                'post_id' => 0,
                'source' => 'annual_meeting',
                'source_id' => $post->ID,
                'type' => 'annual_meeting',
                'title' => $calendar_title ?: get_the_title($post),
                'start_date' => $start_date,
                'start_time' => wp_date('H:i', $start_timestamp, wp_timezone()),
                'end_date' => $end_timestamp ? wp_date('Y-m-d', $end_timestamp, wp_timezone()) : $start_date,
                'end_time' => $end_timestamp ? wp_date('H:i', $end_timestamp, wp_timezone()) : '',
                'location' => (string) get_post_meta($post->ID, '_ssf_am_location', true),
                'excerpt' => $calendar_description ?: ((string) get_post_meta($post->ID, '_ssf_am_intro', true) ?: $this->excerpt($post)),
                'image_id' => (int) get_post_thumbnail_id($post),
                'permalink' => add_query_arg('meeting', $post->ID, $page_id ? get_permalink($page_id) : home_url('/arsmote/')),
                'event_url' => '',
            );
        }
        return $events;
    }

    private function excerpt(\WP_Post $post): string
    {
        $excerpt = has_excerpt($post) ? $post->post_excerpt : wp_strip_all_tags($post->post_content);
        return wp_trim_words($excerpt, 28);
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value . ' 12:00:00', wp_timezone());
        } catch (\Exception $exception) {
            return null;
        }
    }
}
