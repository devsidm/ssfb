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

        $endpoint = 'https://graph.microsoft.com/v1.0/' . ltrim($path, '/');
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

    public function clear_token(): void
    {
        $this->authentication->clear_token();
    }

    public function authentication(): Authentication
    {
        return $this->authentication;
    }
}
