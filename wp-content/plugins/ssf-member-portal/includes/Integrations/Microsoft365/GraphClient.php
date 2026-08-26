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

    public function request(string $method, string $path, $body = null)
    {
        $token = $this->authentication->token();
        if (is_wp_error($token)) {
            return $token;
        }
        $args = array('method' => $method, 'timeout' => 45, 'headers' => array('Authorization' => 'Bearer ' . $token));
        if (null !== $body) {
            $args['headers']['Content-Type'] = is_string($body) ? 'application/octet-stream' : 'application/json';
            $args['body'] = is_string($body) ? $body : wp_json_encode($body);
        }
        $response = wp_remote_request('https://graph.microsoft.com/v1.0/' . ltrim($path, '/'), $args);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $message = sanitize_text_field($json['error']['message'] ?? __('Okänt fel från Microsoft Graph.', 'ssf-member-portal'));
            return new \WP_Error('graph_request_failed', $message, array('status' => $code));
        }
        return is_array($json) ? $json : array();
    }

    public function clear_token(): void
    {
        $this->authentication->clear_token();
    }
}
