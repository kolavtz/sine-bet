<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all plugin data: wp_options entries and the custom log table.
 *
 * @package WP_R2_Offload
 */

// Only execute if WordPress initiated this call.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Remove all plugin options.
$option_names = [
    'wp_r2_offload_account_id',
    'wp_r2_offload_access_key',
    'wp_r2_offload_secret_key',
    'wp_r2_offload_bucket',
    'wp_r2_offload_cdn_domain',
    'wp_r2_offload_url_rewriting',
    'wp_r2_offload_db_version',
];

foreach ($option_names as $option) {
    delete_option($option);
}

// Drop the custom log table.
$table_name = $wpdb->prefix . 'r2_offload_log';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query("DROP TABLE IF EXISTS `{$table_name}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
