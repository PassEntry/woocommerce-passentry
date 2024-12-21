<?php

if (!defined('ABSPATH')) {
    exit;
}

class Product_Template_Handler {
    private $api_handler;

    public function __construct() {
        $this->api_handler = new API_Handler();
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_template_field']);
        add_action('woocommerce_process_product_meta', [$this, 'save_template_field']);
        add_action('wp_ajax_load_template_fields', [$this, 'load_template_fields']);
        add_action('wp_ajax_passentry_get_options', [$this, 'ajax_get_field_options']);
    }

    private static function get_template_by_uuid($uuid) {
        $api_handler = new API_Handler();
        $response = $api_handler->request("/api/v1/pass_templates/{$uuid}");
        
        // Debug logging
        error_log('API Response: ' . print_r($response, true));
        
        if (!$response || isset($response['error'])) {
            error_log('Failed to fetch template: ' . print_r($response, true));
            return null;
        }

        return $response;
    }

    public function add_template_field() {
        global $post;
        
        // Check if API key is present
        $api_key = get_option('passentry_api_key');
        $api_missing = empty($api_key);
        
        if ($api_missing) {
            echo '<div class="options_group">';
            echo '<div class="notice notice-warning inline"><p>';
            printf(
                /* translators: %s: Settings page URL */
                __('PassEntry API key is required. Please configure it in the <a href="%s">PassEntry Settings</a> before enabling pass delivery.', 'woocommerce-passentry-api'),
                esc_url(admin_url('options-general.php?page=passentry-api-settings'))
            );
            echo '</p></div>';
            echo '</div>';
            return;
        }

        // Add toggle for pass delivery
        woocommerce_wp_checkbox([
            'id' => '_passentry_enabled',
            'label' => __('Enable Pass Delivery', 'woocommerce-passentry-api'),
            'description' => __('Enable this to allow pass delivery for this product.', 'woocommerce-passentry-api')
        ]);

        // Get stored values with defaults
        $is_enabled = get_post_meta($post->ID, '_passentry_enabled', true);
        $template_uuid = get_post_meta($post->ID, '_passentry_template_uuid', true);
        $qr_enabled = get_post_meta($post->ID, '_passentry_qr_enabled', true) ?: 'no';
        $nfc_enabled = get_post_meta($post->ID, '_passentry_nfc_enabled', true) ?: 'no';

        ?>
        <div class="passentry_fields" style="display: <?php echo $is_enabled === 'yes' ? 'block' : 'none'; ?>">
            <?php
            // Add template input field
            woocommerce_wp_text_input([
                'id' => '_passentry_template_uuid',
                'label' => __('PassEntry Template UUID', 'woocommerce-passentry-api'),
                'value' => $template_uuid,
                'desc_tip' => true,
                'description' => __('Enter the PassEntry template UUID for this product.', 'woocommerce-passentry-api')
            ]);
            ?>

            <div id="passentry_template_fields">
                <?php
                if ($template_uuid) {
                    $this->render_pass_options($qr_enabled, $nfc_enabled);
                    $this->render_template_fields($template_uuid, $post->ID);
                }
                ?>
            </div>

            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var currentValues = {
                        qr_enabled: <?php echo json_encode($qr_enabled === 'yes'); ?>,
                        nfc_enabled: <?php echo json_encode($nfc_enabled === 'yes'); ?>
                    };
                    var templateInput = $('#_passentry_template_uuid');
                    var fieldsContainer = $('#passentry_template_fields');
                    var debounceTimeout;

                    // Toggle fields visibility based on checkbox
                    $('#_passentry_enabled').on('change', function() {
                        $('.passentry_fields').toggle(this.checked);
                    });

                    // Template input handling with debounce
                    templateInput.on('input', function() {
                        clearTimeout(debounceTimeout);
                        
                        debounceTimeout = setTimeout(function() {
                            var template_uuid = templateInput.val().trim();
                            
                            if (template_uuid) {
                                // Show loading indicator
                                fieldsContainer.html('<p>Loading template...</p>');

                                // Make AJAX call to fetch template data
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'load_template_fields',
                                        template_uuid: template_uuid,
                                        post_id: '<?php echo $post->ID; ?>',
                                        nonce: '<?php echo wp_create_nonce("load_template_fields"); ?>'
                                    },
                                    success: function(response) {
                                        if (response) {
                                            fieldsContainer.html(response);
                                            
                                            // Restore checkbox handlers
                                            $('#_passentry_qr_enabled, #_passentry_nfc_enabled').on('change', function() {
                                                currentValues[this.name.replace('_passentry_', '')] = this.checked;
                                            });
                                        } else {
                                            fieldsContainer.html('<p style="color: red;">No template found with this UUID</p>');
                                        }
                                    },
                                    error: function() {
                                        fieldsContainer.html('<p style="color: red;">Failed to load template</p>');
                                    }
                                });
                            } else {
                                fieldsContainer.empty();
                            }
                        }, 500);
                    });
                });
            </script>
        </div>
        <?php
    }

    private function get_dynamic_options() {
        // Get a sample order to inspect available fields
        $sample_order = wc_get_orders(['limit' => 1, 'return' => 'objects']);
        $sample_product = wc_get_product(get_the_ID());

        $dynamic_options = [];

        if (!empty($sample_order)) {
            $order = $sample_order[0];
            
            // Get all order data
            $order_data = $order->get_data();
            $dynamic_options['order'] = $this->extract_fields($order_data);

            // Get billing data
            $billing_data = $order->get_address('billing');
            $dynamic_options['billing'] = $this->extract_fields($billing_data);

            // Get shipping data
            $shipping_data = $order->get_address('shipping');
            $dynamic_options['shipping'] = $this->extract_fields($shipping_data);
        }

        // Get product data
        if ($sample_product) {
            $product_data = $sample_product->get_data();
            $dynamic_options['product'] = $this->extract_fields($product_data);
        }

        // Get custom fields
        $custom_fields = get_post_custom_keys(get_the_ID());
        if ($custom_fields) {
            $dynamic_options['custom'] = array_combine(
                $custom_fields,
                array_map(function($field) {
                    return ucwords(str_replace('_', ' ', $field));
                }, $custom_fields)
            );
        }

        return $dynamic_options;
    }

    private function extract_fields($data, $prefix = '') {
        $fields = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $sub_fields = $this->extract_fields($value, $prefix . $key . '_');
                $fields = array_merge($fields, $sub_fields);
            } else {
                $label = ucwords(str_replace('_', ' ', $key));
                $fields[$prefix . $key] = $label;
            }
        }

        return $fields;
    }

    public function render_template_fields($template_uuid, $post_id) {
        $template = self::get_template_by_uuid($template_uuid);
        
        if (!$template || empty($template['data']['attributes'])) {
            error_log('PassEntry: Template is empty or invalid');
            return;
        }

        $attributes = $template['data']['attributes'];

        $sections = [
            'header' => ['header_one', 'header_two', 'header_three'],
            'primary' => ['primary_one', 'primary_two'],
            'secondary' => ['secOne', 'secTwo', 'secThree'],
            'auxiliary' => ['auxOne', 'auxTwo', 'auxThree'],
            'backFields' => ['backOne', 'backTwo', 'backThree', 'backFour', 'backFive']
        ];

        echo '<div class="options_group">';
        echo '<h4 style="padding-left: 12px;">' . __('Field Mapping', 'woocommerce-passentry-api') . '</h4>';
        
        // Start table
        echo '<table class="widefat fixed" style="margin: 10px; width: calc(100% - 20px);">
                <thead>
                    <tr>
                        <th style="width: 30%;">' . __('Field', 'woocommerce-passentry-api') . '</th>
                        <th style="width: 20%;">' . __('Type', 'woocommerce-passentry-api') . '</th>
                        <th style="width: 50%;">' . __('Value', 'woocommerce-passentry-api') . '</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($sections as $section => $fields) {
            if (isset($attributes[$section])) {
                foreach ($fields as $field) {
                    if (!empty($attributes[$section][$field]['id'])) {
                        $field_id = $attributes[$section][$field]['id'];
                        $field_label = $attributes[$section][$field]['label'];
                        $field_value = get_post_meta($post_id, "_passentry_{$field_id}", true);
                        $field_type = get_post_meta($post_id, "_passentry_{$field_id}_type", true) ?: 'static';
                        
                        echo '<tr>
                                <td>' . esc_html($field_label) . '</td>
                                <td>
                                    <select class="type-select" 
                                            name="_passentry_' . esc_attr($field_id) . '_type" 
                                            data-field-id="' . esc_attr($field_id) . '"
                                            style="width: 100%;">
                                        <option value="static" ' . selected($field_type, 'static', false) . '>' . 
                                            __('Static', 'woocommerce-passentry-api') . 
                                        '</option>
                                        <option value="dynamic" ' . selected($field_type, 'dynamic', false) . '>' . 
                                            __('Dynamic', 'woocommerce-passentry-api') . 
                                        '</option>
                                    </select>
                                </td>
                                <td class="value-cell" data-current-value="' . esc_attr($field_value) . '">
                                </td>
                            </tr>';
                    }
                }
            }
        }

        echo '</tbody></table>';
        echo '</div>';

        // Get dynamic options
        $dynamic_options = $this->get_dynamic_options();

        // Add JavaScript with dynamic options
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.type-select').on('change', function() {
                const row = $(this).closest('tr');
                const valueCell = row.find('.value-cell');
                const fieldId = $(this).data('field-id');
                const isStatic = $(this).val() === 'static';
                
                if (isStatic) {
                    // Show static text input
                    valueCell.html('<input type="text" name="_passentry_' + fieldId + '" value="' + valueCell.data('current-value') + '" style="width: 100%;" />');
                } else {
                    // Show loading state
                    valueCell.html('<span class="spinner is-active"></span> Loading options...');
                    
                    // Fetch options from API
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'get_passentry_field_options',
                            field_id: fieldId,
                            template_uuid: '<?php echo esc_js($template_uuid); ?>',
                            nonce: '<?php echo wp_create_nonce('passentry_get_options'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                const select = $('<select name="_passentry_' + fieldId + '" style="width: 100%;">');
                                select.append('<option value="">' + '<?php echo esc_js(__('Select a value', 'woocommerce-passentry-api')); ?>' + '</option>');
                                
                                // Add options from API response
                                response.data.forEach(function(option) {
                                    const optionElement = $('<option></option>')
                                        .val(option.value)
                                        .text(option.label);
                                    
                                    if (option.value === valueCell.data('current-value')) {
                                        optionElement.prop('selected', true);
                                    }
                                    
                                    select.append(optionElement);
                                });
                                
                                valueCell.html(select);
                            } else {
                                valueCell.html('<div class="error">Error loading options: ' + response.data + '</div>');
                            }
                        },
                        error: function() {
                            valueCell.html('<div class="error">Failed to load options</div>');
                        }
                    });
                }
            });

            // Initial setup
            $('.type-select').trigger('change');
        });
        </script>
        <?php
    }

    public function load_template_fields() {
        check_ajax_referer('load_template_fields', 'nonce');
        
        $template_uuid = sanitize_text_field($_POST['template_uuid']);
        $post_id = intval($_POST['post_id']);
        
        error_log('PassEntry: Loading template fields for UUID: ' . $template_uuid);
        
        $template = self::get_template_by_uuid($template_uuid);
        
        error_log('PassEntry: Template data in load_template_fields: ' . print_r($template, true));
        
        if ($template) {
            ob_start();
            $this->render_pass_options(
                get_post_meta($post_id, '_passentry_qr_enabled', true) ?: 'no',
                get_post_meta($post_id, '_passentry_nfc_enabled', true) ?: 'no'
            );
            $this->render_template_fields($template_uuid, $post_id);
            $html = ob_get_clean();
            
            error_log('PassEntry: Generated HTML: ' . $html);
            
            echo $html;
        } else {
            error_log('PassEntry: No template found for UUID: ' . $template_uuid);
        }
        
        wp_die();
    }

    public function save_template_field($post_id) {
        // Save enabled status
        $passentry_enabled = isset($_POST['_passentry_enabled']) ? 'yes' : 'no';
        update_post_meta($post_id, '_passentry_enabled', $passentry_enabled);

        if (isset($_POST['_passentry_template_uuid'])) {
            $template_uuid = sanitize_text_field($_POST['_passentry_template_uuid']);
            
            // Save template UUID and name
            $template = self::get_template_by_uuid($template_uuid);
            if ($template) {
                update_post_meta($post_id, '_passentry_template_uuid', $template_uuid);
                update_post_meta($post_id, '_passentry_template_name', $template['name']);

                // Save QR and NFC options
                $qr_enabled = isset($_POST['_passentry_qr_enabled']) ? 'yes' : 'no';
                $nfc_enabled = isset($_POST['_passentry_nfc_enabled']) ? 'yes' : 'no';
                update_post_meta($post_id, '_passentry_qr_enabled', $qr_enabled);
                update_post_meta($post_id, '_passentry_nfc_enabled', $nfc_enabled);

                // Save field values and types
                foreach ($template['fields'] as $field_id => $field_name) {
                    if (isset($_POST["_passentry_{$field_id}"])) {
                        $field_value = sanitize_text_field($_POST["_passentry_{$field_id}"]);
                        $field_type = sanitize_text_field($_POST["_passentry_{$field_id}_type"]);
                        update_post_meta($post_id, "_passentry_{$field_id}", $field_value);
                        update_post_meta($post_id, "_passentry_{$field_id}_type", $field_type);
                    }
                }
            }
        }
    }

    private function render_pass_options($qr_enabled, $nfc_enabled) {
        echo '<div class="options_group">';
        echo '<h4 style="padding-left: 12px;">' . __('Pass Options', 'woocommerce-passentry-api') . '</h4>';
        
        woocommerce_wp_checkbox([
            'id' => '_passentry_qr_enabled',
            'label' => __('Enable QR Code', 'woocommerce-passentry-api'),
            'description' => __('Include QR code on the pass for scanning.', 'woocommerce-passentry-api'),
            'value' => $qr_enabled,
            'default' => 'no'
        ]);

        woocommerce_wp_checkbox([
            'id' => '_passentry_nfc_enabled',
            'label' => __('Enable NFC', 'woocommerce-passentry-api'),
            'description' => __('Enable NFC functionality for the pass.', 'woocommerce-passentry-api'),
            'value' => $nfc_enabled,
            'default' => 'no'
        ]);
        
        echo '</div>';
    }

    public function ajax_get_field_options() {
        check_ajax_referer('passentry_get_options', 'nonce');

        // Get a sample order to extract available fields
        $orders = wc_get_orders(['limit' => 1]);
        $sample_order = !empty($orders) ? $orders[0] : null;
        
        $options = [];

        if ($sample_order) {
            // Order Fields
            $order_data = $sample_order->get_data();
            $options[] = [
                'label' => 'Order',
                'options' => $this->format_fields_for_dropdown($order_data)
            ];

            // Customer Fields
            $customer_data = [
                'billing' => $sample_order->get_address('billing'),
                'shipping' => $sample_order->get_address('shipping')
            ];
            $options[] = [
                'label' => 'Customer',
                'options' => $this->format_fields_for_dropdown($customer_data)
            ];
        }

        // Product Fields
        $product = wc_get_product(get_the_ID());
        if ($product) {
            $product_data = $product->get_data();
            $options[] = [
                'label' => 'Product',
                'options' => $this->format_fields_for_dropdown($product_data)
            ];
        }

        wp_send_json_success($options);
    }

    private function format_fields_for_dropdown($data, $prefix = '') {
        $formatted = [];
        
        foreach ($data as $key => $value) {
            // Skip empty or object values
            if (empty($value) || is_object($value)) {
                continue;
            }

            if (is_array($value)) {
                $sub_fields = $this->format_fields_for_dropdown($value, $prefix . $key . '_');
                $formatted = array_merge($formatted, $sub_fields);
            } else {
                $field_key = $prefix . $key;
                $label = ucwords(str_replace('_', ' ', $key));
                
                $formatted[] = [
                    'value' => '{{' . $field_key . '}}',
                    'label' => $label
                ];
            }
        }

        return $formatted;
    }
}
