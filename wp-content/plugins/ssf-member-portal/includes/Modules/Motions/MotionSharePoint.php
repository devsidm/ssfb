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
    private const RETRY_DELAYS = array(5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS);

    private SharePoint $sharepoint;

    public function __construct()
    {
        $this->sharepoint = new SharePoint(new GraphClient(new Authentication()));
        add_action('ssf_member_portal_sync_motion', array($this, 'sync'));
    }

    public function queue(int $motion_id): void
    {
        if (! $this->sharepoint->enabled()) {
            $this->record_failure($motion_id, new \WP_Error('sharepoint_not_configured', __('SharePoint är inte konfigurerat på servern.', 'ssf-member-portal')), false);
            return;
        }

        update_post_meta($motion_id, '_ssf_mp_sharepoint_status', 'pending');
        if (! wp_next_scheduled('ssf_member_portal_sync_motion', array($motion_id))) {
            wp_schedule_single_event(time() + 30, 'ssf_member_portal_sync_motion', array($motion_id));
        }
    }

    public function retry(int $motion_id): void
    {
        if (! $this->sharepoint->enabled()) {
            $this->record_failure($motion_id, new \WP_Error('sharepoint_not_configured', __('SharePoint är inte konfigurerat på servern.', 'ssf-member-portal')), false);
            return;
        }

        wp_clear_scheduled_hook('ssf_member_portal_sync_motion', array($motion_id));
        update_post_meta($motion_id, '_ssf_mp_sharepoint_attempts', 0);
        update_post_meta($motion_id, '_ssf_mp_sharepoint_status', 'pending');
        delete_post_meta($motion_id, '_ssf_mp_sharepoint_last_error');
        wp_schedule_single_event(time() + 10, 'ssf_member_portal_sync_motion', array($motion_id));
    }

    public function sync(int $motion_id): void
    {
        if (! $this->sharepoint->enabled()) {
            $this->record_failure($motion_id, new \WP_Error('sharepoint_not_configured', __('SharePoint är inte konfigurerat på servern.', 'ssf-member-portal')), false);
            return;
        }

        $motion = get_post($motion_id);
        if (! $motion || MotionPostType::POST_TYPE !== $motion->post_type) {
            return;
        }

        update_post_meta($motion_id, '_ssf_mp_sharepoint_status', 'syncing');
        update_post_meta($motion_id, '_ssf_mp_sharepoint_last_attempt_at', gmdate('c'));

        $year = max(2000, (int) get_post_meta($motion_id, '_ssf_mp_meeting_year', true));
        $number = (string) get_post_meta($motion_id, '_ssf_mp_motion_number', true);
        $title = preg_replace('/^Motion\s+[^:]+:\s*/u', '', (string) $motion->post_title);
        $attachments = array_filter(array_map('absint', (array) get_post_meta($motion_id, '_ssf_mp_file_ids', true)));
        $items = (array) get_post_meta($motion_id, '_ssf_mp_sharepoint_items', true);

        foreach ($attachments as $attachment_id) {
            if ('synced' === ($items[$attachment_id]['sharepoint_sync_status'] ?? '')) {
                continue;
            }

            $item = $this->sharepoint->upload_motion_attachment($attachment_id, $year, $number, (string) $title);
            if (is_wp_error($item)) {
                $items[$attachment_id] = array(
                    'attachment_id' => $attachment_id,
                    'sharepoint_sync_status' => 'error',
                    'sharepoint_last_error' => $item->get_error_message(),
                    'sharepoint_last_attempt_at' => gmdate('c'),
                );
                update_post_meta($motion_id, '_ssf_mp_sharepoint_items', $items);
                $this->record_failure($motion_id, $item, true);
                return;
            }

            $item['attachment_id'] = $attachment_id;
            $item['sharepoint_sync_status'] = 'synced';
            $items[$attachment_id] = $item;
            update_post_meta($motion_id, '_ssf_mp_graph_drive_item_id', $item['drive_item_id']);
            update_post_meta($motion_id, '_ssf_mp_graph_drive_id', $item['drive_id']);
            update_post_meta($motion_id, '_ssf_mp_graph_parent_folder_id', $item['parent_folder_id']);
            update_post_meta($motion_id, '_ssf_mp_sharepoint_web_url', $item['web_url']);
            update_post_meta($motion_id, '_ssf_mp_sharepoint_filename', $item['filename']);
            update_post_meta($motion_id, '_ssf_mp_sharepoint_uploaded_at', $item['uploaded_at']);
        }

        update_post_meta($motion_id, '_ssf_mp_sharepoint_items', $items);
        update_post_meta($motion_id, '_ssf_mp_sharepoint_status', 'synced');
        delete_post_meta($motion_id, '_ssf_mp_sharepoint_last_error');
        Logger::add('motion_sharepoint_synced', array('motion_id' => $motion_id, 'attachments' => count($attachments)));
    }

    public function diagnostics()
    {
        return $this->sharepoint->diagnostics();
    }

    public function test_authentication()
    {
        return $this->sharepoint->test_authentication();
    }

    public function test_write_access(int $year)
    {
        return $this->sharepoint->test_write_access($year);
    }

    public function test_temporary_write()
    {
        return $this->sharepoint->test_temporary_write();
    }

    public function upload_test_file(int $year)
    {
        return $this->sharepoint->upload_test_file($year);
    }

    public function delete_test_file()
    {
        return $this->sharepoint->delete_test_file();
    }

    private function record_failure(int $motion_id, \WP_Error $error, bool $schedule_retry): void
    {
        $attempts = (int) get_post_meta($motion_id, '_ssf_mp_sharepoint_attempts', true);
        if ($schedule_retry) {
            ++$attempts;
            update_post_meta($motion_id, '_ssf_mp_sharepoint_attempts', $attempts);
        }

        update_post_meta($motion_id, '_ssf_mp_sharepoint_status', 'error');
        update_post_meta($motion_id, '_ssf_mp_sharepoint_last_error', $error->get_error_message());
        update_post_meta($motion_id, '_ssf_mp_sharepoint_last_attempt_at', gmdate('c'));
        Logger::add('motion_sharepoint_failed', array('motion_id' => $motion_id, 'attempt' => $attempts, 'error' => $error->get_error_code()));

        if ($schedule_retry && $attempts <= count(self::RETRY_DELAYS)) {
            wp_schedule_single_event(time() + self::RETRY_DELAYS[$attempts - 1], 'ssf_member_portal_sync_motion', array($motion_id));
        }
    }
}
