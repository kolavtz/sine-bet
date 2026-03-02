<?php
/**
 * Media Handler — hooks into WordPress attachment lifecycle.
 *
 * Triggers R2 uploads on attachment creation and deletions on attachment removal.
 * Tracks in-progress uploads via a shared transient for the Dashboard tab.
 * The original local file is NEVER deleted by this plugin.
 *
 * @package WP_R2_Offload
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_R2_Media_Handler
 */
class WP_R2_Media_Handler
{

    /** post_meta key for the R2 object key. */
    const META_KEY_OBJECT_KEY = '_r2_object_key';

    /** post_meta key for the full public CDN URL. */
    const META_KEY_CDN_URL = '_r2_cdn_url';

    /** Transient key for the live active-uploads list (Dashboard polling). */
    const ACTIVE_TRANSIENT = 'r2_active_uploads';

    /** @var WP_R2_Settings|WP_R2_Settings_Override */
    private $settings;

    /** @var WP_R2_Client */
    private WP_R2_Client $client;

    /** @var WP_R2_Logger */
    private WP_R2_Logger $logger;

    public function __construct(
        $settings,
        WP_R2_Client $client,
        WP_R2_Logger $logger
    ) {
        $this->settings = $settings;
        $this->client = $client;
        $this->logger = $logger;
    }

    /**
     * Register all WordPress hooks.
     */
    public function register_hooks(): void
    {
        // Run after WP has saved the attachment post and its meta.
        add_action('add_attachment', [$this, 'handle_new_attachment'], 20);
        add_action('delete_attachment', [$this, 'handle_delete_attachment'], 10);
    }

    // -----------------------------------------------------------------------
    // Hook callbacks
    // -----------------------------------------------------------------------

    /**
     * Triggered when a new attachment is added to the media library.
     *
     * @param int $attachment_id
     */
    public function handle_new_attachment(int $attachment_id): void
    {
        // Guard: bail if credentials are not configured.
        if (!$this->settings->are_credentials_set()) {
            return;
        }

        // Race-condition lock — prevents duplicate offloads when WP fires
        // add_attachment more than once for the same attachment (Gutenberg / REST API).
        $lock_key = 'r2_offload_lock_' . $attachment_id;
        if (get_transient($lock_key)) {
            return;
        }
        set_transient($lock_key, 1, 30); // 30-second lock window.

        // Guard: already offloaded.
        if (get_post_meta($attachment_id, self::META_KEY_OBJECT_KEY, true)) {
            return;
        }

        // Resolve physical path.
        $local_path = get_attached_file($attachment_id);

        if (!$local_path || !file_exists($local_path)) {
            $this->logger->error(
                $attachment_id,
                '',
                sprintf('Local file not found for attachment %d.', $attachment_id)
            );
            return;
        }

        $remote_key = $this->build_remote_key($attachment_id, $local_path);

        // Mark this upload as active so the Dashboard can show it.
        $this->mark_upload_active($attachment_id, wp_basename($local_path));

        // Upload to R2.
        $result = $this->client->upload_file($local_path, $remote_key);

        // Clear active marker regardless of outcome.
        $this->mark_upload_inactive($attachment_id);

        if (is_wp_error($result)) {
            $this->logger->error($attachment_id, $remote_key, $result->get_error_message());
            // Local file remains intact — WordPress continues normally.
            return;
        }

        // Persist metadata.
        update_post_meta($attachment_id, self::META_KEY_OBJECT_KEY, $result['key']);
        update_post_meta($attachment_id, self::META_KEY_CDN_URL, $result['url']);

        // Log success.
        $this->logger->success(
            $attachment_id,
            $result['key'],
            sprintf('Uploaded to R2: %s', $result['url'])
        );
    }

    /**
     * Triggered just before WordPress deletes an attachment.
     *
     * @param int $attachment_id
     */
    public function handle_delete_attachment(int $attachment_id): void
    {
        if (!$this->settings->are_credentials_set()) {
            return;
        }

        $object_key = get_post_meta($attachment_id, self::META_KEY_OBJECT_KEY, true);

        if (!$object_key) {
            // File was never offloaded — nothing to do on R2.
            return;
        }

        $result = $this->client->delete_file($object_key);

        if (is_wp_error($result)) {
            $this->logger->error(
                $attachment_id,
                $object_key,
                'Delete from R2 failed: ' . $result->get_error_message()
            );
        } else {
            $this->logger->success($attachment_id, $object_key, 'Deleted from R2.');
        }

        // Post meta is removed automatically by WordPress when the post is deleted.
    }

    // -----------------------------------------------------------------------
    // Active upload tracking (Dashboard polling)
    // -----------------------------------------------------------------------

    /**
     * Add an entry to the shared active-uploads transient.
     *
     * @param int    $attachment_id
     * @param string $filename
     */
    public function mark_upload_active(int $attachment_id, string $filename): void
    {
        $active = (array) get_transient(self::ACTIVE_TRANSIENT);
        $active[$attachment_id] = [
            'file' => $filename,
            'started' => time(),
        ];
        set_transient(self::ACTIVE_TRANSIENT, $active, 120); // auto-expire after 2 min
    }

    /**
     * Remove an entry from the active-uploads transient.
     *
     * @param int $attachment_id
     */
    public function mark_upload_inactive(int $attachment_id): void
    {
        $active = (array) get_transient(self::ACTIVE_TRANSIENT);
        unset($active[$attachment_id]);
        if (empty($active)) {
            delete_transient(self::ACTIVE_TRANSIENT);
        } else {
            set_transient(self::ACTIVE_TRANSIENT, $active, 120);
        }
    }

    /**
     * Return current active uploads array (used by Dashboard AJAX status endpoint).
     *
     * @return array
     */
    public static function get_active_uploads(): array
    {
        return (array) get_transient(self::ACTIVE_TRANSIENT);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build the R2 object key for a file, mirroring WordPress's upload date structure.
     * Example: 2024/06/my-photo.jpg
     *
     * @param int    $attachment_id
     * @param string $local_path
     *
     * @return string
     */
    public function build_remote_key(int $attachment_id, string $local_path): string
    {
        $post = get_post($attachment_id);
        $post_date = $post ? $post->post_date_gmt : current_time('mysql', true);
        $year = date('Y', strtotime($post_date));
        $month = date('m', strtotime($post_date));
        $filename = wp_basename($local_path);
        $safe_name = sanitize_file_name($filename);

        $date_key = "{$year}/{$month}/{$safe_name}";

        // 'wp' mode: prefix with WordPress uploads sub-path so the R2 key
        // mirrors the local uploads path exactly.  Switching CDN domains then
        // requires NO path changes — only the domain changes.
        if (method_exists($this->settings, 'get_key_prefix') && $this->settings->get_key_prefix() === 'wp') {
            // Build the WP uploads sub-path (e.g. wp-content/uploads/).
            $upload_dir = wp_upload_dir();
            $uploads_url = $upload_dir['baseurl'];          // e.g. https://example.com/wp-content/uploads
            $site_url = rtrim(get_site_url(), '/');
            // Relative path: wp-content/uploads
            $rel_prefix = ltrim(str_replace($site_url, '', $uploads_url), '/');
            return $rel_prefix . '/' . $date_key;
        }

        return $date_key;
    }
}
