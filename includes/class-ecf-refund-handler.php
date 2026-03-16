<?php
defined('ABSPATH') || exit;

use Ecfx\EcfDgii\Model\Ecf34ECF;
use Ecfx\EcfDgii\Model\Ecf34Encabezado;
use Ecfx\EcfDgii\Model\Ecf34IdDoc;
use Ecfx\EcfDgii\Model\Ecf34Emisor;
use Ecfx\EcfDgii\Model\Ecf34Comprador;
use Ecfx\EcfDgii\Model\Ecf34Totales;
use Ecfx\EcfDgii\Model\Ecf34Item;
use Ecfx\EcfDgii\Model\Ecf34Retencion;
use Ecfx\EcfDgii\Model\Ecf34InformacionReferencia;
use Ecfx\EcfDgii\Model\Ecf34VersionType;
use Ecfx\EcfDgii\Model\Ecf34CodigoModificacionType;
use Ecfx\EcfDgii\Model\Ecf34IndicadorFacturacionType;
use Ecfx\EcfDgii\Model\Ecf34IndicadorBienoServicioType;
use Ecfx\EcfDgii\Model\Ecf34TipoPagoType;
use Ecfx\EcfDgii\Model\Ecf34TipoIngresosValidationType;
use Ecfx\EcfDgii\Model\TipoeCFType;
use Ecfx\EcfDgii\Model\IndicadorMontoGravadoType;
use Ecfx\EcfDgii\EcfProcessingException;
use Ecfx\EcfDgii\EcfPollingTimeoutException;

class Ecf_Refund_Handler {

    public const META_REFUND_ECF_STATUS = '_ecf_refund_status';
    public const META_REFUND_ECF_ENCF = '_ecf_refund_encf';
    public const META_REFUND_ECF_CODSEC = '_ecf_refund_codsec';
    public const META_REFUND_ECF_MESSAGE_ID = '_ecf_refund_message_id';
    public const META_REFUND_ECF_ERRORS = '_ecf_refund_errors';

    public static function init(): void {
        add_action('woocommerce_order_refunded', [self::class, 'on_order_refunded'], 10, 2);
        add_action('ecf_dgii_submit_refund_ecf', [self::class, 'async_submit_refund'], 10, 1);
        add_action('ecf_dgii_poll_refund_ecf', [self::class, 'async_poll_refund'], 10, 2);
    }

    /**
     * Hook: refund created. Claim sequence and schedule async submission.
     * Must NOT block — this runs inside the WooCommerce refund AJAX handler.
     */
    public static function on_order_refunded(int $order_id, int $refund_id): void {
        // Skip if the plugin is not configured
        if (!Ecf_Settings::is_configured()) {
            return;
        }

        $order = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);

        if (!$order || !$refund) {
            return;
        }

        $original_encf = $order->get_meta(Ecf_Order_Handler::META_ECF_ENCF);
        $original_status = $order->get_meta(Ecf_Order_Handler::META_ECF_STATUS);
        if (!$original_encf || $original_status !== Ecf_Order_Handler::STATUS_ACCEPTED) {
            $order->add_order_note(
                __('ECF: Cannot generate credit note — original ECF not accepted.', 'ecf-dgii-invoicing')
            );
            return;
        }

        $sequence = Ecf_Sequence_Manager::claim_next('E34');
        if (!$sequence) {
            $refund->update_meta_data(self::META_REFUND_ECF_STATUS, 'error');
            $refund->update_meta_data(self::META_REFUND_ECF_ERRORS, __('No available eNCF sequences for E34.', 'ecf-dgii-invoicing'));
            $refund->save();
            $order->add_order_note(__('ECF Error: No available E34 sequences for credit note.', 'ecf-dgii-invoicing'));
            return;
        }

        $refund->update_meta_data(self::META_REFUND_ECF_ENCF, $sequence['encf']);
        $refund->update_meta_data(self::META_REFUND_ECF_STATUS, 'pending');
        $refund->save();

        // Schedule async submission — do NOT block the AJAX response
        as_schedule_single_action(
            time(),
            'ecf_dgii_submit_refund_ecf',
            ['refund_id' => $refund_id],
            'ecf-dgii'
        );

        $order->add_order_note(
            sprintf(
                __('ECF: Credit note E34 scheduled — eNCF: %s', 'ecf-dgii-invoicing'),
                $sequence['encf']
            )
        );
    }

    /**
     * Async: submit the E34 credit note to DGII.
     */
    public static function async_submit_refund(int $refund_id): void {
        woo_ecf_dgii_autoloader();

        $refund = wc_get_order($refund_id);
        if (!$refund) {
            return;
        }

        $order = wc_get_order($refund->get_parent_id());
        if (!$order) {
            return;
        }

        $encf = $refund->get_meta(self::META_REFUND_ECF_ENCF);
        if (!$encf) {
            return;
        }

        $sequence_data = Ecf_Sequence_Manager::get_sequence_by_encf($encf);
        $expiration_date = $sequence_data['expiration_date'] ?? date('Y-m-d', strtotime('+1 year'));

        try {
            $original_encf = $order->get_meta(Ecf_Order_Handler::META_ECF_ENCF);
            $company_data = Ecf_Settings::get_company_data();
            $ecf = self::build_credit_note($order, $refund, $encf, $expiration_date, $original_encf, $company_data);

            $client = new Ecf_Api_Client();
            $result = $client->submit_ecf($ecf);

            $refund->update_meta_data(self::META_REFUND_ECF_STATUS, 'submitting');
            $refund->update_meta_data(self::META_REFUND_ECF_MESSAGE_ID, $result->getMessageId() ?? '');
            $refund->save();

            // Schedule async polling
            as_schedule_single_action(
                time(),
                'ecf_dgii_poll_refund_ecf',
                ['refund_id' => $refund_id, 'message_id' => $result->getMessageId()],
                'ecf-dgii'
            );
        } catch (\Exception $e) {
            $refund->update_meta_data(self::META_REFUND_ECF_STATUS, 'error');
            $refund->update_meta_data(self::META_REFUND_ECF_ERRORS, $e->getMessage());
            $refund->save();
            $order->add_order_note(
                sprintf(__('ECF Credit Note submission failed: %s', 'ecf-dgii-invoicing'), $e->getMessage())
            );
        }
    }

    public static function async_poll_refund(int $refund_id, string $message_id): void {
        woo_ecf_dgii_autoloader();
        $refund = wc_get_order($refund_id);
        if (!$refund) {
            return;
        }

        $order = wc_get_order($refund->get_parent_id());
        if (!$order) {
            return;
        }

        $rnc = Ecf_Settings::get_company_rnc();
        $encf = $refund->get_meta(self::META_REFUND_ECF_ENCF);

        try {
            $client = new Ecf_Api_Client();
            $result = $client->poll_ecf($rnc, $message_id);

            $refund->update_meta_data(self::META_REFUND_ECF_STATUS, 'accepted');
            $refund->update_meta_data(self::META_REFUND_ECF_CODSEC, $result->getCodSec() ?? '');
            $refund->update_meta_data(self::META_REFUND_ECF_ERRORS, '');
            $refund->save();

            $order->add_order_note(
                sprintf(
                    __('ECF Credit Note accepted! eNCF: %s, Security Code: %s', 'ecf-dgii-invoicing'),
                    $encf,
                    $result->getCodSec() ?? 'N/A'
                )
            );
        } catch (EcfProcessingException $e) {
            $ecf_response = $e->getEcfResponse();
            $refund->update_meta_data(self::META_REFUND_ECF_STATUS, 'rejected');
            $refund->update_meta_data(self::META_REFUND_ECF_ERRORS, $ecf_response ? ($ecf_response->getErrors() ?? $e->getMessage()) : $e->getMessage());
            $refund->save();
            $order->add_order_note(
                sprintf(__('ECF Credit Note rejected: %s', 'ecf-dgii-invoicing'), $e->getMessage())
            );
        } catch (EcfPollingTimeoutException $e) {
            $refund->update_meta_data(self::META_REFUND_ECF_STATUS, 'error');
            $refund->update_meta_data(self::META_REFUND_ECF_ERRORS, $e->getMessage());
            $refund->save();
            $order->add_order_note(__('ECF Credit Note polling timed out.', 'ecf-dgii-invoicing'));
        } catch (\Exception $e) {
            $refund->update_meta_data(self::META_REFUND_ECF_STATUS, 'error');
            $refund->update_meta_data(self::META_REFUND_ECF_ERRORS, $e->getMessage());
            $refund->save();
            $order->add_order_note(
                sprintf(__('ECF Credit Note failed: %s', 'ecf-dgii-invoicing'), $e->getMessage())
            );
        }
    }

    private static function build_credit_note(
        \WC_Abstract_Order $order,
        \WC_Abstract_Order $refund,
        string $encf,
        string $expiration_date,
        string $original_encf,
        array $company_data,
    ): Ecf34ECF {
        $emisor = new Ecf34Emisor();
        $emisor->setRncEmisor(Ecf_Settings::get_company_rnc());
        $emisor->setRazonSocialEmisor($company_data['razonSocial'] ?? '');
        $emisor->setDireccionEmisor($company_data['direccion'] ?? '');
        $emisor->setFechaEmision(new \DateTime());
        $emisor->setNumeroFacturaInterna((string) $refund->get_id());

        $id_doc = new Ecf34IdDoc();
        $id_doc->setTipoeCf(TipoeCFType::NOTA_DE_CREDITO_ELECTRONICA);
        $id_doc->setEncf($encf);
        $id_doc->setTipoPago(Ecf34TipoPagoType::CONTADO);
        $id_doc->setTipoIngresos(Ecf34TipoIngresosValidationType::_01);
        $id_doc->setIndicadorMontoGravado(IndicadorMontoGravadoType::CON_ITBIS_INCLUIDO);

        $totales = new Ecf34Totales();
        $refund_total = abs((float) $refund->get_total());
        $totales->setMontoTotal($refund_total);

        // Calculate ITBIS for refund
        $exempt = 0.0;
        $gravado_i1 = 0.0;
        $total_itbis1 = 0.0;

        foreach ($refund->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $tax = abs((float) $item->get_total_tax());
            $subtotal = abs((float) $item->get_total());

            if ($tax <= 0) {
                $exempt += $subtotal;
            } else {
                $gravado_i1 += $subtotal;
                $total_itbis1 += $tax;
            }
        }

        if ($exempt > 0) {
            $totales->setMontoExento($exempt);
        }
        if ($gravado_i1 > 0) {
            $totales->setItbiS1(18);
            $totales->setMontoGravadoI1($gravado_i1);
            $totales->setMontoGravadoTotal($gravado_i1);
            $totales->setTotalItbis1($total_itbis1);
            $totales->setTotalItbis($total_itbis1);
        }

        $encabezado = new Ecf34Encabezado();
        $encabezado->setVersion(Ecf34VersionType::VERSION1_0);
        $encabezado->setIdDoc($id_doc);
        $encabezado->setEmisor($emisor);
        $encabezado->setTotales($totales);

        $rnc_comprador = $order->get_meta(Ecf_Order_Handler::META_ECF_RNC_COMPRADOR);
        if ($rnc_comprador) {
            $comprador = new Ecf34Comprador();
            $comprador->setRncComprador($rnc_comprador);
            $comprador->setRazonSocialComprador($order->get_meta(Ecf_Order_Handler::META_ECF_RAZON_SOCIAL) ?: null);
            $encabezado->setComprador($comprador);
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

            $ecf_item = new Ecf34Item();
            $ecf_item->setNumeroLinea($line_number++);
            $ecf_item->setNombreItem($item->get_name());
            $ecf_item->setIndicadorFacturacion(self::get_refund_tax_indicator($item));
            $ecf_item->setIndicadorBienoServicio(
                $is_virtual ? Ecf34IndicadorBienoServicioType::SERVICIO : Ecf34IndicadorBienoServicioType::BIEN
            );
            $ecf_item->setCantidadItem((float) $qty);
            $ecf_item->setUnidadMedida('Unidad');
            $ecf_item->setPrecioUnitarioItem($total / max(1, $qty));
            $ecf_item->setMontoItem($total);

            $items[] = $ecf_item;
        }

        // If no line items (amount-only refund), create a single line
        if (empty($items)) {
            $ecf_item = new Ecf34Item();
            $ecf_item->setNumeroLinea(1);
            $ecf_item->setNombreItem(__('Refund', 'ecf-dgii-invoicing'));
            $ecf_item->setIndicadorFacturacion(Ecf34IndicadorFacturacionType::ITBIS1_18_PERCENT);
            $ecf_item->setIndicadorBienoServicio(Ecf34IndicadorBienoServicioType::SERVICIO);
            $ecf_item->setCantidadItem(1.0);
            $ecf_item->setUnidadMedida('Unidad');
            $ecf_item->setPrecioUnitarioItem($refund_total);
            $ecf_item->setMontoItem($refund_total);

            $items[] = $ecf_item;
        }

        $original_date = $order->get_date_paid() ?? $order->get_date_created();

        $info_ref = new Ecf34InformacionReferencia();
        $info_ref->setNcfModificado($original_encf);
        $info_ref->setFechaNcfModificado(new \DateTime($original_date->format('Y-m-d')));
        $info_ref->setCodigoModificacion(Ecf34CodigoModificacionType::CORRIGE_MONTOS_DEL_NCF_MODIFICADO);

        $ecf = new Ecf34ECF();
        $ecf->setEncabezado($encabezado);
        $ecf->setDetallesItems($items);
        $ecf->setInformacionReferencia($info_ref);

        return $ecf;
    }

    private static function get_refund_tax_indicator(\WC_Order_Item_Product $item): string {
        $tax = abs((float) $item->get_total_tax());
        $total = abs((float) $item->get_total());

        if ($tax <= 0 || $total <= 0) {
            return Ecf34IndicadorFacturacionType::EXENTO_E;
        }

        $rate = ($tax / $total) * 100;
        if ($rate >= 17 && $rate <= 19) {
            return Ecf34IndicadorFacturacionType::ITBIS1_18_PERCENT;
        }
        if ($rate >= 15 && $rate < 17) {
            return Ecf34IndicadorFacturacionType::ITBIS2_16_PERCENT;
        }

        return Ecf34IndicadorFacturacionType::ITBIS1_18_PERCENT;
    }
}
