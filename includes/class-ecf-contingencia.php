<?php
defined('ABSPATH') || exit;

/**
 * Handles contingencia (fallback) mode when ECF API is unreachable.
 *
 * Flow:
 * 1. Order handler fails to submit ECF after max retries
 * 2. Contingencia activates: assigns a pre-loaded B-series code
 * 3. Background cron monitors API availability
 * 4. When API recovers, converts B-series orders to real eNCFs
 */
class Ecf_Contingencia {

    public const META_B_SERIES_CODE = '_ecf_b_series_code';
    public const META_CONTINGENCIA_CONVERTED = '_ecf_contingencia_converted';

    public static function init(): void {
        // Recovery cron
        add_action('ecf_dgii_contingencia_recovery', [self::class, 'process_recovery']);

        // Schedule recurring recovery check (every 5 minutes)
        add_action('init', [self::class, 'schedule_recovery_cron']);

        // Admin notice for pending contingencia orders
        add_action('admin_notices', [self::class, 'admin_low_stock_warning']);
    }

    /**
     * Schedule the recovery cron if not already scheduled.
     */
    public static function schedule_recovery_cron(): void {
        if (!as_has_scheduled_action('ecf_dgii_contingencia_recovery')) {
            as_schedule_recurring_action(
                time(),
                5 * MINUTE_IN_SECONDS,
                'ecf_dgii_contingencia_recovery',
                [],
                'ecf-dgii'
            );
        }
    }

    /**
     * Activate contingencia mode for an order.
     * Assigns a B-series code and marks the order for later conversion.
     */
    public static function activate(\WC_Order $order): bool {
        $ecf_type = $order->get_meta(Ecf_Order_Handler::META_ECF_TYPE) ?: 'E32';

        // Claim a B-series code
        $b_code = Ecf_Sequence_Manager::claim_next($ecf_type, 'B');
        if (!$b_code) {
            $order->update_meta_data(Ecf_Order_Handler::META_ECF_STATUS, Ecf_Order_Handler::STATUS_ERROR);
            $order->update_meta_data(Ecf_Order_Handler::META_ECF_ERRORS,
                __('Contingencia failed: No B-series codes available.', 'ecf-dgii-invoicing'));
            $order->save();
            $order->add_order_note(__('ECF CRITICAL: No B-series codes available for contingencia!', 'ecf-dgii-invoicing'));
            return false;
        }

        $order->update_meta_data(self::META_B_SERIES_CODE, $b_code['encf']);
        $order->update_meta_data(Ecf_Order_Handler::META_ECF_STATUS, Ecf_Order_Handler::STATUS_CONTINGENCIA);
        $order->update_meta_data(self::META_CONTINGENCIA_CONVERTED, 'no');
        $order->save();

        $order->add_order_note(
            sprintf(
                __('ECF: Entered contingencia mode. B-series code: %s. Will auto-convert when API recovers.', 'ecf-dgii-invoicing'),
                $b_code['encf']
            )
        );

        return true;
    }

    /**
     * Process recovery: find contingencia orders and try to convert them to real eNCFs.
     */
    public static function process_recovery(): void {
        // First, check if API is reachable
        if (!self::is_api_available()) {
            return;
        }

        // Find orders in contingencia that haven't been converted
        $orders = wc_get_orders([
            'meta_key' => Ecf_Order_Handler::META_ECF_STATUS,
            'meta_value' => Ecf_Order_Handler::STATUS_CONTINGENCIA,
            'limit' => 10, // Process in batches
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        foreach ($orders as $order) {
            $converted = $order->get_meta(self::META_CONTINGENCIA_CONVERTED);
            if ($converted === 'yes') {
                continue;
            }

            self::convert_to_encf($order);
        }
    }

    /**
     * Check if the ECF API is currently available.
     */
    private static function is_api_available(): bool {
        try {
            $client = new Ecf_Api_Client();
            $rnc = Ecf_Settings::get_company_rnc();
            if (empty($rnc)) {
                return false;
            }
            // Simple health check — try to fetch company data
            $client->get_company($rnc);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Convert a contingencia order from B-series to a real eNCF.
     * Submits synchronously and schedules polling.
     */
    public static function convert_to_encf(\WC_Order $order): bool {
        $ecf_type = $order->get_meta(Ecf_Order_Handler::META_ECF_TYPE) ?: 'E32';

        // Claim a new eNCF sequence
        $sequence = Ecf_Sequence_Manager::claim_next($ecf_type, 'E');
        if (!$sequence) {
            $order->add_order_note(
                sprintf(__('ECF Recovery: No eNCF sequences available for %s. Skipping.', 'ecf-dgii-invoicing'), $ecf_type)
            );
            return false;
        }

        $b_code = $order->get_meta(self::META_B_SERIES_CODE);

        $order->add_order_note(
            sprintf(
                __('ECF Recovery: Converting from B-series %s to eNCF %s', 'ecf-dgii-invoicing'),
                $b_code,
                $sequence['encf']
            )
        );

        // Submit synchronously
        try {
            woo_ecf_dgii_autoloader();
            $order->update_meta_data(Ecf_Order_Handler::META_ECF_ENCF, $sequence['encf']);

            $ecf = Ecf_Mapper::map_order($order, $ecf_type, $sequence['encf'], $sequence['expiration_date']);

            $client = new Ecf_Api_Client();
            $result = $client->submit_ecf($ecf);

            $order->update_meta_data(Ecf_Order_Handler::META_ECF_STATUS, Ecf_Order_Handler::STATUS_SUBMITTING);
            $order->update_meta_data(Ecf_Order_Handler::META_ECF_MESSAGE_ID, $result->getMessageId() ?? '');
            $order->update_meta_data(self::META_CONTINGENCIA_CONVERTED, 'yes');
            $order->save();

            // Schedule async polling
            as_schedule_single_action(
                time(),
                'ecf_dgii_poll_ecf',
                ['order_id' => $order->get_id(), 'message_id' => $result->getMessageId()],
                'ecf-dgii'
            );

            return true;
        } catch (\Exception $e) {
            $order->add_order_note(
                sprintf(__('ECF Recovery failed: %s', 'ecf-dgii-invoicing'), $e->getMessage())
            );
            // Revert — stay in contingencia
            $order->update_meta_data(Ecf_Order_Handler::META_ECF_STATUS, Ecf_Order_Handler::STATUS_CONTINGENCIA);
            $order->save();
            return false;
        }
    }

    /**
     * Show admin warning when B-series stock is low.
     */
    public static function admin_low_stock_warning(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $sequences = Ecf_Sequence_Manager::get_all_sequences();
        foreach ($sequences as $seq) {
            if ($seq['serie'] === 'B' && (int) $seq['remaining'] <= 10 && (int) $seq['remaining'] > 0) {
                echo '<div class="notice notice-warning"><p>';
                printf(
                    esc_html__('ECF DGII: Low B-series stock for %s — only %d codes remaining.', 'ecf-dgii-invoicing'),
                    esc_html($seq['ecf_type']),
                    (int) $seq['remaining']
                );
                echo '</p></div>';
            }
        }
    }

    /**
     * Get count of orders pending contingencia conversion.
     */
    public static function get_pending_count(): int {
        $orders = wc_get_orders([
            'meta_key' => Ecf_Order_Handler::META_ECF_STATUS,
            'meta_value' => Ecf_Order_Handler::STATUS_CONTINGENCIA,
            'return' => 'ids',
            'limit' => -1,
        ]);

        return count($orders);
    }
}
