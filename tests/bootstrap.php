<?php
/**
 * PHPUnit bootstrap for WP R2 Offload tests.
 *
 * This file:
 *  1. Loads the WordPress test library (via WP_TESTS_DIR env var).
 *  2. Requires all plugin includes so they are available without WP autoload.
 *
 * Usage (from the plugin root):
 *   WP_TESTS_DIR=/path/to/wordpress-develop/tests/phpunit \
 *     ./vendor/bin/phpunit --configuration phpunit.xml
 *
 * If WP_TESTS_DIR is not set, unit tests that don't need WordPress functions
 * will define their own minimal stubs below.
 *
 * @package WP_R2_Offload
 */

define('ABSPATH', __DIR__ . '/../');
define('WP_R2_OFFLOAD_VERSION', '1.0.0');
define('WP_R2_OFFLOAD_PLUGIN_DIR', dirname(__DIR__) . '/');

// ── Load WordPress test library if available ──────────────────────────────
$wp_tests_dir = getenv('WP_TESTS_DIR');

if ($wp_tests_dir && file_exists($wp_tests_dir . '/includes/bootstrap.php')) {
    require_once $wp_tests_dir . '/includes/functions.php';
    require_once $wp_tests_dir . '/includes/bootstrap.php';
} else {
    // Provide lightweight stubs so pure unit tests run without a WP install.
    require_once __DIR__ . '/stubs/wp-stubs.php';
}

// ── Load plugin classes ───────────────────────────────────────────────────
$includes = [
    'includes/class-settings.php',
    'includes/class-logger.php',
    'includes/class-r2-client.php',
    'includes/class-media-handler.php',
    'includes/class-url-rewriter.php',
    'includes/class-activator.php',
];

foreach ($includes as $file) {
    require_once WP_R2_OFFLOAD_PLUGIN_DIR . $file;
}
