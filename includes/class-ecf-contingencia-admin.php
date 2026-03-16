<?php
defined('ABSPATH') || exit;

class Ecf_Contingencia_Admin {

    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu_page']);
        add_action('admin_post_ecf_batch_submit_contingencia', [self::class, 'handle_batch_submit']);
    }

    public static function add_menu_page(): void {
        $pending = Ecf_Contingencia::get_pending_count();
        $badge = $pending > 0 ? ' <span class="awaiting-mod">' . $pending . '</span>' : '';

        add_submenu_page(
            'woocommerce',
            __('ECF Contingencia', 'ecf-dgii-invoicing'),
            __('ECF Contingencia', 'ecf-dgii-invoicing') . $badge,
            'manage_woocommerce',
            'ecf-contingencia',
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void {
        $orders = wc_get_orders([
            'meta_key' => Ecf_Order_Handler::META_ECF_STATUS,
            'meta_value' => Ecf_Order_Handler::STATUS_CONTINGENCIA,
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('ECF Contingencia — B-Series Orders', 'ecf-dgii-invoicing'); ?></h1>

            <?php if (isset($_GET['msg'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php
                        echo match ($_GET['msg']) {
                            'submitted' => sprintf(
                                esc_html__('%d order(s) submitted to DGII.', 'ecf-dgii-invoicing'),
                                absint($_GET['count'] ?? 0)
                            ),
                            default => '',
                        };
                    ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['error']))); ?></p>
                </div>
            <?php endif; ?>

            <?php if (empty($orders)): ?>
                <p><?php esc_html_e('No orders in contingencia mode.', 'ecf-dgii-invoicing'); ?></p>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('ecf_batch_submit_contingencia'); ?>
                    <input type="hidden" name="action" value="ecf_batch_submit_contingencia">

                    <p>
                        <button type="submit" class="button button-primary"
                                onclick="return confirm('<?php echo esc_js(__('Submit selected orders to DGII?', 'ecf-dgii-invoicing')); ?>');">
                            <?php esc_html_e('Submit Selected to DGII', 'ecf-dgii-invoicing'); ?>
                        </button>
                        <button type="button" class="button" id="ecf-select-all">
                            <?php esc_html_e('Select All', 'ecf-dgii-invoicing'); ?>
                        </button>
                    </p>

                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <td class="check-column"><input type="checkbox" id="cb-select-all"></td>
                                <th><?php esc_html_e('Order', 'ecf-dgii-invoicing'); ?></th>
                                <th><?php esc_html_e('Date', 'ecf-dgii-invoicing'); ?></th>
                                <th><?php esc_html_e('Customer', 'ecf-dgii-invoicing'); ?></th>
                                <th><?php esc_html_e('Total', 'ecf-dgii-invoicing'); ?></th>
                                <th><?php esc_html_e('ECF Type', 'ecf-dgii-invoicing'); ?></th>
                                <th><?php esc_html_e('B-Series Code', 'ecf-dgii-invoicing'); ?></th>
                                <th><?php esc_html_e('Original eNCF', 'ecf-dgii-invoicing'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <?php
                                $b_code = $order->get_meta(Ecf_Contingencia::META_B_SERIES_CODE);
                                $encf = $order->get_meta(Ecf_Order_Handler::META_ECF_ENCF);
                                $ecf_type = $order->get_meta(Ecf_Order_Handler::META_ECF_TYPE);
                                $order_url = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->get_id());
                                ?>
                                <tr>
                                    <th class="check-column">
                                        <input type="checkbox" name="order_ids[]"
                                               value="<?php echo esc_attr($order->get_id()); ?>">
                                    </th>
                                    <td>
                                        <a href="<?php echo esc_url($order_url); ?>">
                                            <strong>#<?php echo esc_html($order->get_id()); ?></strong>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html($order->get_date_created()->format('Y-m-d H:i')); ?></td>
                                    <td><?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?></td>
                                    <td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                                    <td><?php echo esc_html($ecf_type); ?></td>
                                    <td><code><?php echo esc_html($b_code); ?></code></td>
                                    <td><code><?php echo esc_html($encf); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>

                <script>
                jQuery(function($) {
                    $('#cb-select-all, #ecf-select-all').on('click', function() {
                        $('input[name="order_ids[]"]').prop('checked', true);
                    });
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_batch_submit(): void {
        check_admin_referer('ecf_batch_submit_contingencia');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'ecf-dgii-invoicing'));
        }

        $order_ids = array_map('absint', $_POST['order_ids'] ?? []);
        if (empty($order_ids)) {
            wp_safe_redirect(admin_url('admin.php?page=ecf-contingencia&error=' . urlencode(__('No orders selected.', 'ecf-dgii-invoicing'))));
            exit;
        }

        $submitted = 0;
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

            $status = $order->get_meta(Ecf_Order_Handler::META_ECF_STATUS);
            if ($status !== Ecf_Order_Handler::STATUS_CONTINGENCIA) {
                continue;
            }

            if (Ecf_Contingencia::convert_to_encf($order)) {
                $submitted++;
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=ecf-contingencia&msg=submitted&count=' . $submitted));
        exit;
    }
}
