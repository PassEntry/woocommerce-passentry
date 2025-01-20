<?php
/**
 * Plugin Name: WooCommerce PassEntry API Integration
 * Description: Integrates WooCommerce with PassEntry API to manage templates and create template items upon purchase.
 * Version: %%VERSION%%
 * Author: PassEntry
 * Text Domain: woocommerce-passentry-api
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Add this near the top of the file, after the initial checks
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}
if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}
if (!defined('WP_DEBUG_DISPLAY')) {
    define('WP_DEBUG_DISPLAY', false);
}
@ini_set('display_errors', 0);

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
    
    new API_Handler();
    new Product_Template_Handler();
    new Order_Template_Handler();
    new Settings_Handler();
    new Debug_Handler();
}
add_action('plugins_loaded', 'woocommerce_passentry_api_init');

// Add Bootstrap resources
add_action('wp_enqueue_scripts', function() {
    if (is_wc_endpoint_url('order-received')) {
        // Add Bootstrap CSS from CDN
        wp_enqueue_style('bootstrap', 
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'
        );
        
        // Add Bootstrap JS from CDN
        wp_enqueue_script('bootstrap', 
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
            [],
            null,
            true
        );
    }
});

// CHANGE: Add this new function to create the Settings link
function woocommerce_passentry_api_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=passentry-api-settings') . '">' . __('Settings', 'woocommerce-passentry-api') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'woocommerce_passentry_api_plugin_action_links');
