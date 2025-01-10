<?php

if (!defined('ABSPATH')) {
    exit;
}

class Settings_Handler {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_settings_page() {
        add_options_page(
            __('PassEntry API Settings', 'woocommerce-passentry-api'),
            __('PassEntry API', 'woocommerce-passentry-api'),
            'manage_options',
            'passentry-api-settings',
            [$this, 'settings_page']
        );
    }

    public function register_settings() {
        register_setting('passentry_api_settings', 'passentry_api_key');
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('PassEntry API Settings', 'woocommerce-passentry-api'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('passentry_api_settings');
                do_settings_sections('passentry_api_settings');
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php _e('API Key', 'woocommerce-passentry-api'); ?></th>
                        <td>
                            <input type="text" name="passentry_api_key" value="<?php echo esc_attr(get_option('passentry_api_key')); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
