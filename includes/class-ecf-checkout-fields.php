<?php
defined('ABSPATH') || exit;

class Ecf_Checkout_Fields {

    public static function init(): void {
        // Add fields after billing fields
        add_action('woocommerce_after_checkout_billing_form', [self::class, 'render_fields']);

        // Validate fields on checkout
        add_action('woocommerce_checkout_process', [self::class, 'validate_fields']);

        // Save fields to order meta
        add_action('woocommerce_checkout_create_order', [self::class, 'save_fields'], 10, 2);

        // Enqueue checkout JS
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_scripts']);
    }

    /**
     * Render RNC, Razón Social, and Tipo de Comprobante fields at checkout.
     */
    public static function render_fields($checkout): void {
        $default_ecf_type = get_option(Ecf_Settings::OPTION_DEFAULT_ECF_TYPE, 'E31');

        echo '<div id="ecf-dgii-checkout-fields">';
        echo '<h3>' . esc_html__('Fiscal Information', 'ecf-dgii-invoicing') . '</h3>';

        // RNC o Cédula
        woocommerce_form_field('ecf_rnc_comprador', [
            'type' => 'text',
            'class' => ['form-row-wide'],
            'label' => __('RNC o Cédula', 'ecf-dgii-invoicing'),
            'placeholder' => __('Optional - Required for orders >= 250,000 DOP', 'ecf-dgii-invoicing'),
            'required' => false,
            'custom_attributes' => [
                'maxlength' => '11',
                'pattern' => '[0-9]*',
            ],
        ], $checkout->get_value('ecf_rnc_comprador'));

        // Razón Social (visible only when RNC is filled)
        woocommerce_form_field('ecf_razon_social', [
            'type' => 'text',
            'class' => ['form-row-wide', 'ecf-rnc-dependent'],
            'label' => __('Razón Social', 'ecf-dgii-invoicing'),
            'required' => false,
        ], $checkout->get_value('ecf_razon_social'));

        // Tipo de Comprobante (visible only when RNC is filled)
        woocommerce_form_field('ecf_tipo_comprobante', [
            'type' => 'select',
            'class' => ['form-row-wide', 'ecf-rnc-dependent'],
            'label' => __('Tipo de Comprobante', 'ecf-dgii-invoicing'),
            'required' => false,
            'options' => [
                'E31' => __('Crédito Fiscal (E31)', 'ecf-dgii-invoicing'),
                'E32' => __('Consumo (E32)', 'ecf-dgii-invoicing'),
            ],
            'default' => $default_ecf_type,
        ], $checkout->get_value('ecf_tipo_comprobante'));

        echo '</div>';
    }

    /**
     * Validate checkout fields.
     */
    public static function validate_fields(): void {
        $rnc = sanitize_text_field($_POST['ecf_rnc_comprador'] ?? '');
        $razon_social = sanitize_text_field($_POST['ecf_razon_social'] ?? '');

        // Get cart total for 250k validation
        $total = WC()->cart ? (float) WC()->cart->get_total('edit') : 0;

        // If total >= 250,000 DOP, RNC is mandatory
        if ($total >= 250000 && empty($rnc)) {
            wc_add_notice(
                __('RNC o Cédula is required for orders of 250,000 DOP or more.', 'ecf-dgii-invoicing'),
                'error'
            );
            return;
        }

        // If RNC is provided, validate format
        if (!empty($rnc)) {
            $rnc_clean = preg_replace('/[^0-9]/', '', $rnc);
            $len = strlen($rnc_clean);

            if ($len !== 9 && $len !== 11) {
                wc_add_notice(
                    __('RNC must be 9 digits or Cédula must be 11 digits.', 'ecf-dgii-invoicing'),
                    'error'
                );
                return;
            }

            // Razón Social required when RNC is provided
            if (empty($razon_social)) {
                wc_add_notice(
                    __('Razón Social is required when RNC or Cédula is provided.', 'ecf-dgii-invoicing'),
                    'error'
                );
            }
        }
    }

    /**
     * Save ECF fields to order meta.
     */
    public static function save_fields(\WC_Order $order, array $data): void {
        $rnc = sanitize_text_field($_POST['ecf_rnc_comprador'] ?? '');
        $razon_social = sanitize_text_field($_POST['ecf_razon_social'] ?? '');
        $tipo = sanitize_text_field($_POST['ecf_tipo_comprobante'] ?? '');

        if (!empty($rnc)) {
            $rnc_clean = preg_replace('/[^0-9]/', '', $rnc);
            $order->update_meta_data(Ecf_Order_Handler::META_ECF_RNC_COMPRADOR, $rnc_clean);
            $order->update_meta_data(Ecf_Order_Handler::META_ECF_RAZON_SOCIAL, $razon_social);

            // Set ECF type from user selection
            $allowed_types = ['E31', 'E32'];
            $ecf_type = in_array($tipo, $allowed_types, true)
                ? $tipo
                : get_option(Ecf_Settings::OPTION_DEFAULT_ECF_TYPE, 'E31');
            $order->update_meta_data(Ecf_Order_Handler::META_ECF_TYPE, $ecf_type);
        }
    }

    /**
     * Enqueue checkout JavaScript for dynamic field visibility.
     */
    public static function enqueue_scripts(): void {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_script(
            'ecf-dgii-checkout',
            WOO_ECF_DGII_PLUGIN_URL . 'assets/js/checkout.js',
            ['jquery'],
            WOO_ECF_DGII_VERSION,
            true
        );

        wp_localize_script('ecf-dgii-checkout', 'ecfDgiiCheckout', [
            'maxWithoutRnc' => 250000,
            'requiredMessage' => __('RNC o Cédula (required for this order)', 'ecf-dgii-invoicing'),
            'optionalMessage' => __('RNC o Cédula', 'ecf-dgii-invoicing'),
        ]);
    }
}
