<?php

if (!defined('ABSPATH')) {
    exit;
}

class Debug_Handler {
    public function __construct() {
        add_action('admin_init', [$this, 'check_debug_mode']);
    }

    public function check_debug_mode() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(__('Debug mode enabled for PassEntry API Plugin.', 'woocommerce-passentry-api'));
        }
    }
}
