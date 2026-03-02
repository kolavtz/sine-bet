<?php
/**
 * Minimal WordPress function stubs for running pure unit tests
 * without a full WordPress installation.
 *
 * Only the functions actually called by plugin code under test are stubbed here.
 *
 * @package WP_R2_Offload\Tests
 */

if (!function_exists('get_option')) {
    /** @var array $wp_test_options Global in-memory option store for tests. */
    $GLOBALS['wp_test_options'] = [];

    function get_option($option, $default = false)
    {
        return $GLOBALS['wp_test_options'][$option] ?? $default;
    }

    function update_option($option, $value, $autoload = null)
    {
        $GLOBALS['wp_test_options'][$option] = $value;
        return true;
    }

    function add_option($option, $value = '', $deprecated = '', $autoload = 'yes')
    {
        if (!isset($GLOBALS['wp_test_options'][$option])) {
            $GLOBALS['wp_test_options'][$option] = $value;
        }
        return true;
    }

    function delete_option($option)
    {
        unset($GLOBALS['wp_test_options'][$option]);
        return true;
    }
}

if (!function_exists('get_post_meta')) {
    $GLOBALS['wp_test_post_meta'] = [];

    function get_post_meta($post_id, $key = '', $single = false)
    {
        $meta = $GLOBALS['wp_test_post_meta'][$post_id][$key] ?? [];
        return $single ? ($meta[0] ?? '') : $meta;
    }

    function update_post_meta($post_id, $meta_key, $meta_value)
    {
        $GLOBALS['wp_test_post_meta'][$post_id][$meta_key] = [$meta_value];
        return true;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        return strip_tags(trim((string) $str));
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)));
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url)
    {
        return filter_var((string) $url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = [])
    {
        return array_merge((array) $defaults, (array) $args);
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit($s)
    {
        return rtrim($s, '/') . '/';
    }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit($s)
    {
        return rtrim($s, '/');
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s)
    {
        return strip_tags($s);
    }
}

if (!function_exists('wp_basename')) {
    function wp_basename($path, $suffix = '')
    {
        return pathinfo($path, PATHINFO_BASENAME);
    }
}

if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($filename)
    {
        return preg_replace('/[^a-zA-Z0-9._\-]/', '-', $filename);
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = false)
    {
        return $gmt ? gmdate('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    }
}

if (!function_exists('add_action')) {
    function add_action($tag, $callback, $priority = 10, $accepted_args = 1)
    {
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1)
    {
        return true;
    }
}

if (!function_exists('wp_remote_request')) {
    // Default stub — individual tests override this via a global.
    function wp_remote_request($url, $args = [])
    {
        return $GLOBALS['wp_mock_http_response'] ?? new WP_Error('no_mock', 'No HTTP mock configured.');
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        return $response['response']['code'] ?? 0;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response)
    {
        return $response['body'] ?? '';
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private string $code;
        private string $message;
        private $data;

        public function __construct(string $code = '', string $message = '', $data = '')
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }
        public function get_error_message(): string
        {
            return $this->message;
        }
        public function get_error_data()
        {
            return $this->data;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('get_post')) {
    function get_post($id)
    {
        return $GLOBALS['wp_test_posts'][$id] ?? null;
    }
}

if (!function_exists('get_attached_file')) {
    function get_attached_file($id)
    {
        return $GLOBALS['wp_test_attached_files'][$id] ?? false;
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir()
    {
        return [
            'baseurl' => 'http://example.com/wp-content/uploads',
            'basedir' => '/var/www/wp-content/uploads',
        ];
    }
}
