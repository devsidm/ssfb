<?php

namespace SSF\MemberPortal\Integrations\Microsoft365;

if (! defined('ABSPATH')) {
    exit;
}

final class GraphClient
{
    private Authentication $authentication;

    public function __construct(Authentication $authentication)
    {
        $this->authentication = $authentication;
    }

    public function request(string $method, string $path, $body = null, array $headers = array())
    {
        $token = $this->authentication->token();
        if (is_wp_error($token)) {
            return $token;
        }

        // Graph returns absolute @odata.nextLink and copy-monitor URLs. Keep
        // those URLs in the shared authenticated transport.
        $endpoint = preg_match('#^https://graph\\.microsoft\\.com/#i', $path)
            ? $path
            : 'https://graph.microsoft.com/v1.0/' . ltrim($path, '/');
        $args = array(
            'method' => $method,
            'timeout' => 45,
            'headers' => array_merge(array('Authorization' => 'Bearer ' . $token), $headers),
        );
        if (null !== $body) {
            if (! isset($args['headers']['Content-Type'])) {
                $args['headers']['Content-Type'] = is_string($body) ? 'application/octet-stream' : 'application/json';
            }
            $args['body'] = is_string($body) ? $body : wp_json_encode($body);
        }

        $response = wp_remote_request($endpoint, $args);
        if (is_wp_error($response)) {
            return new \WP_Error(
                'graph_request_transport',
                __('Kunde inte kontakta Microsoft Graph.', 'ssf-member-portal'),
                array('http_status' => 0, 'endpoint' => $endpoint)
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $message = sanitize_text_field($json['error']['message'] ?? __('Okänt fel från Microsoft Graph.', 'ssf-member-portal'));
            return new \WP_Error(
                'graph_request_failed',
                $message,
                array(
                    'status' => $code,
                    'http_status' => $code,
                    'endpoint' => $endpoint,
                    'graph_code' => sanitize_key((string) ($json['error']['code'] ?? '')),
                )
            );
        }

        return is_array($json) ? $json : array();
    }

    /** Response headers are needed for async DriveItem copy Location URLs. */
    public function request_response(string $method, string $path, $body = null, array $headers = array())
    {
        $token = $this->authentication->token();
        if (is_wp_error($token)) {
            return $token;
        }
        $endpoint = preg_match('#^https://graph\\.microsoft\\.com/#i', $path)
            ? $path
            : 'https://graph.microsoft.com/v1.0/' . ltrim($path, '/');
        $args = array('method' => $method, 'timeout' => 45, 'headers' => array_merge(array('Authorization' => 'Bearer ' . $token), $headers));
        if (null !== $body) {
            if (! isset($args['headers']['Content-Type'])) {
                $args['headers']['Content-Type'] = is_string($body) ? 'application/octet-stream' : 'application/json';
            }
            $args['body'] = is_string($body) ? $body : wp_json_encode($body);
        }
        $response = wp_remote_request($endpoint, $args);
        if (is_wp_error($response)) {
            return new \WP_Error('graph_request_transport', __('Kunde inte kontakta Microsoft Graph.', 'ssf-member-portal'), array('http_status' => 0));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) {
            return new \WP_Error('graph_request_failed', sanitize_text_field($json['error']['message'] ?? __('Okänt fel från Microsoft Graph.', 'ssf-member-portal')), array('http_status' => $status, 'graph_code' => sanitize_key((string) ($json['error']['code'] ?? ''))));
        }
        return array('status' => $status, 'headers' => wp_remote_retrieve_headers($response), 'body' => is_array($json) ? $json : array());
    }

    /** Copy monitor URLs are short-lived and may be hosted outside Graph. Never send the Graph token to them. */
    public function copy_status(string $url)
    {
        $parts = wp_parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ('https' !== strtolower((string) ($parts['scheme'] ?? ''))
            || ! empty($parts['user']) || ! empty($parts['pass'])
            || (isset($parts['port']) && 443 !== (int) $parts['port'])
            || (! in_array($host, array('api.onedrive.com', 'graph.microsoft.com'), true)
                && ! preg_match('/^[a-z0-9-]+\.sharepoint\.com$/', $host))
            || '' === $path) {
            return new \WP_Error('graph_copy_monitor_invalid', 'Microsoft Graph returnerade en ogiltig adress för kopieringsstatus.');
        }

        $response = wp_remote_get($url, array('timeout' => 20, 'redirection' => 0));
        if (is_wp_error($response)) {
            return new \WP_Error('graph_copy_monitor_transport', 'Kunde inte läsa kopieringsstatus från Microsoft.');
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || ! is_array($body)) {
            return new \WP_Error('graph_copy_monitor_failed', 'Microsoft returnerade inte en giltig kopieringsstatus.', array('http_status' => $code));
        }
        return $body;
    }

    public function clear_token(): void
    {
        $this->authentication->clear_token();
    }

    public function authentication(): Authentication
    {
        return $this->authentication;
    }
}
