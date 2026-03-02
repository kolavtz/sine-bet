<?php
/**
 * Fired during plugin activation.
 *
 * Creates the custom log table and sets default option values.
 *
 * @package WP_R2_Offload
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_R2_Activator
 */
class WP_R2_Activator
{

    /**
     * Current schema version for the log table. Bump when the schema changes.
     */
    const DB_VERSION = '1.0';

    /**
     * Run on plugin activation.
     *
     * @return void
     */
    public static function activate(): void
    {
        self::create_log_table();
        self::set_default_options();
    }

    /**
     * Create (or upgrade) the offload log table using dbDelta.
     *
     * Table: {prefix}r2_offload_log
     * Columns:
     *   id            – Auto-increment primary key.
     *   attachment_id – WordPress attachment post ID (0 for non-attachment entries).
     *   file_key      – The R2 object key (path inside the bucket).
     *   status        – 'success' | 'error'.
     *   message       – Human-readable result or error description.
     *   created_at    – UTC timestamp of the log entry.
     *
     * @return void
     */
    private static function create_log_table(): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'r2_offload_log';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			file_key      TEXT              NOT NULL,
			status        VARCHAR(20)       NOT NULL DEFAULT 'success',
			message       TEXT              NOT NULL,
			created_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY status        (status),
			KEY created_at    (created_at)
		) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('wp_r2_offload_db_version', self::DB_VERSION);
    }

    /**
     * Persist sensible defaults so getters never return null on first run.
     *
     * @return void
     */
    private static function set_default_options(): void
    {
        $defaults = [
            'wp_r2_offload_account_id' => '',
            'wp_r2_offload_access_key' => '',
            'wp_r2_offload_secret_key' => '',
            'wp_r2_offload_bucket' => '',
            'wp_r2_offload_cdn_domain' => '',
            'wp_r2_offload_url_rewriting' => '0',
        ];

        foreach ($defaults as $key => $value) {
            // add_option is a no-op if the option already exists.
            add_option($key, $value);
        }
    }
}
