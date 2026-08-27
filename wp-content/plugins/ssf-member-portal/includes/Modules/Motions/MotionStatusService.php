<?php

namespace SSF\MemberPortal\Modules\Motions;

use SSF\MemberPortal\Core\Logger;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Owns all motion status writes so incoming and manual changes share history.
 */
final class MotionStatusService
{
    private MotionSharePoint $sharepoint;
    private MotionMailer $mailer;

    public function __construct(MotionSharePoint $sharepoint, MotionMailer $mailer)
    {
        $this->sharepoint = $sharepoint;
        $this->mailer = $mailer;
    }

    public function update(int $motion_id, string $status, string $source, array $context = array())
    {
        $motion = get_post($motion_id);
        if (! $motion || MotionPostType::POST_TYPE !== $motion->post_type) {
            return new \WP_Error('motion_not_found', __('Motionen kunde inte hittas.', 'ssf-member-portal'));
        }

        $new_status = MotionStatus::canonical($status);
        if (! $new_status) {
            return new \WP_Error('invalid_status', __('Statusen är inte tillåten.', 'ssf-member-portal'));
        }

        $stored_status = (string) get_post_meta($motion_id, '_ssf_mp_status', true);
        $old_status = MotionStatus::canonical($stored_status) ?: MotionStatus::IN_SORTERAD;
        $received_at = gmdate('c');
        $changed_at = $this->timestamp($context['changed_at'] ?? '') ?: $received_at;
        $source = sanitize_key($source) ?: 'wordpress';

        $this->store_sharepoint_reference($motion_id, $context);

        if ($old_status === $new_status) {
            if ($stored_status !== $old_status) {
                update_post_meta($motion_id, '_ssf_mp_status', $old_status);
            }
            return array(
                'result' => 'no_change',
                'motion_id' => $motion_id,
                'motion_number' => (string) get_post_meta($motion_id, '_ssf_mp_motion_number', true),
                'old_status' => $old_status,
                'new_status' => $new_status,
            );
        }

        update_post_meta($motion_id, '_ssf_mp_status', $new_status);
        update_post_meta($motion_id, '_ssf_mp_status_updated_at', $changed_at);
        update_post_meta($motion_id, '_ssf_mp_status_source', $source);

        $history = (array) get_post_meta($motion_id, '_ssf_mp_status_history', true);
        $history[] = array(
            'old_status' => $old_status,
            'new_status' => $new_status,
            'old_status_label' => MotionStatus::label($old_status),
            'new_status_label' => MotionStatus::label($new_status),
            'changed_at' => $changed_at,
            'received_at' => $received_at,
            'source' => $source,
            'sharepoint_list_item_id' => sanitize_text_field((string) ($context['sharepoint_list_item_id'] ?? '')),
            'sharepoint_file_url' => esc_url_raw((string) ($context['sharepoint_file_url'] ?? '')),
        );
        update_post_meta($motion_id, '_ssf_mp_status_history', array_slice($history, -50));

        Logger::add('motion_status_changed', array(
            'motion_id' => $motion_id,
            'motion_number' => (string) get_post_meta($motion_id, '_ssf_mp_motion_number', true),
            'old_status' => $old_status,
            'new_status' => $new_status,
            'source' => $source,
        ));

        $email_sent = false;
        if ('wordpress' === $source) {
            $this->sharepoint->queue_status_update($motion_id);
        } elseif (in_array($source, array('sharepoint', 'power_automate'), true)) {
            $email_sent = $this->mailer->send_status_change($motion_id, $old_status, $new_status);
        }

        return array(
            'result' => 'updated',
            'motion_id' => $motion_id,
            'motion_number' => (string) get_post_meta($motion_id, '_ssf_mp_motion_number', true),
            'old_status' => $old_status,
            'new_status' => $new_status,
            'email_sent' => $email_sent,
        );
    }

    public function resend_status_email(int $motion_id)
    {
        return $this->mailer->resend_status_email($motion_id);
    }

    private function store_sharepoint_reference(int $motion_id, array $context): void
    {
        $list_item_id = sanitize_text_field((string) ($context['sharepoint_list_item_id'] ?? ''));
        if ($list_item_id && ! get_post_meta($motion_id, '_ssf_mp_sharepoint_list_item_id', true)) {
            update_post_meta($motion_id, '_ssf_mp_sharepoint_list_item_id', $list_item_id);
        }

        $file_url = esc_url_raw((string) ($context['sharepoint_file_url'] ?? ''));
        if ($file_url && ! get_post_meta($motion_id, '_ssf_mp_sharepoint_web_url', true)) {
            update_post_meta($motion_id, '_ssf_mp_sharepoint_web_url', $file_url);
        }
    }

    private function timestamp($value): string
    {
        if (! is_string($value) || '' === trim($value)) {
            return '';
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('c');
        } catch (\Exception $exception) {
            return '';
        }
    }
}
