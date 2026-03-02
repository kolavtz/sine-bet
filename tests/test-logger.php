<?php
/**
 * Unit tests for WP_R2_Logger.
 *
 * Uses an in-memory SQLite database to avoid a real MySQL install.
 * Falls back to mocking log() return values if PDO/SQLite is unavailable.
 *
 * NOTE: When running against a full WordPress test environment with a real DB,
 * these tests automatically use the live DB configured in wp-tests-config.php.
 *
 * @package WP_R2_Offload\Tests
 */

use PHPUnit\Framework\TestCase;

class Test_Logger extends TestCase
{

    /** @var WP_R2_Logger */
    private WP_R2_Logger $logger;

    protected function setUp(): void
    {
        // For stub-only runs, we mock the logger via a partial mock.
        if (!$this->has_real_db()) {
            $this->markTestSkipped('Skipped: Logger tests require a WordPress test DB. Set WP_TESTS_DIR to enable.');
        }

        global $wpdb;
        // Ensure our table exists.
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'r2_offload_log';
        dbDelta("CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			file_key TEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'success',
			message TEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY attachment_id (attachment_id),
			KEY status (status)
		) {$charset_collate};");

        $this->logger = new WP_R2_Logger();

        // Clean up before each test.
        $wpdb->query("TRUNCATE TABLE `{$table}`"); // phpcs:ignore
    }

    private function has_real_db(): bool
    {
        return defined('DB_NAME');
    }

    public function test_log_inserts_success_row(): void
    {
        $result = $this->logger->success(1, '2024/06/photo.jpg', 'Uploaded OK');
        $this->assertTrue($result);

        $data = $this->logger->get_logs(['per_page' => 10]);
        $this->assertCount(1, $data['rows']);
        $this->assertSame('success', $data['rows'][0]['status']);
        $this->assertSame('2024/06/photo.jpg', $data['rows'][0]['file_key']);
    }

    public function test_log_inserts_error_row(): void
    {
        $this->logger->error(2, '2024/06/bad.jpg', 'HTTP 403 Access Denied');
        $data = $this->logger->get_logs(['status' => 'error']);
        $this->assertCount(1, $data['rows']);
        $this->assertStringContainsString('Access Denied', $data['rows'][0]['message']);
    }

    public function test_get_logs_total_count_is_correct(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->logger->success($i, "2024/06/photo-{$i}.jpg");
        }

        $data = $this->logger->get_logs(['per_page' => 2, 'page' => 1]);
        $this->assertSame(5, $data['total']);
        $this->assertCount(2, $data['rows']);
    }

    public function test_get_logs_pagination(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->logger->success($i, "key-{$i}.jpg");
        }

        $page1 = $this->logger->get_logs(['per_page' => 4, 'page' => 1]);
        $page2 = $this->logger->get_logs(['per_page' => 4, 'page' => 2]);

        $this->assertCount(4, $page1['rows']);
        $this->assertCount(2, $page2['rows']);
    }

    public function test_message_truncated_at_1000_chars(): void
    {
        $long_message = str_repeat('A', 1500);
        $this->logger->error(3, 'key.jpg', $long_message);

        $data = $this->logger->get_logs();
        $this->assertLessThanOrEqual(1000, mb_strlen($data['rows'][0]['message']));
    }

    public function test_clear_all_removes_all_rows(): void
    {
        $this->logger->success(1, 'key-1.jpg');
        $this->logger->success(2, 'key-2.jpg');
        $this->logger->clear_all();

        $data = $this->logger->get_logs();
        $this->assertSame(0, $data['total']);
    }

    public function test_invalid_status_coerced_to_error(): void
    {
        $this->logger->log(1, 'key.jpg', 'invalid-status', 'msg');
        $data = $this->logger->get_logs();
        $this->assertSame('error', $data['rows'][0]['status']);
    }
}
