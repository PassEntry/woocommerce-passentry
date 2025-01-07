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
                // Check if PassEntry is enabled for this product
                $passentry_enabled = get_post_meta($product->get_id(), '_passentry_enabled', true);
                if ($passentry_enabled !== 'yes') {
                    continue;
                }

                $template_uuid = get_post_meta($product->get_id(), '_passentry_template_uuid', true);
                $quantity = $item->get_quantity();

                // Create passes for this product
                $pass_urls = $this->create_passes($product, $order, $quantity);

                if (!empty($pass_urls)) {
                    ?>
                    <div class="pass-download-section card mt-4 mb-4">
                        <div class="card-body text-center">
                            <h2 class="card-title mb-3"><?php esc_html_e('Download Your Passes', 'woocommerce-passentry-api'); ?></h2>
                            <?php foreach ($pass_urls as $index => $url) : ?>
                                <a href="<?php echo esc_url($url); ?>" 
                                   class="btn btn-primary btn-lg rounded-pill m-2" 
                                   target="_blank">
                                    <i class="bi bi-download me-2"></i><?php 
                                    printf(
                                        esc_html__('Download Pass %d', 'woocommerce-passentry-api'),
                                        $index + 1
                                    ); 
                                    ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php
                }
            }
        }
    }

    private function create_passes($product, $order, $quantity) {
        $pass_urls = [];
        $template_uuid = get_post_meta($product->get_id(), '_passentry_template_uuid', true);
        
        // Get NFC and QR settings
        $nfc_enabled = get_post_meta($product->get_id(), '_passentry_nfc_enabled', true) === 'yes';
        $qr_enabled = get_post_meta($product->get_id(), '_passentry_qr_enabled', true) === 'yes';

        $api = new API_Handler();

        for ($i = 0; $i < $quantity; $i++) {
            // Prepare pass data
            $pass_data = [
                'pass' => [
                    'nfc' => ['enabled' => $nfc_enabled],
                    'qr' => ['enabled' => $qr_enabled]
                ]
            ];

            // Get all template fields and their values
            $template_fields = $this->get_template_fields($product->get_id());
            foreach ($template_fields as $field_id => $field_config) {
                if ($field_config['type'] === 'static') {
                    $pass_data['pass'][$field_id] = $field_config['value'];
                } else {
                    // Handle dynamic values from order/product data
                    $pass_data['pass'][$field_id] = $this->get_dynamic_value($field_config['value'], $order, $product);
                }
            }

            // Create the pass
            $response = $api->request("/api/v1/passes?passTemplate={$template_uuid}", 'POST', $pass_data);
            
            if ($response && isset($response['data']['attributes']['downloadUrl'])) {
                $pass_urls[] = $response['data']['attributes']['downloadUrl'];
            } else {
                error_log('Failed to create pass: ' . print_r($response, true));
            }
        }

        return $pass_urls;
    }

    private function get_template_fields($product_id) {
        global $wpdb;
        $fields = [];
        
        // Get all post meta that starts with _passentry_ but exclude known system fields
        $meta_keys = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} 
            WHERE post_id = %d 
            AND meta_key LIKE '_passentry_%%'
            AND meta_key NOT IN ('_passentry_enabled', '_passentry_template_uuid', '_passentry_template_name', '_passentry_qr_enabled', '_passentry_nfc_enabled')",
            $product_id
        ));

        foreach ($meta_keys as $meta) {
            if (strpos($meta->meta_key, '_type') === false) {
                $field_id = str_replace('_passentry_', '', $meta->meta_key);
                $type_key = "_passentry_{$field_id}_type";
                $type = get_post_meta($product_id, $type_key, true);
                
                $fields[$field_id] = [
                    'value' => $meta->meta_value,
                    'type' => $type
                ];
            }
        }

        return $fields;
    }

    private function get_dynamic_value($placeholder, $order, $product) {
        // Remove {{ and }}
        $field = trim($placeholder, '{}');
        
        // Split into object and property
        $parts = explode('_', $field);
        $object_type = array_shift($parts);
        $property = implode('_', $parts);

        switch ($object_type) {
            case 'order':
                $method = "get_$property";
                return method_exists($order, $method) ? $order->$method() : '';
                
            case 'billing':
            case 'shipping':
                return $order->get_address($property, $object_type);
                
            case 'product':
                $method = "get_$property";
                return method_exists($product, $method) ? $product->$method() : '';
                
            default:
                return '';
        }
    }
}
