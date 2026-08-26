<?php

namespace SSF\MemberPortal\Core;

if (! defined('ABSPATH')) {
    exit;
}

final class Settings
{
    public const OPTION = 'ssf_member_portal_settings';

    public static function all(): array
    {
        return wp_parse_args(
            (array) get_option(self::OPTION, array()),
            array(
                'notification_email' => 'medlem@ssfb.se',
                'max_upload_mb' => 10,
                'sharepoint_enabled' => 'no',
                'microsoft_tenant_id' => '',
                'microsoft_client_id' => '',
                'microsoft_client_secret' => '',
                'sharepoint_site_id' => '',
                'sharepoint_drive_id' => '',
            )
        );
    }

    public static function save(array $input): void
    {
        $current = self::all();
        $settings = array(
            'notification_email' => sanitize_email($input['notification_email'] ?? ''),
            'max_upload_mb' => min(25, max(1, absint($input['max_upload_mb'] ?? 10))),
            'sharepoint_enabled' => ! empty($input['sharepoint_enabled']) ? 'yes' : 'no',
            'microsoft_tenant_id' => sanitize_text_field($input['microsoft_tenant_id'] ?? ''),
            'microsoft_client_id' => sanitize_text_field($input['microsoft_client_id'] ?? ''),
            'microsoft_client_secret' => $current['microsoft_client_secret'],
            'sharepoint_site_id' => sanitize_text_field($input['sharepoint_site_id'] ?? ''),
            'sharepoint_drive_id' => sanitize_text_field($input['sharepoint_drive_id'] ?? ''),
        );

        if (! empty($input['microsoft_client_secret'])) {
            $encrypted = self::encrypt((string) $input['microsoft_client_secret']);
            if ($encrypted) {
                $settings['microsoft_client_secret'] = $encrypted;
            }
        }

        update_option(self::OPTION, $settings, false);
    }

    public static function decrypt_secret(): string
    {
        return self::decrypt((string) self::all()['microsoft_client_secret']);
    }

    private static function encrypt(string $value): string
    {
        if (! function_exists('openssl_encrypt') || ! $value) {
            return '';
        }

        $cipher = 'aes-256-cbc';
        $iv = random_bytes(openssl_cipher_iv_length($cipher));
        $encrypted = openssl_encrypt($value, $cipher, self::key(), OPENSSL_RAW_DATA, $iv);
        return $encrypted ? base64_encode($iv . $encrypted) : '';
    }

    private static function decrypt(string $value): string
    {
        if (! function_exists('openssl_decrypt') || ! $value) {
            return '';
        }

        $cipher = 'aes-256-cbc';
        $decoded = base64_decode($value, true);
        $iv_length = openssl_cipher_iv_length($cipher);
        if (! $decoded || strlen($decoded) <= $iv_length) {
            return '';
        }

        return (string) openssl_decrypt(substr($decoded, $iv_length), $cipher, self::key(), OPENSSL_RAW_DATA, substr($decoded, 0, $iv_length));
    }

    private static function key(): string
    {
        return hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
    }
}
