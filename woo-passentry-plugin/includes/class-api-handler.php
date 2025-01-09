<?php

if (!defined('ABSPATH')) {
    exit;
}

class API_Handler {
    private $api_url = 'https://api.passentry.com';

    public function request($endpoint, $method = 'GET', $body = []) {
        $api_key = get_option('passentry_api_key');
        if (!$api_key) {
            error_log('PassEntry: API key is missing.');
            return false;
        }

        $args = [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
        ];

        if (!empty($body)) {
            $args['body'] = json_encode($body);
        }

        $url = "{$this->api_url}{$endpoint}";
        
        // Log the request
        error_log('PassEntry: Making API request to: ' . $url);
        error_log('PassEntry: Request args: ' . print_r($args, true));
        
        $response = ($method === 'POST') ? wp_remote_post($url, $args) : wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            error_log('PassEntry: API request failed: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $status = wp_remote_retrieve_response_code($response);
        
        // Log the response
        error_log('PassEntry: API response status: ' . $status);
        error_log('PassEntry: API response body: ' . $body);

        return json_decode($body, true);
    }
}
