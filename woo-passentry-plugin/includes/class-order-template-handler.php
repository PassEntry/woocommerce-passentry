<?php

if (!defined('ABSPATH')) {
    exit;
}

class Order_Template_Handler {
    public function __construct() {
        add_action('woocommerce_order_status_completed', [$this, 'create_template_item'], 10, 1);
    }

    public function create_template_item($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if ($product) {
                $payload = [
                    'template_id' => $product->get_id(),
                    'quantity' => $item->get_quantity(),
                    'buyer_email' => $order->get_billing_email(),
                ];

                $api = new API_Handler();
                $response = $api->request('/template-items', 'POST', $payload);

                if ($response && !empty($response['success'])) {
                    error_log(__('Template item created successfully in PassEntry API.', 'woocommerce-passentry-api'));
                } else {
                    error_log(__('Failed to create template item in PassEntry API.', 'woocommerce-passentry-api'));
                }
            }
        }
    }
}
