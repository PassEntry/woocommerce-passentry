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
        
        // error_log("\n=== PassEntry Template Response ===");
        // error_log("Template UUID: " . $uuid);
        
        if (!$response || isset($response['error'])) {
            error_log("Error fetching template:");
            error_log(print_r($response, true));
            return null;
        }

        if (isset($response['data']['attributes'])) {
            $attrs = $response['data']['attributes'];
            error_log("\nTemplate Basic Info:");
            error_log("Name: " . ($attrs['name'] ?? 'N/A'));
            error_log("Type: " . ($attrs['templateType'] ?? 'N/A'));
            error_log("Description: " . ($attrs['description'] ?? 'N/A'));

            error_log("\nTemplate Fields:");
            foreach (['header', 'primary', 'secondary', 'auxiliary', 'backFields'] as $section) {
                if (isset($attrs[$section])) {
                    // error_log("\n" . strtoupper($section) . " Fields:");
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

    private function get_dynamic_values() {
        return [
            'option1' => __('Option 1', 'woocommerce-passentry-api'),
            'option2' => __('Option 2', 'woocommerce-passentry-api'),
            'option3' => __('Option 3', 'woocommerce-passentry-api')
        ];
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
                    // Remove the problematic WooCommerce save button handler
                    // $(document).off('click', '.woocommerce-save-button');
                    
                    var templateInput = $('#_passentry_template_uuid');
                    var fieldsContainer = $('#passentry_template_fields');
                    var debounceTimeout;

                    // Update the form submission handler to be more specific
                    $('form#post').on('submit', function(e) {
                        // e.preventDefault();
                        // Only prevent default for checkbox clicks
                        if (e.target.activeElement && e.target.activeElement.type === 'checkbox') {
                            e.preventDefault();
                            return false;
                        }
                        // Allow normal form submission for other cases
                        return true;
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
                                    <td>\
                                        <select name="passentry_type_qr_value" style="width: 100%;">\
                                            <option value="static">' + wp.i18n.__('Static', 'woocommerce-passentry-api') + '</option>\
                                            <option value="dynamic">' + wp.i18n.__('Dynamic', 'woocommerce-passentry-api') + '</option>\
                                        </select>\
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
                                    <td>\
                                        <select name="passentry_type_custom_nfc_value" style="width: 100%;">\
                                            <option value="static">' + wp.i18n.__('Static', 'woocommerce-passentry-api') + '</option>\
                                            <option value="dynamic">' + wp.i18n.__('Dynamic', 'woocommerce-passentry-api') + '</option>\
                                        </select>\
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

                    function createDynamicSelect(fieldName, currentValue) {
                        var options = <?php echo json_encode($this->get_dynamic_values()); ?>;
                        var select = '<select name="' + fieldName + '" style="width: 100%;">';
                        
                        Object.keys(options).forEach(function(key) {
                            var selected = currentValue === key ? ' selected' : '';
                            select += '<option value="' + key + '"' + selected + '>' + options[key] + '</option>';
                        });
                        
                        select += '</select>';
                        return select;
                    }

                    // Update type select change handlers
                    $(document).on('change', 'select[name^="passentry_type_"]', function() {
                        var fieldName = $(this).attr('name').replace('type_', 'value_');
                        var currentValue = $('[name="' + fieldName + '"]').val();
                        var container = $(this).closest('tr').find('td:eq(1)');
                        
                        console.log("Type changed: " + $(this).val());
                        console.log("Field name: " + fieldName);
                        console.log("Current value: " + currentValue);

                        if ($(this).val() === 'dynamic') {
                            var dynamicSelect = createDynamicSelect(fieldName, currentValue);
                            container.html(dynamicSelect);
                            // Trigger change to ensure the value is set
                            container.find('select').val(currentValue).trigger('change');
                        } else {
                            container.html('<input type="text" name="' + fieldName + '" class="regular-text" ' +
                                'style="width: 100%;" value="' + currentValue + '" placeholder="' + 
                                wp.i18n.__('Enter value', 'woocommerce-passentry-api') + '">');
                        }
                    });

                    // Add handler for dynamic select changes
                    $(document).on('change', 'select[name^="passentry_value_"]', function() {
                        var value = $(this).val();
                        var name = $(this).attr('name');
                        console.log('Dynamic select changed:', name, 'New value:', value);
                        
                        // Ensure the value persists
                        $(this).attr('data-value', value);
                    });

                    // Add form submit handler to debug values
                    $('form#post').on('submit', function() {
                        console.log('Form submitting...');
                        
                        // Log all passentry fields
                        var formData = {};
                        $('[name^="passentry_"]').each(function() {
                            var $field = $(this);
                            var name = $field.attr('name');
                            var value = $field.val();
                            var type = $(this).closest('tr').find('select[name^="passentry_type_"]').val() || 'static';
                            formData[name] = {
                                value: value,
                                type: type,
                            };
                            console.log('Field:', name, 'Value:', value, 'Type:', type, 'Is Select:', $field.is('select'));
                        });
                        
                        console.log('Complete form data:', formData);
                    });

                    // Initialize dynamic fields on page load
                    $('select[name^="passentry_type_"]').each(function() {
                        if ($(this).val() === 'dynamic') {
                            $(this).trigger('change');
                        }
                    });
                });
            </script>
        </div>
        <?php
    }

    public function render_template_fields($template_uuid, $post_id) {
        // error_log("\n=== PassEntry Template Fields Debug ===");
        
        // $all_meta = get_post_meta($post_id);
        // $log_data = [
        //     'post_id' => $post_id,
        //     'all_meta' => $all_meta,
        //     'passentry_meta' => array_filter(
        //         $all_meta, 
        //         function($key) {
        //             return strpos($key, '_passentry_') === 0;
        //         }, 
        //         ARRAY_FILTER_USE_KEY
        //     )
        // ];
        
        // // error_log(json_encode($log_data));
        // error_log("=====================================\n");

        $template = self::get_template_by_uuid($template_uuid);
        
        if (!$template || empty($template['data']['attributes'])) {
            error_log('PassEntry: Template is empty or invalid');
            return;
        }

        // Debug log the template data
        // error_log(str_repeat("=", 50));
        // error_log("PassEntry Template Data:");
        // error_log(json_encode([
        //     'id' => $template['data']['id'],
        //     'type' => $template['data']['type'],
        //     'attributes' => $template['data']['attributes']
        // ], true));
        // error_log(str_repeat("=", 50));

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
        
        // error_log(PHP_EOL . "=== Post Meta for Product {$post_id} ===" . PHP_EOL);
        // error_log(json_encode($clean_meta));
        // error_log(str_repeat("=", 50) . PHP_EOL);

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
                        <th style="width: 25%;">' . __('Field', 'woocommerce-passentry-api') . '</th>
                        <th style="width: 60%;">' . __('Value', 'woocommerce-passentry-api') . '</th>
                        <th style="width: 15%;">' . __('Type', 'woocommerce-passentry-api') . '</th>
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
            $qr_type = isset($field_mappings['qr_value']) ? $field_mappings['qr_value']['type'] : 'static';
            
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
                    <td>
                        <select name="passentry_type_qr_value" style="width: 100%;">
                            <option value="static" ' . selected($qr_type, 'static', false) . '>' . 
                                esc_html__('Static', 'woocommerce-passentry-api') . '</option>
                            <option value="dynamic" ' . selected($qr_type, 'dynamic', false) . '>' . 
                                esc_html__('Dynamic', 'woocommerce-passentry-api') . '</option>
                        </select>
                    </td>
                </tr>';
        }

        // Add NFC Value fields if NFC is enabled
        $nfc_enabled = get_post_meta($post_id, '_passentry_nfc_enabled', true);
        if ($nfc_enabled === 'yes') {
            $custom_nfc_value = isset($field_mappings['custom_nfc_value']) ? $field_mappings['custom_nfc_value']['value'] : '';
            $custom_nfc_type = isset($field_mappings['custom_nfc_value']) ? $field_mappings['custom_nfc_value']['type'] : 'static';
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
                    <td>
                        <select name="passentry_type_custom_nfc_value" style="width: 100%;">
                            <option value="static" ' . selected($custom_nfc_type, 'static', false) . '>' . 
                                esc_html__('Static', 'woocommerce-passentry-api') . '</option>
                            <option value="dynamic" ' . selected($custom_nfc_type, 'dynamic', false) . '>' . 
                                esc_html__('Dynamic', 'woocommerce-passentry-api') . '</option>
                        </select>
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
                        $stored_value = '';
                        $stored_type = 'static';

                        if (!empty($field_mappings[$field_id])) {
                            error_log("FIELD MAPPING: " . json_encode($field_mappings[$field_id]));
                            $stored_value = $field_mappings[$field_id]['value'] ?? '';
                            $stored_type = $field_mappings[$field_id]['type'] ?? 'static';
                        }
                        // error_log("STORED VALUE: " . $stored_value);
                        // error_log("STORED TYPE: " . $stored_type);
                        // error_log("COMPUTED TYPE:" . print_r($field_mappings[$field_id]['type'], true));

                        echo '<tr>
                                <td>' . esc_html($field_label) . '</td>
                                <td>';
                        
                        // Output hidden fields
                        echo '<input type="hidden" name="passentry_field_' . esc_attr($field_id) . '" value="' . esc_attr($field_id) . '">
                              <input type="hidden" name="passentry_label_' . esc_attr($field_id) . '" value="' . esc_attr($field_label) . '">';
                        
                        // Output value field based on type
                        if ($stored_type === 'dynamic') {
                            echo '<select name="passentry_value_' . esc_attr($field_id) . '" style="width: 100%;">';
                            foreach ($this->get_dynamic_values() as $value => $label) {
                                echo '<option value="' . esc_attr($value) . '" ' . 
                                     selected($stored_value, $value, false) . '>' . 
                                     esc_html($label) . '</option>';
                            }
                            echo '</select>';
                        } else {
                            echo '<input type="text" name="passentry_value_' . esc_attr($field_id) . 
                                 '" class="regular-text" value="' . esc_attr($stored_value) . 
                                 '" style="width: 100%;">';
                        }

                        echo '</td>
                              <td>
                                  <select name="passentry_type_' . esc_attr($field_id) . '" style="width: 100%;">
                                      <option value="static" ' . selected($stored_type, 'static', false) . '>' . 
                                          esc_html__('Static', 'woocommerce-passentry-api') . '</option>
                                      <option value="dynamic" ' . selected($stored_type, 'dynamic', false) . '>' . 
                                          esc_html__('Dynamic', 'woocommerce-passentry-api') . '</option>
                                  </select>
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
        
        // error_log('PassEntry: Loading template fields for UUID: ' . $template_uuid);
        
        $template = self::get_template_by_uuid($template_uuid);
        
        // error_log('PassEntry: Template data in load_template_fields: ' . json_encode($template));
        
        if ($template) {
            ob_start();
            $this->render_pass_options(
                get_post_meta($post_id, '_passentry_qr_enabled', true) ?: 'no',
                get_post_meta($post_id, '_passentry_nfc_enabled', true) ?: 'no'
            );
            $this->render_template_fields($template_uuid, $post_id);
            $html = ob_get_clean();
            
            // error_log('PassEntry: Generated HTML: ' . $html);
            
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

        // error_log("\n=== PassEntry Save Template Fields ===");
        // $log_data = [
        //     'post_id' => $post_id,
        //     'post_data' => $_POST,
        //     'final_meta' => get_post_meta($post_id)
        // ];
        
        // error_log(json_encode($log_data));
        // error_log("=====================================\n");

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
        
        // First handle special fields (QR and NFC)
        if ($qr_enabled === 'yes' && isset($_POST['passentry_value_qr_value'])) {
            $field_mappings->qr_value = (object)[
                'id' => 'qr_value',
                'label' => 'QR Code Value',
                'value' => sanitize_text_field($_POST['passentry_value_qr_value']),
                'type' => sanitize_text_field($_POST['passentry_type_qr_value'] ?? 'static')
            ];
        }

        if ($nfc_enabled === 'yes' && isset($_POST['passentry_value_custom_nfc_value'])) {
            $field_mappings->custom_nfc_value = (object)[
                'id' => 'custom_nfc_value',
                'label' => 'Custom NFC Value',
                'value' => sanitize_text_field($_POST['passentry_value_custom_nfc_value']),
                'type' => sanitize_text_field($_POST['passentry_type_custom_nfc_value'] ?? 'static')
            ];
        }
        error_log("POST :" . json_encode($_POST));
        
        // Then handle template fields
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'passentry_value_') === 0) {
                $field_id = str_replace('passentry_value_', '', $key);
                
                // Skip QR and NFC fields as they're already handled
                if (in_array($field_id, ['qr_value', 'custom_nfc_value'])) {
                    continue;
                }
                
                // Get the corresponding value and type keys
                $value_key = "passentry_value_{$field_id}";
                $type_key = "passentry_type_{$field_id}";
                $label_key = "passentry_label_{$field_id}";

                error_log("FIELD ID: " . $field_id);
                error_log("TYPE KEY: " . $type_key);
                error_log("TYPE VALUE: " . $_POST[$type_key]);
                
                // Only process if we have both value and type keys in POST data
                if (isset($_POST[$value_key])) {
                    $field_mappings->$field_id = (object)[
                        'id' => sanitize_text_field($field_id),
                        'label' => sanitize_text_field($_POST[$label_key] ?? ''),
                        'value' => sanitize_text_field($_POST[$value_key]),
                        'type' => sanitize_text_field($_POST[$type_key] ?? 'static')
                    ];
                    
                    error_log("Saved mapping for {$field_id}:");
                    error_log(json_encode([
                        'value' => $_POST[$value_key],
                        'type' => $_POST[$type_key] ?? 'static'
                    ]));
                }
            }
        }

        // Save as JSON
        update_post_meta($post_id, '_passentry_field_mappings', json_encode($field_mappings));

        // Log final saved state
        error_log("Final post meta after save:");
        error_log(json_encode(get_post_meta($post_id), true));
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
