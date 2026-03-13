<?php
defined('ABSPATH') || exit;

use Ecfx\EcfDgii\EcfProcessingException;
use Ecfx\EcfDgii\EcfPollingTimeoutException;

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
    public const META_ECF_EXPIRATION_DATE = '_ecf_expiration_date';
    public const META_ECF_IMPRESION_URL = '_ecf_impresion_url';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUBMITTING = 'submitting';
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

        // Action Scheduler hook for polling
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

        // Determine ECF type
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

        $order->update_meta_data(self::META_ECF_TYPE, $ecf_type);
        $order->update_meta_data(self::META_ECF_ENCF, $sequence['encf']);
        $order->update_meta_data(self::META_ECF_EXPIRATION_DATE, $sequence['expiration_date']);

        // Submit synchronously (fire-and-forget — don't wait for DGII result)
        try {
            woo_ecf_dgii_autoloader();
            $ecf = Ecf_Mapper::map_order($order, $ecf_type, $sequence['encf'], $sequence['expiration_date']);

            $client = new Ecf_Api_Client();
            $result = $client->submit_ecf($ecf);

            $order->update_meta_data(self::META_ECF_STATUS, self::STATUS_SUBMITTING);
            $order->update_meta_data(self::META_ECF_MESSAGE_ID, $result->getMessageId() ?? '');
            $order->save();

            // Schedule async polling for the result
            as_schedule_single_action(
                time(),
                'ecf_dgii_poll_ecf',
                ['order_id' => $order_id, 'message_id' => $result->getMessageId()],
                'ecf-dgii'
            );
        } catch (\Exception $e) {
            $order->update_meta_data(self::META_ECF_STATUS, self::STATUS_ERROR);
            $order->update_meta_data(self::META_ECF_ERRORS, $e->getMessage());
            $order->save();
            $order->add_order_note(
                sprintf(__('ECF submission failed: %s', 'woo-ecf-dgii'), $e->getMessage())
            );
        }
    }

    public static function async_poll_ecf(int $order_id, string $message_id): void {
        woo_ecf_dgii_autoloader();
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $rnc = Ecf_Settings::get_company_rnc();

        try {
            $client = new Ecf_Api_Client();
            $result = $client->poll_ecf($rnc, $message_id);

            $order->update_meta_data(self::META_ECF_STATUS, self::STATUS_ACCEPTED);
            $order->update_meta_data(self::META_ECF_CODSEC, $result->getCodSec() ?? '');
            $order->update_meta_data(self::META_ECF_IMPRESION_URL, $result->getImpresionUrl() ?? '');
            $order->update_meta_data(self::META_ECF_RESPONSE, json_encode($result->jsonSerialize()));
            $order->update_meta_data(self::META_ECF_ERRORS, '');
            $order->save();

            $order->add_order_note(
                sprintf(
                    __('ECF accepted! eNCF: %s, Security Code: %s', 'woo-ecf-dgii'),
                    $order->get_meta(self::META_ECF_ENCF),
                    $result->getCodSec() ?? 'N/A'
                )
            );
        } catch (EcfProcessingException $e) {
            $ecf_response = $e->getEcfResponse();
            $order->update_meta_data(self::META_ECF_STATUS, self::STATUS_REJECTED);
            $order->update_meta_data(self::META_ECF_ERRORS, $ecf_response ? ($ecf_response->getErrors() ?? $e->getMessage()) : $e->getMessage());
            $order->save();

            $order->add_order_note(
                sprintf(__('ECF rejected: %s', 'woo-ecf-dgii'), $e->getMessage())
            );
        } catch (EcfPollingTimeoutException $e) {
            $order->add_order_note(__('ECF polling timed out.', 'woo-ecf-dgii'));

            if (!Ecf_Contingencia::activate($order)) {
                $order->update_meta_data(self::META_ECF_STATUS, self::STATUS_ERROR);
                $order->update_meta_data(self::META_ECF_ERRORS, $e->getMessage());
                $order->save();
            }
        } catch (\Exception $e) {
            $order->add_order_note(
                sprintf(__('ECF polling failed: %s', 'woo-ecf-dgii'), $e->getMessage())
            );

            if (!Ecf_Contingencia::activate($order)) {
                $order->update_meta_data(self::META_ECF_STATUS, self::STATUS_ERROR);
                $order->update_meta_data(self::META_ECF_ERRORS, $e->getMessage());
                $order->save();
            }
        }
    }
}
