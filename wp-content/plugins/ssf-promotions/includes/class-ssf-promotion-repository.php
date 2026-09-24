<?php

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Promotion_Repository
{
    public const POST_TYPE = 'ssf_promotion';
    public const CACHE_VERSION_OPTION = 'ssf_promotions_cache_version';

    private SSF_Promotion_Relations $relations;

    public function __construct(SSF_Promotion_Relations $relations)
    {
        $this->relations = $relations;
    }

    public function active(array $args = array()): array
    {
        $args = wp_parse_args($args, array(
            'location' => 'home',
            'max' => 3,
            'type' => '',
        ));
        $location = sanitize_key((string) $args['location']) ?: 'home';
        $max = min(20, max(1, absint($args['max'])));
        $type = sanitize_key((string) $args['type']);
        $ids = $this->active_ids($location);
        $posts = array();

        foreach ($ids as $post_id) {
            if ($type && $type !== get_post_meta($post_id, '_ssf_promotion_type', true)) {
                continue;
            }
            $post = get_post($post_id);
            if ($post instanceof WP_Post) {
                $posts[] = $post;
            }
            if (count($posts) >= $max) {
                break;
            }
        }

        return $posts;
    }

    public function active_ids(string $location = 'home'): array
    {
        $location = sanitize_key($location) ?: 'home';
        // A cached list can keep a promotion visible past its end time or hide
        // one after its start time. Evaluate the current site time on each view.
        $now = current_datetime()->getTimestamp();
        $query = new WP_Query(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 100,
            'fields' => 'ids',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'relation' => 'OR',
                    array('key' => '_ssf_promotion_archived', 'compare' => 'NOT EXISTS'),
                    array('key' => '_ssf_promotion_archived', 'value' => '1', 'compare' => '!='),
                ),
                array(
                    'relation' => 'OR',
                    array('key' => '_ssf_promotion_start', 'compare' => 'NOT EXISTS'),
                    array('key' => '_ssf_promotion_start', 'value' => $now, 'compare' => '<=', 'type' => 'NUMERIC'),
                ),
                array(
                    'relation' => 'OR',
                    array('key' => '_ssf_promotion_end', 'compare' => 'NOT EXISTS'),
                    array('key' => '_ssf_promotion_end', 'value' => $now, 'compare' => '>=', 'type' => 'NUMERIC'),
                ),
                array(
                    'relation' => 'OR',
                    array('key' => '_ssf_promotion_locations', 'compare' => 'NOT EXISTS'),
                    array('key' => '_ssf_promotion_locations', 'value' => '"' . $location . '"', 'compare' => 'LIKE'),
                    array('key' => '_ssf_promotion_locations', 'value' => '"all"', 'compare' => 'LIKE'),
                ),
            ),
        ));

        $ids = array_map('absint', $query->posts);
        usort($ids, function (int $left, int $right): int {
            $priority = (int) get_post_meta($right, '_ssf_promotion_priority', true) <=> (int) get_post_meta($left, '_ssf_promotion_priority', true);
            if (0 !== $priority) {
                return $priority;
            }
            $start = (int) get_post_meta($right, '_ssf_promotion_start', true) <=> (int) get_post_meta($left, '_ssf_promotion_start', true);
            return 0 !== $start ? $start : $right <=> $left;
        });

        return $ids;
    }

    public function data(int $post_id): array
    {
        $related_type = sanitize_key((string) get_post_meta($post_id, '_ssf_promotion_related_type', true));
        $related_id = (int) get_post_meta($post_id, '_ssf_promotion_related_id', true);
        $anchor = sanitize_key((string) get_post_meta($post_id, '_ssf_promotion_anchor', true));
        $relation = $this->relations->resolve($related_type, $related_id, $anchor);
        $link_mode = (string) get_post_meta($post_id, '_ssf_promotion_link_mode', true);
        $page_id = (int) get_post_meta($post_id, '_ssf_promotion_page_id', true);
        $manual_url = (string) get_post_meta($post_id, '_ssf_promotion_url', true);
        $url = ! empty($relation['url']) ? (string) $relation['url'] : $manual_url;
        if ('none' === $link_mode) {
            $url = '';
        } elseif ('page' === $link_mode) {
            $page = get_post($page_id);
            $url = $page && 'page' === $page->post_type && 'publish' === $page->post_status ? (string) get_permalink($page) : '';
        } elseif ('url' === $link_mode) {
            $url = $manual_url;
        }
        $locations = get_post_meta($post_id, '_ssf_promotion_locations', true);
        if (! is_array($locations) || ! $locations) {
            $locations = array('home');
        }

        return array(
            'id' => $post_id,
            'type' => sanitize_key((string) get_post_meta($post_id, '_ssf_promotion_type', true)) ?: 'information',
            'priority' => (int) get_post_meta($post_id, '_ssf_promotion_priority', true) ?: 50,
            'start' => (int) get_post_meta($post_id, '_ssf_promotion_start', true),
            'end' => (int) get_post_meta($post_id, '_ssf_promotion_end', true),
            'cta_text' => (string) get_post_meta($post_id, '_ssf_promotion_cta_text', true),
            'url' => $url,
            'manual_url' => $manual_url,
            'link_mode' => $link_mode,
            'page_id' => $page_id,
            'related_type' => $related_type,
            'related_id' => $related_id,
            'anchor' => $anchor,
            'relation' => $relation,
            'relation_missing' => (bool) ($related_type && $related_id && ! $relation),
            'layout' => sanitize_key((string) get_post_meta($post_id, '_ssf_promotion_layout', true)) ?: 'banner',
            'locations' => array_values(array_intersect(array('home', 'annual', 'all'), array_map('sanitize_key', $locations))),
            'show_countdown' => (bool) get_post_meta($post_id, '_ssf_promotion_show_countdown', true),
            'archived' => (bool) get_post_meta($post_id, '_ssf_promotion_archived', true),
            'needs_review' => (bool) get_post_meta($post_id, '_ssf_promotion_needs_review', true),
        );
    }

    public function status(int $post_id): string
    {
        $post = get_post($post_id);
        if (! $post || self::POST_TYPE !== $post->post_type) {
            return 'draft';
        }
        $data = $this->data($post_id);
        if ($data['archived']) {
            return 'archived';
        }
        if ('publish' !== $post->post_status) {
            return 'future' === $post->post_status ? 'scheduled' : 'draft';
        }
        $now = current_datetime()->getTimestamp();
        if ($data['start'] && $data['start'] > $now) {
            return 'scheduled';
        }
        if ($data['end'] && $data['end'] < $now) {
            return 'expired';
        }
        return 'active';
    }

    public function status_label(string $status): string
    {
        $labels = array(
            'draft' => __('Utkast', 'ssf-promotions'),
            'scheduled' => __('Schemalagd', 'ssf-promotions'),
            'active' => __('Aktiv', 'ssf-promotions'),
            'expired' => __('Utgången', 'ssf-promotions'),
            'archived' => __('Arkiverad', 'ssf-promotions'),
        );
        return $labels[$status] ?? $labels['draft'];
    }

    public function invalidate_cache(): void
    {
        update_option(self::CACHE_VERSION_OPTION, (int) get_option(self::CACHE_VERSION_OPTION, 1) + 1, false);
    }
}
