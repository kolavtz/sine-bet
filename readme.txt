=== WP R2 Offload ===
Contributors: Saurav Bhattarai
Tags: cloudflare, r2, cdn, media, offload, s3
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically offloads WordPress Media Library uploads to Cloudflare R2 and optionally serves them via a CDN URL.

== Description ==

**WP R2 Offload** intercepts every file added to the WordPress Media Library and uploads a copy to your Cloudflare R2 bucket using Cloudflare's S3-compatible API (AWS Signature Version 4). No external PHP libraries or SDKs are required — everything runs through WordPress's built-in HTTP client.

**Key features:**

* Zero-dependency upload — pure AWS Sig-V4 via `wp_remote_request()`
* Stores the R2 object key and CDN URL in post meta
* Optional built-in URL rewriting (`wp_get_attachment_url`, `the_content`, `srcset`)
* Admin settings page with "Test Connection" button
* Paginated, filterable offload log in the admin dashboard
* Retry-failed-uploads bulk action
* Clean uninstall: removes all options and DB tables
* Credentials can be stored in `wp-config.php` constants for extra security

== Installation ==

1. Upload the `wp-r2-offload` folder to `/wp-content/plugins/`.
2. Activate the plugin via the **Plugins** admin screen.
3. Go to **R2 Offload → Settings** and enter your Cloudflare R2 credentials:
   - **Account ID** — found in the Cloudflare dashboard URL
   - **Access Key ID** & **Secret Access Key** — create an R2 API token in Cloudflare → R2 → Manage R2 API tokens
   - **Bucket Name** — the name of your R2 bucket
   - **CDN Domain** — e.g. `https://cdn.yourdomain.com` (optional, for public CDN delivery)
4. Click **Save Settings**, then **Test Connection** to verify your credentials.

**Optional: Store credentials in wp-config.php**

For better security, define constants before the `/* That's all, stop editing! */` line:

```php
define( 'WP_R2_ACCOUNT_ID', 'your-account-id' );
define( 'WP_R2_ACCESS_KEY', 'your-access-key-id' );
define( 'WP_R2_SECRET_KEY', 'your-secret-access-key' );
define( 'WP_R2_BUCKET',     'your-bucket-name' );
define( 'WP_R2_CDN_DOMAIN', 'https://cdn.yourdomain.com' );
```

When constants are defined they take precedence over the database-stored values.

== Frequently Asked Questions ==

= Does this plugin delete local files after uploading to R2? =
No. The plugin **only copies** files to R2. Your local WordPress uploads are preserved.

= What happens if an upload fails? =
The failure is logged to the R2 Offload → Logs screen. WordPress continues to work normally using the local file. You can bulk-retry failed uploads from the Logs tab.

= Can I use a custom CDN domain in front of R2? =
Yes. Set your CDN domain in the settings. The plugin stores the CDN URL in post meta. You can also leave URL rewriting disabled and use a separate CDN/caching plugin for URL substitution.

= Is URL rewriting enabled by default? =
No. It is opt-in. Enable it in R2 Offload → Settings if you want `wp_get_attachment_url()`, post content, and srcset attributes to return CDN URLs.

== Screenshots ==

1. Settings page — credentials and options.
2. Logs page — filterable offload history with error/success badges.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release. No upgrade steps required.
