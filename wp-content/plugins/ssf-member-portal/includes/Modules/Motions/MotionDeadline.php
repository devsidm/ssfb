<?php

namespace SSF\MemberPortal\Modules\Motions;

use SSF\MemberPortal\Modules\AnnualMeetings\Module as AnnualMeetings;

if (! defined('ABSPATH')) {
    exit;
}

final class MotionDeadline
{
    private AnnualMeetings $meetings;

    public function __construct(AnnualMeetings $meetings)
    {
        $this->meetings = $meetings;
    }

    public function active_meeting(): array
    {
        $id = (int) get_option('ssf_member_portal_active_meeting_id', 0);
        $meeting = $this->meeting($id);
        if (! $meeting['id']) {
            foreach ($this->meetings->all() as $post) {
                $meeting = $this->meeting((int) $post->ID);
                if ($meeting['id']) {
                    break;
                }
            }
        }
        return $meeting;
    }

    public function meeting(int $meeting_id): array
    {
        $post = $meeting_id ? get_post($meeting_id) : null;
        if (! $post || AnnualMeetings::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status) {
            return array('id' => 0, 'year' => 0, 'meeting_date' => '', 'motion_opens_at' => 0, 'motion_closes_at' => 0, 'sharepoint_folder' => '');
        }
        return $this->meetings->data((int) $post->ID);
    }

    public function state(?array $meeting = null): array
    {
        $meeting = $meeting ?: $this->active_meeting();
        $now = (new \DateTimeImmutable('now', wp_timezone()))->getTimestamp();
        $opens = (int) $meeting['motion_opens_at'];
        $closes = (int) $meeting['motion_closes_at'];
        $override = ! empty($meeting['allow_late_motions']);

        $state = 'not_configured';
        if (! empty($meeting['year']) && $opens && $closes && $closes > $opens) {
            if ($now < $opens) {
                $state = 'upcoming';
            } elseif ($now <= $closes) {
                $state = 'open';
            } elseif ($override) {
                $state = 'late';
            } else {
                $state = 'closed';
            }
        }

        return array(
            'state' => $state,
            'meeting' => $meeting,
            'now' => $now,
            'opens_at' => $opens,
            'closes_at' => $closes,
            'override' => $override,
            'allowed' => in_array($state, array('open', 'late'), true),
        );
    }

    public function snapshot(array $period, int $submitted_at): array
    {
        return array(
            'meeting_id' => (int) $period['meeting']['id'],
            'meeting_year' => (int) $period['meeting']['year'],
            'submission_open_at' => (int) $period['opens_at'],
            'submission_deadline_at' => (int) $period['closes_at'],
            'submitted_at' => $submitted_at,
            'submitted_after_deadline' => 'late' === $period['state'] ? 1 : 0,
        );
    }

    public function format(int $timestamp): string
    {
        return $timestamp ? wp_date('j F Y \\k\\l. H:i', $timestamp, wp_timezone()) : '';
    }

    public function time_remaining(int $deadline): string
    {
        $seconds = max(0, $deadline - (new \DateTimeImmutable('now', wp_timezone()))->getTimestamp());
        $days = (int) floor($seconds / DAY_IN_SECONDS);
        $hours = (int) floor(($seconds % DAY_IN_SECONDS) / HOUR_IN_SECONDS);
        return sprintf(_n('%d dag', '%d dagar', $days, 'ssf-member-portal'), $days) . ', ' . sprintf(_n('%d timme', '%d timmar', $hours, 'ssf-member-portal'), $hours);
    }
}
