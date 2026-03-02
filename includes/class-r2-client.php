<?php
/**
 * Cloudflare R2 API client.
 *
 * Uses Cloudflare's S3-compatible endpoint with AWS Signature Version 4.
 * Zero external dependencies — all HTTP calls go through wp_remote_request().
 * Compatible with PHP 7.4+.
 *
 * Endpoint: https://{account_id}.r2.cloudflarestorage.com/{bucket}/{key}
 *
 * @package WP_R2_Offload
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_R2_Client
 */
class WP_R2_Client
{

    private const SERVICE = 's3';
    private const REGION = 'auto';
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    /** HTTP timeout in seconds for upload requests. */
    private const TIMEOUT = 60;

    /** @var WP_R2_Settings_Contract */
    private WP_R2_Settings_Contract $settings;

    /**
     * @param WP_R2_Settings_Contract $settings
     */
    public function __construct(WP_R2_Settings_Contract $settings)
    {
        $this->settings = $settings;
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Upload a local file to R2.
     *
     * @param string $local_path  Absolute path to the file on disk.
     * @param string $remote_key  Object key inside the bucket (e.g. '2024/01/photo.jpg').
     *
     * @return WP_Error|array WP_Error on failure; array{ key: string, url: string } on success.
     */
    public function upload_file(string $local_path, string $remote_key)
    {
        if (!file_exists($local_path) || !is_readable($local_path)) {
            return new WP_Error(
                'r2_file_not_found',
                sprintf('Cannot read local file: %s', $local_path)
            );
        }

        $body = $this->read_file_contents($local_path);
        if ($body === false) {
            return new WP_Error(
                'r2_file_read_error',
                sprintf('Could not read file: %s', $local_path)
            );
        }

        // Detect MIME type; fall back to safe default.
        $content_type = $this->detect_mime_type($local_path);

        $result = $this->request('PUT', $remote_key, $body, $content_type);

        if (is_wp_error($result)) {
            return $result;
        }

        return array(
            'key' => $remote_key,
            'url' => $this->get_public_url($remote_key),
        );
    }

    /**
     * Delete an object from R2.
     *
     * @param string $remote_key Object key.
     *
     * @return WP_Error|bool WP_Error on failure, true on success.
     */
    public function delete_file(string $remote_key)
    {
        $result = $this->request('DELETE', $remote_key);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Build the public CDN URL for a given R2 object key.
     *
     * @param string $remote_key
     *
     * @return string
     */
    public function get_public_url(string $remote_key): string
    {
        $cdn = $this->settings->get_cdn_domain();

        if ($cdn !== '') {
            return $cdn . '/' . ltrim($remote_key, '/');
        }

        // Fallback: direct R2 endpoint URL (only works for public buckets).
        return sprintf(
            'https://%s.r2.cloudflarestorage.com/%s/%s',
            $this->settings->get_account_id(),
            $this->settings->get_bucket(),
            ltrim($remote_key, '/')
        );
    }

    /**
     * Send a HEAD request to verify credentials and bucket access.
     * Used by the admin "Test Connection" button.
     *
     * @return WP_Error|bool
     */
    public function test_connection()
    {
        $result = $this->request('HEAD', '');
        if (is_wp_error($result)) {
            return $result;
        }
        return true;
    }

    // -----------------------------------------------------------------------
    // Internal: HTTP request + AWS Sig-V4
    // -----------------------------------------------------------------------

    /**
     * Build and dispatch a signed S3-compatible request.
     *
     * @param string $method       HTTP verb: GET, PUT, DELETE, HEAD.
     * @param string $key          Object key (empty string for bucket-level ops).
     * @param string $body         Request body (for PUT).
     * @param string $content_type Content-Type header value.
     *
     * @return WP_Error|array wp_remote_request response array on success.
     */
    private function request(
        string $method,
        string $key,
        string $body = '',
        string $content_type = ''
    ) {
        $account_id = $this->settings->get_account_id();
        $access_key = $this->settings->get_access_key();
        $secret_key = $this->settings->get_secret_key();
        $bucket = $this->settings->get_bucket();

        if (!$account_id || !$access_key || !$secret_key || !$bucket) {
            return new WP_Error('r2_missing_credentials', 'R2 credentials are not fully configured.');
        }

        $host = "{$account_id}.r2.cloudflarestorage.com";
        $uri = '/' . $bucket . ($key !== '' ? '/' . ltrim($key, '/') : '');
        $query_string = '';

        $payload_hash = hash('sha256', $body);
        $datetime = gmdate('Ymd\THis\Z');
        $date = substr($datetime, 0, 8);

        // Build canonical headers (must be alphabetically sorted).
        $headers_to_sign = array(
            'host' => $host,
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date' => $datetime,
        );
        if ($content_type !== '') {
            $headers_to_sign['content-type'] = $content_type;
        }
        ksort($headers_to_sign);

        $canonical_headers = '';
        $signed_headers = '';
        foreach ($headers_to_sign as $name => $value) {
            $canonical_headers .= strtolower($name) . ':' . trim($value) . "\n";
            $signed_headers .= strtolower($name) . ';';
        }
        $signed_headers = rtrim($signed_headers, ';');

        // Canonical request.
        $canonical_request = implode("\n", array(
            $method,
            $uri,
            $query_string,
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ));

        // Credential scope.
        $credential_scope = implode('/', array($date, self::REGION, self::SERVICE, 'aws4_request'));

        // String to sign.
        $string_to_sign = implode("\n", array(
            self::ALGORITHM,
            $datetime,
            $credential_scope,
            hash('sha256', $canonical_request),
        ));

        // Signing key (HMAC chain).
        $signing_key = $this->derive_signing_key($secret_key, $date);

        // Signature.
        $signature = bin2hex(hash_hmac('sha256', $string_to_sign, $signing_key, true));

        // Authorization header.
        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        // Assemble request headers.
        $wp_headers = array(
            'Authorization' => $authorization,
            'x-amz-date' => $datetime,
            'x-amz-content-sha256' => $payload_hash,
        );
        if ($content_type !== '') {
            $wp_headers['Content-Type'] = $content_type;
        }
        if ($body !== '') {
            $wp_headers['Content-Length'] = (string) strlen($body);
        }

        $url = 'https://' . $host . $uri;

        $response = wp_remote_request($url, array(
            'method' => $method,
            'headers' => $wp_headers,
            'body' => $body,
            'timeout' => self::TIMEOUT,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        // 2xx = success (covers 200 OK and 204 No Content for DELETE).
        if ($status_code >= 200 && $status_code < 300) {
            return $response;
        }

        // Parse error from R2's S3-compatible XML response body.
        $error_body = wp_remote_retrieve_body($response);
        $error_message = $this->parse_s3_error($error_body, $status_code);

        return new WP_Error('r2_http_error', $error_message, array('status' => $status_code));
    }

    /**
     * Derive the AWS SigV4 signing key via a nested HMAC-SHA256 chain.
     *
     * @param string $secret_key Raw secret key (without "AWS4" prefix).
     * @param string $date       Date string in Ymd format.
     *
     * @return string Binary signing key.
     */
    private function derive_signing_key(string $secret_key, string $date): string
    {
        $k_date = hash_hmac('sha256', $date, 'AWS4' . $secret_key, true);
        $k_region = hash_hmac('sha256', self::REGION, $k_date, true);
        $k_service = hash_hmac('sha256', self::SERVICE, $k_region, true);
        return hash_hmac('sha256', 'aws4_request', $k_service, true);
    }

    /**
     * Extract a human-readable error message from an S3 XML error body.
     * Compatible with PHP 7.4 (no str_contains).
     *
     * @param string $xml_body
     * @param int    $status_code
     *
     * @return string
     */
    private function parse_s3_error(string $xml_body, int $status_code): string
    {
        if (strpos($xml_body, '<Message>') !== false) {
            preg_match('/<Message>(.*?)<\/Message>/s', $xml_body, $matches);
            $msg = isset($matches[1]) ? $matches[1] : $xml_body;
        } else {
            $msg = mb_substr($xml_body, 0, 500);
        }

        return sprintf('R2 responded with HTTP %d: %s', $status_code, wp_strip_all_tags($msg));
    }

    /**
     * Read file contents using a binary-safe stream.
     * Avoids loading the entire file string in one allocation like file_get_contents.
     *
     * @param string $path Absolute file path.
     *
     * @return string|false File contents, or false on read failure.
     */
    private function read_file_contents(string $path)
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $contents = stream_get_contents($handle);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);
        return $contents;
    }

    /**
     * Detect a file's MIME type using multiple fallback strategies.
     * Compatible with servers where mime_content_type() may not be available.
     *
     * @param string $path Absolute file path.
     *
     * @return string MIME type string.
     */
    private function detect_mime_type(string $path): string
    {
        // Strategy 1: PHP's built-in finfo.
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if ($mime) {
                    return $mime;
                }
            }
        }

        // Strategy 2: mime_content_type (may not exist on all hosts).
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
            if ($mime) {
                return $mime;
            }
        }

        // Strategy 3: WordPress's own file-type detection by extension.
        $wp_filetype = wp_check_filetype(wp_basename($path));
        if (!empty($wp_filetype['type'])) {
            return $wp_filetype['type'];
        }

        return 'application/octet-stream';
    }
}
