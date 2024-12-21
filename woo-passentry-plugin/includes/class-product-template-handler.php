<?php

if (!defined('ABSPATH')) {
    exit;
}

class Product_Template_Handler {
    public function __construct() {
        add_action('save_post_product', [$this, 'add_template_to_api'], 10, 3);
    }

    public function add_template_to_api($post_id, $post, $update) {
        if ($post->post_status === 'auto-draft' || $update) {
            return;
        }

        $product = wc_get_product($post_id);
        if ($product) {
            $payload = [
                'template_name' => $product->get_name(),
                'description' => $product->get_description(),
                'price' => $product->get_price(),
                'sku' => $product->get_sku(),
            ];

            $api = new API_Handler();
            $response = $api->request('/templates', 'POST', $payload);

            if ($response && !empty($response['success'])) {
                error_log(__('Template added successfully to PassEntry API.', 'woocommerce-passentry-api'));
            } else {
                error_log(__('Failed to add template to PassEntry API.', 'woocommerce-passentry-api'));
            }
        }
    }
}
