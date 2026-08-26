<?php

namespace SSF\MemberPortal\Modules\Motions;

use SSF\MemberPortal\Core\Logger;
use SSF\MemberPortal\Integrations\Microsoft365\Authentication;
use SSF\MemberPortal\Integrations\Microsoft365\GraphClient;
use SSF\MemberPortal\Integrations\Microsoft365\SharePoint;

if (! defined('ABSPATH')) {
    exit;
}

final class MotionSharePoint
{
    private SharePoint $sharepoint;

    public function __construct()
    {
        $this->sharepoint = new SharePoint(new GraphClient(new Authentication()));
        add_action('ssf_member_portal_sync_motion', array($this, 'sync'));
    }

    public function queue(int $motion_id): void
    {
        if (! $this->sharepoint->enabled()) {
            update_post_meta($motion_id, '_ssf_mp_sharepoint_status', 'not_configured');
            return;
        }
        update_post_meta($motion_id, '_ssf_mp_sharepoint_status', 'queued');
        if (! wp_next_scheduled('ssf_member_portal_sync_motion', array($motion_id))) {
            wp_schedule_single_event(time() + 30, 'ssf_member_portal_sync_motion', array($motion_id));
        }
    }

    public function sync(int $motion_id): void
    {
        if (! $this->sharepoint->enabled()) {
            return;
        }
        $year = (int) get_post_meta($motion_id, '_ssf_mp_meeting_year', true);
        $number = (string) get_post_meta($motion_id, '_ssf_mp_motion_number', true);
        $folder = 'Årsmöten/' . $year . '/Motioner/' . sanitize_file_name($number);
        $attachments = (array) get_post_meta($motion_id, '_ssf_mp_file_ids', true);
        $success = true;
        foreach ($attachments as $attachment_id) {
            if (! $this->sharepoint->upload((int) $attachment_id, $folder)) {
                $success = false;
                break;
            }
        }
        if ($success) {
            update_post_meta($motion_id, '_ssf_mp_sharepoint_status', 'synced');
            Logger::add('motion_sharepoint_synced', array('motion_id' => $motion_id));
            return;
        }
        $attempts = (int) get_post_meta($motion_id, '_ssf_mp_sharepoint_attempts', true) + 1;
        update_post_meta($motion_id, '_ssf_mp_sharepoint_attempts', $attempts);
        update_post_meta($motion_id, '_ssf_mp_sharepoint_status', 'retrying');
        Logger::add('motion_sharepoint_failed', array('motion_id' => $motion_id, 'attempt' => $attempts));
        if ($attempts < 5) {
            wp_schedule_single_event(time() + ($attempts * HOUR_IN_SECONDS), 'ssf_member_portal_sync_motion', array($motion_id));
        }
    }
}
