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
    }

    private static function get_template_by_uuid($uuid) {           
        $api_handler = new API_Handler();
        $response = $api_handler->request("/api/v1/pass_templates/{$uuid}");
        
        // Pretty print debug logging with clear separation
        error_log("\n=== PassEntry Template Response ===");
        error_log("Template UUID: " . $uuid);
        
        if (!$response || isset($response['error'])) {
            error_log("Error fetching template:");
            error_log(print_r($response, true));
            return null;
        }

        // Log specific sections separately for better readability
        if (isset($response['data']['attributes'])) {
            $attrs = $response['data']['attributes'];
            error_log("\nTemplate Basic Info:");
            error_log("Name: " . ($attrs['name'] ?? 'N/A'));
            error_log("Type: " . ($attrs['templateType'] ?? 'N/A'));
            error_log("Description: " . ($attrs['description'] ?? 'N/A'));

            error_log("\nTemplate Fields:");
            foreach (['header', 'primary', 'secondary', 'auxiliary', 'backFields'] as $section) {
                if (isset($attrs[$section])) {
                    error_log("\n" . strtoupper($section) . " Fields:");
                    foreach ($attrs[$section] as $key => $field) {
                        if (!empty($field['id'])) {
                            error_log("- {$key}: ID={$field['id']}, Label={$field['label']}, Default={$field['default_value']}");
                        }
                    }
                }
            }
        }
        
        error_log("=====================================\n");
        
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
                jQuery(function($) {
                    // Prevent default WooCommerce checkbox behavior
                    $(document).off('click', '.woocommerce-save-button');
                    
                    var templateInput = $('#_passentry_template_uuid');
                    var fieldsContainer = $('#passentry_template_fields');
                    var debounceTimeout;

                    // Prevent form submission on checkbox changes
                    $('form#post').on('submit', function(e) {
                        if (e.target.activeElement.type === 'checkbox') {
                            e.preventDefault();
                            return false;
                        }
                    });

                    // Unbind any existing handlers first
                    $('#_passentry_enabled').off('change');

                    // Toggle fields visibility based on checkbox
                    $('#_passentry_enabled').on('change', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        $('.passentry_fields').toggle(this.checked);
                        return false;
                    });

                    // Toggle QR value field visibility
                    $('#_passentry_qr_enabled').on('change', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        var $qrField = $('tr:has(input[name="passentry_value_qr_value"])');
                        
                        if (this.checked) {
                            if ($qrField.length === 0) {
                                // Create new QR field if it doesn't exist
                                var $newRow = $('<tr>\
                                    <td>' + wp.i18n.__('QR Code Value', 'woocommerce-passentry-api') + '</td>\
                                    <td>\
                                        <input type="hidden" name="passentry_field_qr_value" value="qr_value">\
                                        <input type="hidden" name="passentry_label_qr_value" value="QR Code Value">\
                                        <input type="text" name="passentry_value_qr_value" class="regular-text" \
                                            style="width: 100%;" \
                                            placeholder="' + wp.i18n.__('Enter QR code value or leave blank for auto-generation', 'woocommerce-passentry-api') + '">\
                                    </td>\
                                </tr>');
                                
                                // Insert after the first field in the mapping table
                                $('.widefat tbody tr:first').after($newRow);
                            } else {
                                $qrField.show();
                            }
                        } else {
                            $qrField.hide();
                        }
                        
                        return false;
                    });

                    // Toggle Custom NFC value field visibility
                    $('#_passentry_nfc_enabled').on('change', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        var $customNfcField = $('tr:has(input[name="passentry_value_custom_nfc_value"])');
                        
                        if (this.checked) {
                            if ($customNfcField.length === 0) {
                                // Create new Custom NFC field if it doesn't exist
                                var $customNfcRow = $('<tr>\
                                    <td>' + wp.i18n.__('Custom NFC Value', 'woocommerce-passentry-api') + '</td>\
                                    <td>\
                                        <input type="hidden" name="passentry_field_custom_nfc_value" value="custom_nfc_value">\
                                        <input type="hidden" name="passentry_label_custom_nfc_value" value="Custom NFC Value">\
                                        <input type="text" name="passentry_value_custom_nfc_value" class="regular-text" \
                                            style="width: 100%;" \
                                            placeholder="' + wp.i18n.__('Enter custom NFC value', 'woocommerce-passentry-api') + '">\
                                    </td>\
                                </tr>');
                                
                                // Insert after QR field if it exists, otherwise after first field
                                var $qrField = $('tr:has(input[name="passentry_value_qr_value"])');
                                if ($qrField.length > 0) {
                                    $qrField.after($customNfcRow);
                                } else {
                                    $('.widefat tbody tr:first').after($customNfcRow);
                                }
                            } else {
                                $customNfcField.show();
                            }
                        } else {
                            $customNfcField.hide();
                        }
                        
                        return false;
                    });

                    // Initial visibility state
                    $('tr:has(input[name="passentry_value_qr_value"])').toggle($('#_passentry_qr_enabled').is(':checked'));

                    // Template input handling with debounce
                    templateInput.on('input', function() {
                        clearTimeout(debounceTimeout);
                        
                        debounceTimeout = setTimeout(function() {
                            var template_uuid = templateInput.val().trim();
                            if (template_uuid) {
                                reloadTemplateFields(template_uuid);
                            } else {
                                fieldsContainer.empty();
                            }
                        }, 500);
                    });

                    function reloadTemplateFields(template_uuid) {
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
                                    // Re-apply QR visibility state after reload
                                    $('tr:has(input[name="passentry_value_qr_value"])').toggle($('#_passentry_qr_enabled').is(':checked'));
                                } else {
                                    fieldsContainer.html('<p style="color: red;">No template found with this UUID</p>');
                                }
                            },
                            error: function() {
                                fieldsContainer.html('<p style="color: red;">Failed to load template</p>');
                            }
                        });
                    }
                });
            </script>
        </div>
        <?php
    }

    public function render_template_fields($template_uuid, $post_id) {
        error_log("\n=== PassEntry Template Fields Debug ===");
        
        // Get and log all post meta
        $all_meta = get_post_meta($post_id);
        $log_data = [
            'post_id' => $post_id,
            'all_meta' => $all_meta,
            'passentry_meta' => array_filter(
                $all_meta, 
                function($key) {
                    return strpos($key, '_passentry_') === 0;
                }, 
                ARRAY_FILTER_USE_KEY
            )
        ];
        
        error_log(json_encode($log_data, JSON_PRETTY_PRINT));
        error_log("=====================================\n");

        $template = self::get_template_by_uuid($template_uuid);
        
        if (!$template || empty($template['data']['attributes'])) {
            error_log('PassEntry: Template is empty or invalid');
            return;
        }

        // Debug log the template data
        error_log(str_repeat("=", 50));
        error_log("PassEntry Template Data:");
        error_log(json_encode([
            'id' => $template['data']['id'],
            'type' => $template['data']['type'],
            'attributes' => $template['data']['attributes']
        ], true));
        error_log(str_repeat("=", 50));

        $attributes = $template['data']['attributes'];

        // Debug log stored meta values
        $all_meta = get_post_meta($post_id);
        // Convert array values to strings and handle nested JSON
        $clean_meta = array_map(function($item) {
            $value = $item[0];
            // Try to decode if it looks like JSON
            if (is_string($value) && strpos($value, '{') === 0) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }
            return $value;
        }, $all_meta);
        
        error_log(PHP_EOL . "=== Post Meta for Product {$post_id} ===" . PHP_EOL);
        error_log(str_replace('\/', '/', print_r(json_encode($clean_meta, 
            JSON_PRETTY_PRINT | 
            JSON_UNESCAPED_SLASHES | 
            JSON_UNESCAPED_UNICODE
        ), true)));
        error_log(str_repeat("=", 50) . PHP_EOL);

        $sections = [
            'header' => ['header_one', 'header_two', 'header_three'],
            'primary' => ['primary_one', 'primary_two'],
            'secondary' => ['secOne', 'secTwo', 'secThree'],
            'auxiliary' => ['auxOne', 'auxTwo', 'auxThree'],
            'backFields' => ['backOne', 'backTwo', 'backThree', 'backFour', 'backFive']
        ];

        echo '<div class="options_group">';
        echo '<h4 style="padding-left: 12px;">' . __('Field Mapping', 'woocommerce-passentry-api') . '</h4>';
        
  
        echo '<table class="widefat fixed" style="margin: 10px; width: calc(100% - 20px);">
                <thead>
                    <tr>
                        <th style="width: 30%;">' . __('Field', 'woocommerce-passentry-api') . '</th>
                        <th style="width: 70%;">' . __('Value', 'woocommerce-passentry-api') . '</th>
                    </tr>
                </thead>
                <tbody>';

        // Get stored field mappings
        $stored_mappings = get_post_meta($post_id, '_passentry_field_mappings', true);
        $field_mappings = !empty($stored_mappings) ? json_decode($stored_mappings, true) : [];

        // Add QR Value field if QR is enabled
        $qr_enabled = get_post_meta($post_id, '_passentry_qr_enabled', true);
        if ($qr_enabled === 'yes') {
            $qr_value = isset($field_mappings['qr_value']) ? $field_mappings['qr_value']['value'] : '';
            
            echo '<tr>
                    <td>' . esc_html__('QR Code Value', 'woocommerce-passentry-api') . '</td>
                    <td>
                        <input type="hidden" 
                            name="passentry_field_qr_value" 
                            value="qr_value">
                        <input type="hidden" 
                            name="passentry_label_qr_value" 
                            value="QR Code Value">
                        <input type="text" 
                            name="passentry_value_qr_value" 
                            class="regular-text" 
                            value="' . esc_attr($qr_value) . '"
                            style="width: 100%;"
                            placeholder="' . esc_attr__('Enter QR code value or leave blank for auto-generation', 'woocommerce-passentry-api') . '">
                    </td>
                </tr>';
        }

        // Add NFC Value fields if NFC is enabled
        $nfc_enabled = get_post_meta($post_id, '_passentry_nfc_enabled', true);
        if ($nfc_enabled === 'yes') {
            $nfc_value = isset($field_mappings['nfc_value']) ? $field_mappings['nfc_value']['value'] : '';
            $custom_nfc_value = isset($field_mappings['custom_nfc_value']) ? $field_mappings['custom_nfc_value']['value'] : '';

            echo '<tr>
                    <td>' . esc_html__('Custom NFC Value', 'woocommerce-passentry-api') . '</td>
                    <td>
                        <input type="hidden" 
                            name="passentry_field_custom_nfc_value" 
                            value="custom_nfc_value">
                        <input type="hidden" 
                            name="passentry_label_custom_nfc_value" 
                            value="Custom NFC Value">
                        <input type="text" 
                            name="passentry_value_custom_nfc_value" 
                            class="regular-text" 
                            value="' . esc_attr($custom_nfc_value) . '"
                            style="width: 100%;"
                            placeholder="' . esc_attr__('Enter custom NFC value', 'woocommerce-passentry-api') . '">
                    </td>
                </tr>';
        }

        // Continue with regular fields
        foreach ($sections as $section => $fields) {
            if (isset($attributes[$section])) {
                foreach ($fields as $field) {
                    if (!empty($attributes[$section][$field]['id'])) {
                        $field_id = $attributes[$section][$field]['id'];
                        $field_label = $attributes[$section][$field]['label'];

                        // Get stored value if it exists
                        $stored_value = '';
                        if (!empty($field_mappings[$field_id])) {
                            error_log("field_mapping: " . $field_mappings[$field_id]);
                            $stored_value = $field_mappings[$field_id]['value'] ?? '';
                        }

                        // Hidden fields for field ID and label
                        echo '<input type="hidden" 
                            name="passentry_field_' . esc_attr($field_id) . '" 
                            value="' . esc_attr($field_id) . '">';
                        echo '<input type="hidden" 
                            name="passentry_label_' . esc_attr($field_id) . '" 
                            value="' . esc_attr($field_label) . '">';

                        // The visible form fields - now with stored value
                        echo '<tr>
                                <td>' . esc_html($field_label) . '</td>
                                <td>
                                    <input type="text" 
                                        name="passentry_value_' . esc_attr($field_id) . '" 
                                        class="regular-text" 
                                        value="' . esc_attr($stored_value) . '"
                                        style="width: 100%;">
                                </td>
                            </tr>';
                    }
                }
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function load_template_fields() {
        check_ajax_referer('load_template_fields', 'nonce');
        
        $template_uuid = sanitize_text_field($_POST['template_uuid']);
        $post_id = intval($_POST['post_id']);
        
        error_log('PassEntry: Loading template fields for UUID: ' . $template_uuid);
        
        $template = self::get_template_by_uuid($template_uuid);
        
        error_log('PassEntry: Template data in load_template_fields: ' . json_encode($template, JSON_PRETTY_PRINT));
        
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
        if (!isset($_POST['_passentry_template_uuid'])) {
            return;
        }

        // Debug log at start of save
        error_log("\n=== PassEntry Save Template Fields ===");
        $log_data = [
            'post_id' => $post_id,
            'post_data' => $_POST,
            'field_mappings' => $field_mappings,
            'final_meta' => get_post_meta($post_id)
        ];
        
        error_log(json_encode($log_data, JSON_PRETTY_PRINT));
        error_log("=====================================\n");

        // Save basic settings
        $passentry_enabled = isset($_POST['_passentry_enabled']) ? 'yes' : 'no';
        $template_uuid = sanitize_text_field($_POST['_passentry_template_uuid']);
        $qr_enabled = isset($_POST['_passentry_qr_enabled']) ? 'yes' : 'no';
        $nfc_enabled = isset($_POST['_passentry_nfc_enabled']) ? 'yes' : 'no';

        update_post_meta($post_id, '_passentry_enabled', $passentry_enabled);
        update_post_meta($post_id, '_passentry_template_uuid', $template_uuid);
        update_post_meta($post_id, '_passentry_qr_enabled', $qr_enabled);
        update_post_meta($post_id, '_passentry_nfc_enabled', $nfc_enabled);

        // Save field mappings as an object
        $field_mappings = new stdClass();
        
        // Loop through POST data to find field mappings
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'passentry_field_') === 0) {
                $field_id = str_replace('passentry_field_', '', $key);
                
                // Create object for this field
                $field_mappings->$field_id = (object)[
                    'id' => sanitize_text_field($field_id),
                    'label' => sanitize_text_field($_POST["passentry_label_{$field_id}"] ?? ''),
                    'value' => sanitize_text_field($_POST["passentry_value_{$field_id}"] ?? '')
                ];
            }
        }

        // Save as JSON object
        update_post_meta($post_id, '_passentry_field_mappings', json_encode($field_mappings));

        // Log final saved state
        error_log("Final post meta after save:");
        error_log(print_r(get_post_meta($post_id), true));
        error_log("=====================================\n");
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
}
