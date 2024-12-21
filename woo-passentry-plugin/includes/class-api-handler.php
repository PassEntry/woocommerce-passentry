<?php

if (!defined('ABSPATH')) {
    exit;
}

class API_Handler {
    private $api_url = 'https://api.passentry.com';

    public function request($endpoint, $method = 'GET', $body = []) {
        $api_key = get_option('passentry_api_key');
        if (!$api_key) {
            error_log(__('PassEntry API key is missing.', 'woocommerce-passentry-api'));
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
        $response = ($method === 'POST') ? wp_remote_post($url, $args) : wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            error_log(__('API request failed: ', 'woocommerce-passentry-api') . $response->get_error_message());
            return false;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }
}
