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
            __('ECF DGII', 'woo-ecf-dgii'),
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
            echo '<p>' . esc_html__('Order not found.', 'woo-ecf-dgii') . '</p>';
            return;
        }

        $status = $order->get_meta(Ecf_Order_Handler::META_ECF_STATUS) ?: 'none';
        $ecf_type = $order->get_meta(Ecf_Order_Handler::META_ECF_TYPE);
        $encf = $order->get_meta(Ecf_Order_Handler::META_ECF_ENCF);
        $codsec = $order->get_meta(Ecf_Order_Handler::META_ECF_CODSEC);
        $errors = $order->get_meta(Ecf_Order_Handler::META_ECF_ERRORS);
        $rnc_comprador = $order->get_meta(Ecf_Order_Handler::META_ECF_RNC_COMPRADOR);
        $razon_social = $order->get_meta(Ecf_Order_Handler::META_ECF_RAZON_SOCIAL);

        $status_labels = [
            'none' => ['label' => __('Not sent', 'woo-ecf-dgii'), 'class' => 'ecf-status-none'],
            Ecf_Order_Handler::STATUS_PENDING => ['label' => __('Pending', 'woo-ecf-dgii'), 'class' => 'ecf-status-pending'],
            Ecf_Order_Handler::STATUS_SUBMITTING => ['label' => __('Submitting', 'woo-ecf-dgii'), 'class' => 'ecf-status-pending'],
            Ecf_Order_Handler::STATUS_POLLING => ['label' => __('Processing', 'woo-ecf-dgii'), 'class' => 'ecf-status-pending'],
            Ecf_Order_Handler::STATUS_ACCEPTED => ['label' => __('Accepted', 'woo-ecf-dgii'), 'class' => 'ecf-status-accepted'],
            Ecf_Order_Handler::STATUS_REJECTED => ['label' => __('Rejected', 'woo-ecf-dgii'), 'class' => 'ecf-status-rejected'],
            Ecf_Order_Handler::STATUS_ERROR => ['label' => __('Error', 'woo-ecf-dgii'), 'class' => 'ecf-status-error'],
            Ecf_Order_Handler::STATUS_CONTINGENCIA => ['label' => __('Contingencia', 'woo-ecf-dgii'), 'class' => 'ecf-status-contingencia'],
        ];

        $s = $status_labels[$status] ?? $status_labels['none'];
        ?>
        <div class="ecf-metabox">
            <p>
                <strong><?php esc_html_e('Status:', 'woo-ecf-dgii'); ?></strong>
                <span class="ecf-status-badge <?php echo esc_attr($s['class']); ?>">
                    <?php echo esc_html($s['label']); ?>
                </span>
            </p>

            <?php if ($ecf_type): ?>
                <p><strong><?php esc_html_e('Type:', 'woo-ecf-dgii'); ?></strong> <?php echo esc_html($ecf_type); ?></p>
            <?php endif; ?>

            <?php if ($rnc_comprador): ?>
                <p><strong><?php esc_html_e('Buyer RNC:', 'woo-ecf-dgii'); ?></strong> <?php echo esc_html($rnc_comprador); ?></p>
            <?php endif; ?>

            <?php if ($razon_social): ?>
                <p><strong><?php esc_html_e('Buyer:', 'woo-ecf-dgii'); ?></strong> <?php echo esc_html($razon_social); ?></p>
            <?php endif; ?>

            <?php if ($encf): ?>
                <p><strong><?php esc_html_e('eNCF:', 'woo-ecf-dgii'); ?></strong> <?php echo esc_html($encf); ?></p>
            <?php endif; ?>

            <?php if ($codsec): ?>
                <p><strong><?php esc_html_e('Security Code:', 'woo-ecf-dgii'); ?></strong> <?php echo esc_html($codsec); ?></p>
            <?php endif; ?>

            <?php if ($errors): ?>
                <p class="ecf-error-details">
                    <strong><?php esc_html_e('Errors:', 'woo-ecf-dgii'); ?></strong><br>
                    <?php echo esc_html($errors); ?>
                </p>
            <?php endif; ?>

            <?php if (in_array($status, ['error', 'rejected', 'none'])): ?>
                <p>
                    <button type="button" class="button ecf-retry-btn"
                            data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                        <?php esc_html_e('Send ECF', 'woo-ecf-dgii'); ?>
                    </button>
                </p>
                <script>
                jQuery(function($) {
                    $('.ecf-retry-btn').on('click', function() {
                        var $btn = $(this);
                        $btn.prop('disabled', true).text('<?php echo esc_js(__('Sending...', 'woo-ecf-dgii')); ?>');
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
                    $ref_codsec = $refund->get_meta(Ecf_Refund_Handler::META_REFUND_ECF_CODSEC);
                    $ref_errors = $refund->get_meta(Ecf_Refund_Handler::META_REFUND_ECF_ERRORS);
                    if (!$ref_encf) continue;

                    $ref_status_label = match($ref_status) {
                        'accepted' => ['label' => __('Accepted', 'woo-ecf-dgii'), 'class' => 'ecf-status-accepted'],
                        'rejected' => ['label' => __('Rejected', 'woo-ecf-dgii'), 'class' => 'ecf-status-rejected'],
                        'error' => ['label' => __('Error', 'woo-ecf-dgii'), 'class' => 'ecf-status-error'],
                        'polling' => ['label' => __('Processing', 'woo-ecf-dgii'), 'class' => 'ecf-status-pending'],
                        default => ['label' => __('Pending', 'woo-ecf-dgii'), 'class' => 'ecf-status-pending'],
                    };
                    ?>
                    <hr style="margin:10px 0;">
                    <p><strong><?php esc_html_e('Credit Note (E34)', 'woo-ecf-dgii'); ?></strong></p>
                    <p>
                        <span class="ecf-status-badge <?php echo esc_attr($ref_status_label['class']); ?>">
                            <?php echo esc_html($ref_status_label['label']); ?>
                        </span>
                    </p>
                    <p><strong><?php esc_html_e('eNCF:', 'woo-ecf-dgii'); ?></strong> <?php echo esc_html($ref_encf); ?></p>
                    <?php if ($ref_codsec): ?>
                        <p><strong><?php esc_html_e('Security Code:', 'woo-ecf-dgii'); ?></strong> <?php echo esc_html($ref_codsec); ?></p>
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
