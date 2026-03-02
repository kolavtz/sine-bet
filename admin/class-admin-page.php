<?php
/**
 * Admin Page — Dashboard, Settings and Logs UI.
 *
 * Three tabs:
 *   1. Dashboard — stats cards, live active-upload feed, bulk offload.
 *   2. Settings  — credentials & options form.
 *   3. Logs      — paginated offload log table with clear/retry actions.
 *
 * @package WP_R2_Offload
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_R2_Admin_Page
 */
class WP_R2_Admin_Page
{

    /** @var WP_R2_Settings */
    private WP_R2_Settings $settings;

    public function __construct(WP_R2_Settings $settings)
    {
        $this->settings = $settings;

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX actions.
        add_action('wp_ajax_r2_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_r2_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_r2_get_status', [$this, 'ajax_get_status']);
        add_action('wp_ajax_r2_bulk_offload', [$this, 'ajax_bulk_offload']);

        add_action('wp_ajax_r2_list_pending', [$this, 'ajax_list_pending']);
        add_action('wp_ajax_r2_selective_upload', [$this, 'ajax_selective_upload']);
        add_action('wp_ajax_r2_list_orphans', [$this, 'ajax_list_orphans']);
        add_action('wp_ajax_r2_delete_orphan', [$this, 'ajax_delete_orphan']);

        // Admin-post action.
        add_action('admin_post_r2_retry_errors', [$this, 'handle_retry_errors']);
    }

    // -----------------------------------------------------------------------
    // Menu & assets
    // -----------------------------------------------------------------------

    public function register_menu(): void
    {
        add_menu_page(
            __('R2 Offload', 'wp-r2-offload'),
            __('R2 Offload', 'wp-r2-offload'),
            'manage_options',
            'r2-offload',
            [$this, 'render_page'],
            'dashicons-cloud-upload',
            80
        );
    }

    public function register_settings(): void
    {
        $this->settings->register();
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if (strpos($hook_suffix, 'r2-offload') === false) {
            return;
        }

        wp_enqueue_style(
            'wp-r2-offload-admin',
            WP_R2_OFFLOAD_PLUGIN_URL . 'admin/assets/admin.css',
            [],
            WP_R2_OFFLOAD_VERSION
        );

        wp_enqueue_script(
            'wp-r2-offload-admin',
            WP_R2_OFFLOAD_PLUGIN_URL . 'admin/assets/admin.js',
            ['jquery'],
            WP_R2_OFFLOAD_VERSION,
            true
        );

        wp_localize_script('wp-r2-offload-admin', 'wpR2Offload', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_r2_offload_admin'),
            'i18n' => [
                'testing' => __('Testing…', 'wp-r2-offload'),
                'connected' => __('✔ Connection successful!', 'wp-r2-offload'),
                'failed' => __('✘ Connection failed: ', 'wp-r2-offload'),
                'cleared' => __('Logs cleared.', 'wp-r2-offload'),
                'confirmClear' => __('This will permanently delete all log entries. Continue?', 'wp-r2-offload'),
                'fillRequired' => __('Please fill in Account ID, Access Key, Secret Key, and Bucket Name first.', 'wp-r2-offload'),
                'unknownError' => __('Unknown error. Check your server error log.', 'wp-r2-offload'),
                'startingBulk' => __('Preparing bulk offload…', 'wp-r2-offload'),
                'bulkDone' => __('✔ Bulk offload complete!', 'wp-r2-offload'),
                'bulkProgress' => __('Uploading… %1$s / %2$s files', 'wp-r2-offload'),
                'noCredentials' => __('R2 credentials not configured. Go to Settings tab.', 'wp-r2-offload'),
                'idle' => __('Idle — no active uploads', 'wp-r2-offload'),
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // Page renderer
    // -----------------------------------------------------------------------

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wp-r2-offload'));
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification
        ?>
        <div class="wrap r2-offload-wrap">
            <h1 class="r2-offload-title">
                <span class="dashicons dashicons-cloud-upload"></span>
                <?php esc_html_e('WP R2 Offload', 'wp-r2-offload'); ?>
            </h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('admin.php?page=r2-offload&tab=dashboard')); ?>"
                    class="nav-tab <?php echo $active_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Dashboard', 'wp-r2-offload'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=r2-offload&tab=settings')); ?>"
                    class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Settings', 'wp-r2-offload'); ?>
                    <?php if (!$this->settings->are_credentials_set()): ?>
                        <span class="r2-badge-warn">!</span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=r2-offload&tab=logs')); ?>"
                    class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Logs', 'wp-r2-offload'); ?>
                </a>
            </nav>

            <div class="r2-offload-content">
                <?php
                match ($active_tab) {
                    'settings' => $this->render_settings_tab(),
                    'logs' => $this->render_logs_tab(),
                    default => $this->render_dashboard_tab(),
                };
                ?>
            </div>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Dashboard tab
    // -----------------------------------------------------------------------

    private function render_dashboard_tab(): void
    {
        $logger = new WP_R2_Logger();
        $stats = $logger->get_stats();
        $creds = $this->settings->are_credentials_set();

        /* translators: %s = human-readable datetime */
        $last_str = $stats['last_upload']
            ? esc_html(human_time_diff(strtotime($stats['last_upload']), time()) . ' ago')
            : esc_html__('Never', 'wp-r2-offload');
        ?>

        <!-- Credential warning banner -->
        <?php if (!$creds): ?>
            <div class="r2-alert r2-alert--warn">
                <span class="dashicons dashicons-warning"></span>
                <?php printf(
                    wp_kses(__('R2 credentials not configured. <a href="%s">Go to Settings</a> to add them.', 'wp-r2-offload'), ['a' => ['href' => []]]),
                    esc_url(admin_url('admin.php?page=r2-offload&tab=settings'))
                ); ?>
            </div>
        <?php endif; ?>

        <!-- Stats cards -->
        <div class="r2-stats-grid">
            <div class="r2-stat-card r2-stat-card--total">
                <div class="r2-stat-value"><?php echo esc_html(number_format_i18n($stats['success'])); ?></div>
                <div class="r2-stat-label"><?php esc_html_e('Files Offloaded', 'wp-r2-offload'); ?></div>
            </div>
            <div class="r2-stat-card r2-stat-card--pending">
                <div class="r2-stat-value"><?php echo esc_html(number_format_i18n($stats['pending'])); ?></div>
                <div class="r2-stat-label"><?php esc_html_e('Pending (not on R2)', 'wp-r2-offload'); ?></div>
            </div>
            <div class="r2-stat-card r2-stat-card--error">
                <div class="r2-stat-value"><?php echo esc_html(number_format_i18n($stats['errors'])); ?></div>
                <div class="r2-stat-label"><?php esc_html_e('Failed Uploads', 'wp-r2-offload'); ?></div>
            </div>
            <div class="r2-stat-card r2-stat-card--time">
                <div class="r2-stat-value r2-stat-value--sm"><?php echo $last_str; // escaped above ?></div>
                <div class="r2-stat-label"><?php esc_html_e('Last Upload', 'wp-r2-offload'); ?></div>
            </div>
        </div>

        <!-- Bulk offload panel -->
        <div class="r2-panel">
            <h2 class="r2-panel__title">
                <span class="dashicons dashicons-upload"></span>
                <?php esc_html_e('Bulk Offload', 'wp-r2-offload'); ?>
            </h2>
            <p><?php printf(
                esc_html__('%d file(s) in your Media Library have not been offloaded to R2 yet.', 'wp-r2-offload'),
                (int) $stats['pending']
            ); ?></p>
            <button id="r2-bulk-start" class="button button-primary" <?php echo $creds ? '' : 'disabled'; ?>>
                <?php esc_html_e('Upload Now', 'wp-r2-offload'); ?>
            </button>
            <div id="r2-bulk-status" class="r2-bulk-status" style="display:none;">
                <div class="r2-progress-bar">
                    <div class="r2-progress-fill" id="r2-progress-fill"></div>
                </div>
                <p class="r2-progress-label" id="r2-progress-label"></p>
            </div>
        </div>

        <!-- Live activity panel -->
        <div class="r2-panel">
            <h2 class="r2-panel__title">
                <span class="dashicons dashicons-update r2-spin" id="r2-activity-spinner" style="display:none;"></span>
                <span class="dashicons dashicons-clock" id="r2-idle-icon"></span>
                <?php esc_html_e('Live Activity', 'wp-r2-offload'); ?>
                <span class="r2-live-dot" id="r2-live-dot"></span>
            </h2>
            <div id="r2-active-list">
                <p class="r2-idle-text"><?php esc_html_e('Idle — no active uploads', 'wp-r2-offload'); ?></p>
            </div>
        </div>

        <!-- Selective upload panel -->
        <div class="r2-panel">
            <h2 class="r2-panel__title">
                <span class="dashicons dashicons-search"></span>
                <?php esc_html_e('Selective Upload', 'wp-r2-offload'); ?>
            </h2>
            <p><?php esc_html_e('Search your media library and upload specific files to R2.', 'wp-r2-offload'); ?></p>
            <div style="display:flex;gap:8px;margin-bottom:14px;">
                <input type="text" id="r2-selective-search" class="regular-text"
                    placeholder="<?php esc_attr_e('Search filename…', 'wp-r2-offload'); ?>" />
                <button type="button" id="r2-selective-search-btn" class="button">
                    <?php esc_html_e('Search', 'wp-r2-offload'); ?>
                </button>
            </div>
            <div id="r2-selective-list">
                <p class="r2-idle-text">
                    <?php esc_html_e('Type a filename or leave blank to load all pending files.', 'wp-r2-offload'); ?>
                </p>
            </div>
        </div>

        <!-- Quick actions -->
        <div class="r2-panel">
            <h2 class="r2-panel__title">
                <span class="dashicons dashicons-admin-tools"></span>
                <?php esc_html_e('Quick Actions', 'wp-r2-offload'); ?>
            </h2>
            <div class="r2-quick-actions">
                <button id="r2-test-connection-dash" class="button" <?php echo $creds ? '' : 'disabled'; ?>>
                    <?php esc_html_e('Test Connection', 'wp-r2-offload'); ?>
                </button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=r2-offload&tab=logs')); ?>" class="button">
                    <?php esc_html_e('View Full Logs', 'wp-r2-offload'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=r2-offload&tab=settings')); ?>" class="button">
                    <?php esc_html_e('Settings', 'wp-r2-offload'); ?>
                </a>
                <span id="r2-test-result-dash" class="r2-test-result" aria-live="polite"></span>
            </div>
        </div>

        <!-- Recent log entries -->
        <?php
        $recent = $logger->get_logs(['per_page' => 8, 'page' => 1]);
        if (!empty($recent['rows'])):
            ?>
            <div class="r2-panel">
                <h2 class="r2-panel__title">
                    <span class="dashicons dashicons-list-view"></span>
                    <?php esc_html_e('Recent Activity', 'wp-r2-offload'); ?>
                </h2>
                <table class="wp-list-table widefat fixed striped r2-log-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Status', 'wp-r2-offload'); ?></th>
                            <th><?php esc_html_e('File', 'wp-r2-offload'); ?></th>
                            <th><?php esc_html_e('Message', 'wp-r2-offload'); ?></th>
                            <th style="width:130px"><?php esc_html_e('Time (UTC)', 'wp-r2-offload'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent['rows'] as $row): ?>
                            <tr>
                                <td><span
                                        class="r2-badge r2-badge--<?php echo esc_attr($row['status']); ?>"><?php echo esc_html(ucfirst($row['status'])); ?></span>
                                </td>
                                <td><code><?php echo esc_html(wp_basename($row['file_key'])); ?></code></td>
                                <td><?php echo esc_html(mb_strimwidth($row['message'], 0, 80, '…')); ?></td>
                                <td><?php echo esc_html($row['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- R2 Cleanup panel -->
        <div class="r2-panel">
            <h2 class="r2-panel__title">
                <span class="dashicons dashicons-trash"></span>
                <?php esc_html_e('R2 Cleanup — Orphaned Files', 'wp-r2-offload'); ?>
            </h2>
            <p><?php esc_html_e('Scan for files that are stored in your R2 bucket but whose source file no longer exists on this server. You can then delete them from R2.', 'wp-r2-offload'); ?>
            </p>
            <button type="button" id="r2-scan-orphans" class="button" <?php echo $creds ? '' : 'disabled'; ?>>
                <?php esc_html_e('Scan for Orphaned Files', 'wp-r2-offload'); ?>
            </button>
            <div id="r2-orphan-results" style="margin-top:14px;"></div>
        </div>
        <?php
    }


    // -----------------------------------------------------------------------
    // Settings tab
    // -----------------------------------------------------------------------

    private function render_settings_tab(): void
    {
        ?>
        <form method="post" action="options.php" class="r2-settings-form">
            <?php settings_fields(WP_R2_Settings::SETTINGS_GROUP); ?>

            <?php
            $fields = [
                ['key' => WP_R2_Settings::OPT_ACCOUNT_ID, 'label' => __('Account ID', 'wp-r2-offload'), 'type' => 'text', 'description' => __('Your Cloudflare Account ID (found in the dashboard URL or Overview page).', 'wp-r2-offload')],
                ['key' => WP_R2_Settings::OPT_ACCESS_KEY, 'label' => __('Access Key ID', 'wp-r2-offload'), 'type' => 'text', 'description' => __('R2 API token Access Key ID. Create one in Cloudflare → R2 → Manage R2 API tokens.', 'wp-r2-offload')],
                ['key' => WP_R2_Settings::OPT_SECRET_KEY, 'label' => __('Secret Access Key', 'wp-r2-offload'), 'type' => 'password', 'description' => __('R2 API token Secret Access Key. Stored in wp_options; prefer defining WP_R2_SECRET_KEY in wp-config.php for security.', 'wp-r2-offload')],
                ['key' => WP_R2_Settings::OPT_BUCKET, 'label' => __('Bucket Name', 'wp-r2-offload'), 'type' => 'text', 'description' => __('The name of your R2 bucket (lowercase, no spaces).', 'wp-r2-offload')],
                ['key' => WP_R2_Settings::OPT_CDN_DOMAIN, 'label' => __('CDN Domain', 'wp-r2-offload'), 'type' => 'url', 'description' => __('Public domain for serving assets (e.g. https://cdn.yourdomain.com). Leave blank to use the R2 bucket URL directly.', 'wp-r2-offload')],
            ];
            ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <?php foreach ($fields as $field): ?>
                        <tr>
                            <th scope="row">
                                <label for="<?php echo esc_attr($field['key']); ?>"><?php echo esc_html($field['label']); ?></label>
                            </th>
                            <td>
                                <input type="<?php echo esc_attr($field['type']); ?>" id="<?php echo esc_attr($field['key']); ?>"
                                    name="<?php echo esc_attr($field['key']); ?>"
                                    value="<?php echo esc_attr(get_option($field['key'], '')); ?>" class="regular-text"
                                    autocomplete="off" />
                                <p class="description"><?php echo esc_html($field['description']); ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('R2 Key Path Structure', 'wp-r2-offload'); ?></th>
                        <td>
                            <fieldset>
                                <label style="display:block;margin-bottom:6px">
                                    <input type="radio" name="<?php echo esc_attr(WP_R2_Settings::OPT_KEY_PREFIX); ?>"
                                        value="date" <?php checked($this->settings->get_key_prefix(), 'date'); ?> />
                                    <strong>yyyy/mm/filename.jpg</strong> — compact (default)
                                </label>
                                <label style="display:block">
                                    <input type="radio" name="<?php echo esc_attr(WP_R2_Settings::OPT_KEY_PREFIX); ?>"
                                        value="wp" <?php checked($this->settings->get_key_prefix(), 'wp'); ?> />
                                    <strong>wp-content/uploads/yyyy/mm/filename.jpg</strong>
                                    <span class="description"> — mirrors WordPress uploads path exactly; switching CDN domains
                                        only requires changing the domain</span>
                                </label>
                            </fieldset>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('URL Rewriting', 'wp-r2-offload'); ?></th>
                        <td>
                            <label for="<?php echo esc_attr(WP_R2_Settings::OPT_URL_REWRITING); ?>">
                                <input type="checkbox" id="<?php echo esc_attr(WP_R2_Settings::OPT_URL_REWRITING); ?>"
                                    name="<?php echo esc_attr(WP_R2_Settings::OPT_URL_REWRITING); ?>" value="1" <?php checked(get_option(WP_R2_Settings::OPT_URL_REWRITING), '1'); ?> />
                                <?php esc_html_e('Rewrite attachment and content URLs to CDN domain', 'wp-r2-offload'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When enabled, wp_get_attachment_url() and embedded media URLs in post content will point to the CDN domain. Disable if you use a separate CDN or caching plugin for URL rewriting.', 'wp-r2-offload'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p class="submit r2-submit-row">
                <?php submit_button(__('Save Settings', 'wp-r2-offload'), 'primary', 'submit', false); ?>
                <button type="button" id="r2-test-connection" class="button button-secondary">
                    <?php esc_html_e('Test Connection', 'wp-r2-offload'); ?>
                </button>
                <span id="r2-test-result" class="r2-test-result" aria-live="polite"></span>
            </p>
        </form>
        <?php
    }

    // -----------------------------------------------------------------------
    // Logs tab
    // -----------------------------------------------------------------------

    private function render_logs_tab(): void
    {
        $logger = new WP_R2_Logger();
        $per_page = 25;
        $page = max(1, (int) ($_GET['paged'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification
        $filter = isset($_GET['status']) ? sanitize_key($_GET['status']) : ''; // phpcs:ignore

        $result = $logger->get_logs(['per_page' => $per_page, 'page' => $page, 'status' => $filter]);
        $rows = $result['rows'];
        $total = $result['total'];
        $total_pages = (int) ceil($total / $per_page);
        ?>
        <div class="r2-logs-toolbar">
            <a href="<?php echo esc_url(admin_url('admin.php?page=r2-offload&tab=logs')); ?>"
                class="button<?php echo $filter === '' ? ' button-primary' : ''; ?>">
                <?php esc_html_e('All', 'wp-r2-offload'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=r2-offload&tab=logs&status=success')); ?>"
                class="button<?php echo $filter === 'success' ? ' button-primary' : ''; ?>">
                <?php esc_html_e('Success', 'wp-r2-offload'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=r2-offload&tab=logs&status=error')); ?>"
                class="button<?php echo $filter === 'error' ? ' button-primary' : ''; ?>">
                <?php esc_html_e('Errors', 'wp-r2-offload'); ?>
            </a>
            <button type="button" id="r2-clear-logs" class="button r2-btn-danger">
                <?php esc_html_e('Clear All Logs', 'wp-r2-offload'); ?>
            </button>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                <input type="hidden" name="action" value="r2_retry_errors">
                <?php wp_nonce_field('r2_retry_errors'); ?>
                <button type="submit" class="button">
                    <?php esc_html_e('Retry Failed', 'wp-r2-offload'); ?>
                </button>
            </form>
        </div>

        <?php if (empty($rows)): ?>
            <p class="r2-empty-state"><?php esc_html_e('No log entries found.', 'wp-r2-offload'); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped r2-log-table">
                <thead>
                    <tr>
                        <th scope="col" style="width:60px"><?php esc_html_e('ID', 'wp-r2-offload'); ?></th>
                        <th scope="col" style="width:100px"><?php esc_html_e('Status', 'wp-r2-offload'); ?></th>
                        <th scope="col" style="width:100px"><?php esc_html_e('Attachment', 'wp-r2-offload'); ?></th>
                        <th scope="col"><?php esc_html_e('R2 Key', 'wp-r2-offload'); ?></th>
                        <th scope="col"><?php esc_html_e('Message', 'wp-r2-offload'); ?></th>
                        <th scope="col" style="width:160px"><?php esc_html_e('Date (UTC)', 'wp-r2-offload'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['id']); ?></td>
                            <td>
                                <span class="r2-badge r2-badge--<?php echo esc_attr($row['status']); ?>">
                                    <?php echo esc_html(ucfirst($row['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($row['attachment_id']): ?>
                                    <a href="<?php echo esc_url(get_edit_post_link((int) $row['attachment_id'])); ?>">
                                        #<?php echo esc_html($row['attachment_id']); ?>
                                    </a>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html($row['file_key']); ?></code></td>
                            <td><?php echo esc_html($row['message']); ?></td>
                            <td><?php echo esc_html($row['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1):
                echo paginate_links([ // phpcs:ignore WordPress.Security.EscapeOutput
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $page,
                    'total' => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ]);
            endif; ?>
        <?php endif; ?>
    <?php
    }

    // -----------------------------------------------------------------------
    // AJAX handlers
    // -----------------------------------------------------------------------

    /**
     * AJAX: test R2 connection using live form values.
     */
    public function ajax_test_connection(): void
    {
        check_ajax_referer('wp_r2_offload_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-r2-offload')], 403);
        }

        $post_account_id = isset($_POST['account_id']) ? sanitize_text_field(wp_unslash($_POST['account_id'])) : '';
        $post_access_key = isset($_POST['access_key']) ? sanitize_text_field(wp_unslash($_POST['access_key'])) : '';
        $post_secret_key = isset($_POST['secret_key']) ? sanitize_text_field(wp_unslash($_POST['secret_key'])) : '';
        $post_bucket = isset($_POST['bucket']) ? sanitize_key(wp_unslash($_POST['bucket'])) : '';
        $post_cdn_domain = isset($_POST['cdn_domain']) ? esc_url_raw(wp_unslash($_POST['cdn_domain'])) : '';

        if ($post_account_id && $post_access_key && $post_secret_key && $post_bucket) {
            $test_settings = new WP_R2_Settings_Override($post_account_id, $post_access_key, $post_secret_key, $post_bucket, $post_cdn_domain);
            $r2_client = new WP_R2_Client($test_settings);
        } else {
            // Fall back to saved settings.
            $r2_client = new WP_R2_Client($this->settings);
        }

        $result = $r2_client->test_connection();
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => __('Connection successful!', 'wp-r2-offload')]);
    }

    /**
     * AJAX: clear all log entries.
     */
    public function ajax_clear_logs(): void
    {
        check_ajax_referer('wp_r2_offload_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-r2-offload')], 403);
        }
        (new WP_R2_Logger())->clear_all();
        wp_send_json_success(['message' => __('Logs cleared.', 'wp-r2-offload')]);
    }

    /**
     * AJAX: return current plugin status (active uploads + stats) for Dashboard polling.
     */
    public function ajax_get_status(): void
    {
        check_ajax_referer('wp_r2_offload_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }

        $active_raw = WP_R2_Media_Handler::get_active_uploads();
        $active = [];
        $now = time();
        foreach ($active_raw as $id => $info) {
            $active[] = [
                'id' => (int) $id,
                'file' => esc_html($info['file']),
                'elapsed' => human_time_diff($info['started'], $now),
            ];
        }

        $logger = new WP_R2_Logger();
        $stats = $logger->get_stats();

        // Recent 5 logs for dashboard feed.
        $recent_result = $logger->get_logs(['per_page' => 5, 'page' => 1]);
        $recent = [];
        foreach ($recent_result['rows'] as $row) {
            $recent[] = [
                'status' => esc_html($row['status']),
                'file' => esc_html(wp_basename($row['file_key'])),
                'message' => esc_html(mb_strimwidth($row['message'], 0, 60, '…')),
                'time' => esc_html($row['created_at']),
            ];
        }

        wp_send_json_success([
            'active' => $active,
            'stats' => $stats,
            'recent' => $recent,
        ]);
    }

    /**
     * AJAX: bulk-offload one chunk of unprocessed attachments.
     * JS calls this repeatedly until done = true.
     *
     * POST params:
     *   offset  int   how many to skip (caller increments by chunk_size each tick)
     */
    public function ajax_bulk_offload(): void
    {
        check_ajax_referer('wp_r2_offload_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-r2-offload')], 403);
        }

        if (!$this->settings->are_credentials_set()) {
            wp_send_json_error(['message' => __('R2 credentials not configured.', 'wp-r2-offload')]);
        }

        $chunk_size = 5;
        $offset = max(0, (int) ($_POST['offset'] ?? 0)); // phpcs:ignore

        global $wpdb;

        // Total count of un-offloaded attachments.
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
				ON pm.post_id = p.ID AND pm.meta_key = '_r2_object_key'
			 WHERE p.post_type = 'attachment'
			   AND p.post_status = 'inherit'
			   AND pm.meta_id IS NULL"
        );

        if ($total === 0) {
            wp_send_json_success(['done' => true, 'processed' => 0, 'total' => 0]);
        }

        // Fetch one chunk.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
				ON pm.post_id = p.ID AND pm.meta_key = '_r2_object_key'
			 WHERE p.post_type = 'attachment'
			   AND p.post_status = 'inherit'
			   AND pm.meta_id IS NULL
			 ORDER BY p.ID ASC
			 LIMIT %d OFFSET %d",
            $chunk_size,
            $offset
        ));

        $logger = new WP_R2_Logger();
        $handler = new WP_R2_Media_Handler($this->settings, new WP_R2_Client($this->settings), $logger);

        foreach ($ids as $id) {
            $handler->handle_new_attachment((int) $id);
        }

        $processed = $offset + count($ids);
        $done = $processed >= $total || empty($ids);

        wp_send_json_success([
            'done' => $done,
            'processed' => $processed,
            'total' => $total,
        ]);
    }

    /**
     * admin_post: retry all failed offload attempts.
     */
    public function handle_retry_errors(): void
    {
        check_admin_referer('r2_retry_errors');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'wp-r2-offload'));
        }

        $logger = new WP_R2_Logger();
        $errors = $logger->get_logs(['status' => 'error', 'per_page' => 200]);

        if (!empty($errors['rows'])) {
            $r2_client = new WP_R2_Client($this->settings);
            $media_handler = new WP_R2_Media_Handler($this->settings, $r2_client, $logger);

            $retried = [];
            foreach ($errors['rows'] as $row) {
                $id = (int) $row['attachment_id'];
                if ($id && !in_array($id, $retried, true)) {
                    $media_handler->handle_new_attachment($id);
                    $retried[] = $id;
                }
            }
        }

        wp_safe_redirect(add_query_arg(
            ['page' => 'r2-offload', 'tab' => 'logs', 'retried' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * AJAX: list un-offloaded media attachments for the Selective Upload panel.
     * Accepts optional POST param 'search' (filename substring).
     */
    public function ajax_list_pending(): void
    {
        check_ajax_referer('wp_r2_offload_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }

        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';

        global $wpdb;

        $search_sql = '';
        $params = [];
        if ($search !== '') {
            $search_sql = " AND p.post_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $params[] = 100; // max rows

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT p.ID, p.post_title, p.post_date, p.post_mime_type
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm
					ON pm.post_id = p.ID AND pm.meta_key = '_r2_object_key'
				 WHERE p.post_type = 'attachment'
				   AND p.post_status = 'inherit'
				   AND pm.meta_id IS NULL
				   {$search_sql}
				 ORDER BY p.ID DESC
				 LIMIT %d",
                ...$params
            ),
            ARRAY_A
        );

        $items = [];
        foreach ($rows ?? [] as $row) {
            $file = get_attached_file((int) $row['ID']);
            $items[] = [
                'id' => (int) $row['ID'],
                'filename' => $file ? esc_html(wp_basename($file)) : esc_html($row['post_title']),
                'mime' => esc_html($row['post_mime_type']),
                'date' => esc_html(substr($row['post_date'], 0, 10)),
            ];
        }

        wp_send_json_success(['items' => $items]);
    }

    /**
     * AJAX: offload a single attachment (used by the Selective Upload panel).
     */
    public function ajax_selective_upload(): void
    {
        check_ajax_referer('wp_r2_offload_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-r2-offload')], 403);
        }
        if (!$this->settings->are_credentials_set()) {
            wp_send_json_error(['message' => __('R2 credentials not configured.', 'wp-r2-offload')]);
        }

        $attachment_id = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;
        if (!$attachment_id) {
            wp_send_json_error(['message' => 'Invalid attachment ID.']);
        }

        $logger = new WP_R2_Logger();
        $handler = new WP_R2_Media_Handler($this->settings, new WP_R2_Client($this->settings), $logger);
        $handler->handle_new_attachment($attachment_id);

        wp_send_json_success(['id' => $attachment_id]);
    }

    /**
     * AJAX: scan all R2-tracked attachments and return those whose source file
     * no longer exists on this server (orphaned R2 objects).
     *
     * Two orphan types reported:
     *   'deleted_post'  — WordPress attachment post was deleted entirely.
     *   'missing_file'  — WP attachment still registered but local file is gone.
     */
    public function ajax_list_orphans(): void
    {
        check_ajax_referer('wp_r2_offload_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }

        global $wpdb;

        // All post_meta rows where we stored an R2 key.
        $rows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value AS r2_key,
			        p.post_title, p.post_status
			 FROM {$wpdb->postmeta} pm
			 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_r2_object_key'
			 ORDER BY pm.post_id DESC
			 LIMIT 500",
            ARRAY_A
        );

        $orphans = [];

        foreach ($rows ?? [] as $row) {
            $reason = null;
            $filename = wp_basename((string) $row['r2_key']);

            if (empty($row['post_status']) || in_array($row['post_status'], ['trash', 'auto-draft'], true)) {
                // Attachment post no longer exists or is trashed.
                $reason = 'deleted_post';
                $label = __('WP attachment deleted / trashed', 'wp-r2-offload');
            } else {
                // Attachment post exists — check if the local file is still on disk.
                $local_path = get_attached_file((int) $row['post_id']);
                if (!$local_path || !file_exists($local_path)) {
                    $reason = 'missing_file';
                    $label = __('Local file missing from server', 'wp-r2-offload');
                }
            }

            if ($reason) {
                $orphans[] = [
                    'post_id' => (int) $row['post_id'],
                    'r2_key' => esc_html($row['r2_key']),
                    'filename' => esc_html($filename),
                    'reason' => $reason,
                    'label' => esc_html($label),
                ];
            }
        }

        wp_send_json_success(['orphans' => $orphans]);
    }

    /**
     * AJAX: delete a single orphaned R2 object and clean up post meta.
     *
     * POST params:
     *   r2_key   string  R2 object key to delete.
     *   post_id  int     Attachment post ID whose meta should be cleared.
     */
    public function ajax_delete_orphan(): void
    {
        check_ajax_referer('wp_r2_offload_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-r2-offload')], 403);
        }
        if (!$this->settings->are_credentials_set()) {
            wp_send_json_error(['message' => __('R2 credentials not configured.', 'wp-r2-offload')]);
        }

        $r2_key = isset($_POST['r2_key']) ? sanitize_text_field(wp_unslash($_POST['r2_key'])) : '';
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

        if (!$r2_key) {
            wp_send_json_error(['message' => 'R2 key is required.']);
        }

        $r2_client = new WP_R2_Client($this->settings);
        $result = $r2_client->delete_file($r2_key);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // Remove stale post meta so the attachment no longer appears as offloaded.
        if ($post_id) {
            delete_post_meta($post_id, '_r2_object_key');
            delete_post_meta($post_id, '_r2_cdn_url');
        }

        // Log the cleanup action.
        (new WP_R2_Logger())->log(
            $post_id,
            $r2_key,
            'success',
            sprintf('Orphan deleted from R2: %s', $r2_key)
        );

        wp_send_json_success(['r2_key' => $r2_key]);
    }
}


