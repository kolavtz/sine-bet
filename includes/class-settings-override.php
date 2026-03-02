<?php
/**
 * Lightweight settings override — holds credential values passed directly
 * (e.g. from live form POST data during a Test Connection request).
 *
 * Implements WP_R2_Settings_Contract so it can be passed to WP_R2_Client
 * and WP_R2_Media_Handler without touching the database.
 *
 * @package WP_R2_Offload
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_R2_Settings_Override
 */
class WP_R2_Settings_Override implements WP_R2_Settings_Contract
{

    private string $account_id;
    private string $access_key;
    private string $secret_key;
    private string $bucket;
    private string $cdn_domain;

    public function __construct(
        string $account_id,
        string $access_key,
        string $secret_key,
        string $bucket,
        string $cdn_domain = ''
    ) {
        $this->account_id = $account_id;
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        $this->bucket = $bucket;
        $this->cdn_domain = $cdn_domain;
    }

    public function get_account_id(): string
    {
        return $this->account_id;
    }

    public function get_access_key(): string
    {
        return $this->access_key;
    }

    public function get_secret_key(): string
    {
        return $this->secret_key;
    }

    public function get_bucket(): string
    {
        return $this->bucket;
    }

    public function get_cdn_domain(): string
    {
        return $this->cdn_domain;
    }

    /** Override always uses compact date-based keys. */
    public function get_key_prefix(): string
    {
        return 'date';
    }

    public function is_url_rewriting_enabled(): bool
    {
        return false;
    }

    public function are_credentials_set(): bool
    {
        return $this->account_id !== ''
            && $this->access_key !== ''
            && $this->secret_key !== ''
            && $this->bucket !== '';
    }
}
