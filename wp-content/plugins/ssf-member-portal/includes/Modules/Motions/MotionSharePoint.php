<?php

namespace SSF\MemberPortal\Modules\Motions;

use SSF\MemberPortal\Core\Logger;
use SSF\MemberPortal\Integrations\Microsoft365\Authentication;
use SSF\MemberPortal\Integrations\Microsoft365\GraphClient;
use SSF\MemberPortal\Integrations\Microsoft365\SharePoint;
use SSF\MemberPortal\Modules\AnnualMeetings\Module as AnnualMeetings;

if (! defined('ABSPATH')) {
    exit;
}

final class MotionSharePoint
{
    private const RETRY_DELAYS = array(5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS);
    private const STATUS_RETRY_DELAYS = array(5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS);
    private const POLL_HOOK = 'ssf_motion_sharepoint_status_poll';
    private const POLL_LOCK = 'ssf_motion_status_poll_lock';
    private const POLL_DIAGNOSTICS_OPTION = 'ssf_member_portal_motion_status_poll_diagnostics';

    private SharePoint $sharepoint;
    private ?MotionStatusService $statuses = null;

    public function __construct()
    {
        $this->sharepoint = new SharePoint(new GraphClient(new Authentication()));
        add_action('ssf_member_portal_sync_motion', array($this, 'sync'));
        add_action('ssf_member_portal_sync_motion_status', array($this, 'sync_status'));
        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action(self::POLL_HOOK, array($this, 'poll_statuses'));
    }

    public function set_status_service(MotionStatusService $statuses): void
    {
        $this->statuses = $statuses;
    }

    public function cron_schedules(array $schedules): array
    {
        $schedules['ssf_thirty_minutes'] = array(
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => __('Var 30:e minut', 'ssf-member-portal'),
        );
        return $schedules;
    }

    public function register(): void
    {
        if (! wp_next_scheduled(self::POLL_HOOK)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'ssf_thirty_minutes', self::POLL_HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::POLL_HOOK);
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

            $item = $this->sharepoint->upload_motion_attachment($attachment_id, $motion_id, $year, $number, (string) $title);
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
            update_post_meta($motion_id, '_ssf_mp_graph_list_id', $item['sharepoint_list_id']);
            update_post_meta($motion_id, '_ssf_mp_graph_list_item_id', $item['sharepoint_list_item_id']);
            update_post_meta($motion_id, '_ssf_mp_sharepoint_web_url', $item['web_url']);
            update_post_meta($motion_id, '_ssf_mp_sharepoint_filename', $item['filename']);
            update_post_meta($motion_id, '_ssf_mp_sharepoint_uploaded_at', $item['uploaded_at']);
            update_post_meta($motion_id, '_ssf_mp_sharepoint_last_status', MotionStatus::label(MotionStatus::INKOMMEN));
            update_post_meta($motion_id, '_ssf_mp_sharepoint_last_checked_at', gmdate('c'));
            update_post_meta($motion_id, '_ssf_mp_sharepoint_last_modified', $item['last_modified']);
            update_post_meta($motion_id, '_ssf_mp_sharepoint_etag', $item['etag']);
            if (! empty($item['schema_warning'])) {
                update_post_meta($motion_id, '_ssf_mp_sharepoint_schema_warning', $item['schema_warning']);
            } else {
                delete_post_meta($motion_id, '_ssf_mp_sharepoint_schema_warning');
            }
            if (! empty($item['sharepoint_list_item_id'])) {
                update_post_meta($motion_id, '_ssf_mp_sharepoint_list_item_id', $item['sharepoint_list_item_id']);
            }
        }

        update_post_meta($motion_id, '_ssf_mp_sharepoint_items', $items);
        update_post_meta($motion_id, '_ssf_mp_sharepoint_status', 'synced');
        delete_post_meta($motion_id, '_ssf_mp_sharepoint_last_error');
        if ('waiting_for_document' === get_post_meta($motion_id, '_ssf_mp_sharepoint_status_sync', true)) {
            $this->queue_status_update($motion_id);
        }
        Logger::add('motion_sharepoint_synced', array('motion_id' => $motion_id, 'attachments' => count($attachments)));
    }

    public function diagnostics()
    {
        return $this->sharepoint->diagnostics();
    }

    public function ensure_status_schema()
    {
        return $this->sharepoint->ensure_motion_status_schema();
    }

    public function status_schema_diagnostics()
    {
        return $this->sharepoint->motion_status_schema_diagnostics();
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

    /**
     * Only locally initiated status changes are placed on the outbound queue.
     */
    public function queue_status_update(int $motion_id): void
    {
        if (! $this->sharepoint->enabled()) {
            update_post_meta($motion_id, '_ssf_mp_sharepoint_status_sync', 'not_configured');
            return;
        }

        update_post_meta($motion_id, '_ssf_mp_sharepoint_status_sync', 'pending');
        if (! wp_next_scheduled('ssf_member_portal_sync_motion_status', array($motion_id))) {
            wp_schedule_single_event(time() + 10, 'ssf_member_portal_sync_motion_status', array($motion_id));
        }
    }

    public function sync_status(int $motion_id): void
    {
        $motion = get_post($motion_id);
        if (! $motion || MotionPostType::POST_TYPE !== $motion->post_type) {
            return;
        }

        $items = (array) get_post_meta($motion_id, '_ssf_mp_sharepoint_items', true);
        $documents = array();
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['drive_item_id'])) {
                continue;
            }
            $documents[] = array(
                'drive_item_id' => (string) $item['drive_item_id'],
                'list_id' => (string) ($item['sharepoint_list_id'] ?? ''),
                'list_item_id' => (string) ($item['sharepoint_list_item_id'] ?? ''),
            );
        }
        if (! $documents) {
            $drive_item_id = (string) get_post_meta($motion_id, '_ssf_mp_graph_drive_item_id', true);
            if ($drive_item_id) {
                $documents[] = array(
                    'drive_item_id' => $drive_item_id,
                    'list_id' => (string) get_post_meta($motion_id, '_ssf_mp_graph_list_id', true),
                    'list_item_id' => (string) (get_post_meta($motion_id, '_ssf_mp_graph_list_item_id', true) ?: get_post_meta($motion_id, '_ssf_mp_sharepoint_list_item_id', true)),
                );
            }
        }
        if (! $documents) {
            update_post_meta($motion_id, '_ssf_mp_sharepoint_status_sync', 'waiting_for_document');
            return;
        }

        $status = MotionStatus::canonical((string) get_post_meta($motion_id, '_ssf_mp_status', true));
        if (! $status) {
            update_post_meta($motion_id, '_ssf_mp_sharepoint_status_sync', 'error');
            update_post_meta($motion_id, '_ssf_mp_sharepoint_status_last_error', __('Motionens status saknas eller är ogiltig.', 'ssf-member-portal'));
            return;
        }

        foreach ($documents as $document) {
            $result = $this->sharepoint->update_motion_status($document['drive_item_id'], $status, $document['list_id'], $document['list_item_id']);
            if (is_wp_error($result)) {
                $attempts = (int) get_post_meta($motion_id, '_ssf_mp_sharepoint_status_attempts', true) + 1;
                update_post_meta($motion_id, '_ssf_mp_sharepoint_status_attempts', $attempts);
                update_post_meta($motion_id, '_ssf_mp_sharepoint_status_sync', 'error');
                update_post_meta($motion_id, '_ssf_mp_sharepoint_status_last_error', $result->get_error_message());
                Logger::add('motion_sharepoint_status_failed', array('motion_id' => $motion_id, 'attempt' => $attempts, 'error' => $result->get_error_code()));
                if ($attempts <= count(self::STATUS_RETRY_DELAYS)) {
                    wp_schedule_single_event(time() + self::STATUS_RETRY_DELAYS[$attempts - 1], 'ssf_member_portal_sync_motion_status', array($motion_id));
                }
                return;
            }
        }

        update_post_meta($motion_id, '_ssf_mp_sharepoint_status_sync', 'synced');
        update_post_meta($motion_id, '_ssf_mp_sharepoint_status_synced_at', gmdate('c'));
        update_post_meta($motion_id, '_ssf_mp_sharepoint_status_attempts', 0);
        delete_post_meta($motion_id, '_ssf_mp_sharepoint_status_last_error');
        Logger::add('motion_sharepoint_status_synced', array('motion_id' => $motion_id, 'status' => $status));
    }

    /**
     * Reads each current/coming motion directly from its saved list item. This
     * is deliberately separate from the webhook, but both write through the
     * same MotionStatusService and are therefore idempotent.
     */
    public function poll_statuses(): array
    {
        if (get_transient(self::POLL_LOCK)) {
            return array('ok' => true, 'skipped' => 'locked');
        }
        set_transient(self::POLL_LOCK, 1, 10 * MINUTE_IN_SECONDS);

        $summary = array(
            'ok' => true,
            'timestamp' => gmdate('c'),
            'checked' => 0,
            'changed' => 0,
            'emails_sent' => 0,
            'errors' => 0,
            'unknown_statuses' => 0,
        );
        try {
            if (! $this->sharepoint->enabled() || ! $this->statuses) {
                $summary['ok'] = false;
                $summary['errors'] = 1;
                return $summary;
            }

            foreach ($this->pollable_motion_ids() as $motion_id) {
                ++$summary['checked'];
                $result = $this->poll_motion((int) $motion_id);
                if (is_wp_error($result)) {
                    ++$summary['errors'];
                    continue;
                }
                if (! empty($result['unknown_status'])) {
                    ++$summary['unknown_statuses'];
                }
                if ('updated' === ($result['result'] ?? '')) {
                    ++$summary['changed'];
                    $summary['emails_sent'] += ! empty($result['email_sent']) ? 1 : 0;
                }
            }
            return $summary;
        } finally {
            update_option(self::POLL_DIAGNOSTICS_OPTION, $summary, false);
            delete_transient(self::POLL_LOCK);
        }
    }

    public function poll_motion(int $motion_id)
    {
        if (! $this->statuses) {
            return new \WP_Error('motion_status_service_missing', __('Statusynkronisering är inte tillgänglig.', 'ssf-member-portal'));
        }
        $list_id = (string) get_post_meta($motion_id, '_ssf_mp_graph_list_id', true);
        $list_item_id = (string) (get_post_meta($motion_id, '_ssf_mp_graph_list_item_id', true) ?: get_post_meta($motion_id, '_ssf_mp_sharepoint_list_item_id', true));
        if (! $list_item_id) {
            return new \WP_Error('sharepoint_list_item_missing', __('Motionen saknar SharePoint-listkoppling.', 'ssf-member-portal'));
        }

        $remote = $this->sharepoint->get_motion_status($list_id, $list_item_id);
        update_post_meta($motion_id, '_ssf_mp_sharepoint_last_checked_at', gmdate('c'));
        if (is_wp_error($remote)) {
            update_post_meta($motion_id, '_ssf_mp_sharepoint_status_poll_error', $remote->get_error_message());
            Logger::add('motion_sharepoint_status_poll_failed', array('motion_id' => $motion_id, 'error' => $remote->get_error_code()));
            return $remote;
        }

        update_post_meta($motion_id, '_ssf_mp_sharepoint_last_status', $remote['status']);
        update_post_meta($motion_id, '_ssf_mp_sharepoint_last_modified', $remote['last_modified']);
        update_post_meta($motion_id, '_ssf_mp_sharepoint_etag', $remote['etag']);
        delete_post_meta($motion_id, '_ssf_mp_sharepoint_status_poll_error');

        $status = MotionStatus::canonical((string) $remote['status']);
        if (! $status) {
            $warning = sprintf(__('Okänd SharePoint-status: %s', 'ssf-member-portal'), (string) $remote['status']);
            update_post_meta($motion_id, '_ssf_mp_sharepoint_status_warning', $warning);
            Logger::add('motion_sharepoint_unknown_status', array('motion_id' => $motion_id, 'status' => $remote['status']));
            return array('result' => 'no_change', 'unknown_status' => true);
        }

        delete_post_meta($motion_id, '_ssf_mp_sharepoint_status_warning');
        return $this->statuses->update($motion_id, $status, 'sharepoint', array(
            'changed_at' => $remote['last_modified'] ?: gmdate('c'),
            'sharepoint_list_item_id' => $list_item_id,
            'sharepoint_file_url' => (string) get_post_meta($motion_id, '_ssf_mp_sharepoint_web_url', true),
        ));
    }

    public function status_poll_diagnostics(): array
    {
        return (array) get_option(self::POLL_DIAGNOSTICS_OPTION, array());
    }

    private function pollable_motion_ids(): array
    {
        $active_id = (int) get_option('ssf_member_portal_active_meeting_id', 0);
        $meeting_ids = $active_id ? array($active_id) : array();
        $current_year = (int) wp_date('Y', null, wp_timezone());
        $meetings = get_posts(array(
            'post_type' => AnnualMeetings::POST_TYPE,
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'meta_query' => array(array('key' => '_ssf_mp_meeting_year', 'value' => $current_year, 'compare' => '>=')),
        ));
        $meeting_ids = array_values(array_unique(array_filter(array_merge($meeting_ids, array_map('absint', $meetings)))));
        if (! $meeting_ids) {
            return array();
        }

        $motions = get_posts(array(
            'post_type' => MotionPostType::POST_TYPE,
            'post_status' => 'private',
            'fields' => 'ids',
            'posts_per_page' => 200,
            'meta_query' => array(
                'relation' => 'AND',
                array('key' => '_ssf_mp_annual_meeting_id', 'value' => $meeting_ids, 'compare' => 'IN'),
                array('key' => '_ssf_mp_sharepoint_status', 'value' => 'synced'),
                array('key' => '_ssf_mp_status', 'value' => MotionStatus::AVSLUTAD, 'compare' => '!='),
                array(
                    'relation' => 'OR',
                    array('key' => '_ssf_mp_graph_list_item_id', 'compare' => 'EXISTS'),
                    array('key' => '_ssf_mp_sharepoint_list_item_id', 'compare' => 'EXISTS'),
                ),
            ),
        ));

        return array_map('absint', $motions);
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
