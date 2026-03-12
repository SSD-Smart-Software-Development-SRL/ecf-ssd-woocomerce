<?php
defined('ABSPATH') || exit;

use Ecfx\EcfDgii\Model\ECF;
use Ecfx\EcfDgii\Model\Encabezado;
use Ecfx\EcfDgii\Model\IdDoc;
use Ecfx\EcfDgii\Model\Emisor;
use Ecfx\EcfDgii\Model\Comprador;
use Ecfx\EcfDgii\Model\Totales;
use Ecfx\EcfDgii\Model\Item;
use Ecfx\EcfDgii\Model\Retencion;
use Ecfx\EcfDgii\Model\InformacionReferencia;
use Ecfx\EcfDgii\Model\VersionType;
use Ecfx\EcfDgii\Model\TipoeCFType;
use Ecfx\EcfDgii\Model\CodigoModificacionType;
use Ecfx\EcfDgii\Model\IndicadorFacturacionType;
use Ecfx\EcfDgii\Model\IndicadorBienoServicioType;

class Ecf_Refund_Handler {

    public const META_REFUND_ECF_STATUS = '_ecf_refund_status';
    public const META_REFUND_ECF_ENCF = '_ecf_refund_encf';
    public const META_REFUND_ECF_CODSEC = '_ecf_refund_codsec';
    public const META_REFUND_ECF_MESSAGE_ID = '_ecf_refund_message_id';
    public const META_REFUND_ECF_ERRORS = '_ecf_refund_errors';

    public static function init(): void {
        add_action('woocommerce_order_refunded', [self::class, 'on_order_refunded'], 10, 2);
        add_action('ecf_dgii_submit_refund_ecf', [self::class, 'async_submit_refund'], 10, 2);
        add_action('ecf_dgii_poll_refund_ecf', [self::class, 'async_poll_refund'], 10, 2);
    }

    /**
     * Triggered when a WooCommerce refund is created.
     */
    public static function on_order_refunded(int $order_id, int $refund_id): void {
        $order = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);

        if (!$order || !$refund) {
            return;
        }

        // Original order must have an accepted ECF
        $original_encf = $order->get_meta(Ecf_Order_Handler::META_ECF_ENCF);
        $original_status = $order->get_meta(Ecf_Order_Handler::META_ECF_STATUS);
        if (!$original_encf || $original_status !== Ecf_Order_Handler::STATUS_ACCEPTED) {
            $order->add_order_note(
                __('ECF: Cannot generate credit note — original ECF not accepted.', 'woo-ecf-dgii')
            );
            return;
        }

        // Claim E34 sequence
        $sequence = Ecf_Sequence_Manager::claim_next('E34');
        if (!$sequence) {
            $refund->update_meta_data(self::META_REFUND_ECF_STATUS, 'error');
            $refund->update_meta_data(self::META_REFUND_ECF_ERRORS, __('No available eNCF sequences for E34.', 'woo-ecf-dgii'));
            $refund->save();
            $order->add_order_note(__('ECF Error: No available E34 sequences for credit note.', 'woo-ecf-dgii'));
            return;
        }

        $refund->update_meta_data(self::META_REFUND_ECF_ENCF, $sequence['encf']);
        $refund->update_meta_data(self::META_REFUND_ECF_STATUS, 'pending');
        $refund->save();

        as_schedule_single_action(
            time(),
            'ecf_dgii_submit_refund_ecf',
            ['refund_id' => $refund_id, 'expiration_date' => $sequence['expiration_date']],
            'ecf-dgii'
        );
    }

    /**
     * Async: Build and submit E34 credit note.
     */
    public static function async_submit_refund(int $refund_id, string $expiration_date): void {
        $refund = wc_get_order($refund_id);
        if (!$refund) {
            return;
        }

        $order = wc_get_order($refund->get_parent_id());
        if (!$order) {
            return;
        }

        $encf = $refund->get_meta(self::META_REFUND_ECF_ENCF);
        $original_encf = $order->get_meta(Ecf_Order_Handler::META_ECF_ENCF);
        $company_data = Ecf_Settings::get_company_data();

        try {
            $ecf = self::build_credit_note($order, $refund, $encf, $expiration_date, $original_encf, $company_data);

            $client = new Ecf_Api_Client();
            $response = $client->submit_ecf($ecf, 'E34');

            $message_id = $response->getMessageId();
            $refund->update_meta_data(self::META_REFUND_ECF_MESSAGE_ID, $message_id);
            $refund->update_meta_data(self::META_REFUND_ECF_STATUS, 'polling');
            $refund->save();

            $order->add_order_note(
                sprintf(__('ECF Credit Note (E34) submitted. eNCF: %s', 'woo-ecf-dgii'), $encf)
            );

            as_schedule_single_action(
                time() + 3,
                'ecf_dgii_poll_refund_ecf',
                ['refund_id' => $refund_id, 'attempt' => 1],
                'ecf-dgii'
            );
        } catch (\Exception $e) {
            $refund->update_meta_data(self::META_REFUND_ECF_STATUS, 'error');
            $refund->update_meta_data(self::META_REFUND_ECF_ERRORS, $e->getMessage());
            $refund->save();
            $order->add_order_note(
                sprintf(__('ECF Credit Note failed: %s', 'woo-ecf-dgii'), $e->getMessage())
            );
        }
    }

    /**
     * Async: Poll E34 status.
     */
    public static function async_poll_refund(int $refund_id, int $attempt): void {
        $refund = wc_get_order($refund_id);
        if (!$refund) {
            return;
        }

        $order = wc_get_order($refund->get_parent_id());
        $max_polls = (int) get_option(Ecf_Settings::OPTION_RETRY_MAX, 3) * 10;
        $retry_interval = (int) get_option(Ecf_Settings::OPTION_RETRY_INTERVAL, 5);
        $rnc = Ecf_Settings::get_company_rnc();
        $message_id = $refund->get_meta(self::META_REFUND_ECF_MESSAGE_ID);

        try {
            $client = new Ecf_Api_Client();
            $results = $client->get_ecf_status($rnc, $message_id);

            if (empty($results)) {
                throw new \RuntimeException('Empty response');
            }

            $result = $results[0];
            $progress = $result->getProgress();
            $progress_value = is_object($progress) ? ($progress->value ?? (string)$progress) : (string)$progress;

            if (strtolower($progress_value) === 'finished') {
                $refund->update_meta_data(self::META_REFUND_ECF_STATUS, 'accepted');
                $refund->update_meta_data(self::META_REFUND_ECF_CODSEC, $result->getCodSec() ?? '');
                $refund->save();

                if ($order) {
                    $order->add_order_note(
                        sprintf(
                            __('ECF Credit Note accepted! eNCF: %s, Security Code: %s', 'woo-ecf-dgii'),
                            $refund->get_meta(self::META_REFUND_ECF_ENCF),
                            $result->getCodSec() ?? 'N/A'
                        )
                    );
                }
                return;
            }

            if (strtolower($progress_value) === 'error') {
                $refund->update_meta_data(self::META_REFUND_ECF_STATUS, 'rejected');
                $refund->update_meta_data(self::META_REFUND_ECF_ERRORS, $result->getErrors() ?? '');
                $refund->save();
                return;
            }

            if ($attempt < $max_polls) {
                as_schedule_single_action(
                    time() + $retry_interval,
                    'ecf_dgii_poll_refund_ecf',
                    ['refund_id' => $refund_id, 'attempt' => $attempt + 1],
                    'ecf-dgii'
                );
            } else {
                $refund->update_meta_data(self::META_REFUND_ECF_STATUS, 'error');
                $refund->update_meta_data(self::META_REFUND_ECF_ERRORS, __('Polling timed out', 'woo-ecf-dgii'));
                $refund->save();
            }
        } catch (\Exception $e) {
            if ($attempt < $max_polls) {
                as_schedule_single_action(
                    time() + $retry_interval,
                    'ecf_dgii_poll_refund_ecf',
                    ['refund_id' => $refund_id, 'attempt' => $attempt + 1],
                    'ecf-dgii'
                );
            } else {
                $refund->update_meta_data(self::META_REFUND_ECF_STATUS, 'error');
                $refund->update_meta_data(self::META_REFUND_ECF_ERRORS, $e->getMessage());
                $refund->save();
            }
        }
    }

    /**
     * Build E34 credit note ECF from a WooCommerce refund.
     */
    private static function build_credit_note(
        \WC_Order $order,
        \WC_Order $refund,
        string $encf,
        string $expiration_date,
        string $original_encf,
        array $company_data,
    ): ECF {
        $emisor = new Emisor([
            'rnc_emisor' => Ecf_Settings::get_company_rnc(),
            'razon_social_emisor' => $company_data['razonSocial'] ?? '',
            'direccion_emisor' => $company_data['direccion'] ?? '',
            'fecha_emision' => new \DateTime(),
            'numero_factura_interna' => (string) $refund->get_id(),
        ]);

        $encabezado = new Encabezado([
            'version' => VersionType::VERSION1_0,
            'id_doc' => new IdDoc([
                'tipoe_cf' => TipoeCFType::NOTA_DE_CREDITO_ELECTRONICA,
                'encf' => $encf,
                'fecha_vencimiento_secuencia' => new \DateTime($expiration_date),
            ]),
            'emisor' => $emisor,
            'totales' => new Totales([
                'monto_total' => abs((float) $refund->get_total()),
            ]),
        ]);

        // Add comprador if original order had one
        $rnc_comprador = $order->get_meta(Ecf_Order_Handler::META_ECF_RNC_COMPRADOR);
        if ($rnc_comprador) {
            $encabezado->setComprador(new Comprador([
                'razon_social_comprador' => $order->get_meta(Ecf_Order_Handler::META_ECF_RAZON_SOCIAL) ?: null,
            ]));
        }

        // Build refund line items
        $items = [];
        $line_number = 1;

        foreach ($refund->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $qty = abs($item->get_quantity());
            $total = abs((float) $item->get_total());
            if ($qty <= 0 || $total <= 0) {
                continue;
            }

            $product = $item->get_product();
            $is_virtual = $product && $product->is_virtual();

            $items[] = new Item([
                'numero_linea' => $line_number++,
                'nombre_item' => $item->get_name(),
                'indicador_facturacion' => self::get_refund_tax_indicator($item),
                'indicador_bieno_servicio' => $is_virtual
                    ? IndicadorBienoServicioType::SERVICIO
                    : IndicadorBienoServicioType::BIEN,
                'cantidad_item' => (float) $qty,
                'precio_unitario_item' => $total / max(1, $qty),
                'monto_item' => $total,
                'retencion' => new Retencion(),
            ]);
        }

        // If no line items (amount-only refund), create a single line
        if (empty($items)) {
            $items[] = new Item([
                'numero_linea' => 1,
                'nombre_item' => __('Refund', 'woo-ecf-dgii'),
                'indicador_facturacion' => IndicadorFacturacionType::ITBIS1_18_PERCENT,
                'indicador_bieno_servicio' => IndicadorBienoServicioType::SERVICIO,
                'cantidad_item' => 1.0,
                'precio_unitario_item' => abs((float) $refund->get_total()),
                'monto_item' => abs((float) $refund->get_total()),
                'retencion' => new Retencion(),
            ]);
        }

        // Get original emission date for the reference
        $original_date = $order->get_date_paid() ?? $order->get_date_created();

        return new ECF([
            'encabezado' => $encabezado,
            'detalles_items' => $items,
            'informacion_referencia' => new InformacionReferencia([
                'ncf_modificado' => $original_encf,
                'fecha_ncf_modificado' => new \DateTime($original_date->format('Y-m-d')),
                'codigo_modificacion' => CodigoModificacionType::CORRIGE_MONTOS_DEL_NCF_MODIFICADO,
            ]),
        ]);
    }

    private static function get_refund_tax_indicator(\WC_Order_Item_Product $item): string {
        $tax = abs((float) $item->get_total_tax());
        $total = abs((float) $item->get_total());

        if ($tax <= 0 || $total <= 0) {
            return IndicadorFacturacionType::EXENTO_E;
        }

        $rate = ($tax / $total) * 100;
        if ($rate >= 17 && $rate <= 19) {
            return IndicadorFacturacionType::ITBIS1_18_PERCENT;
        }
        if ($rate >= 15 && $rate < 17) {
            return IndicadorFacturacionType::ITBIS2_16_PERCENT;
        }

        return IndicadorFacturacionType::ITBIS1_18_PERCENT;
    }
}
