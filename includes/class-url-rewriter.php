<?php
/**
 * URL Rewriter — optionally rewrites WordPress media URLs to CDN equivalents.
 *
 * Only active when "Enable URL Rewriting" is checked in settings. Filters:
 *   - wp_get_attachment_url       (direct URL lookup)
 *   - wp_calculate_image_srcset   (responsive image srcset attributes)
 *   - the_content                 (embedded image / link URLs in post body)
 *
 * @package WP_R2_Offload
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_R2_URL_Rewriter
 */
class WP_R2_URL_Rewriter
{

    /** @var WP_R2_Settings */
    private WP_R2_Settings $settings;

    /** @var string Cached local uploads base URL, used for content rewriting. */
    private string $uploads_base_url = '';

    public function __construct(WP_R2_Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Register filter hooks.
     *
     * @return void
     */
    public function register_hooks(): void
    {
        add_filter('wp_get_attachment_url', [$this, 'filter_attachment_url'], 20, 2);
        add_filter('wp_calculate_image_srcset', [$this, 'filter_srcset'], 20, 5);
        add_filter('the_content', [$this, 'filter_content'], 20);
    }

    // -----------------------------------------------------------------------
    // Filter callbacks
    // -----------------------------------------------------------------------

    /**
     * Rewrite a single attachment URL to its CDN equivalent.
     *
     * @param string $url         Current URL returned by WordPress.
     * @param int    $attachment_id
     *
     * @return string
     */
    public function filter_attachment_url(string $url, int $attachment_id): string
    {
        $cdn_url = get_post_meta($attachment_id, '_r2_cdn_url', true);

        if ($cdn_url) {
            return (string) $cdn_url;
        }

        return $url;
    }

    /**
     * Rewrite URLs inside a srcset array returned by wp_calculate_image_srcset.
     *
     * Each item in $sources is: [ 'url' => ..., 'descriptor' => ..., 'value' => ... ]
     *
     * @param array|false    $sources       Srcset sources.
     * @param array          $size_array    [ width, height ].
     * @param string         $image_src     Full src URL of the image.
     * @param array          $image_meta    Attachment image metadata.
     * @param int            $attachment_id
     *
     * @return array|false
     */
    public function filter_srcset($sources, array $size_array, string $image_src, array $image_meta, int $attachment_id)
    {
        if (!is_array($sources)) {
            return $sources;
        }

        $cdn_domain = $this->settings->get_cdn_domain();
        $uploads_url = $this->get_uploads_base_url();

        if (!$cdn_domain || !$uploads_url) {
            return $sources;
        }

        foreach ($sources as &$source) {
            if (isset($source['url']) && strpos($source['url'], $uploads_url) === 0) {
                $source['url'] = str_replace($uploads_url, $cdn_domain, $source['url']);
            }
        }

        return $sources;
    }

    /**
     * Rewrite all local upload URLs inside post content to their CDN equivalents.
     *
     * This approach performs a simple string replacement of the uploads base URL
     * with the CDN domain — it is fast and does not rely on parsing HTML.
     *
     * @param string $content Post content.
     *
     * @return string
     */
    public function filter_content(string $content): string
    {
        $cdn_domain = $this->settings->get_cdn_domain();
        $uploads_url = $this->get_uploads_base_url();

        if (!$cdn_domain || !$uploads_url || $content === '') {
            return $content;
        }

        return str_replace($uploads_url, $cdn_domain, $content);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Return the site's uploads directory base URL (no trailing slash).
     * Result is cached for the current request.
     *
     * @return string
     */
    private function get_uploads_base_url(): string
    {
        if ($this->uploads_base_url === '') {
            $upload_dir = wp_upload_dir();
            $this->uploads_base_url = untrailingslashit($upload_dir['baseurl']);
        }

        return $this->uploads_base_url;
    }
}
