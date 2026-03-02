<?php
/**
 * Unit tests for WP_R2_URL_Rewriter.
 *
 * @package WP_R2_Offload\Tests
 */

use PHPUnit\Framework\TestCase;

class Test_URL_Rewriter extends TestCase
{

    protected function setUp(): void
    {
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_post_meta'] = [];
    }

    private function make_rewriter(string $cdn_domain = 'https://cdn.example.com', bool $rewriting_enabled = true): WP_R2_URL_Rewriter
    {
        update_option(WP_R2_Settings::OPT_CDN_DOMAIN, $cdn_domain);
        update_option(WP_R2_Settings::OPT_URL_REWRITING, $rewriting_enabled ? '1' : '0');
        $settings = new WP_R2_Settings();
        return new WP_R2_URL_Rewriter($settings);
    }

    // ── filter_attachment_url ─────────────────────────────────────────────

    public function test_filter_attachment_url_returns_cdn_url_when_meta_set(): void
    {
        $rewriter = $this->make_rewriter();
        update_post_meta(42, '_r2_cdn_url', 'https://cdn.example.com/2024/06/photo.jpg');

        $result = $rewriter->filter_attachment_url('http://local.test/wp-content/uploads/2024/06/photo.jpg', 42);

        $this->assertSame('https://cdn.example.com/2024/06/photo.jpg', $result);
    }

    public function test_filter_attachment_url_passthrough_when_no_meta(): void
    {
        $rewriter = $this->make_rewriter();
        $original_url = 'http://local.test/wp-content/uploads/2024/06/photo.jpg';

        $result = $rewriter->filter_attachment_url($original_url, 99); // No meta on post 99.

        $this->assertSame($original_url, $result);
    }

    // ── filter_content ────────────────────────────────────────────────────

    public function test_filter_content_replaces_upload_urls_with_cdn(): void
    {
        $rewriter = $this->make_rewriter('https://cdn.example.com');

        $content = '<img src="http://example.com/wp-content/uploads/2024/06/photo.jpg" />';
        $processed = $rewriter->filter_content($content);

        $this->assertStringContainsString('https://cdn.example.com', $processed);
        $this->assertStringNotContainsString('http://example.com/wp-content/uploads', $processed);
    }

    public function test_filter_content_returns_unchanged_when_cdn_domain_empty(): void
    {
        $rewriter = $this->make_rewriter(''); // No CDN domain.

        $content = '<img src="http://example.com/wp-content/uploads/photo.jpg" />';
        $processed = $rewriter->filter_content($content);

        $this->assertSame($content, $processed);
    }

    public function test_filter_content_returns_unchanged_when_content_is_empty(): void
    {
        $rewriter = $this->make_rewriter();
        $this->assertSame('', $rewriter->filter_content(''));
    }

    // ── filter_srcset ─────────────────────────────────────────────────────

    public function test_filter_srcset_replaces_upload_urls_in_sources(): void
    {
        $rewriter = $this->make_rewriter('https://cdn.example.com');

        $sources = [
            300 => ['url' => 'http://example.com/wp-content/uploads/photo-300x200.jpg', 'descriptor' => 'w', 'value' => 300],
            600 => ['url' => 'http://example.com/wp-content/uploads/photo-600x400.jpg', 'descriptor' => 'w', 'value' => 600],
        ];

        $result = $rewriter->filter_srcset($sources, [600, 400], '', [], 1);

        $this->assertStringContainsString('https://cdn.example.com', $result[300]['url']);
        $this->assertStringContainsString('https://cdn.example.com', $result[600]['url']);
    }

    public function test_filter_srcset_returns_false_unchanged(): void
    {
        $rewriter = $this->make_rewriter();
        $this->assertFalse($rewriter->filter_srcset(false, [], '', [], 1));
    }
}
