# WP R2 Offload

**WP R2 Offload** is a lightweight, zero-dependency WordPress plugin that automatically offloads your Media Library uploads to **Cloudflare R2** and serves them via a custom CDN domain.

---

## 🚀 What is it?
This plugin is designed for WordPress site owners who want to leverage Cloudflare R2's S3-compatible storage to reduce server storage usage and improve media delivery speeds. Unlike other bulky solutions, it is built to be minimal, secure, and highly efficient.

## ✨ What it Does
- **Automatic Offloading**: Intercepts files added to the WordPress Media Library and uploads them to Cloudflare R2.
- **CDN Integration**: Replaces local media URLs with your Cloudflare CDN URLs.
- **Zero External Dependencies**: Does not require any external PHP SDKs; it uses WordPress's native `wp_remote_request()`.
- **Security-First**: Credentials can be optionally defined in `wp-config.php` to keep them out of the database.
- **Management Tools**: Includes a filterable log of offloads, retry mechanisms for failed uploads, and a "Test Connection" feature.

## 🛠 How it Works
1. **Interception**: The plugin hooks into WordPress's media handling process (`wp_generate_attachment_metadata`).
2. **Signature V4 Auth**: It generates AWS Signature Version 4 headers to authenticate with Cloudflare R2's S3-compatible API.
3. **Storage**: Files are uploaded to R2, and the R2 object keys and CDN URLs are stored in the WordPress post meta.
4. **URL Rewriting**: If enabled, the plugin dynamically filters `wp_get_attachment_url` and post content to serve media from your CDN.

---

## 📦 Installation

1. Upload the `wp-r2-offload` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **R2 Offload → Settings** in your admin dashboard.
4. Configure your Cloudflare R2 credentials:
   - **Account ID** (found in your Cloudflare dashboard URL)
   - **Access Key ID** & **Secret Access Key** (created in Cloudflare → R2 → Manage R2 API tokens)
   - **Bucket Name**
   - **CDN Domain** (e.g., `https://cdn.yourdomain.com`)
5. Click **Save Settings** and then **Test Connection**.

## 🔐 Advanced Configuration
For better security, you can define your credentials in `wp-config.php`:

```php
define( 'WP_R2_ACCOUNT_ID', 'your-account-id' );
define( 'WP_R2_ACCESS_KEY', 'your-access-key-id' );
define( 'WP_R2_SECRET_KEY', 'your-secret-access-key' );
define( 'WP_R2_BUCKET',     'your-bucket-name' );
define( 'WP_R2_CDN_DOMAIN', 'https://cdn.yourdomain.com' );
```

---

## ❓ FAQ

**Does this delete local files?**  
No. The plugin copies files to R2 while keeping the local copies on your server as a fallback.

**What happens if an upload fails?**  
Failures are logged in the **Logs** tab. WordPress will continue to serve the local file, and you can retry the upload manually.

**Is URL rewriting enabled by default?**  
No. You must manually enable URL rewriting in the settings to start serving media from your CDN.

---

## ⚖️ License
This project is licensed under the GPL v2 or later.
