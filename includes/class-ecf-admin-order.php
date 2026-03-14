<?php
defined('ABSPATH') || exit;

class Ecf_Admin_Order {

    public static function init(): void {
        add_action('add_meta_boxes', [self::class, 'add_metabox']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_styles']);
        add_action('wp_ajax_ecf_dgii_retry_submission', [self::class, 'ajax_retry']);
    }

    public static function add_metabox(): void {
        $screen = class_exists(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)
            && wc_get_container()
                ->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)
                ->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'ecf-dgii-status',
            __('ECF DGII', 'ecf-dgii-invoicing'),
            [self::class, 'render_metabox'],
            $screen,
            'side',
            'high'
        );
    }

    public static function render_metabox($post_or_order): void {
        $order = ($post_or_order instanceof \WC_Order)
            ? $post_or_order
            : wc_get_order($post_or_order->ID);

        if (!$order) {
            echo '<p>' . esc_html__('Order not found.', 'ecf-dgii-invoicing') . '</p>';
            return;
        }

        $status = $order->get_meta(Ecf_Order_Handler::META_ECF_STATUS) ?: 'none';

        // On-demand polling: if still submitting, check the result now
        if ($status === Ecf_Order_Handler::STATUS_SUBMITTING) {
            $status = self::try_poll_order($order);
        }

        $ecf_type = $order->get_meta(Ecf_Order_Handler::META_ECF_TYPE);
        $encf = $order->get_meta(Ecf_Order_Handler::META_ECF_ENCF);
        $codsec = $order->get_meta(Ecf_Order_Handler::META_ECF_CODSEC);
        $errors = $order->get_meta(Ecf_Order_Handler::META_ECF_ERRORS);
        $rnc_comprador = $order->get_meta(Ecf_Order_Handler::META_ECF_RNC_COMPRADOR);
        $razon_social = $order->get_meta(Ecf_Order_Handler::META_ECF_RAZON_SOCIAL);

        $status_labels = [
            'none' => ['label' => __('Not sent', 'ecf-dgii-invoicing'), 'class' => 'ecf-status-none'],
            Ecf_Order_Handler::STATUS_PENDING => ['label' => __('Pending', 'ecf-dgii-invoicing'), 'class' => 'ecf-status-pending'],
            Ecf_Order_Handler::STATUS_SUBMITTING => ['label' => __('Submitting', 'ecf-dgii-invoicing'), 'class' => 'ecf-status-pending'],
            Ecf_Order_Handler::STATUS_ACCEPTED => ['label' => __('Accepted', 'ecf-dgii-invoicing'), 'class' => 'ecf-status-accepted'],
            Ecf_Order_Handler::STATUS_REJECTED => ['label' => __('Rejected', 'ecf-dgii-invoicing'), 'class' => 'ecf-status-rejected'],
            Ecf_Order_Handler::STATUS_ERROR => ['label' => __('Error', 'ecf-dgii-invoicing'), 'class' => 'ecf-status-error'],
            Ecf_Order_Handler::STATUS_CONTINGENCIA => ['label' => __('Contingencia', 'ecf-dgii-invoicing'), 'class' => 'ecf-status-contingencia'],
        ];

        $s = $status_labels[$status] ?? $status_labels['none'];
        ?>
        <div class="ecf-metabox">
            <p>
                <strong><?php esc_html_e('Status:', 'ecf-dgii-invoicing'); ?></strong>
                <span class="ecf-status-badge <?php echo esc_attr($s['class']); ?>">
                    <?php echo esc_html($s['label']); ?>
                </span>
            </p>

            <?php if ($ecf_type): ?>
                <p><strong><?php esc_html_e('Type:', 'ecf-dgii-invoicing'); ?></strong> <?php echo esc_html($ecf_type); ?></p>
            <?php endif; ?>

            <?php if ($rnc_comprador): ?>
                <p><strong><?php esc_html_e('Buyer RNC:', 'ecf-dgii-invoicing'); ?></strong> <?php echo esc_html($rnc_comprador); ?></p>
            <?php endif; ?>

            <?php if ($razon_social): ?>
                <p><strong><?php esc_html_e('Buyer:', 'ecf-dgii-invoicing'); ?></strong> <?php echo esc_html($razon_social); ?></p>
            <?php endif; ?>

            <?php if ($encf): ?>
                <p><strong><?php esc_html_e('eNCF:', 'ecf-dgii-invoicing'); ?></strong> <?php echo esc_html($encf); ?></p>
            <?php endif; ?>

            <?php if ($codsec): ?>
                <p><strong><?php esc_html_e('Security Code:', 'ecf-dgii-invoicing'); ?></strong> <?php echo esc_html($codsec); ?></p>
            <?php endif; ?>

            <?php if ($errors): ?>
                <p class="ecf-error-details">
                    <strong><?php esc_html_e('Errors:', 'ecf-dgii-invoicing'); ?></strong><br>
                    <?php echo esc_html($errors); ?>
                </p>
            <?php endif; ?>

            <?php if ($status === Ecf_Order_Handler::STATUS_ACCEPTED): ?>
                <p>
                    <a href="<?php echo esc_url(wp_nonce_url(
                        admin_url('admin-ajax.php?action=ecf_dgii_download_invoice&order_id=' . $order->get_id()),
                        'ecf_invoice_' . $order->get_id()
                    )); ?>" class="button" target="_blank">
                        <?php esc_html_e('Download Invoice', 'ecf-dgii-invoicing'); ?>
                    </a>
                </p>
            <?php endif; ?>

            <?php if (in_array($status, ['error', 'rejected', 'none'])): ?>
                <p>
                    <button type="button" class="button ecf-retry-btn"
                            data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                        <?php esc_html_e('Send ECF', 'ecf-dgii-invoicing'); ?>
                    </button>
                </p>
                <script>
                jQuery(function($) {
                    $('.ecf-retry-btn').on('click', function() {
                        var $btn = $(this);
                        $btn.prop('disabled', true).text('<?php echo esc_js(__('Sending...', 'ecf-dgii-invoicing')); ?>');
                        $.post(ajaxurl, {
                            action: 'ecf_dgii_retry_submission',
                            order_id: $btn.data('order-id'),
                            _wpnonce: '<?php echo wp_create_nonce('ecf_dgii_retry'); ?>'
                        }, function() {
                            location.reload();
                        });
                    });
                });
                </script>
            <?php endif; ?>

            <?php
            // Show credit notes (E34) for refunds
            $refunds = $order->get_refunds();
            if (!empty($refunds)):
                foreach ($refunds as $refund):
                    $ref_encf = $refund->get_meta(Ecf_Refund_Handler::META_REFUND_ECF_ENCF);
                    $ref_status = $refund->get_meta(Ecf_Refund_Handler::META_REFUND_ECF_STATUS);
                    if ($ref_status === 'pending') {
                        $ref_status = self::try_submit_refund($refund);
                    } elseif ($ref_status === 'submitting') {
                        $ref_status = self::try_poll_refund($refund);
                    }
                    $ref_codsec = $refund->get_meta(Ecf_Refund_Handler::META_REFUND_ECF_CODSEC);
                    $ref_errors = $refund->get_meta(Ecf_Refund_Handler::META_REFUND_ECF_ERRORS);
                    if (!$ref_encf) continue;

                    $ref_status_label = match($ref_status) {
                        'accepted' => ['label' => __('Accepted', 'ecf-dgii-invoicing'), 'class' => 'ecf-status-accepted'],
                        'rejected' => ['label' => __('Rejected', 'ecf-dgii-invoicing'), 'class' => 'ecf-status-rejected'],
                        'error' => ['label' => __('Error', 'ecf-dgii-invoicing'), 'class' => 'ecf-status-error'],
                        'submitting' => ['label' => __('Processing', 'ecf-dgii-invoicing'), 'class' => 'ecf-status-pending'],
                        default => ['label' => __('Pending', 'ecf-dgii-invoicing'), 'class' => 'ecf-status-pending'],
                    };
                    ?>
                    <hr style="margin:10px 0;">
                    <p>
                        <strong><?php esc_html_e('Credit Note (E34)', 'ecf-dgii-invoicing'); ?></strong>
                        — <?php echo wp_kses_post(wc_price(abs((float) $refund->get_total()))); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Status:', 'ecf-dgii-invoicing'); ?></strong>
                        <span class="ecf-status-badge <?php echo esc_attr($ref_status_label['class']); ?>">
                            <?php echo esc_html($ref_status_label['label']); ?>
                        </span>
                    </p>
                    <p><strong><?php esc_html_e('eNCF:', 'ecf-dgii-invoicing'); ?></strong> <?php echo esc_html($ref_encf); ?></p>
                    <?php if ($ref_codsec): ?>
                        <p><strong><?php esc_html_e('Security Code:', 'ecf-dgii-invoicing'); ?></strong> <?php echo esc_html($ref_codsec); ?></p>
                    <?php endif; ?>
                    <?php if ($ref_errors): ?>
                        <p class="ecf-error-details"><?php echo esc_html($ref_errors); ?></p>
                    <?php endif; ?>
                <?php
                endforeach;
            endif;
            ?>
        </div>
        <?php
    }

    private static function try_poll_order(\WC_Order $order): string {
        $message_id = $order->get_meta(Ecf_Order_Handler::META_ECF_MESSAGE_ID);
        if (!$message_id) {
            return Ecf_Order_Handler::STATUS_SUBMITTING;
        }

        try {
            woo_ecf_dgii_autoloader();
            $client = new Ecf_Api_Client();
            $latest = $client->check_ecf(Ecf_Settings::get_company_rnc(), $message_id);

            if (!$latest) {
                return Ecf_Order_Handler::STATUS_SUBMITTING;
            }

            $progress = $latest->getProgress();

            if ($progress === \Ecfx\EcfDgii\Model\EcfProgress::FINISHED) {
                $order->update_meta_data(Ecf_Order_Handler::META_ECF_STATUS, Ecf_Order_Handler::STATUS_ACCEPTED);
                $order->update_meta_data(Ecf_Order_Handler::META_ECF_CODSEC, $latest->getCodSec() ?? '');
                $order->update_meta_data(Ecf_Order_Handler::META_ECF_IMPRESION_URL, $latest->getImpresionUrl() ?? '');
                $order->update_meta_data(Ecf_Order_Handler::META_ECF_RESPONSE, json_encode($latest->jsonSerialize()));
                $order->update_meta_data(Ecf_Order_Handler::META_ECF_ERRORS, '');
                $order->save();
                return Ecf_Order_Handler::STATUS_ACCEPTED;
            }

            if ($progress === \Ecfx\EcfDgii\Model\EcfProgress::ERROR) {
                $order->update_meta_data(Ecf_Order_Handler::META_ECF_STATUS, Ecf_Order_Handler::STATUS_REJECTED);
                $order->update_meta_data(Ecf_Order_Handler::META_ECF_ERRORS, $latest->getErrors() ?? $latest->getMensaje() ?? 'Unknown error');
                $order->save();
                return Ecf_Order_Handler::STATUS_REJECTED;
            }
        } catch (\Exception $e) {
            // Silently fail — don't break the admin page
        }

        return Ecf_Order_Handler::STATUS_SUBMITTING;
    }

    /**
     * On-demand: if refund is pending, submit it now (Action Scheduler didn't fire).
     */
    private static function try_submit_refund(\WC_Abstract_Order $refund): string {
        try {
            Ecf_Refund_Handler::async_submit_refund($refund->get_id());
            // Re-read status after submission
            $refund = wc_get_order($refund->get_id());
            return $refund ? ($refund->get_meta(Ecf_Refund_Handler::META_REFUND_ECF_STATUS) ?: 'pending') : 'pending';
        } catch (\Exception $e) {
            return 'error';
        }
    }

    private static function try_poll_refund(\WC_Abstract_Order $refund): string {
        $message_id = $refund->get_meta(Ecf_Refund_Handler::META_REFUND_ECF_MESSAGE_ID);
        $status = $refund->get_meta(Ecf_Refund_Handler::META_REFUND_ECF_STATUS);
        if (!$message_id || $status !== 'submitting') {
            return $status ?: 'pending';
        }

        try {
            woo_ecf_dgii_autoloader();
            $client = new Ecf_Api_Client();
            $latest = $client->check_ecf(Ecf_Settings::get_company_rnc(), $message_id);

            if (!$latest) {
                return 'submitting';
            }

            $progress = $latest->getProgress();

            if ($progress === \Ecfx\EcfDgii\Model\EcfProgress::FINISHED) {
                $refund->update_meta_data(Ecf_Refund_Handler::META_REFUND_ECF_STATUS, 'accepted');
                $refund->update_meta_data(Ecf_Refund_Handler::META_REFUND_ECF_CODSEC, $latest->getCodSec() ?? '');
                $refund->update_meta_data(Ecf_Refund_Handler::META_REFUND_ECF_ERRORS, '');
                $refund->save();
                return 'accepted';
            }

            if ($progress === \Ecfx\EcfDgii\Model\EcfProgress::ERROR) {
                $refund->update_meta_data(Ecf_Refund_Handler::META_REFUND_ECF_STATUS, 'rejected');
                $refund->update_meta_data(Ecf_Refund_Handler::META_REFUND_ECF_ERRORS, $latest->getErrors() ?? 'Unknown error');
                $refund->save();
                return 'rejected';
            }
        } catch (\Exception $e) {
            // Silently fail
        }

        return 'submitting';
    }

    public static function enqueue_styles(string $hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php', 'woocommerce_page_wc-orders'])) {
            return;
        }
        wp_enqueue_style(
            'ecf-dgii-admin',
            WOO_ECF_DGII_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WOO_ECF_DGII_VERSION
        );
    }

    public static function ajax_retry(): void {
        check_ajax_referer('ecf_dgii_retry');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }

        $order_id = absint($_POST['order_id'] ?? 0);
        if (!$order_id) {
            wp_send_json_error('Invalid order ID');
        }

        $order = wc_get_order($order_id);
        if ($order) {
            $order->update_meta_data(Ecf_Order_Handler::META_ECF_STATUS, Ecf_Order_Handler::STATUS_ERROR);
            $order->save();
        }

        Ecf_Order_Handler::on_payment_complete($order_id);
        wp_send_json_success();
    }
}
