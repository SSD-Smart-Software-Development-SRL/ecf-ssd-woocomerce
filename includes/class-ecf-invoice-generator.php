<?php
defined('ABSPATH') || exit;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Generates DGII-format invoice PDFs from the ECF API response data.
 *
 * All invoice data (eNCF, security code, signature date, print URL, etc.)
 * comes from the EcfResponse stored in order meta — nothing is fabricated.
 */
class Ecf_Invoice_Generator {

    private const ECF_TYPE_NAMES = [
        'E31' => 'Factura de Crédito Fiscal Electrónica',
        'E32' => 'Factura de Consumo Electrónica',
        'E33' => 'Nota de Débito Electrónica',
        'E34' => 'Nota de Crédito Electrónica',
    ];

    public static function init(): void {
        add_action('wp_ajax_ecf_dgii_download_invoice', [self::class, 'handle_download']);
    }

    public static function handle_download(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'ecf-dgii-invoicing'));
        }

        $order_id = absint($_GET['order_id'] ?? 0);
        check_admin_referer('ecf_invoice_' . $order_id);

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(esc_html__('Order not found.', 'ecf-dgii-invoicing'));
        }

        $status = $order->get_meta(Ecf_Order_Handler::META_ECF_STATUS);
        if ($status !== Ecf_Order_Handler::STATUS_ACCEPTED) {
            wp_die(esc_html__('Invoice only available for accepted ECFs.', 'ecf-dgii-invoicing'));
        }

        $pdf = self::generate_pdf($order);

        $encf = $order->get_meta(Ecf_Order_Handler::META_ECF_ENCF);
        $filename = sanitize_file_name("ECF-{$encf}-Order-{$order_id}.pdf");

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF output, cannot be escaped.
        echo $pdf;
        exit;
    }

    public static function generate_pdf(\WC_Order $order): string {
        woo_ecf_dgii_autoloader();
        $html = self::build_html($order);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Build HTML for the invoice using stored ECF API response data.
     */
    private static function build_html(\WC_Order $order): string {
        // -- All data from API response and order meta --
        $ecf_type = $order->get_meta(Ecf_Order_Handler::META_ECF_TYPE);
        $response_json = $order->get_meta(Ecf_Order_Handler::META_ECF_RESPONSE);
        $response = $response_json ? json_decode($response_json, true) : [];

        // From EcfResponse
        $encf = $response['encf'] ?? $order->get_meta(Ecf_Order_Handler::META_ECF_ENCF);
        $codsec = $response['codSec'] ?? $order->get_meta(Ecf_Order_Handler::META_ECF_CODSEC);
        $fecha_firma = $response['fechaFirma'] ?? '';
        $rnc_emisor = $response['rncEmisor'] ?? Ecf_Settings::get_company_rnc();
        $impresion_url = $response['impresionUrl'] ?? $order->get_meta(Ecf_Order_Handler::META_ECF_IMPRESION_URL);
        $fecha_emision = $response['fechaEmision'] ?? $order->get_date_created()?->format('Y-m-d\TH:i:s');
        $monto_total = $response['montoTotal'] ?? (float) $order->get_total();

        // From order meta (set at checkout/submission time)
        $rnc_comprador = $order->get_meta(Ecf_Order_Handler::META_ECF_RNC_COMPRADOR);
        $razon_social_comprador = $order->get_meta(Ecf_Order_Handler::META_ECF_RAZON_SOCIAL);
        $expiration_date = $order->get_meta(Ecf_Order_Handler::META_ECF_EXPIRATION_DATE);

        // Company info from settings (fetched from API via test connection)
        $company_data = Ecf_Settings::get_company_data();
        $company_name = $company_data['razonSocial'] ?? '';

        // Format dates
        $fecha_emision_fmt = self::format_date($fecha_emision, 'd-m-Y');
        $fecha_firma_fmt = self::format_datetime($fecha_firma);
        $fecha_vencimiento_fmt = '';
        if ($expiration_date && in_array($ecf_type, ['E31', 'E33'], true)) {
            $fecha_vencimiento_fmt = self::format_date($expiration_date, 'd-m-Y');
        }

        $doc_type_name = self::ECF_TYPE_NAMES[$ecf_type] ?? 'Comprobante Fiscal Electrónico';

        // QR code from API's impresionUrl
        $qr_image = '';
        if ($impresion_url) {
            $qr_image = self::generate_qr_base64($impresion_url);
        }

        // Build items from order
        $items_html = self::build_items_html($order);

        // Totals from order
        $total_tax = (float) $order->get_total_tax();
        $total = is_numeric($monto_total) ? (float) $monto_total : (float) $order->get_total();
        $exempt = 0.0;
        $gravado = 0.0;
        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            if ((float) $item->get_total_tax() > 0) {
                $gravado += (float) $item->get_subtotal();
            } else {
                $exempt += (float) $item->get_subtotal();
            }
        }

        // NCF modificado for credit/debit notes
        $ncf_modificado = '';
        if (in_array($ecf_type, ['E33', 'E34'], true)) {
            $parent_order = $order->get_parent_id() ? wc_get_order($order->get_parent_id()) : null;
            if ($parent_order) {
                $ncf_modificado = $parent_order->get_meta(Ecf_Order_Handler::META_ECF_ENCF);
            }
        }

        // Build HTML
        $html = self::get_html_template();
        return str_replace([
            '{{DOC_TYPE_NAME}}',
            '{{COMPANY_NAME}}',
            '{{COMPANY_RNC}}',
            '{{ENCF}}',
            '{{FECHA_EMISION}}',
            '{{FECHA_VENCIMIENTO_SECTION}}',
            '{{CLIENT_SECTION}}',
            '{{ITEMS_HTML}}',
            '{{SUBTOTAL_GRAVADO_ROW}}',
            '{{SUBTOTAL_EXENTO_ROW}}',
            '{{TOTAL_ITBIS_ROW}}',
            '{{TOTAL}}',
            '{{QR_SECTION}}',
            '{{CODIGO_SEGURIDAD}}',
            '{{FECHA_FIRMA}}',
            '{{NCF_MODIFICADO_SECTION}}',
        ], [
            esc_html($doc_type_name),
            esc_html($company_name),
            esc_html($rnc_emisor),
            esc_html($encf),
            esc_html($fecha_emision_fmt),
            $fecha_vencimiento_fmt ? '<div class="info-row"><span class="label">Fecha Vencimiento:</span> ' . esc_html($fecha_vencimiento_fmt) . '</div>' : '',
            ($rnc_comprador || $razon_social_comprador) ? self::build_client_section($rnc_comprador, $razon_social_comprador) : '',
            $items_html,
            $gravado > 0 ? '<tr><td class="totals-label">Subtotal Gravado:</td><td class="totals-value">' . number_format($gravado, 2) . '</td></tr>' : '',
            $exempt > 0 ? '<tr><td class="totals-label">Subtotal Exento:</td><td class="totals-value">' . number_format($exempt, 2) . '</td></tr>' : '',
            $total_tax > 0 ? '<tr><td class="totals-label">Total ITBIS:</td><td class="totals-value">' . number_format($total_tax, 2) . '</td></tr>' : '',
            number_format($total, 2),
            $qr_image ? '<img src="' . $qr_image . '" class="qr-image" alt="QR">' : '',
            esc_html($codsec),
            esc_html($fecha_firma_fmt),
            $ncf_modificado ? '<div class="info-row"><span class="label">NCF Modificado:</span> <strong>' . esc_html($ncf_modificado) . '</strong></div>' : '',
        ], $html);
    }

    private static function format_date(string $value, string $format): string {
        if (!$value) return '';
        $dt = date_create($value);
        return $dt ? $dt->format($format) : $value;
    }

    private static function format_datetime(string $value): string {
        if (!$value) return '';
        $dt = date_create($value);
        return $dt ? $dt->format('d-m-Y H:i:s') : $value;
    }

    private static function build_client_section(string $rnc, string $razon_social): string {
        $html = '<div class="client-section">';
        if ($razon_social) {
            $html .= '<div class="info-row"><span class="label">Razón Social Cliente:</span> ' . esc_html($razon_social) . '</div>';
        }
        if ($rnc) {
            $html .= '<div class="info-row"><span class="label">RNC Cliente:</span> ' . esc_html($rnc) . '</div>';
        }
        $html .= '</div><hr class="separator">';
        return $html;
    }

    private static function build_items_html(\WC_Order $order): string {
        $html = '';

        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $qty = $item->get_quantity();
            $product = $item->get_product();
            $name = $item->get_name();
            $subtotal = (float) $item->get_subtotal();
            $tax = (float) $item->get_total_tax();
            $total_line = (float) $item->get_total();
            $price = $qty > 0 ? $subtotal / $qty : 0;
            $is_virtual = $product && $product->is_virtual();
            $unit = $is_virtual ? 'Servicio' : 'Unidad';
            $is_exempt = $tax == 0;

            $desc = $is_exempt ? 'E ' . esc_html($name) : esc_html($name);

            $html .= '<tr>';
            $html .= '<td class="text-right">' . number_format($qty, 2) . '</td>';
            $html .= '<td>' . $desc . '</td>';
            $html .= '<td class="text-center">' . esc_html($unit) . '</td>';
            $html .= '<td class="text-right">' . number_format($price, 2) . '</td>';
            $html .= '<td class="text-right">' . ($tax > 0 ? number_format($tax, 2) : '') . '</td>';
            $html .= '<td class="text-right">' . number_format($total_line, 2) . '</td>';
            $html .= '</tr>';
        }

        // Shipping as service line
        $shipping_total = (float) $order->get_shipping_total();
        if ($shipping_total > 0) {
            $shipping_tax = (float) $order->get_shipping_tax();
            $html .= '<tr>';
            $html .= '<td class="text-right">1.00</td>';
            $html .= '<td>' . esc_html__('Envío', 'ecf-dgii-invoicing') . '</td>';
            $html .= '<td class="text-center">Servicio</td>';
            $html .= '<td class="text-right">' . number_format($shipping_total, 2) . '</td>';
            $html .= '<td class="text-right">' . ($shipping_tax > 0 ? number_format($shipping_tax, 2) : '') . '</td>';
            $html .= '<td class="text-right">' . number_format($shipping_total, 2) . '</td>';
            $html .= '</tr>';
        }

        // Fees
        foreach ($order->get_items('fee') as $fee) {
            /** @var \WC_Order_Item_Fee $fee */
            $fee_total = (float) $fee->get_total();
            $fee_tax = (float) $fee->get_total_tax();
            $html .= '<tr>';
            $html .= '<td class="text-right">1.00</td>';
            $html .= '<td>' . esc_html($fee->get_name()) . '</td>';
            $html .= '<td class="text-center">Servicio</td>';
            $html .= '<td class="text-right">' . number_format($fee_total, 2) . '</td>';
            $html .= '<td class="text-right">' . ($fee_tax > 0 ? number_format($fee_tax, 2) : '') . '</td>';
            $html .= '<td class="text-right">' . number_format($fee_total, 2) . '</td>';
            $html .= '</tr>';
        }

        return $html;
    }

    private static function generate_qr_base64(string $data): string {
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'eccLevel' => QRCode::ECC_M,
            'scale' => 5,
            'imageBase64' => false,
        ]);

        $qrcode = new QRCode($options);
        $imageData = $qrcode->render($data);

        return 'data:image/png;base64,' . base64_encode($imageData);
    }

    private static function get_html_template(): string {
        return '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 8.5pt; color: #333; }
    .page { padding: 36px; }

    .header { width: 100%; margin-bottom: 4px; }
    .header-left { float: left; width: 50%; }
    .header-right { float: right; width: 50%; text-align: right; }
    .clearfix::after { content: ""; display: table; clear: both; }

    .company-name { font-size: 10pt; font-weight: bold; color: #0046A0; margin-bottom: 2px; }
    .company-detail { font-size: 8.5pt; margin-bottom: 1px; }

    .doc-type { font-size: 12pt; font-weight: bold; color: #0070C0; margin-bottom: 3px; }
    .encf { font-size: 8.5pt; font-weight: bold; margin-bottom: 1px; }
    .info-row { font-size: 8.5pt; margin-bottom: 1px; }
    .label { font-weight: bold; }

    hr.separator { border: none; border-top: 1px solid #333; margin: 4px 0; }

    .client-section { margin: 4px 0; }

    .items-table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    .items-table th {
        background: #DCDCDC;
        border: 0.5px solid #B4B4B4;
        font-size: 7.5pt;
        font-weight: bold;
        text-align: center;
        padding: 3px 4px;
    }
    .items-table td {
        border: 0.5px solid #C8C8C8;
        font-size: 7.5pt;
        padding: 2px 4px;
    }
    .text-right { text-align: right; }
    .text-center { text-align: center; }

    .bottom-section { width: 100%; margin-top: 8px; }
    .qr-section { float: left; width: 50%; }
    .totals-section { float: right; width: 50%; }

    .qr-image { width: 70px; height: 70px; }
    .security-info { font-size: 7.5pt; margin-top: 2px; }

    .totals-table { width: 100%; }
    .totals-table td { font-size: 8.5pt; padding: 1px 4px; }
    .totals-label { text-align: right; font-weight: bold; }
    .totals-value { text-align: right; width: 85px; }
    .total-final { font-weight: bold; }

    .footer-note {
        font-size: 6.5pt;
        color: #888;
        margin-top: 10px;
    }
</style>
</head>
<body>
<div class="page">

    <!-- HEADER -->
    <div class="header clearfix">
        <div class="header-left">
            <div class="company-name">{{COMPANY_NAME}}</div>
            <div class="company-detail">RNC {{COMPANY_RNC}}</div>
            <div class="company-detail">Fecha Emisión: {{FECHA_EMISION}}</div>
        </div>
        <div class="header-right">
            <div class="doc-type">{{DOC_TYPE_NAME}}</div>
            <div class="encf">e-NCF: {{ENCF}}</div>
            {{FECHA_VENCIMIENTO_SECTION}}
            {{NCF_MODIFICADO_SECTION}}
        </div>
    </div>

    <hr class="separator">

    <!-- CLIENT -->
    {{CLIENT_SECTION}}

    <!-- ITEMS TABLE -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:40px;">Cantidad</th>
                <th style="width:180px;">Descripción</th>
                <th style="width:55px;">Unidad de<br>Medida</th>
                <th style="width:60px;">Precio</th>
                <th style="width:60px;">ITBIS</th>
                <th style="width:65px;">Valor</th>
            </tr>
        </thead>
        <tbody>
            {{ITEMS_HTML}}
        </tbody>
    </table>

    <!-- QR + TOTALS -->
    <div class="bottom-section clearfix">
        <div class="qr-section">
            {{QR_SECTION}}
            <div class="security-info">Código de Seguridad: {{CODIGO_SEGURIDAD}}</div>
            <div class="security-info">Fecha de Firma Digital: {{FECHA_FIRMA}}</div>
        </div>
        <div class="totals-section">
            <table class="totals-table">
                {{SUBTOTAL_GRAVADO_ROW}}
                {{SUBTOTAL_EXENTO_ROW}}
                {{TOTAL_ITBIS_ROW}}
                <tr class="total-final">
                    <td class="totals-label">Total:</td>
                    <td class="totals-value">{{TOTAL}}</td>
                </tr>
            </table>
        </div>
    </div>

</div>
</body>
</html>';
    }
}
