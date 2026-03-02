<?php
/**
 * Plugin settings — registration and typed getters.
 *
 * Credentials are read from wp-config.php constants first (recommended for
 * production) and fall back to values stored in wp_options.
 *
 * wp-config.php constants (optional):
 *   define( 'WP_R2_ACCOUNT_ID',   '...' );
 *   define( 'WP_R2_ACCESS_KEY',   '...' );
 *   define( 'WP_R2_SECRET_KEY',   '...' );
 *   define( 'WP_R2_BUCKET',       '...' );
 *   define( 'WP_R2_CDN_DOMAIN',   'https://cdn.example.com' );
 *
 * @package WP_R2_Offload
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_R2_Settings
 */
class WP_R2_Settings implements WP_R2_Settings_Contract
{

    // Option key constants.
    const OPT_ACCOUNT_ID = 'wp_r2_offload_account_id';
    const OPT_ACCESS_KEY = 'wp_r2_offload_access_key';
    const OPT_SECRET_KEY = 'wp_r2_offload_secret_key';
    const OPT_BUCKET = 'wp_r2_offload_bucket';
    const OPT_CDN_DOMAIN = 'wp_r2_offload_cdn_domain';
    const OPT_URL_REWRITING = 'wp_r2_offload_url_rewriting';
    /**
     * Key prefix strategy:
     *   'date'  → 2024/06/filename.jpg            (compact, current default)
     *   'wp'    → wp-content/uploads/2024/06/filename.jpg
     *             mirrors WordPress uploads structure exactly — switching CDN
     *             domains only requires changing the domain, not any paths.
     */
    const OPT_KEY_PREFIX = 'wp_r2_offload_key_prefix';

    // Settings group used by register_setting().
    const SETTINGS_GROUP = 'wp_r2_offload_settings';

    /**
     * Register settings with the WordPress Settings API.
     * Call this on the 'admin_init' hook.
     */
    public function register(): void
    {
        $fields = [
            self::OPT_ACCOUNT_ID => ['sanitize' => [$this, 'sanitize_plain_text']],
            self::OPT_ACCESS_KEY => ['sanitize' => [$this, 'sanitize_plain_text']],
            self::OPT_SECRET_KEY => ['sanitize' => [$this, 'sanitize_plain_text']],
            self::OPT_BUCKET => ['sanitize' => [$this, 'sanitize_slug']],
            self::OPT_CDN_DOMAIN => ['sanitize' => 'esc_url_raw'],
            self::OPT_URL_REWRITING => ['sanitize' => [$this, 'sanitize_checkbox']],
            self::OPT_KEY_PREFIX => ['sanitize' => [$this, 'sanitize_key_prefix']],
        ];

        foreach ($fields as $key => $field) {
            register_setting(self::SETTINGS_GROUP, $key, [
                'type' => 'string',
                'sanitize_callback' => $field['sanitize'],
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // Getters — constants take precedence over DB values.
    // -----------------------------------------------------------------------

    public function get_account_id(): string
    {
        return defined('WP_R2_ACCOUNT_ID') ? WP_R2_ACCOUNT_ID : (string) get_option(self::OPT_ACCOUNT_ID, '');
    }

    public function get_access_key(): string
    {
        return defined('WP_R2_ACCESS_KEY') ? WP_R2_ACCESS_KEY : (string) get_option(self::OPT_ACCESS_KEY, '');
    }

    public function get_secret_key(): string
    {
        return defined('WP_R2_SECRET_KEY') ? WP_R2_SECRET_KEY : (string) get_option(self::OPT_SECRET_KEY, '');
    }

    public function get_bucket(): string
    {
        return defined('WP_R2_BUCKET') ? WP_R2_BUCKET : (string) get_option(self::OPT_BUCKET, '');
    }

    public function get_cdn_domain(): string
    {
        $domain = defined('WP_R2_CDN_DOMAIN') ? WP_R2_CDN_DOMAIN : (string) get_option(self::OPT_CDN_DOMAIN, '');
        return rtrim($domain, '/');
    }

    public function is_url_rewriting_enabled(): bool
    {
        return (bool) get_option(self::OPT_URL_REWRITING, false);
    }

    /**
     * Return the configured key prefix strategy ('date' or 'wp').
     *
     * 'wp'   → keys include wp-content/uploads/ prefix, mirroring WP uploads paths
     * 'date' → keys are compact: yyyy/mm/filename (default)
     */
    public function get_key_prefix(): string
    {
        return get_option(self::OPT_KEY_PREFIX, 'date') === 'wp' ? 'wp' : 'date';
    }

    public function are_credentials_set(): bool
    {
        return $this->get_account_id() !== ''
            && $this->get_access_key() !== ''
            && $this->get_secret_key() !== ''
            && $this->get_bucket() !== '';
    }

    // -----------------------------------------------------------------------
    // Sanitization callbacks
    // -----------------------------------------------------------------------

    public function sanitize_plain_text($value): string
    {
        return sanitize_text_field((string) $value);
    }

    public function sanitize_slug($value): string
    {
        return sanitize_key((string) $value);
    }

    public function sanitize_checkbox($value): string
    {
        return $value ? '1' : '0';
    }

    public function sanitize_key_prefix($value): string
    {
        return in_array($value, ['wp', 'date'], true) ? $value : 'date';
    }
}
