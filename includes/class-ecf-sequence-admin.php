<?php
defined('ABSPATH') || exit;

class Ecf_Sequence_Admin {

    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu_page']);
        add_action('admin_post_ecf_add_sequence', [self::class, 'handle_add_sequence']);
        add_action('admin_post_ecf_deactivate_sequence', [self::class, 'handle_deactivate_sequence']);
    }

    public static function add_menu_page(): void {
        add_submenu_page(
            'woocommerce',
            __('ECF Sequences', 'woo-ecf-dgii'),
            __('ECF Sequences', 'woo-ecf-dgii'),
            'manage_woocommerce',
            'ecf-sequences',
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void {
        $sequences = Ecf_Sequence_Manager::get_all_sequences();
        $today = current_time('Y-m-d');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('ECF eNCF Sequences', 'woo-ecf-dgii'); ?></h1>

            <?php if (isset($_GET['msg'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php
                        echo match ($_GET['msg']) {
                            'added' => esc_html__('Sequence added successfully.', 'woo-ecf-dgii'),
                            'deactivated' => esc_html__('Sequence deactivated.', 'woo-ecf-dgii'),
                            default => '',
                        };
                    ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html(urldecode($_GET['error'])); ?></p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e('Active Sequences', 'woo-ecf-dgii'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Type', 'woo-ecf-dgii'); ?></th>
                        <th><?php esc_html_e('Serie', 'woo-ecf-dgii'); ?></th>
                        <th><?php esc_html_e('Prefix', 'woo-ecf-dgii'); ?></th>
                        <th><?php esc_html_e('Start', 'woo-ecf-dgii'); ?></th>
                        <th><?php esc_html_e('End', 'woo-ecf-dgii'); ?></th>
                        <th><?php esc_html_e('Current', 'woo-ecf-dgii'); ?></th>
                        <th><?php esc_html_e('Remaining', 'woo-ecf-dgii'); ?></th>
                        <th><?php esc_html_e('Expiry Date', 'woo-ecf-dgii'); ?></th>
                        <th><?php esc_html_e('Status', 'woo-ecf-dgii'); ?></th>
                        <th><?php esc_html_e('Actions', 'woo-ecf-dgii'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sequences)): ?>
                        <tr>
                            <td colspan="10"><?php esc_html_e('No sequences found. Add one below.', 'woo-ecf-dgii'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sequences as $seq): ?>
                            <?php
                            $expired = $seq['expiration_date'] < $today;
                            $exhausted = (int) $seq['remaining'] <= 0;
                            $status_class = $expired ? 'color:red;' : ($exhausted ? 'color:orange;' : 'color:green;');
                            $status_text = $expired
                                ? __('Expired', 'woo-ecf-dgii')
                                : ($exhausted ? __('Exhausted', 'woo-ecf-dgii') : __('Active', 'woo-ecf-dgii'));
                            ?>
                            <tr>
                                <td><?php echo esc_html($seq['ecf_type']); ?></td>
                                <td><?php echo esc_html($seq['serie']); ?></td>
                                <td><code><?php echo esc_html($seq['prefix']); ?></code></td>
                                <td><?php echo esc_html($seq['range_start']); ?></td>
                                <td><?php echo esc_html($seq['range_end']); ?></td>
                                <td><strong><?php echo esc_html($seq['current_number']); ?></strong></td>
                                <td style="<?php echo esc_attr($status_class); ?>">
                                    <strong><?php echo esc_html(max(0, (int) $seq['remaining'])); ?></strong>
                                </td>
                                <td><?php echo esc_html($seq['expiration_date']); ?></td>
                                <td><span style="<?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_text); ?></span></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <?php wp_nonce_field('ecf_deactivate_seq_' . $seq['id']); ?>
                                        <input type="hidden" name="action" value="ecf_deactivate_sequence">
                                        <input type="hidden" name="sequence_id" value="<?php echo esc_attr($seq['id']); ?>">
                                        <button type="submit" class="button button-small"
                                                onclick="return confirm('<?php echo esc_js(__('Deactivate this sequence?', 'woo-ecf-dgii')); ?>');">
                                            <?php esc_html_e('Deactivate', 'woo-ecf-dgii'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <hr>
            <h2><?php esc_html_e('Add New Sequence', 'woo-ecf-dgii'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ecf_add_sequence'); ?>
                <input type="hidden" name="action" value="ecf_add_sequence">
                <table class="form-table">
                    <tr>
                        <th><label for="serie"><?php esc_html_e('Serie', 'woo-ecf-dgii'); ?></label></th>
                        <td>
                            <select name="serie" id="serie" required>
                                <option value="E">E - Electrónico</option>
                                <option value="B">B - Contingencia</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ecf_type"><?php esc_html_e('Type', 'woo-ecf-dgii'); ?></label></th>
                        <td>
                            <select name="ecf_type" id="ecf_type" required>
                            </select>
                            <input type="hidden" name="prefix" id="prefix" value="">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="range_start"><?php esc_html_e('Range Start', 'woo-ecf-dgii'); ?></label></th>
                        <td>
                            <input type="number" name="range_start" id="range_start" required min="1" class="regular-text"
                                   placeholder="1">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="range_end"><?php esc_html_e('Range End', 'woo-ecf-dgii'); ?></label></th>
                        <td>
                            <input type="number" name="range_end" id="range_end" required min="1" class="regular-text"
                                   placeholder="1000">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="expiration_date"><?php esc_html_e('Expiration Date', 'woo-ecf-dgii'); ?></label></th>
                        <td>
                            <input type="date" name="expiration_date" id="expiration_date" required class="regular-text">
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Add Sequence', 'woo-ecf-dgii')); ?>
            </form>
        </div>
        <script>
        jQuery(function($) {
            var types = {
                E: [
                    {value: 'E31', label: 'E31 - Crédito Fiscal', prefix: 'E31'},
                    {value: 'E32', label: 'E32 - Consumo', prefix: 'E32'},
                    {value: 'E33', label: 'E33 - Nota de Débito', prefix: 'E33'},
                    {value: 'E34', label: 'E34 - Nota de Crédito', prefix: 'E34'}
                ],
                B: [
                    {value: 'E31', label: 'B01 - Crédito Fiscal', prefix: 'B01'},
                    {value: 'E32', label: 'B02 - Consumo', prefix: 'B02'},
                    {value: 'E33', label: 'B03 - Nota de Débito', prefix: 'B03'},
                    {value: 'E34', label: 'B04 - Nota de Crédito', prefix: 'B04'}
                ]
            };

            function updateTypes() {
                var serie = $('#serie').val();
                var $type = $('#ecf_type');
                var $prefix = $('#prefix');
                $type.empty();
                $.each(types[serie] || [], function(_, t) {
                    $type.append($('<option>', {value: t.value, text: t.label, 'data-prefix': t.prefix}));
                });
                updatePrefix();
            }

            function updatePrefix() {
                var prefix = $('#ecf_type option:selected').data('prefix') || '';
                $('#prefix').val(prefix);
            }

            $('#serie').on('change', updateTypes);
            $('#ecf_type').on('change', updatePrefix);
            updateTypes();
        });
        </script>
        <?php
    }

    public static function handle_add_sequence(): void {
        check_admin_referer('ecf_add_sequence');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Permission denied.', 'woo-ecf-dgii'));
        }

        $ecf_type = sanitize_text_field($_POST['ecf_type'] ?? '');
        $serie = sanitize_text_field($_POST['serie'] ?? 'E');
        $prefix = sanitize_text_field($_POST['prefix'] ?? '');
        $range_start = absint($_POST['range_start'] ?? 0);
        $range_end = absint($_POST['range_end'] ?? 0);
        $expiration_date = sanitize_text_field($_POST['expiration_date'] ?? '');

        if (!$ecf_type || !$prefix || !$range_start || !$range_end || !$expiration_date) {
            wp_redirect(admin_url('admin.php?page=ecf-sequences&error=' . urlencode(__('All fields are required.', 'woo-ecf-dgii'))));
            exit;
        }

        if ($range_start > $range_end) {
            wp_redirect(admin_url('admin.php?page=ecf-sequences&error=' . urlencode(__('Range start must be less than range end.', 'woo-ecf-dgii'))));
            exit;
        }

        if (!in_array($ecf_type, ['E31', 'E32', 'E33', 'E34'], true)) {
            wp_redirect(admin_url('admin.php?page=ecf-sequences&error=' . urlencode(__('Invalid ECF type.', 'woo-ecf-dgii'))));
            exit;
        }

        Ecf_Sequence_Manager::add_sequence($ecf_type, $serie, $prefix, $range_start, $range_end, $expiration_date);

        wp_redirect(admin_url('admin.php?page=ecf-sequences&msg=added'));
        exit;
    }

    public static function handle_deactivate_sequence(): void {
        $seq_id = absint($_POST['sequence_id'] ?? 0);
        check_admin_referer('ecf_deactivate_seq_' . $seq_id);

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Permission denied.', 'woo-ecf-dgii'));
        }

        if ($seq_id) {
            Ecf_Sequence_Manager::deactivate($seq_id);
        }

        wp_redirect(admin_url('admin.php?page=ecf-sequences&msg=deactivated'));
        exit;
    }
}
