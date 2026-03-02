<?php
/**
 * Unit tests for WP_R2_Client.
 *
 * Tests cover: successful upload, file-not-found error, HTTP error response,
 * public URL generation with and without CDN domain, and delete_file.
 *
 * HTTP calls are intercepted via the $GLOBALS['wp_mock_http_response'] stub
 * defined in tests/stubs/wp-stubs.php.
 *
 * @package WP_R2_Offload\Tests
 */

use PHPUnit\Framework\TestCase;

class Test_R2_Client extends TestCase
{

    // ── Helpers ───────────────────────────────────────────────────────────

    private function make_settings(array $overrides = []): WP_R2_Settings
    {
        $GLOBALS['wp_test_options'] = array_merge([
            WP_R2_Settings::OPT_ACCOUNT_ID => 'test-account',
            WP_R2_Settings::OPT_ACCESS_KEY => 'test-access-key',
            WP_R2_Settings::OPT_SECRET_KEY => 'test-secret-key',
            WP_R2_Settings::OPT_BUCKET => 'test-bucket',
            WP_R2_Settings::OPT_CDN_DOMAIN => '',
        ], $overrides);

        return new WP_R2_Settings();
    }

    /**
     * Set the HTTP mock to return a successful 200 response.
     */
    private function mock_http_success(string $body = ''): void
    {
        $GLOBALS['wp_mock_http_response'] = [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body' => $body,
        ];
    }

    /**
     * Set the HTTP mock to return an error status with an S3 XML body.
     */
    private function mock_http_error(int $code, string $xml_message): void
    {
        $GLOBALS['wp_mock_http_response'] = [
            'response' => ['code' => $code, 'message' => 'Error'],
            'body' => "<Error><Code>AccessDenied</Code><Message>{$xml_message}</Message></Error>",
        ];
    }

    private function create_temp_file(string $content = 'test content'): string
    {
        $path = sys_get_temp_dir() . '/r2-test-' . uniqid() . '.jpg';
        file_put_contents($path, $content);
        return $path;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_mock_http_response']);
    }

    // ── get_public_url ────────────────────────────────────────────────────

    public function test_get_public_url_uses_cdn_domain_when_set(): void
    {
        $settings = $this->make_settings([WP_R2_Settings::OPT_CDN_DOMAIN => 'https://cdn.example.com']);
        $client = new WP_R2_Client($settings);

        $url = $client->get_public_url('2024/06/photo.jpg');
        $this->assertSame('https://cdn.example.com/2024/06/photo.jpg', $url);
    }

    public function test_get_public_url_falls_back_to_r2_endpoint(): void
    {
        $settings = $this->make_settings(); // CDN domain empty.
        $client = new WP_R2_Client($settings);

        $url = $client->get_public_url('2024/06/photo.jpg');
        $this->assertStringContainsString('test-account.r2.cloudflarestorage.com', $url);
        $this->assertStringContainsString('test-bucket', $url);
        $this->assertStringContainsString('2024/06/photo.jpg', $url);
    }

    // ── upload_file ───────────────────────────────────────────────────────

    public function test_upload_file_returns_wp_error_when_file_missing(): void
    {
        $settings = $this->make_settings();
        $client = new WP_R2_Client($settings);

        $result = $client->upload_file('/nonexistent/path/to/file.jpg', '2024/06/file.jpg');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('r2_file_not_found', $result->get_error_code());
    }

    public function test_upload_file_returns_wp_error_when_credentials_missing(): void
    {
        $GLOBALS['wp_test_options'] = []; // All credentials empty.
        $settings = new WP_R2_Settings();
        $client = new WP_R2_Client($settings);

        $tmp = $this->create_temp_file();
        $result = $client->upload_file($tmp, '2024/06/file.jpg');
        unlink($tmp);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('r2_missing_credentials', $result->get_error_code());
    }

    public function test_upload_file_returns_key_and_url_on_success(): void
    {
        $settings = $this->make_settings([WP_R2_Settings::OPT_CDN_DOMAIN => 'https://cdn.example.com']);
        $client = new WP_R2_Client($settings);
        $this->mock_http_success();

        $tmp = $this->create_temp_file('fake-image-data');
        $result = $client->upload_file($tmp, '2024/06/photo.jpg');
        unlink($tmp);

        $this->assertIsArray($result);
        $this->assertSame('2024/06/photo.jpg', $result['key']);
        $this->assertSame('https://cdn.example.com/2024/06/photo.jpg', $result['url']);
    }

    public function test_upload_file_returns_wp_error_on_http_error_response(): void
    {
        $settings = $this->make_settings();
        $client = new WP_R2_Client($settings);
        $this->mock_http_error(403, 'Access Denied by bucket policy');

        $tmp = $this->create_temp_file();
        $result = $client->upload_file($tmp, '2024/06/photo.jpg');
        unlink($tmp);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertStringContainsString('403', $result->get_error_message());
        $this->assertStringContainsString('Access Denied', $result->get_error_message());
    }

    // ── delete_file ───────────────────────────────────────────────────────

    public function test_delete_file_returns_true_on_success(): void
    {
        $settings = $this->make_settings();
        $client = new WP_R2_Client($settings);
        $this->mock_http_success();

        $result = $client->delete_file('2024/06/photo.jpg');
        $this->assertTrue($result);
    }

    public function test_delete_file_returns_wp_error_on_http_error(): void
    {
        $settings = $this->make_settings();
        $client = new WP_R2_Client($settings);
        $this->mock_http_error(404, 'NoSuchKey');

        $result = $client->delete_file('2024/06/ghost.jpg');
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    // ── Edge cases ────────────────────────────────────────────────────────

    public function test_upload_large_file_succeeds(): void
    {
        $settings = $this->make_settings();
        $client = new WP_R2_Client($settings);
        $this->mock_http_success();

        // 5 MB stub file.
        $tmp = $this->create_temp_file(str_repeat('A', 5 * 1024 * 1024));
        $result = $client->upload_file($tmp, '2024/06/large.jpg');
        unlink($tmp);

        $this->assertIsArray($result);
    }
}
