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
        error_log('template_uuid: ' . strval($template_uuid));
        
        // Get NFC and QR settings
        $nfc_enabled = get_post_meta($product->get_id(), '_passentry_nfc_enabled', true) === 'yes';
        $qr_enabled = get_post_meta($product->get_id(), '_passentry_qr_enabled', true) === 'yes';
        error_log("nfc_enabled: " . ($nfc_enabled ? 'true' : 'false'));
        error_log("qr_enabled: " . ($qr_enabled ? 'true' : 'false'));

        // Get field mappings
        $field_mappings_json = get_post_meta($product->get_id(), '_passentry_field_mappings', true);
        $field_mappings = !empty($field_mappings_json) ? json_decode($field_mappings_json, true) : [];
        error_log("field_mappings: " . json_encode($field_mappings));
        $api = new API_Handler();

        for ($i = 0; $i < $quantity; $i++) {
            // Prepare pass data with NFC and QR settings
            $pass_data = [
                'pass' => [
                    'nfc' => ['enabled' => $nfc_enabled],
                ]
            ];
            
            if ($qr_enabled) {
                $pass_data['pass']['qr'] = [
                    'value' => $field_mappings['qr_value']['type'] === 'dynamic' ? $this->get_dynamic_values($field_mappings['qr_value']['value'], $order, $product) : $field_mappings['qr_value']['value'],
                    'displayText' => true
                ];
            }

            if ($nfc_enabled && isset($field_mappings['custom_nfc_value']) && !empty($field_mappings['custom_nfc_value']['value'])) {
                $pass_data['pass']['nfc']['source'] = 'custom';
                if ($field_mappings['custom_nfc_value']['type'] === 'dynamic') {
                    $pass_data['pass']['nfc']['customValue'] = $this->get_dynamic_values($field_mappings['custom_nfc_value']['value'], $order, $product);
                } else {
                    $pass_data['pass']['nfc']['customValue'] = $field_mappings['custom_nfc_value']['value'];
                }
            }
            
            error_log("pass_data: " . json_encode($pass_data));

            // Add field values from mappings
            if (!empty($field_mappings)) {
                foreach ($field_mappings as $field_id => $field_config) {
                    error_log("field_id: " . $field_id);
                    error_log("field_config: " . json_encode($field_config));
                    // Skip qr_value field
                    if ($field_id === 'qr_value' || $field_id === 'custom_nfc_value') {
                        continue;
                    }
                    
                    if (isset($field_config['value'])) {
                        // Check if field type is dynamic
                        if (isset($field_config['type']) && $field_config['type'] === 'dynamic') {
                            $pass_data['pass'][$field_id] = $this->get_dynamic_values($field_config['value'], $order, $product);
                        } else {
                            $pass_data['pass'][$field_id] = $field_config['value'];
                        }
                    }
                }
            }
            error_log("pass_data: " . json_encode($pass_data));

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
                $fields[$field_id] = [
                    'value' => $meta->meta_value
                ];
            }
        }

        return $fields;
    }

    private function get_dynamic_values($value, $order, $product) {
        switch ($value) {
            case 'order_id':
                return strval($order->get_id());
            case 'full_name':
                $first_name = $order->get_billing_first_name();
                $last_name = $order->get_billing_last_name();
                if (empty($first_name) && empty($last_name)) {
                    return 'Guest';
                }
                return trim($first_name . ' ' . $last_name);
            case 'price':
                return '£' . $product->get_price();
            default:
                return $value;
        }
    }
}
