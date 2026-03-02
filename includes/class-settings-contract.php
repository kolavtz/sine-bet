<?php
/**
 * Contract for all settings objects passed to WP_R2_Client and WP_R2_Media_Handler.
 *
 * Both WP_R2_Settings (DB-backed) and WP_R2_Settings_Override (in-memory, for
 * live Test Connection requests) implement this interface so they can be used
 * interchangeably without PHP 8 TypeErrors.
 *
 * @package WP_R2_Offload
 */

if (!defined('ABSPATH')) {
    exit;
}

interface WP_R2_Settings_Contract
{
    public function get_account_id(): string;
    public function get_access_key(): string;
    public function get_secret_key(): string;
    public function get_bucket(): string;
    public function get_cdn_domain(): string;
    public function get_key_prefix(): string;
    public function is_url_rewriting_enabled(): bool;
    public function are_credentials_set(): bool;
}
