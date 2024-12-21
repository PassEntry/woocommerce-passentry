<?php

if (!defined('ABSPATH')) {
    exit;
}

class Order_Template_Handler {
    public function __construct() {
        add_action('woocommerce_order_status_completed', [$this, 'create_template_item'], 10, 1);
        add_action('woocommerce_thankyou', [$this, 'display_pass_download_button'], 10, 1);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_bootstrap']);
    }

    public function enqueue_bootstrap() {
        // Bootstrap CSS
        wp_enqueue_style('bootstrap', 
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css', 
            array(), 
            '5.3.0'
        );

        // Bootstrap Icons
        wp_enqueue_style('bootstrap-icons', 
            'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css', 
            array(), 
            '1.10.0'
        );

        // Bootstrap JS and Popper.js
        wp_enqueue_script('bootstrap-bundle',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
            array('jquery'),
            '5.3.0',
            true
        );
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

    public function display_pass_download_button($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !in_array($order->get_status(), ['completed', 'on-hold'])) {
            return;
        }

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $quantity = $item->get_quantity();
                ?>
                <div class="pass-download-section card mt-4 mb-4">
                    <div class="card-body text-center">
                        <h2 class="card-title mb-3"><?php esc_html_e('Download Your Passes', 'woocommerce-passentry-api'); ?></h2>
                        <?php for ($i = 0; $i < $quantity; $i++) : ?>
                            <button class="btn btn-primary btn-lg rounded-pill m-2" 
                                    data-order-id="<?php echo esc_attr($order_id); ?>"
                                    data-product-id="<?php echo esc_attr($product->get_id()); ?>"
                                    data-pass-index="<?php echo esc_attr($i); ?>">
                                <i class="bi bi-download me-2"></i><?php 
                                printf(
                                    esc_html__('Download Pass %d', 'woocommerce-passentry-api'),
                                    $i + 1
                                ); 
                                ?>
                            </button>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php
            }
        }
    }
}
