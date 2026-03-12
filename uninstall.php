<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// Drop custom table
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ecf_sequences");

// Remove plugin options
$options = [
    'ecf_dgii_api_token',
    'ecf_dgii_environment',
    'ecf_dgii_company_rnc',
    'ecf_dgii_company_data',
    'ecf_dgii_default_ecf_type',
    'ecf_dgii_retry_max',
    'ecf_dgii_retry_interval',
];

foreach ($options as $option) {
    delete_option($option);
}
