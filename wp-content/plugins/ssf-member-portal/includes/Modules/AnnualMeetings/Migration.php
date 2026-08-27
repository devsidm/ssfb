<?php

namespace SSF\MemberPortal\Modules\AnnualMeetings;

use SSF\MemberPortal\Modules\Motions\MotionPostType;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Relates legacy records without deleting source data or calendar events.
 */
final class Migration
{
    public const REPORT_OPTION = 'ssf_member_portal_annual_meeting_migration_report';

    private Module $meetings;

    public function __construct(Module $meetings)
    {
        $this->meetings = $meetings;
    }

    public function run(): array
    {
        $by_year = array();
        foreach ($this->meetings->all() as $meeting) {
            $year = (int) $this->meetings->data((int) $meeting->ID)['year'];
            if ($year) {
                update_post_meta($meeting->ID, '_ssf_am_sharepoint_year', $year);
                update_post_meta($meeting->ID, '_ssf_mp_sharepoint_folder', 'Årsmöten/' . $year . '/Motioner/');
            }
            if ($year) {
                $by_year[$year][] = (int) $meeting->ID;
            }
        }

        $report = array(
            'ran_at' => gmdate('c'),
            'motions_linked' => 0,
            'registrations_linked' => 0,
            'motions_unresolved' => 0,
            'registrations_unresolved' => 0,
            'calendar_duplicates_changed' => 0,
        );

        $motions = get_posts(array('post_type' => MotionPostType::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1));
        foreach ($motions as $motion) {
            $meeting_id = $this->motion_meeting_id($motion, $by_year);
            if (! $meeting_id) {
                $report['motions_unresolved']++;
                continue;
            }
            $changed = (int) get_post_meta($motion->ID, '_ssf_mp_annual_meeting_id', true) !== $meeting_id;
            update_post_meta($motion->ID, '_ssf_mp_annual_meeting_id', $meeting_id);
            if ((int) $motion->post_parent !== $meeting_id) {
                wp_update_post(array('ID' => $motion->ID, 'post_parent' => $meeting_id));
                $changed = true;
            }
            if ($changed) {
                $report['motions_linked']++;
            }
        }

        $registrations = get_posts(array('post_type' => RegistrationPostType::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1));
        foreach ($registrations as $registration) {
            $meeting_id = $this->valid_meeting_id((int) $registration->post_parent);
            if (! $meeting_id) {
                $meeting_id = $this->valid_meeting_id((int) get_post_meta($registration->ID, '_ssf_am_annual_meeting_id', true));
            }
            if (! $meeting_id) {
                $report['registrations_unresolved']++;
                continue;
            }
            if ((int) get_post_meta($registration->ID, '_ssf_am_annual_meeting_id', true) !== $meeting_id) {
                update_post_meta($registration->ID, '_ssf_am_annual_meeting_id', $meeting_id);
                $report['registrations_linked']++;
            }
            if ((int) $registration->post_parent !== $meeting_id) {
                wp_update_post(array('ID' => $registration->ID, 'post_parent' => $meeting_id));
            }
        }

        $this->seed_motion_sequences();
        update_option(self::REPORT_OPTION, $report, false);
        return $report;
    }

    private function motion_meeting_id(\WP_Post $motion, array $by_year): int
    {
        foreach (array('_ssf_mp_annual_meeting_id', '_ssf_mp_meeting_id') as $key) {
            $meeting_id = $this->valid_meeting_id((int) get_post_meta($motion->ID, $key, true));
            if ($meeting_id) {
                return $meeting_id;
            }
        }
        $meeting_id = $this->valid_meeting_id((int) $motion->post_parent);
        if ($meeting_id) {
            return $meeting_id;
        }
        $year = (int) get_post_meta($motion->ID, '_ssf_mp_meeting_year', true);
        return 1 === count($by_year[$year] ?? array()) ? (int) $by_year[$year][0] : 0;
    }

    private function valid_meeting_id(int $meeting_id): int
    {
        return $meeting_id && Module::POST_TYPE === get_post_type($meeting_id) ? $meeting_id : 0;
    }

    private function seed_motion_sequences(): void
    {
        foreach ($this->meetings->all() as $meeting) {
            $meeting_id = (int) $meeting->ID;
            $year = (int) $this->meetings->data($meeting_id)['year'];
            $last = 0;
            $motions = get_posts(array('post_type' => MotionPostType::POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_ssf_mp_annual_meeting_id', 'meta_value' => $meeting_id));
            foreach ($motions as $motion_id) {
                if (preg_match('/^' . preg_quote((string) $year, '/') . '-(\d+)$/', (string) get_post_meta($motion_id, '_ssf_mp_motion_number', true), $matches)) {
                    $last = max($last, (int) $matches[1]);
                }
            }
            update_option('ssf_member_portal_motion_sequence_' . $meeting_id, $last, false);
        }
    }
}
