<?php

namespace SSF\MemberPortal\Integrations\Microsoft365;

use SSF\MemberPortal\Core\Settings;

if (! defined('ABSPATH')) {
    exit;
}

final class Authentication
{
    public function token()
    {
        $cached = get_transient('ssf_member_portal_graph_token');
        if ($cached) {
            return (string) $cached;
        }

        $settings = Settings::all();
        $secret = Settings::decrypt_secret();
        if (! $settings['microsoft_tenant_id'] || ! $settings['microsoft_client_id'] || ! $secret) {
            return new \WP_Error('microsoft_not_configured', __('Microsoft 365 är inte konfigurerat för Medlemsportalen.', 'ssf-member-portal'));
        }

        $response = wp_remote_post(
            'https://login.microsoftonline.com/' . rawurlencode($settings['microsoft_tenant_id']) . '/oauth2/v2.0/token',
            array('timeout' => 25, 'body' => array('client_id' => $settings['microsoft_client_id'], 'client_secret' => $secret, 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials'))
        );
        if (is_wp_error($response)) {
            return new \WP_Error('microsoft_token_request', __('Kunde inte kontakta Microsoft 365 för SharePoint-synk.', 'ssf-member-portal'));
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (200 !== (int) wp_remote_retrieve_response_code($response) || empty($data['access_token'])) {
            return new \WP_Error('microsoft_token_response', __('Microsoft 365 kunde inte skapa en token för SharePoint-synk.', 'ssf-member-portal'));
        }
        set_transient('ssf_member_portal_graph_token', (string) $data['access_token'], max(60, (int) ($data['expires_in'] ?? 3600) - 120));
        return (string) $data['access_token'];
    }
}
