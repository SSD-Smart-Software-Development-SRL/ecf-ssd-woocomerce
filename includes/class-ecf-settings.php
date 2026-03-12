<?php
defined('ABSPATH') || exit;

class Ecf_Settings {

    public const OPTION_API_TOKEN = 'ecf_dgii_api_token';
    public const OPTION_ENVIRONMENT = 'ecf_dgii_environment';
    public const OPTION_COMPANY_RNC = 'ecf_dgii_company_rnc';
    public const OPTION_COMPANY_DATA = 'ecf_dgii_company_data';
    public const OPTION_DEFAULT_ECF_TYPE = 'ecf_dgii_default_ecf_type';
    public const OPTION_RETRY_MAX = 'ecf_dgii_retry_max';
    public const OPTION_RETRY_INTERVAL = 'ecf_dgii_retry_interval';

    private const ENVIRONMENTS = [
        'test' => 'https://api.test.ecfx.ssd.com.do',
        'cert' => 'https://api.cert.ecfx.ssd.com.do',
        'prod' => 'https://api.prod.ecfx.ssd.com.do',
    ];

    public static function init(): void {
        add_filter('woocommerce_settings_tabs_array', [self::class, 'add_settings_tab'], 50);
        add_action('woocommerce_settings_tabs_ecf_dgii', [self::class, 'render_settings']);
        add_action('woocommerce_update_options_ecf_dgii', [self::class, 'save_settings']);
        add_action('wp_ajax_ecf_dgii_test_connection', [self::class, 'ajax_test_connection']);
    }

    public static function add_settings_tab(array $tabs): array {
        $tabs['ecf_dgii'] = __('ECF DGII', 'woo-ecf-dgii');
        return $tabs;
    }

    private static function get_environment_options(): array {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return [
                'test' => __('Test', 'woo-ecf-dgii'),
                'cert' => __('Certification', 'woo-ecf-dgii'),
                'prod' => __('Production', 'woo-ecf-dgii'),
            ];
        }
        return [
            'prod' => __('Production', 'woo-ecf-dgii'),
        ];
    }

    public static function get_api_host(): string {
        $env = get_option(self::OPTION_ENVIRONMENT, 'test');
        return self::ENVIRONMENTS[$env] ?? self::ENVIRONMENTS['test'];
    }

    public static function get_api_token(): string {
        return get_option(self::OPTION_API_TOKEN, '');
    }

    public static function get_company_rnc(): string {
        return get_option(self::OPTION_COMPANY_RNC, '');
    }

    public static function get_company_data(): array {
        return get_option(self::OPTION_COMPANY_DATA, []);
    }

    public static function get_settings_fields(): array {
        $fields = [
            'ecf_dgii_section_api' => [
                'name' => __('API Connection', 'woo-ecf-dgii'),
                'type' => 'title',
                'desc' => __('Configure your ECF SSD API connection.', 'woo-ecf-dgii'),
            ],
            self::OPTION_ENVIRONMENT => [
                'name' => __('Environment', 'woo-ecf-dgii'),
                'type' => 'select',
                'options' => self::get_environment_options(),
                'default' => defined('WP_DEBUG') && WP_DEBUG ? 'test' : 'prod',
                'desc' => defined('WP_DEBUG') && WP_DEBUG
                    ? __('Select the ECF SSD API environment. (Test/Cert visible in debug mode)', 'woo-ecf-dgii')
                    : '',
            ],
            self::OPTION_API_TOKEN => [
                'name' => __('API Token', 'woo-ecf-dgii'),
                'type' => 'password',
                'desc' => __('Your ECF SSD API authentication token.', 'woo-ecf-dgii'),
            ],
            self::OPTION_COMPANY_RNC => [
                'name' => __('Company RNC', 'woo-ecf-dgii'),
                'type' => 'text',
                'desc' => __('Your company RNC. Cannot be changed once saved.', 'woo-ecf-dgii'),
                'custom_attributes' => get_option(self::OPTION_COMPANY_RNC)
                    ? ['readonly' => 'readonly']
                    : [],
            ],
            'ecf_dgii_section_api_end' => [
                'type' => 'sectionend',
            ],
            'ecf_dgii_section_general' => [
                'name' => __('General Settings', 'woo-ecf-dgii'),
                'type' => 'title',
            ],
            self::OPTION_DEFAULT_ECF_TYPE => [
                'name' => __('Default ECF Type (when RNC provided)', 'woo-ecf-dgii'),
                'type' => 'select',
                'options' => [
                    'E31' => __('E31 - Crédito Fiscal', 'woo-ecf-dgii'),
                    'E32' => __('E32 - Consumo', 'woo-ecf-dgii'),
                ],
                'default' => 'E31',
            ],
            self::OPTION_RETRY_MAX => [
                'name' => __('Max retries before contingencia', 'woo-ecf-dgii'),
                'type' => 'number',
                'default' => 3,
                'desc' => __('Number of retry attempts before falling back to B-series.', 'woo-ecf-dgii'),
                'custom_attributes' => ['min' => 1, 'max' => 10],
            ],
            self::OPTION_RETRY_INTERVAL => [
                'name' => __('Retry interval (seconds)', 'woo-ecf-dgii'),
                'type' => 'number',
                'default' => 5,
                'custom_attributes' => ['min' => 1, 'max' => 60],
            ],
            'ecf_dgii_section_general_end' => [
                'type' => 'sectionend',
            ],
        ];

        return $fields;
    }

    public static function render_settings(): void {
        // Show company data if fetched
        $company_data = self::get_company_data();
        if (!empty($company_data)) {
            echo '<div class="ecf-company-info">';
            echo '<h3>' . esc_html__('Company Information (from ECF SSD)', 'woo-ecf-dgii') . '</h3>';
            echo '<table class="form-table">';
            if (!empty($company_data['razonSocial'])) {
                echo '<tr><th>' . esc_html__('Legal Name', 'woo-ecf-dgii') . '</th>';
                echo '<td>' . esc_html($company_data['razonSocial']) . '</td></tr>';
            }
            if (!empty($company_data['direccion'])) {
                echo '<tr><th>' . esc_html__('Address', 'woo-ecf-dgii') . '</th>';
                echo '<td>' . esc_html($company_data['direccion']) . '</td></tr>';
            }
            echo '</table></div>';
        }

        woocommerce_admin_fields(self::get_settings_fields());

        // Test connection button
        echo '<table class="form-table"><tr><th></th><td>';
        echo '<button type="button" class="button" id="ecf-test-connection">';
        echo esc_html__('Test Connection', 'woo-ecf-dgii');
        echo '</button>';
        echo '<span id="ecf-connection-result" style="margin-left:10px;"></span>';
        echo '</td></tr></table>';

        ?>
        <script>
        jQuery(function($) {
            $('#ecf-test-connection').on('click', function() {
                var $btn = $(this);
                var $result = $('#ecf-connection-result');
                $btn.prop('disabled', true);
                $result.text('<?php echo esc_js(__('Testing...', 'woo-ecf-dgii')); ?>');
                $.post(ajaxurl, {
                    action: 'ecf_dgii_test_connection',
                    _wpnonce: '<?php echo wp_create_nonce('ecf_dgii_test_connection'); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $result.html('<span style="color:green;">' + response.data + '</span>');
                    } else {
                        $result.html('<span style="color:red;">' + response.data + '</span>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    public static function save_settings(): void {
        woocommerce_update_options(self::get_settings_fields());
    }

    public static function ajax_test_connection(): void {
        check_ajax_referer('ecf_dgii_test_connection');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied.', 'woo-ecf-dgii'));
        }

        try {
            $client = new Ecf_Api_Client();
            $company = $client->get_company(self::get_company_rnc());

            // Cache company data
            update_option(self::OPTION_COMPANY_DATA, [
                'razonSocial' => $company->getRazonSocial() ?? '',
                'direccion' => $company->getDireccion() ?? '',
            ]);

            wp_send_json_success(
                sprintf(
                    __('Connected! Company: %s', 'woo-ecf-dgii'),
                    $company->getRazonSocial() ?? 'OK'
                )
            );
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}
