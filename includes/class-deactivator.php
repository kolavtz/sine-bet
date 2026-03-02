<?php
/**
 * Fired during plugin deactivation.
 *
 * @package WP_R2_Offload
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_R2_Deactivator
 */
class WP_R2_Deactivator
{

    /**
     * Run on plugin deactivation.
     *
     * NOTE: We intentionally do NOT delete options or the log table here.
     * Data is preserved so that reactivating the plugin resumes cleanly.
     * Cleanup is reserved for uninstall.php.
     *
     * @return void
     */
    public static function deactivate(): void
    {
        // Flush rewrite rules in case the plugin registered custom endpoints.
        flush_rewrite_rules();
    }
}
