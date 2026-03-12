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
use Ecfx\EcfDgii\Model\VersionType;
use Ecfx\EcfDgii\Model\TipoeCFType;
use Ecfx\EcfDgii\Model\IndicadorFacturacionType;
use Ecfx\EcfDgii\Model\IndicadorBienoServicioType;

class Ecf_Mapper {

    private const ECF_TYPE_MAP = [
        'E31' => TipoeCFType::FACTURA_DE_CREDITO_FISCAL_ELECTRONICA,
        'E32' => TipoeCFType::FACTURA_DE_CONSUMO_ELECTRONICA,
        'E33' => TipoeCFType::NOTA_DE_DEBITO_ELECTRONICA,
        'E34' => TipoeCFType::NOTA_DE_CREDITO_ELECTRONICA,
    ];

    /**
     * Map a WooCommerce order to an ECF model.
     *
     * @param \WC_Order $order The WooCommerce order
     * @param string $ecf_type ECF type shorthand: E31, E32, E33, E34
     * @param string $encf The assigned eNCF number
     * @param string $expiration_date Sequence expiration date (Y-m-d)
     * @return ECF
     */
    public static function map_order(
        \WC_Order $order,
        string $ecf_type,
        string $encf,
        string $expiration_date,
    ): ECF {
        $company_data = Ecf_Settings::get_company_data();

        $encabezado = new Encabezado([
            'version' => VersionType::VERSION1_0,
            'id_doc' => self::build_id_doc($ecf_type, $encf, $expiration_date),
            'emisor' => self::build_emisor($company_data, $order),
            'totales' => self::build_totales($order),
        ]);

        // Add comprador for E31 (if RNC provided)
        $rnc = $order->get_meta('_ecf_rnc_comprador');
        if ($rnc && $ecf_type === 'E31') {
            $encabezado->setComprador(new Comprador([
                'razon_social_comprador' => $order->get_meta('_ecf_razon_social') ?: null,
            ]));
        }

        return new ECF([
            'encabezado' => $encabezado,
            'detalles_items' => self::build_items($order),
        ]);
    }

    private static function build_id_doc(
        string $ecf_type,
        string $encf,
        string $expiration_date,
    ): IdDoc {
        return new IdDoc([
            'tipoe_cf' => self::ECF_TYPE_MAP[$ecf_type],
            'encf' => $encf,
            'fecha_vencimiento_secuencia' => new \DateTime($expiration_date),
        ]);
    }

    private static function build_emisor(array $company_data, \WC_Order $order): Emisor {
        return new Emisor([
            'rnc_emisor' => Ecf_Settings::get_company_rnc(),
            'razon_social_emisor' => $company_data['razonSocial'] ?? '',
            'direccion_emisor' => $company_data['direccion'] ?? '',
            'fecha_emision' => new \DateTime($order->get_date_paid()?->format('Y-m-d') ?? 'now'),
            'numero_factura_interna' => (string) $order->get_id(),
        ]);
    }

    private static function build_totales(\WC_Order $order): Totales {
        return new Totales([
            'monto_total' => (float) $order->get_total(),
        ]);
    }

    /**
     * Build ECF line items from WooCommerce order items.
     *
     * @return Item[]
     */
    private static function build_items(\WC_Order $order): array {
        $items = [];
        $line_number = 1;

        // Product lines
        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $product = $item->get_product();
            $is_virtual = $product && $product->is_virtual();

            $items[] = new Item([
                'numero_linea' => $line_number++,
                'nombre_item' => $item->get_name(),
                'indicador_facturacion' => self::get_tax_indicator($item, $order),
                'indicador_bieno_servicio' => $is_virtual
                    ? IndicadorBienoServicioType::SERVICIO
                    : IndicadorBienoServicioType::BIEN,
                'cantidad_item' => (float) $item->get_quantity(),
                'precio_unitario_item' => (float) ($item->get_subtotal() / max(1, $item->get_quantity())),
                'monto_item' => (float) $item->get_subtotal(),
                'retencion' => new Retencion(),
            ]);
        }

        // Shipping as a line item
        foreach ($order->get_items('shipping') as $shipping) {
            /** @var \WC_Order_Item_Shipping $shipping */
            $shipping_total = (float) $shipping->get_total();
            if ($shipping_total > 0) {
                $items[] = new Item([
                    'numero_linea' => $line_number++,
                    'nombre_item' => $shipping->get_method_title() ?: __('Shipping', 'woo-ecf-dgii'),
                    'indicador_facturacion' => self::get_shipping_tax_indicator($shipping),
                    'indicador_bieno_servicio' => IndicadorBienoServicioType::SERVICIO,
                    'cantidad_item' => 1.0,
                    'precio_unitario_item' => $shipping_total,
                    'monto_item' => $shipping_total,
                    'retencion' => new Retencion(),
                ]);
            }
        }

        // Fee lines
        foreach ($order->get_items('fee') as $fee) {
            /** @var \WC_Order_Item_Fee $fee */
            $fee_total = (float) $fee->get_total();
            if ($fee_total != 0) {
                $items[] = new Item([
                    'numero_linea' => $line_number++,
                    'nombre_item' => $fee->get_name(),
                    'indicador_facturacion' => IndicadorFacturacionType::ITBIS1_18_PERCENT,
                    'indicador_bieno_servicio' => IndicadorBienoServicioType::SERVICIO,
                    'cantidad_item' => 1.0,
                    'precio_unitario_item' => $fee_total,
                    'monto_item' => $fee_total,
                    'retencion' => new Retencion(),
                ]);
            }
        }

        return $items;
    }

    /**
     * Map WooCommerce tax rate to ECF IndicadorFacturacionType.
     */
    private static function get_tax_indicator(\WC_Order_Item_Product $item, \WC_Order $order): string {
        $tax_total = (float) $item->get_total_tax();
        $subtotal = (float) $item->get_subtotal();

        if ($tax_total <= 0 || $subtotal <= 0) {
            $product = $item->get_product();
            $tax_class = $product ? $product->get_tax_class() : '';

            if ($tax_class === 'zero-rate') {
                return IndicadorFacturacionType::ITBIS3_0_PERCENT;
            }
            if ($tax_class === 'exempt' || $tax_class === 'reduced-rate') {
                return IndicadorFacturacionType::EXENTO_E;
            }
            return IndicadorFacturacionType::NO_FACTURABLE_18_PERCENT;
        }

        // Calculate effective tax rate
        $rate = ($tax_total / $subtotal) * 100;

        if ($rate >= 17 && $rate <= 19) {
            return IndicadorFacturacionType::ITBIS1_18_PERCENT;
        }
        if ($rate >= 15 && $rate < 17) {
            return IndicadorFacturacionType::ITBIS2_16_PERCENT;
        }

        return IndicadorFacturacionType::ITBIS1_18_PERCENT;
    }

    /**
     * Get tax indicator for shipping items.
     */
    private static function get_shipping_tax_indicator(\WC_Order_Item_Shipping $shipping): string {
        $tax = (float) $shipping->get_total_tax();
        $total = (float) $shipping->get_total();

        if ($tax <= 0 || $total <= 0) {
            return IndicadorFacturacionType::EXENTO_E;
        }

        $rate = ($tax / $total) * 100;
        if ($rate >= 17 && $rate <= 19) {
            return IndicadorFacturacionType::ITBIS1_18_PERCENT;
        }
        return IndicadorFacturacionType::ITBIS2_16_PERCENT;
    }
}
