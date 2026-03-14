<?php
defined('ABSPATH') || exit;

// E32 models (Factura de Consumo)
use Ecfx\EcfDgii\Model\Ecf32ECF;
use Ecfx\EcfDgii\Model\Ecf32Encabezado;
use Ecfx\EcfDgii\Model\Ecf32IdDoc;
use Ecfx\EcfDgii\Model\Ecf32Emisor;
use Ecfx\EcfDgii\Model\Ecf32Comprador;
use Ecfx\EcfDgii\Model\Ecf32Totales;
use Ecfx\EcfDgii\Model\Ecf32Item;
use Ecfx\EcfDgii\Model\Ecf32FormaDePago;
use Ecfx\EcfDgii\Model\Ecf32VersionType;
use Ecfx\EcfDgii\Model\Ecf32TipoPagoType;
use Ecfx\EcfDgii\Model\Ecf32FormaPagoType;
use Ecfx\EcfDgii\Model\Ecf32IndicadorFacturacionType;
use Ecfx\EcfDgii\Model\Ecf32IndicadorBienoServicioType;
use Ecfx\EcfDgii\Model\Ecf32TipoIngresosValidationType;

// E31 models (Crédito Fiscal)
use Ecfx\EcfDgii\Model\Ecf31ECF;
use Ecfx\EcfDgii\Model\Ecf31Encabezado;
use Ecfx\EcfDgii\Model\Ecf31IdDoc;
use Ecfx\EcfDgii\Model\Ecf31Emisor;
use Ecfx\EcfDgii\Model\Ecf31Comprador;
use Ecfx\EcfDgii\Model\Ecf31Totales;
use Ecfx\EcfDgii\Model\Ecf31Item;
use Ecfx\EcfDgii\Model\Ecf31FormaDePago;
use Ecfx\EcfDgii\Model\Ecf31VersionType;
use Ecfx\EcfDgii\Model\Ecf31TipoPagoType;
use Ecfx\EcfDgii\Model\Ecf31FormaPagoType;
use Ecfx\EcfDgii\Model\Ecf31IndicadorFacturacionType;
use Ecfx\EcfDgii\Model\Ecf31IndicadorBienoServicioType;
use Ecfx\EcfDgii\Model\Ecf31TipoIngresosValidationType;

use Ecfx\EcfDgii\Model\TipoeCFType;
use Ecfx\EcfDgii\Model\IndicadorMontoGravadoType;

class Ecf_Mapper {

    private const ECF_TYPE_MAP = [
        'E31' => TipoeCFType::FACTURA_DE_CREDITO_FISCAL_ELECTRONICA,
        'E32' => TipoeCFType::FACTURA_DE_CONSUMO_ELECTRONICA,
        'E33' => TipoeCFType::NOTA_DE_DEBITO_ELECTRONICA,
        'E34' => TipoeCFType::NOTA_DE_CREDITO_ELECTRONICA,
    ];

    /**
     * WooCommerce payment method → ECF FormaPagoType mapping (E32).
     */
    private const PAYMENT_METHOD_MAP_E32 = [
        'cod'    => Ecf32FormaPagoType::EFECTIVO,
        'bacs'   => Ecf32FormaPagoType::CHEQUE_SLASH_TRANSFERENCIA_SLASH_DEPOSITO,
        'cheque' => Ecf32FormaPagoType::CHEQUE_SLASH_TRANSFERENCIA_SLASH_DEPOSITO,
    ];

    /**
     * WooCommerce payment method → ECF FormaPagoType mapping (E31).
     */
    private const PAYMENT_METHOD_MAP_E31 = [
        'cod'    => Ecf31FormaPagoType::EFECTIVO,
        'bacs'   => Ecf31FormaPagoType::CHEQUE_SLASH_TRANSFERENCIA_SLASH_DEPOSITO,
        'cheque' => Ecf31FormaPagoType::CHEQUE_SLASH_TRANSFERENCIA_SLASH_DEPOSITO,
    ];

    /**
     * Map a WooCommerce order to a typed ECF model.
     *
     * @return Ecf31ECF|Ecf32ECF
     */
    public static function map_order(
        \WC_Order $order,
        string $ecf_type,
        string $encf,
        string $expiration_date,
    ): Ecf31ECF|Ecf32ECF {
        return match ($ecf_type) {
            'E31' => self::build_ecf31($order, $encf, $expiration_date),
            'E32' => self::build_ecf32($order, $encf, $expiration_date),
            default => throw new \InvalidArgumentException("Unsupported ECF type for orders: $ecf_type"),
        };
    }

    // ── E32 (Factura de Consumo) ────────────────────────────────────────

    private static function build_ecf32(\WC_Order $order, string $encf, string $expiration_date): Ecf32ECF {
        $company_data = Ecf_Settings::get_company_data();

        $encabezado = new Ecf32Encabezado();
        $encabezado->setVersion(Ecf32VersionType::VERSION1_0);
        $encabezado->setIdDoc(self::build_ecf32_id_doc($encf, $expiration_date, $order));
        $encabezado->setEmisor(self::build_ecf32_emisor($company_data, $order));
        $encabezado->setTotales(self::build_ecf32_totales($order));
        $encabezado->setComprador(new Ecf32Comprador());

        $ecf = new Ecf32ECF();
        $ecf->setEncabezado($encabezado);
        $ecf->setDetallesItems(self::build_ecf32_items($order));

        return $ecf;
    }

    private static function build_ecf32_id_doc(string $encf, string $expiration_date, \WC_Order $order): Ecf32IdDoc {
        $id_doc = new Ecf32IdDoc();
        $id_doc->setTipoeCf(self::ECF_TYPE_MAP['E32']);
        $id_doc->setEncf($encf);
        $id_doc->setTipoIngresos(Ecf32TipoIngresosValidationType::_01);
        $id_doc->setTipoPago(Ecf32TipoPagoType::CONTADO);
        $id_doc->setIndicadorMontoGravado(IndicadorMontoGravadoType::CON_ITBIS_INCLUIDO);

        $forma_pago = new Ecf32FormaDePago();
        $forma_pago->setFormaPago(self::get_forma_pago_e32($order));
        $forma_pago->setMontoPago((float) $order->get_total());
        $id_doc->setTablaFormasPago([$forma_pago]);

        return $id_doc;
    }

    private static function build_ecf32_emisor(array $company_data, \WC_Order $order): Ecf32Emisor {
        $emisor = new Ecf32Emisor();
        $emisor->setRncEmisor(Ecf_Settings::get_company_rnc());
        $emisor->setRazonSocialEmisor($company_data['razonSocial'] ?? '');
        $emisor->setDireccionEmisor($company_data['direccion'] ?? '');
        $emisor->setNumeroFacturaInterna((string) $order->get_id());
        $emisor->setFechaEmision(new \DateTime($order->get_date_paid()?->format('Y-m-d') ?? 'now'));

        return $emisor;
    }

    private static function build_ecf32_totales(\WC_Order $order): Ecf32Totales {
        $totales = new Ecf32Totales();
        $totales->setMontoTotal((float) $order->get_total());
        $totales->setMontoPeriodo((float) $order->get_total());

        $exempt = 0.0;
        $gravado_i1 = 0.0;
        $total_itbis1 = 0.0;

        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $tax = (float) $item->get_total_tax();
            $subtotal = (float) $item->get_subtotal();

            if ($tax <= 0) {
                $exempt += $subtotal;
            } else {
                $gravado_i1 += $subtotal;
                $total_itbis1 += $tax;
            }
        }

        foreach ($order->get_items('shipping') as $shipping) {
            /** @var \WC_Order_Item_Shipping $shipping */
            $tax = (float) $shipping->get_total_tax();
            $subtotal = (float) $shipping->get_total();

            if ($tax > 0) {
                $gravado_i1 += $subtotal;
                $total_itbis1 += $tax;
            } elseif ($subtotal > 0) {
                $exempt += $subtotal;
            }
        }

        foreach ($order->get_items('fee') as $fee) {
            /** @var \WC_Order_Item_Fee $fee */
            $tax = (float) $fee->get_total_tax();
            $subtotal = (float) $fee->get_total();

            if ($tax > 0) {
                $gravado_i1 += $subtotal;
                $total_itbis1 += $tax;
            } elseif ($subtotal > 0) {
                $exempt += $subtotal;
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

        return $totales;
    }

    /**
     * @return Ecf32Item[]
     */
    private static function build_ecf32_items(\WC_Order $order): array {
        $items = [];
        $line_number = 1;

        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $product = $item->get_product();
            $is_virtual = $product && $product->is_virtual();

            $ecf_item = new Ecf32Item();
            $ecf_item->setNumeroLinea($line_number++);
            $ecf_item->setNombreItem($item->get_name());
            $ecf_item->setIndicadorFacturacion(self::get_tax_indicator_e32($item));
            $ecf_item->setIndicadorBienoServicio(
                $is_virtual ? Ecf32IndicadorBienoServicioType::SERVICIO : Ecf32IndicadorBienoServicioType::BIEN
            );
            $ecf_item->setCantidadItem((float) $item->get_quantity());
            $ecf_item->setUnidadMedida('Unidad');
            $ecf_item->setPrecioUnitarioItem((float) ($item->get_subtotal() / max(1, $item->get_quantity())));
            $ecf_item->setMontoItem((float) $item->get_subtotal());

            $items[] = $ecf_item;
        }

        foreach ($order->get_items('shipping') as $shipping) {
            /** @var \WC_Order_Item_Shipping $shipping */
            $shipping_total = (float) $shipping->get_total();
            if ($shipping_total > 0) {
                $ecf_item = new Ecf32Item();
                $ecf_item->setNumeroLinea($line_number++);
                $ecf_item->setNombreItem($shipping->get_method_title() ?: __('Shipping', 'ecf-dgii-invoicing'));
                $ecf_item->setIndicadorFacturacion(self::get_shipping_tax_indicator_e32($shipping));
                $ecf_item->setIndicadorBienoServicio(Ecf32IndicadorBienoServicioType::SERVICIO);
                $ecf_item->setCantidadItem(1.0);
                $ecf_item->setUnidadMedida('Unidad');
                $ecf_item->setPrecioUnitarioItem($shipping_total);
                $ecf_item->setMontoItem($shipping_total);

                $items[] = $ecf_item;
            }
        }

        foreach ($order->get_items('fee') as $fee) {
            /** @var \WC_Order_Item_Fee $fee */
            $fee_total = (float) $fee->get_total();
            if ($fee_total != 0) {
                $ecf_item = new Ecf32Item();
                $ecf_item->setNumeroLinea($line_number++);
                $ecf_item->setNombreItem($fee->get_name());
                $ecf_item->setIndicadorFacturacion(Ecf32IndicadorFacturacionType::ITBIS1_18_PERCENT);
                $ecf_item->setIndicadorBienoServicio(Ecf32IndicadorBienoServicioType::SERVICIO);
                $ecf_item->setCantidadItem(1.0);
                $ecf_item->setUnidadMedida('Unidad');
                $ecf_item->setPrecioUnitarioItem($fee_total);
                $ecf_item->setMontoItem($fee_total);

                $items[] = $ecf_item;
            }
        }

        return $items;
    }

    private static function get_forma_pago_e32(\WC_Order $order): string {
        $method = $order->get_payment_method();

        if (isset(self::PAYMENT_METHOD_MAP_E32[$method])) {
            return self::PAYMENT_METHOD_MAP_E32[$method];
        }

        if (str_contains($method, 'stripe') || str_contains($method, 'paypal') || str_contains($method, 'card')) {
            return Ecf32FormaPagoType::TARJETA_DE_DEBITO_SLASH_CREDITO;
        }

        return Ecf32FormaPagoType::OTRAS_FORMAS_DE_PAGO;
    }

    private static function get_tax_indicator_e32(\WC_Order_Item_Product $item): string {
        $tax_total = (float) $item->get_total_tax();
        $subtotal = (float) $item->get_subtotal();

        if ($tax_total <= 0 || $subtotal <= 0) {
            $product = $item->get_product();
            $tax_class = $product ? $product->get_tax_class() : '';

            if ($tax_class === 'zero-rate') {
                return Ecf32IndicadorFacturacionType::ITBIS3_0_PERCENT;
            }
            if ($tax_class === 'exempt' || $tax_class === 'reduced-rate') {
                return Ecf32IndicadorFacturacionType::EXENTO_E;
            }
            return Ecf32IndicadorFacturacionType::NO_FACTURABLE_18_PERCENT;
        }

        $rate = ($tax_total / $subtotal) * 100;

        if ($rate >= 17 && $rate <= 19) {
            return Ecf32IndicadorFacturacionType::ITBIS1_18_PERCENT;
        }
        if ($rate >= 15 && $rate < 17) {
            return Ecf32IndicadorFacturacionType::ITBIS2_16_PERCENT;
        }

        return Ecf32IndicadorFacturacionType::ITBIS1_18_PERCENT;
    }

    private static function get_shipping_tax_indicator_e32(\WC_Order_Item_Shipping $shipping): string {
        $tax = (float) $shipping->get_total_tax();
        $total = (float) $shipping->get_total();

        if ($tax <= 0 || $total <= 0) {
            return Ecf32IndicadorFacturacionType::EXENTO_E;
        }

        $rate = ($tax / $total) * 100;
        if ($rate >= 17 && $rate <= 19) {
            return Ecf32IndicadorFacturacionType::ITBIS1_18_PERCENT;
        }
        return Ecf32IndicadorFacturacionType::ITBIS2_16_PERCENT;
    }

    // ── E31 (Crédito Fiscal) ────────────────────────────────────────────

    private static function build_ecf31(\WC_Order $order, string $encf, string $expiration_date): Ecf31ECF {
        $company_data = Ecf_Settings::get_company_data();

        $encabezado = new Ecf31Encabezado();
        $encabezado->setVersion(Ecf31VersionType::VERSION1_0);
        $encabezado->setIdDoc(self::build_ecf31_id_doc($encf, $expiration_date, $order));
        $encabezado->setEmisor(self::build_ecf31_emisor($company_data, $order));
        $encabezado->setTotales(self::build_ecf31_totales($order));

        $rnc = $order->get_meta('_ecf_rnc_comprador');
        if ($rnc) {
            $comprador = new Ecf31Comprador();
            $comprador->setRncComprador($rnc);
            $comprador->setRazonSocialComprador($order->get_meta('_ecf_razon_social') ?: null);
            $encabezado->setComprador($comprador);
        }

        $ecf = new Ecf31ECF();
        $ecf->setEncabezado($encabezado);
        $ecf->setDetallesItems(self::build_ecf31_items($order));

        return $ecf;
    }

    private static function build_ecf31_id_doc(string $encf, string $expiration_date, \WC_Order $order): Ecf31IdDoc {
        $id_doc = new Ecf31IdDoc();
        $id_doc->setTipoeCf(self::ECF_TYPE_MAP['E31']);
        $id_doc->setEncf($encf);
        $id_doc->setFechaVencimientoSecuencia(new \DateTime($expiration_date));
        $id_doc->setTipoIngresos(Ecf31TipoIngresosValidationType::_01);
        $id_doc->setTipoPago(Ecf31TipoPagoType::CONTADO);
        $id_doc->setIndicadorMontoGravado(IndicadorMontoGravadoType::CON_ITBIS_INCLUIDO);

        $forma_pago = new Ecf31FormaDePago();
        $forma_pago->setFormaPago(self::get_forma_pago_e31($order));
        $forma_pago->setMontoPago((float) $order->get_total());
        $id_doc->setTablaFormasPago([$forma_pago]);

        return $id_doc;
    }

    private static function build_ecf31_emisor(array $company_data, \WC_Order $order): Ecf31Emisor {
        $emisor = new Ecf31Emisor();
        $emisor->setRncEmisor(Ecf_Settings::get_company_rnc());
        $emisor->setRazonSocialEmisor($company_data['razonSocial'] ?? '');
        $emisor->setDireccionEmisor($company_data['direccion'] ?? '');
        $emisor->setNumeroFacturaInterna((string) $order->get_id());
        $emisor->setFechaEmision(new \DateTime($order->get_date_paid()?->format('Y-m-d') ?? 'now'));

        return $emisor;
    }

    private static function build_ecf31_totales(\WC_Order $order): Ecf31Totales {
        $totales = new Ecf31Totales();
        $totales->setMontoTotal((float) $order->get_total());
        $totales->setMontoPeriodo((float) $order->get_total());

        $exempt = 0.0;
        $gravado_i1 = 0.0;
        $total_itbis1 = 0.0;

        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $tax = (float) $item->get_total_tax();
            $subtotal = (float) $item->get_subtotal();

            if ($tax <= 0) {
                $exempt += $subtotal;
            } else {
                $gravado_i1 += $subtotal;
                $total_itbis1 += $tax;
            }
        }

        foreach ($order->get_items('shipping') as $shipping) {
            /** @var \WC_Order_Item_Shipping $shipping */
            $tax = (float) $shipping->get_total_tax();
            $subtotal = (float) $shipping->get_total();

            if ($tax > 0) {
                $gravado_i1 += $subtotal;
                $total_itbis1 += $tax;
            } elseif ($subtotal > 0) {
                $exempt += $subtotal;
            }
        }

        foreach ($order->get_items('fee') as $fee) {
            /** @var \WC_Order_Item_Fee $fee */
            $tax = (float) $fee->get_total_tax();
            $subtotal = (float) $fee->get_total();

            if ($tax > 0) {
                $gravado_i1 += $subtotal;
                $total_itbis1 += $tax;
            } elseif ($subtotal > 0) {
                $exempt += $subtotal;
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

        return $totales;
    }

    /**
     * @return Ecf31Item[]
     */
    private static function build_ecf31_items(\WC_Order $order): array {
        $items = [];
        $line_number = 1;

        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $product = $item->get_product();
            $is_virtual = $product && $product->is_virtual();

            $ecf_item = new Ecf31Item();
            $ecf_item->setNumeroLinea($line_number++);
            $ecf_item->setNombreItem($item->get_name());
            $ecf_item->setIndicadorFacturacion(self::get_tax_indicator_e31($item));
            $ecf_item->setIndicadorBienoServicio(
                $is_virtual ? Ecf31IndicadorBienoServicioType::SERVICIO : Ecf31IndicadorBienoServicioType::BIEN
            );
            $ecf_item->setCantidadItem((float) $item->get_quantity());
            $ecf_item->setUnidadMedida('Unidad');
            $ecf_item->setPrecioUnitarioItem((float) ($item->get_subtotal() / max(1, $item->get_quantity())));
            $ecf_item->setMontoItem((float) $item->get_subtotal());

            $items[] = $ecf_item;
        }

        foreach ($order->get_items('shipping') as $shipping) {
            /** @var \WC_Order_Item_Shipping $shipping */
            $shipping_total = (float) $shipping->get_total();
            if ($shipping_total > 0) {
                $ecf_item = new Ecf31Item();
                $ecf_item->setNumeroLinea($line_number++);
                $ecf_item->setNombreItem($shipping->get_method_title() ?: __('Shipping', 'ecf-dgii-invoicing'));
                $ecf_item->setIndicadorFacturacion(self::get_shipping_tax_indicator_e31($shipping));
                $ecf_item->setIndicadorBienoServicio(Ecf31IndicadorBienoServicioType::SERVICIO);
                $ecf_item->setCantidadItem(1.0);
                $ecf_item->setUnidadMedida('Unidad');
                $ecf_item->setPrecioUnitarioItem($shipping_total);
                $ecf_item->setMontoItem($shipping_total);

                $items[] = $ecf_item;
            }
        }

        foreach ($order->get_items('fee') as $fee) {
            /** @var \WC_Order_Item_Fee $fee */
            $fee_total = (float) $fee->get_total();
            if ($fee_total != 0) {
                $ecf_item = new Ecf31Item();
                $ecf_item->setNumeroLinea($line_number++);
                $ecf_item->setNombreItem($fee->get_name());
                $ecf_item->setIndicadorFacturacion(Ecf31IndicadorFacturacionType::ITBIS1_18_PERCENT);
                $ecf_item->setIndicadorBienoServicio(Ecf31IndicadorBienoServicioType::SERVICIO);
                $ecf_item->setCantidadItem(1.0);
                $ecf_item->setUnidadMedida('Unidad');
                $ecf_item->setPrecioUnitarioItem($fee_total);
                $ecf_item->setMontoItem($fee_total);

                $items[] = $ecf_item;
            }
        }

        return $items;
    }

    private static function get_forma_pago_e31(\WC_Order $order): string {
        $method = $order->get_payment_method();

        if (isset(self::PAYMENT_METHOD_MAP_E31[$method])) {
            return self::PAYMENT_METHOD_MAP_E31[$method];
        }

        if (str_contains($method, 'stripe') || str_contains($method, 'paypal') || str_contains($method, 'card')) {
            return Ecf31FormaPagoType::TARJETA_DE_DEBITO_SLASH_CREDITO;
        }

        return Ecf31FormaPagoType::OTRAS_FORMAS_DE_PAGO;
    }

    private static function get_tax_indicator_e31(\WC_Order_Item_Product $item): string {
        $tax_total = (float) $item->get_total_tax();
        $subtotal = (float) $item->get_subtotal();

        if ($tax_total <= 0 || $subtotal <= 0) {
            $product = $item->get_product();
            $tax_class = $product ? $product->get_tax_class() : '';

            if ($tax_class === 'zero-rate') {
                return Ecf31IndicadorFacturacionType::ITBIS3_0_PERCENT;
            }
            if ($tax_class === 'exempt' || $tax_class === 'reduced-rate') {
                return Ecf31IndicadorFacturacionType::EXENTO_E;
            }
            return Ecf31IndicadorFacturacionType::NO_FACTURABLE_18_PERCENT;
        }

        $rate = ($tax_total / $subtotal) * 100;

        if ($rate >= 17 && $rate <= 19) {
            return Ecf31IndicadorFacturacionType::ITBIS1_18_PERCENT;
        }
        if ($rate >= 15 && $rate < 17) {
            return Ecf31IndicadorFacturacionType::ITBIS2_16_PERCENT;
        }

        return Ecf31IndicadorFacturacionType::ITBIS1_18_PERCENT;
    }

    private static function get_shipping_tax_indicator_e31(\WC_Order_Item_Shipping $shipping): string {
        $tax = (float) $shipping->get_total_tax();
        $total = (float) $shipping->get_total();

        if ($tax <= 0 || $total <= 0) {
            return Ecf31IndicadorFacturacionType::EXENTO_E;
        }

        $rate = ($tax / $total) * 100;
        if ($rate >= 17 && $rate <= 19) {
            return Ecf31IndicadorFacturacionType::ITBIS1_18_PERCENT;
        }
        return Ecf31IndicadorFacturacionType::ITBIS2_16_PERCENT;
    }
}
