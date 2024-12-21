<?php
/**
 * Plugin Name: WooCommerce PassEntry API Integration
 * Description: Integrates WooCommerce with PassEntry API to manage templates and create template items upon purchase.
 * Version: 1.2
 * Author: Your Name
 * Text Domain: woocommerce-passentry-api
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Check for WooCommerce dependency.
function woocommerce_passentry_api_check_dependencies() {
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('WooCommerce PassEntry API Integration requires WooCommerce to be installed and active.', 'woocommerce-passentry-api'),
            __('Plugin Dependency Check', 'woocommerce-passentry-api'),
            ['back_link' => true]
        );
    }
}
add_action('admin_init', 'woocommerce_passentry_api_check_dependencies');

// Include necessary files.
require_once plugin_dir_path(__FILE__) . 'includes/class-api-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-product-template-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-order-template-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-settings-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-debug-handler.php';

// Initialize the plugin.
function woocommerce_passentry_api_init() {
    // Add debug output
    error_log('PassEntry Plugin: Initializing plugin...');
    
    // new API_Handler();
    // new Product_Template_Handler();
    // new Order_Template_Handler();
    $settings = new Settings_Handler();
    error_log('PassEntry Plugin: Settings handler initialized');
    new Debug_Handler();
}
add_action('plugins_loaded', 'woocommerce_passentry_api_init');
