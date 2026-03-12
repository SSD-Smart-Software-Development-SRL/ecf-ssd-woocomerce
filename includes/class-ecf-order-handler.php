<?php
defined('ABSPATH') || exit;

class Ecf_Order_Handler {

    public const META_ECF_TYPE = '_ecf_type';
    public const META_ECF_ENCF = '_ecf_encf';
    public const META_ECF_STATUS = '_ecf_status';
    public const META_ECF_CODSEC = '_ecf_codsec';
    public const META_ECF_MESSAGE_ID = '_ecf_message_id';
    public const META_ECF_RESPONSE = '_ecf_response';
    public const META_ECF_ERRORS = '_ecf_errors';
    public const META_ECF_RNC_COMPRADOR = '_ecf_rnc_comprador';
    public const META_ECF_RAZON_SOCIAL = '_ecf_razon_social';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUBMITTING = 'submitting';
    public const STATUS_POLLING = 'polling';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ERROR = 'error';
    public const STATUS_CONTINGENCIA = 'contingencia';

    public static function init(): void {
        // Primary hook: fires on successful payment
        add_action('woocommerce_payment_complete', [self::class, 'on_payment_complete']);

        // Fallback hooks for offline payment methods
        add_action('woocommerce_order_status_processing', [self::class, 'on_order_processing']);
        add_action('woocommerce_order_status_completed', [self::class, 'on_order_completed']);

        // Action Scheduler hooks
        add_action('ecf_dgii_submit_ecf', [self::class, 'async_submit_ecf'], 10, 2);
        add_action('ecf_dgii_poll_ecf', [self::class, 'async_poll_ecf'], 10, 2);
    }

    public static function on_payment_complete(int $order_id): void {
        self::schedule_ecf_submission($order_id);
    }

    public static function on_order_processing(int $order_id): void {
        self::schedule_ecf_submission($order_id);
    }

    public static function on_order_completed(int $order_id): void {
        self::schedule_ecf_submission($order_id);
    }

    private static function schedule_ecf_submission(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Don't re-process orders that already have an ECF
        $existing_status = $order->get_meta(self::META_ECF_STATUS);
        if ($existing_status && $existing_status !== self::STATUS_ERROR) {
            return;
        }

        // Determine ECF type:
        // - If checkout already set the type (user selected), use that
        // - If RNC provided but no type set, use the configured default
        // - If no RNC, always E32
        $rnc = $order->get_meta(self::META_ECF_RNC_COMPRADOR);
        $existing_type = $order->get_meta(self::META_ECF_TYPE);
        if ($existing_type && in_array($existing_type, ['E31', 'E32', 'E33', 'E34'], true)) {
            $ecf_type = $existing_type;
        } elseif ($rnc) {
            $ecf_type = get_option(Ecf_Settings::OPTION_DEFAULT_ECF_TYPE, 'E31');
        } else {
            $ecf_type = 'E32';
        }

        // Claim eNCF sequence
        $sequence = Ecf_Sequence_Manager::claim_next($ecf_type);
        if (!$sequence) {
            $order->update_meta_data(self::META_ECF_STATUS, self::STATUS_ERROR);
            $order->update_meta_data(self::META_ECF_ERRORS, __('No available eNCF sequences for type ', 'woo-ecf-dgii') . $ecf_type);
            $order->save();
            $order->add_order_note(
                sprintf(__('ECF Error: No available eNCF sequences for %s.', 'woo-ecf-dgii'), $ecf_type)
            );
            return;
        }

        // Store initial ECF data on the order
        $order->update_meta_data(self::META_ECF_TYPE, $ecf_type);
        $order->update_meta_data(self::META_ECF_ENCF, $sequence['encf']);
        $order->update_meta_data(self::META_ECF_STATUS, self::STATUS_PENDING);
        $order->save();

        // Schedule async submission
        as_schedule_single_action(
            time(),
            'ecf_dgii_submit_ecf',
            ['order_id' => $order_id, 'expiration_date' => $sequence['expiration_date']],
            'ecf-dgii'
        );
    }

    public static function async_submit_ecf(int $order_id, string $expiration_date): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $ecf_type = $order->get_meta(self::META_ECF_TYPE);
        $encf = $order->get_meta(self::META_ECF_ENCF);

        $order->update_meta_data(self::META_ECF_STATUS, self::STATUS_SUBMITTING);
        $order->save();

        try {
            $ecf = Ecf_Mapper::map_order($order, $ecf_type, $encf, $expiration_date);

            $client = new Ecf_Api_Client();
            $response = $client->submit_ecf($ecf, $ecf_type);

            $message_id = $response->getMessageId();
            $order->update_meta_data(self::META_ECF_MESSAGE_ID, $message_id);
            $order->update_meta_data(self::META_ECF_STATUS, self::STATUS_POLLING);
            $order->save();

            $order->add_order_note(
                sprintf(__('ECF submitted. eNCF: %s, MessageId: %s', 'woo-ecf-dgii'), $encf, $message_id)
            );

            // Schedule first poll
            as_schedule_single_action(
                time() + 3,
                'ecf_dgii_poll_ecf',
                ['order_id' => $order_id, 'attempt' => 1],
                'ecf-dgii'
            );
        } catch (\Exception $e) {
            $order->add_order_note(
                sprintf(__('ECF submission failed: %s', 'woo-ecf-dgii'), $e->getMessage())
            );

            // Activate contingencia mode
            if (!Ecf_Contingencia::activate($order)) {
                $order->update_meta_data(self::META_ECF_STATUS, self::STATUS_ERROR);
                $order->update_meta_data(self::META_ECF_ERRORS, $e->getMessage());
                $order->save();
            }
        }
    }

    public static function async_poll_ecf(int $order_id, int $attempt): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $max_polls = (int) get_option(Ecf_Settings::OPTION_RETRY_MAX, 3) * 10;
        $retry_interval = (int) get_option(Ecf_Settings::OPTION_RETRY_INTERVAL, 5);
        $rnc = Ecf_Settings::get_company_rnc();
        $message_id = $order->get_meta(self::META_ECF_MESSAGE_ID);

        try {
            $client = new Ecf_Api_Client();
            $results = $client->get_ecf_status($rnc, $message_id);

            if (empty($results)) {
                throw new \RuntimeException('Empty response from ECF status check');
            }

            $result = $results[0];
            $progress = $result->getProgress();
            $progress_value = is_object($progress) ? ($progress->value ?? (string)$progress) : (string)$progress;

            if (strtolower($progress_value) === 'finished') {
                $order->update_meta_data(self::META_ECF_STATUS, self::STATUS_ACCEPTED);
                $order->update_meta_data(self::META_ECF_CODSEC, $result->getCodSec() ?? '');
                $order->update_meta_data(self::META_ECF_RESPONSE, json_encode($result->jsonSerialize()));
                $order->save();
                $order->add_order_note(
                    sprintf(
                        __('ECF accepted! eNCF: %s, Security Code: %s', 'woo-ecf-dgii'),
                        $order->get_meta(self::META_ECF_ENCF),
                        $result->getCodSec() ?? 'N/A'
                    )
                );
                return;
            }

            if (strtolower($progress_value) === 'error') {
                $order->update_meta_data(self::META_ECF_STATUS, self::STATUS_REJECTED);
                $order->update_meta_data(self::META_ECF_ERRORS, $result->getErrors() ?? '');
                $order->save();
                $order->add_order_note(
                    sprintf(__('ECF rejected: %s', 'woo-ecf-dgii'), $result->getErrors() ?? 'Unknown error')
                );
                return;
            }

            // Still processing — schedule another poll
            if ($attempt < $max_polls) {
                as_schedule_single_action(
                    time() + $retry_interval,
                    'ecf_dgii_poll_ecf',
                    ['order_id' => $order_id, 'attempt' => $attempt + 1],
                    'ecf-dgii'
                );
            } else {
                // Polling timed out — activate contingencia
                $order->add_order_note(__('ECF polling timed out.', 'woo-ecf-dgii'));
                Ecf_Contingencia::activate($order);
            }
        } catch (\Exception $e) {
            if ($attempt < $max_polls) {
                as_schedule_single_action(
                    time() + $retry_interval,
                    'ecf_dgii_poll_ecf',
                    ['order_id' => $order_id, 'attempt' => $attempt + 1],
                    'ecf-dgii'
                );
            } else {
                // All retries exhausted — activate contingencia
                $order->add_order_note(
                    sprintf(__('ECF polling failed: %s', 'woo-ecf-dgii'), $e->getMessage())
                );
                Ecf_Contingencia::activate($order);
            }
        }
    }
}
