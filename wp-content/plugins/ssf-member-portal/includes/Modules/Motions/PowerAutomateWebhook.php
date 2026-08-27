<?php

namespace SSF\MemberPortal\Modules\Motions;

use SSF\MemberPortal\Core\Logger;
use SSF\MemberPortal\Integrations\Microsoft365\Configuration;

if (! defined('ABSPATH')) {
    exit;
}

final class PowerAutomateWebhook
{
    private const LAST_RESULT_OPTION = 'ssf_member_portal_power_automate_last_result';
    private const RATE_LIMIT = 120;
    private const RATE_WINDOW = 300;

    private MotionStatusService $statuses;

    public function __construct(MotionStatusService $statuses)
    {
        $this->statuses = $statuses;
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        if ('POST' !== $request->get_method()) {
            return $this->response(405, 'invalid_method');
        }
        if (! $this->within_rate_limit()) {
            return $this->response(429, 'rate_limit_exceeded');
        }
        if (! Configuration::inbound_sync_enabled()) {
            return $this->response(403, 'inbound_sync_disabled');
        }

        $secret = Configuration::webhook_secret();
        $provided = trim((string) $request->get_header('x-ssf-webhook-secret'));
        if (! $secret || ! $provided || ! hash_equals($secret, $provided)) {
            return $this->response(401, 'invalid_webhook_secret');
        }

        $content_type = strtolower(trim(explode(';', (string) $request->get_header('content-type'))[0]));
        if ('application/json' !== $content_type) {
            return $this->response(400, 'invalid_content_type');
        }

        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            return $this->response(400, 'invalid_json');
        }

        $motion_id_value = trim((string) ($payload['wordpress_motion_id'] ?? ''));
        $motion_number = sanitize_text_field((string) ($payload['motion_number'] ?? ''));
        $status = MotionStatus::canonical((string) ($payload['status'] ?? ''));
        if (! preg_match('/^\d+$/', $motion_id_value) || ! $motion_number) {
            return $this->response(400, 'invalid_request');
        }
        if (! $status) {
            return $this->response(400, 'invalid_status');
        }

        $motion_id = absint($motion_id_value);
        $motion = get_post($motion_id);
        if (! $motion || MotionPostType::POST_TYPE !== $motion->post_type) {
            return $this->response(404, 'motion_not_found');
        }
        if (! hash_equals((string) get_post_meta($motion_id, '_ssf_mp_motion_number', true), $motion_number)) {
            return $this->response(409, 'motion_identity_mismatch');
        }

        $file_url = esc_url_raw((string) ($payload['sharepoint_file_url'] ?? ''));
        if (! empty($payload['sharepoint_file_url']) && ! $file_url) {
            return $this->response(400, 'invalid_sharepoint_file_url');
        }

        $result = $this->statuses->update($motion_id, $status, 'power_automate', array(
            'changed_at' => (string) ($payload['changed_at'] ?? ''),
            'sharepoint_list_item_id' => sanitize_text_field((string) ($payload['sharepoint_list_item_id'] ?? '')),
            'sharepoint_file_url' => $file_url,
        ));
        if (is_wp_error($result)) {
            return $this->response(500, 'status_update_failed');
        }

        $response = array(
            'success' => true,
            'result' => $result['result'],
            'wordpress_motion_id' => $motion_id,
            'motion_number' => $motion_number,
            'old_status' => MotionStatus::label($result['old_status']),
            'new_status' => MotionStatus::label($result['new_status']),
        );
        $this->record_result(200, $result['result'], $motion_id, $motion_number, $result['old_status'], $result['new_status']);

        return new \WP_REST_Response($response, 200);
    }

    private function response(int $status, string $error): \WP_REST_Response
    {
        $this->record_result($status, $error);
        return new \WP_REST_Response(array('success' => false, 'error' => $error), $status);
    }

    private function record_result(int $http_status, string $result, int $motion_id = 0, string $motion_number = '', string $old_status = '', string $new_status = ''): void
    {
        $data = array(
            'timestamp' => gmdate('c'),
            'http_status' => $http_status,
            'result' => sanitize_key($result),
            'motion_id' => $motion_id,
            'motion_number' => sanitize_text_field($motion_number),
        );
        update_option(self::LAST_RESULT_OPTION, $data, false);
        Logger::add('power_automate_webhook', array(
            'http_status' => $http_status,
            'result' => $result,
            'motion_id' => $motion_id,
            'motion_number' => $motion_number,
            'old_status' => $old_status,
            'new_status' => $new_status,
            'source' => 'power_automate',
        ));
    }

    private function within_rate_limit(): bool
    {
        $ip = sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $key = 'ssf_mp_webhook_rate_' . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT) {
            return false;
        }
        set_transient($key, $count + 1, self::RATE_WINDOW);
        return true;
    }
}
