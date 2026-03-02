<?php
/**
 * Integration tests for WP_R2_Media_Handler.
 *
 * Tests the full add_attachment → upload → meta flow using mocked R2 client
 * and a partial mock logger.  Tests also cover the delete_attachment path
 * and the early-return guard when credentials are not configured.
 *
 * @package WP_R2_Offload\Tests
 */

use PHPUnit\Framework\TestCase;

class Test_Media_Handler extends TestCase
{

    protected function setUp(): void
    {
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_post_meta'] = [];
        $GLOBALS['wp_test_posts'] = [];
        $GLOBALS['wp_test_attached_files'] = [];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function make_settings(bool $with_credentials = true): WP_R2_Settings
    {
        if ($with_credentials) {
            update_option(WP_R2_Settings::OPT_ACCOUNT_ID, 'acct-id');
            update_option(WP_R2_Settings::OPT_ACCESS_KEY, 'key');
            update_option(WP_R2_Settings::OPT_SECRET_KEY, 'secret');
            update_option(WP_R2_Settings::OPT_BUCKET, 'my-bucket');
            update_option(WP_R2_Settings::OPT_CDN_DOMAIN, 'https://cdn.example.com');
        }
        return new WP_R2_Settings();
    }

    /**
     * Create a mock R2 client that simulates a successful upload.
     *
     * @return WP_R2_Client
     */
    private function mock_client_success(WP_R2_Settings $settings): WP_R2_Client
    {
        $mock = $this->getMockBuilder(WP_R2_Client::class)
            ->setConstructorArgs([$settings])
            ->onlyMethods(['upload_file', 'delete_file'])
            ->getMock();

        $mock->method('upload_file')
            ->willReturn(['key' => '2024/01/photo.jpg', 'url' => 'https://cdn.example.com/2024/01/photo.jpg']);

        $mock->method('delete_file')
            ->willReturn(true);

        return $mock;
    }

    /**
     * Create a mock R2 client that simulates an upload failure.
     *
     * @return WP_R2_Client
     */
    private function mock_client_failure(WP_R2_Settings $settings): WP_R2_Client
    {
        $mock = $this->getMockBuilder(WP_R2_Client::class)
            ->setConstructorArgs([$settings])
            ->onlyMethods(['upload_file'])
            ->getMock();

        $mock->method('upload_file')
            ->willReturn(new WP_Error('r2_http_error', 'HTTP 403: Access Denied'));

        return $mock;
    }

    /**
     * Create a simple spy logger that records calls.
     *
     * @return WP_R2_Logger
     */
    private function spy_logger(): WP_R2_Logger
    {
        return $this->getMockBuilder(WP_R2_Logger::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['success', 'error'])
            ->getMock();
    }

    private function register_test_attachment(int $id, string $local_path, string $post_date = '2024-01-15 00:00:00'): void
    {
        $post = new stdClass();
        $post->post_date_gmt = $post_date;
        $GLOBALS['wp_test_posts'][$id] = $post;
        $GLOBALS['wp_test_attached_files'][$id] = $local_path;
    }

    // ── handle_new_attachment ─────────────────────────────────────────────

    public function test_handle_new_attachment_bails_when_no_credentials(): void
    {
        $settings = $this->make_settings(false); // no credentials
        $client = $this->mock_client_success($settings);
        $logger = $this->spy_logger();

        $logger->expects($this->never())->method('success');
        $logger->expects($this->never())->method('error');

        $handler = new WP_R2_Media_Handler($settings, $client, $logger);
        $handler->handle_new_attachment(1);
    }

    public function test_handle_new_attachment_logs_error_when_local_file_missing(): void
    {
        $settings = $this->make_settings();
        $client = $this->mock_client_success($settings);
        $logger = $this->spy_logger();

        // No file registered → get_attached_file returns false.
        $logger->expects($this->once())->method('error');

        $handler = new WP_R2_Media_Handler($settings, $client, $logger);
        $handler->handle_new_attachment(99); // Unregistered ID.
    }

    public function test_handle_new_attachment_stores_meta_on_success(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'r2test');
        file_put_contents($tmp, 'fake-image');

        $settings = $this->make_settings();
        $client = $this->mock_client_success($settings);
        $logger = $this->spy_logger();

        $this->register_test_attachment(1, $tmp);
        $logger->expects($this->once())->method('success');

        $handler = new WP_R2_Media_Handler($settings, $client, $logger);
        $handler->handle_new_attachment(1);

        unlink($tmp);

        $this->assertSame('2024/01/photo.jpg', get_post_meta(1, '_r2_object_key', true));
        $this->assertSame('https://cdn.example.com/2024/01/photo.jpg', get_post_meta(1, '_r2_cdn_url', true));
    }

    public function test_handle_new_attachment_logs_error_on_upload_failure(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'r2test');
        file_put_contents($tmp, 'fake-image');

        $settings = $this->make_settings();
        $client = $this->mock_client_failure($settings);
        $logger = $this->spy_logger();

        $this->register_test_attachment(5, $tmp);
        $logger->expects($this->once())->method('error');
        $logger->expects($this->never())->method('success');

        $handler = new WP_R2_Media_Handler($settings, $client, $logger);
        $handler->handle_new_attachment(5);

        unlink($tmp);

        // Meta should not be set on failure.
        $this->assertSame('', get_post_meta(5, '_r2_cdn_url', true));
    }

    // ── handle_delete_attachment ──────────────────────────────────────────

    public function test_handle_delete_attachment_does_nothing_when_no_r2_key(): void
    {
        $settings = $this->make_settings();
        $client = $this->mock_client_success($settings);
        $logger = $this->spy_logger();

        // No meta for post 10.
        $client->expects($this->never())->method('delete_file');

        $handler = new WP_R2_Media_Handler($settings, $client, $logger);
        $handler->handle_delete_attachment(10);
    }

    public function test_handle_delete_attachment_calls_r2_delete_when_key_exists(): void
    {
        $settings = $this->make_settings();
        $client = $this->mock_client_success($settings);
        $logger = $this->spy_logger();

        update_post_meta(20, '_r2_object_key', '2024/01/photo.jpg');
        $client->expects($this->once())->method('delete_file')->with('2024/01/photo.jpg');
        $logger->expects($this->once())->method('success');

        $handler = new WP_R2_Media_Handler($settings, $client, $logger);
        $handler->handle_delete_attachment(20);
    }
}
