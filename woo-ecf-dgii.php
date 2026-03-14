<?php
/**
 * Plugin Name: ECF DGII Invoicing for WooCommerce
 * Plugin URI: https://github.com/SSD-Smart-Software-Development-SRL/ecf-ssd-woocomerce
 * Description: Electronic fiscal documents (ECF) for Dominican Republic via ECF SSD API. Automatically sends invoices to DGII when WooCommerce orders are paid.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Author: SSD Smart Software Development SRL
 * Author URI: https://ssd.com.do
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: ecf-dgii-invoicing
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.6
 */

defined('ABSPATH') || exit;

define('WOO_ECF_DGII_VERSION', '1.0.0'); // x-release-please-version
define('WOO_ECF_DGII_PLUGIN_FILE', __FILE__);
define('WOO_ECF_DGII_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WOO_ECF_DGII_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Load Composer autoloader on demand to avoid class conflicts with WooCommerce
 * bundled dependencies (e.g., sabberworm/php-css-parser).
 */
function woo_ecf_dgii_autoloader(): void {
    static $loaded = false;
    if (!$loaded && file_exists(WOO_ECF_DGII_PLUGIN_DIR . 'vendor/autoload.php')) {
        require_once WOO_ECF_DGII_PLUGIN_DIR . 'vendor/autoload.php';
        $loaded = true;
    }
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

// Check WooCommerce is active before initializing
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>';
            echo esc_html__('WooCommerce ECF DGII requires WooCommerce to be installed and active.', 'ecf-dgii-invoicing');
            echo '</p></div>';
        });
        return;
    }

    require_once WOO_ECF_DGII_PLUGIN_DIR . 'includes/class-ecf-plugin.php';
    Ecf_Plugin::instance();
});

// Activation hook: create custom tables
register_activation_hook(__FILE__, function () {
    require_once WOO_ECF_DGII_PLUGIN_DIR . 'includes/class-ecf-plugin.php';
    Ecf_Plugin::activate();
});
