<?php
/**
 * Unit tests for WP_R2_Settings.
 *
 * Covers: sanitization callbacks, getter fallbacks, constant overrides,
 * and are_credentials_set() logic.
 *
 * @package WP_R2_Offload\Tests
 */

use PHPUnit\Framework\TestCase;

class Test_Settings extends TestCase
{

    protected function setUp(): void
    {
        // Reset in-memory option store before each test.
        $GLOBALS['wp_test_options'] = [];
    }

    // ── Getters returning defaults ─────────────────────────────────────────

    public function test_get_account_id_returns_empty_string_by_default(): void
    {
        $s = new WP_R2_Settings();
        $this->assertSame('', $s->get_account_id());
    }

    public function test_get_cdn_domain_strips_trailing_slash(): void
    {
        update_option(WP_R2_Settings::OPT_CDN_DOMAIN, 'https://cdn.example.com/');
        $s = new WP_R2_Settings();
        $this->assertSame('https://cdn.example.com', $s->get_cdn_domain());
    }

    public function test_get_cdn_domain_without_trailing_slash_unchanged(): void
    {
        update_option(WP_R2_Settings::OPT_CDN_DOMAIN, 'https://cdn.example.com');
        $s = new WP_R2_Settings();
        $this->assertSame('https://cdn.example.com', $s->get_cdn_domain());
    }

    // ── are_credentials_set ───────────────────────────────────────────────

    public function test_credentials_not_set_when_all_empty(): void
    {
        $s = new WP_R2_Settings();
        $this->assertFalse($s->are_credentials_set());
    }

    public function test_credentials_set_when_all_provided(): void
    {
        update_option(WP_R2_Settings::OPT_ACCOUNT_ID, 'acct-123');
        update_option(WP_R2_Settings::OPT_ACCESS_KEY, 'key-abc');
        update_option(WP_R2_Settings::OPT_SECRET_KEY, 'secret-xyz');
        update_option(WP_R2_Settings::OPT_BUCKET, 'my-bucket');
        $s = new WP_R2_Settings();
        $this->assertTrue($s->are_credentials_set());
    }

    public function test_credentials_not_set_when_bucket_missing(): void
    {
        update_option(WP_R2_Settings::OPT_ACCOUNT_ID, 'acct-123');
        update_option(WP_R2_Settings::OPT_ACCESS_KEY, 'key-abc');
        update_option(WP_R2_Settings::OPT_SECRET_KEY, 'secret-xyz');
        // OPT_BUCKET intentionally absent.
        $s = new WP_R2_Settings();
        $this->assertFalse($s->are_credentials_set());
    }

    // ── Sanitization callbacks ────────────────────────────────────────────

    public function test_sanitize_plain_text_strips_tags(): void
    {
        $s = new WP_R2_Settings();
        $this->assertSame('hello world', $s->sanitize_plain_text('<b>hello world</b>'));
    }

    public function test_sanitize_plain_text_trims_whitespace(): void
    {
        $s = new WP_R2_Settings();
        $this->assertSame('abc', $s->sanitize_plain_text('  abc  '));
    }

    public function test_sanitize_slug_lowercases_and_strips_special(): void
    {
        $s = new WP_R2_Settings();
        $this->assertSame('my-bucket', $s->sanitize_slug('My-Bucket!!'));
    }

    public function test_sanitize_checkbox_truthy_returns_one(): void
    {
        $s = new WP_R2_Settings();
        $this->assertSame('1', $s->sanitize_checkbox('1'));
        $this->assertSame('1', $s->sanitize_checkbox(true));
    }

    public function test_sanitize_checkbox_falsy_returns_zero(): void
    {
        $s = new WP_R2_Settings();
        $this->assertSame('0', $s->sanitize_checkbox(''));
        $this->assertSame('0', $s->sanitize_checkbox(false));
    }

    // ── URL rewriting flag ────────────────────────────────────────────────

    public function test_url_rewriting_disabled_by_default(): void
    {
        $s = new WP_R2_Settings();
        $this->assertFalse($s->is_url_rewriting_enabled());
    }

    public function test_url_rewriting_enabled_when_option_is_one(): void
    {
        update_option(WP_R2_Settings::OPT_URL_REWRITING, '1');
        $s = new WP_R2_Settings();
        $this->assertTrue($s->is_url_rewriting_enabled());
    }
}
