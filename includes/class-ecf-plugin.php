<?php
defined('ABSPATH') || exit;

class Ecf_Plugin {

    private static ?Ecf_Plugin $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies(): void {
        require_once WOO_ECF_DGII_PLUGIN_DIR . 'includes/class-ecf-settings.php';
        require_once WOO_ECF_DGII_PLUGIN_DIR . 'includes/class-ecf-sequence-manager.php';
        require_once WOO_ECF_DGII_PLUGIN_DIR . 'includes/class-ecf-api-client.php';
        require_once WOO_ECF_DGII_PLUGIN_DIR . 'includes/class-ecf-mapper.php';
        require_once WOO_ECF_DGII_PLUGIN_DIR . 'includes/class-ecf-order-handler.php';
        require_once WOO_ECF_DGII_PLUGIN_DIR . 'includes/class-ecf-checkout-fields.php';
        require_once WOO_ECF_DGII_PLUGIN_DIR . 'includes/class-ecf-refund-handler.php';
        require_once WOO_ECF_DGII_PLUGIN_DIR . 'includes/class-ecf-contingencia.php';
        require_once WOO_ECF_DGII_PLUGIN_DIR . 'includes/class-ecf-admin-order.php';
        require_once WOO_ECF_DGII_PLUGIN_DIR . 'includes/class-ecf-sequence-admin.php';
        require_once WOO_ECF_DGII_PLUGIN_DIR . 'includes/class-ecf-contingencia-admin.php';
        require_once WOO_ECF_DGII_PLUGIN_DIR . 'includes/class-ecf-invoice-generator.php';
    }

    private function init_hooks(): void {
        Ecf_Settings::init();
        Ecf_Order_Handler::init();
        Ecf_Checkout_Fields::init();
        Ecf_Refund_Handler::init();
        Ecf_Contingencia::init();
        Ecf_Admin_Order::init();
        Ecf_Sequence_Admin::init();
        Ecf_Contingencia_Admin::init();
        Ecf_Invoice_Generator::init();
    }

    public static function activate(): void {
        require_once WOO_ECF_DGII_PLUGIN_DIR . 'includes/class-ecf-sequence-manager.php';
        Ecf_Sequence_Manager::create_table();
    }
}
