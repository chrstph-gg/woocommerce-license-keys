<?php
/**
 * Plugin Name: WooCommerce License Key Generator
 * Description: Generates unique license keys for specific WooCommerce products and tracks activation status.
 * Version:     1.0
 * Author:      chrstph.gg
 * License:     GPL3
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_License_Key_Generator {
    
    public function __construct() {
        add_action('init', array($this, 'create_license_keys_table'));
        add_action('woocommerce_order_status_completed', array($this, 'generate_license_key'), 10, 1);
        add_filter('woocommerce_email_order_meta_fields', array($this, 'add_license_key_to_email'), 10, 3);
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_license_key_checkbox'));
        add_action('woocommerce_process_product_meta', array($this, 'save_license_key_checkbox'));
    }

    public function create_license_keys_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'license_keys';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            license_key VARCHAR(19) NOT NULL,
            activation_status TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function generate_license_key($order_id) {
        global $wpdb;
        $order = wc_get_order($order_id);
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if (get_post_meta($product_id, '_enable_license_key', true) === 'yes') {
                $license_key = $this->generate_random_key();
                $wpdb->insert(
                    $wpdb->prefix . 'license_keys',
                    [
                        'order_id' => $order_id,
                        'product_id' => $product_id,
                        'license_key' => $license_key,
                        'activation_status' => 0
                    ],
                    ['%d', '%d', '%s', '%d']
                );
            }
        }
    }

    private function generate_random_key() {
        return strtoupper(implode('-', str_split(bin2hex(random_bytes(8)), 4)));
    }

    public function add_license_key_to_email($fields, $sent_to_admin, $order) {
        global $wpdb;
        $order_id = $order->get_id();
        $table_name = $wpdb->prefix . 'license_keys';
        $keys = $wpdb->get_results($wpdb->prepare("SELECT license_key FROM $table_name WHERE order_id = %d", $order_id));

        if (!empty($keys)) {
            $fields['license_key'] = [
                'label' => __('License Key', 'woocommerce'),
                'value' => implode(', ', wp_list_pluck($keys, 'license_key'))
            ];
        }
        return $fields;
    }

    public function register_admin_page() {
        add_menu_page('License Keys', 'License Keys', 'manage_options', 'license_keys', array($this, 'display_license_keys_page'));
    }

    public function display_license_keys_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'license_keys';
        $keys = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
        echo '<div class="wrap"><h2>License Keys</h2><table class="widefat"><thead><tr><th>Order ID</th><th>Product ID</th><th>License Key</th><th>Activation Status</th><th>Created At</th></tr></thead><tbody>';
        foreach ($keys as $key) {
            echo '<tr>';
            echo '<td>' . esc_html($key->order_id) . '</td>';
            echo '<td>' . esc_html($key->product_id) . '</td>';
            echo '<td>' . esc_html($key->license_key) . '</td>';
            echo '<td>' . ($key->activation_status ? 'Activated' : 'Not Activated') . '</td>';
            echo '<td>' . esc_html($key->created_at) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    public function add_license_key_checkbox() {
        echo '<div class="options_group">';
        woocommerce_wp_checkbox([
            'id' => '_enable_license_key',
            'label' => __('Enable License Key Generation', 'woocommerce'),
            'description' => __('Generate a license key for each purchase of this product.', 'woocommerce'),
            'desc_tip' => true
        ]);
        echo '</div>';
    }

    public function save_license_key_checkbox($post_id) {
        $enable_license = isset($_POST['_enable_license_key']) ? 'yes' : 'no';
        update_post_meta($post_id, '_enable_license_key', $enable_license);
    }
}

new WC_License_Key_Generator();