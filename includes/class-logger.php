<?php
/**
 * Database-backed offload logger.
 *
 * Reads and writes to {prefix}r2_offload_log created by WP_R2_Activator.
 *
 * @package WP_R2_Offload
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_R2_Logger
 */
class WP_R2_Logger
{

    /** @var string Full table name including WordPress prefix. */
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'r2_offload_log';
    }

    // -----------------------------------------------------------------------
    // Write
    // -----------------------------------------------------------------------

    /**
     * Persist a log entry.
     *
     * @param int    $attachment_id WordPress attachment post ID (0 for general entries).
     * @param string $file_key      R2 object key.
     * @param string $status        'success' | 'error'.
     * @param string $message       Human-readable description.
     *
     * @return bool True on success, false on DB error.
     */
    public function log(int $attachment_id, string $file_key, string $status, string $message): bool
    {
        global $wpdb;

        // Ensure status is a known value.
        $status = in_array($status, ['success', 'error'], true) ? $status : 'error';

        // Truncate long messages to avoid bloating the table.
        $message = mb_substr($message, 0, 1000);

        $result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $this->table,
            [
                'attachment_id' => $attachment_id,
                'file_key' => $file_key,
                'status' => $status,
                'message' => $message,
                'created_at' => current_time('mysql', true), // UTC
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            // Fallback: write to PHP error log so nothing is silently lost.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions
            error_log(sprintf(
                '[WP R2 Offload] Logger DB error: attachment=%d key=%s status=%s msg=%s',
                $attachment_id,
                $file_key,
                $status,
                $message
            ));
        }

        return (bool) $result;
    }

    /**
     * Convenience: log a success event.
     *
     * @param int    $attachment_id
     * @param string $file_key
     * @param string $message
     *
     * @return bool
     */
    public function success(int $attachment_id, string $file_key, string $message = ''): bool
    {
        return $this->log(
            $attachment_id,
            $file_key,
            'success',
            $message ?: sprintf('Uploaded to R2 as %s', $file_key)
        );
    }

    /**
     * Convenience: log a failure event.
     *
     * @param int    $attachment_id
     * @param string $file_key
     * @param string $message
     *
     * @return bool
     */
    public function error(int $attachment_id, string $file_key, string $message): bool
    {
        return $this->log($attachment_id, $file_key, 'error', $message);
    }

    // -----------------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------------

    /**
     * Fetch log entries with optional filtering and pagination.
     *
     * @param array{
     *   status?:         string,
     *   attachment_id?:  int,
     *   per_page?:       int,
     *   page?:           int,
     * } $args
     *
     * @return array{ rows: array, total: int }
     */
    public function get_logs(array $args = []): array
    {
        global $wpdb;

        $per_page = max(1, (int) ($args['per_page'] ?? 25));
        $page = max(1, (int) ($args['page'] ?? 1));
        $offset = ($page - 1) * $per_page;

        $where = '1=1';
        $params = [];

        if (!empty($args['status'])) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }

        if (!empty($args['attachment_id'])) {
            $where .= ' AND attachment_id = %d';
            $params[] = (int) $args['attachment_id'];
        }

        // Count query.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var(
            !empty($params)
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ? $wpdb->prepare("SELECT COUNT(*) FROM `{$this->table}` WHERE {$where}", ...$params)
            : "SELECT COUNT(*) FROM `{$this->table}`"
        );

        // Data query.
        $data_sql = "SELECT * FROM `{$this->table}` WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $data_params = array_merge($params, [$per_page, $offset]);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare($data_sql, ...$data_params),
            ARRAY_A
        );

        return [
            'rows' => $rows ?? [],
            'total' => $total,
        ];
    }

    /**
     * Delete all log entries.
     *
     * @return int|false Number of rows deleted or false on error.
     */
    public function clear_all(): int|false
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->query("TRUNCATE TABLE `{$this->table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Delete log entries by status.
     *
     * @param string $status 'success' | 'error'.
     *
     * @return int|false
     */
    public function clear_by_status(string $status): int|false
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->delete($this->table, ['status' => $status], ['%s']);
    }

    // -----------------------------------------------------------------------
    // Stats
    // -----------------------------------------------------------------------

    /**
     * Return aggregate statistics for the Dashboard tab.
     *
     * @return array{
     *   total:        int,
     *   success:      int,
     *   errors:       int,
     *   last_upload:  string|null,
     *   pending:      int,
     * }
     */
    public function get_stats(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) as cnt FROM `{$this->table}` GROUP BY status",
            ARRAY_A
        ) ?? [];

        $success = 0;
        $errors = 0;
        foreach ($rows as $row) {
            if ($row['status'] === 'success') {
                $success = (int) $row['cnt'];
            } elseif ($row['status'] === 'error') {
                $errors = (int) $row['cnt'];
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $last_upload = $wpdb->get_var(
            "SELECT created_at FROM `{$this->table}` WHERE status = 'success' ORDER BY created_at DESC LIMIT 1"
        );

        // Count media attachments not yet offloaded (no _r2_object_key meta).
        $pending = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID AND pm.meta_key = '_r2_object_key'
             WHERE p.post_type = 'attachment'
               AND p.post_status = 'inherit'
               AND pm.meta_id IS NULL"
        );

        return [
            'total' => $success + $errors,
            'success' => $success,
            'errors' => $errors,
            'last_upload' => $last_upload,
            'pending' => $pending,
        ];
    }
}

