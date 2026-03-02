<?php
/**
 * Plugin Name:       WP R2 Offload
 * Plugin URI:        https://collectoris.com
 * Description:       Automatically offloads WordPress Media Library uploads to Cloudflare R2 and serves them via a CDN URL.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Saurav Bhattarai
 * Author URI:        https://collectoris.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-r2-offload
 *
 * @package WP_R2_Offload
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

// ---------------------------------------------------------------------------
// Plugin constants
// ---------------------------------------------------------------------------

define('WP_R2_OFFLOAD_VERSION', '1.0.0');
define('WP_R2_OFFLOAD_PLUGIN_FILE', __FILE__);
define('WP_R2_OFFLOAD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_R2_OFFLOAD_PLUGIN_URL', plugin_dir_url(__FILE__));

// ---------------------------------------------------------------------------
// Autoload includes
// ---------------------------------------------------------------------------

$wp_r2_includes = [
	'includes/class-settings-contract.php',
	'includes/class-activator.php',
	'includes/class-deactivator.php',
	'includes/class-settings.php',
	'includes/class-logger.php',
	'includes/class-r2-client.php',
	'includes/class-media-handler.php',
	'includes/class-settings-override.php',
	'includes/class-url-rewriter.php',
	'admin/class-admin-page.php',
];

foreach ($wp_r2_includes as $file) {
	$path = WP_R2_OFFLOAD_PLUGIN_DIR . $file;
	if (file_exists($path)) {
		require_once $path;
	}
}

// ---------------------------------------------------------------------------
// Activation / Deactivation
// ---------------------------------------------------------------------------

register_activation_hook(__FILE__, ['WP_R2_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['WP_R2_Deactivator', 'deactivate']);

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

/**
 * Initialise all plugin components after all plugins are loaded so that
 * third-party hooks (e.g. Action Scheduler) are available.
 */
function wp_r2_offload_init(): void
{
	$settings = new WP_R2_Settings();

	// Admin UI (back-end only).
	if (is_admin()) {
		new WP_R2_Admin_Page($settings);
	}

	// Media interception runs on both front and back end because REST API
	// uploads can originate from any context.
	$logger = new WP_R2_Logger();
	$r2_client = new WP_R2_Client($settings);
	$media = new WP_R2_Media_Handler($settings, $r2_client, $logger);
	$media->register_hooks();

	// URL rewriting is optional.
	if ($settings->is_url_rewriting_enabled()) {
		$rewriter = new WP_R2_URL_Rewriter($settings);
		$rewriter->register_hooks();
	}
}
add_action('plugins_loaded', 'wp_r2_offload_init');
