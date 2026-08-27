<?php

namespace SSF\MemberPortal\Integrations\Microsoft365;

if (! defined('ABSPATH')) {
    exit;
}

final class Authentication
{
    private array $last_token_info = array();

    public function token(bool $force_refresh = false)
    {
        if ($force_refresh) {
            $this->clear_token();
        }

        $cached = get_transient('ssf_member_portal_graph_token');
        if (is_string($cached) && '' !== $cached) {
            return $cached;
        }

        $config = Configuration::all();
        if (! Configuration::complete()) {
            return new \WP_Error(
                'microsoft_not_configured',
                __('Microsoft 365 är inte komplett konfigurerat på servern.', 'ssf-member-portal'),
                array('http_status' => 0, 'missing' => Configuration::missing())
            );
        }

        $endpoint = 'https://login.microsoftonline.com/' . rawurlencode($config['tenant_id']) . '/oauth2/v2.0/token';
        $response = wp_remote_post(
            $endpoint,
            array(
                'timeout' => 25,
                'body' => array(
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ),
            )
        );
        if (is_wp_error($response)) {
            return new \WP_Error(
                'microsoft_token_request',
                __('Kunde inte kontakta Microsoft 365 för SharePoint-synk.', 'ssf-member-portal'),
                array('http_status' => 0, 'endpoint' => $endpoint)
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (200 !== $status || empty($data['access_token']) || empty($data['token_type']) || ! isset($data['expires_in'])) {
            return new \WP_Error(
                'microsoft_token_response',
                __('Microsoft 365 kunde inte utfärda en åtkomsttoken för SharePoint-synk.', 'ssf-member-portal'),
                array(
                    'http_status' => $status,
                    'endpoint' => $endpoint,
                    'microsoft_code' => sanitize_key((string) ($data['error'] ?? '')),
                )
            );
        }

        $this->last_token_info = array(
            'token_type' => sanitize_text_field((string) $data['token_type']),
            'expires_in' => (int) $data['expires_in'],
        );
        set_transient('ssf_member_portal_graph_token', (string) $data['access_token'], max(60, $this->last_token_info['expires_in'] - 300));

        return (string) $data['access_token'];
    }

    public function test()
    {
        $token = $this->token(true);
        if (is_wp_error($token)) {
            return $token;
        }

        return array(
            'ok' => true,
            'endpoint' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
            'http_status' => 200,
            'token_type' => $this->last_token_info['token_type'] ?? '',
            'expires_in' => $this->last_token_info['expires_in'] ?? 0,
            'timestamp' => gmdate('c'),
        );
    }

    public function clear_token(): void
    {
        delete_transient('ssf_member_portal_graph_token');
    }
}
