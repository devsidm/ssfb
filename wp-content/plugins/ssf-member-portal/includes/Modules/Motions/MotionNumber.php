<?php

namespace SSF\MemberPortal\Modules\Motions;

if (! defined('ABSPATH')) {
    exit;
}

final class MotionNumber
{
    public function next(int $meeting_id, int $year): string
    {
        $key = 'ssf_member_portal_motion_sequence_' . max(1, $meeting_id);
        $current = (int) get_option($key, 0);
        if (! $current) {
            $motions = get_posts(array(
                'post_type' => MotionPostType::POST_TYPE,
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => -1,
                'meta_query' => array(
                    'relation' => 'OR',
                    array('key' => '_ssf_mp_annual_meeting_id', 'value' => $meeting_id),
                    array('key' => '_ssf_mp_meeting_id', 'value' => $meeting_id),
                ),
            ));
            foreach ($motions as $motion_id) {
                if (preg_match('/^' . preg_quote((string) $year, '/') . '-(\d+)$/', (string) get_post_meta($motion_id, '_ssf_mp_motion_number', true), $matches)) {
                    $current = max($current, (int) $matches[1]);
                }
            }
        }
        $next = max(1, $current + 1);
        update_option($key, $next, false);
        return sprintf('%d-%03d', $year, $next);
    }
}
