=== Admin WP-CLI Console ===
Contributors: custom
Tags: wp-cli, console, admin, terminal, maintenance
Requires at least: 5.0
Tested up to: 6.6
Stable tag: 1.3.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Execute WP-CLI commands directly from your WordPress admin dashboard with quick actions, active plugin cheatsheets, and audit logging.

== Description ==

Admin WP-CLI Console brings the power of the WordPress Command Line Interface (WP-CLI) directly into your WordPress dashboard. Built for site administrators, agencies, and developers, this plugin lets you run CLI commands without requiring SSH or cPanel terminal access.

= Key Features =

* **Dedicated Admin Page:** Integrated directly into the main admin sidebar.
* **Smart Output Rendering:** Renders text in a high-contrast terminal window or parses `--format=json` outputs into native WordPress HTML tables.
* **Active Plugin CLI Intelligence:** Auto-detects supported plugins (WooCommerce, Elementor, WP Rocket, Yoast SEO, Gravity Forms) and displays click-to-run command cheatsheets.
* **Add-On Recommendations:** Recommends missing CLI extensions (like the Gravity Forms CLI add-on) when parent plugins are detected.
* **Quick Action Buttons:** One-click shortcuts for routine tasks like flushing cache, clearing transients, and optimizing the database.
* **Command Audit Log:** Keeps a lightweight log tracking timestamps, users, and executed commands (automatically capped at 50 entries).
* **Strict Role Security:** Restricted strictly to full Administrator privileges to prevent access by lower roles.

== Installation ==

1. Download or clone the plugin repository into your `/wp-content/plugins/` directory:
   `/wp-content/plugins/wp-cli-console/`
2. Ensure the main plugin file is named `wp-cli-executor.php`.
3. Log into your WordPress Dashboard and navigate to **Plugins > Installed Plugins**.
4. Locate **Admin WP-CLI Console** and click **Activate**.
5. Access the console via the **WP-CLI Console** menu item in your sidebar.

== Frequently Asked Questions ==

= Does this plugin require WP-CLI to be installed on the server? =
Yes. The web server must have the `wp` CLI binary installed in its system PATH and PHP execution functions (`shell_exec` / `exec`) enabled.

= How do I render clean HTML tables instead of plain text? =
Append `--format=json` to list commands (e.g., `plugin list --format=json` or `user list --format=json`) and the plugin will automatically parse the JSON into a styled WordPress admin table.

= Is this plugin secure for multi-admin sites? =
Yes. It uses strict capability checks (`administrator`), incorporates WordPress security nonces for cross-site request forgery protection, and logs every command executed by timestamp and username.

== Changelog ==

= 1.3.0 =
* Added active plugin CLI detection and dynamic command cheatsheets.
* Added CLI add-on recommendation banners.

= 1.2.0 =
* Moved console to a top-level admin menu item.
* Added Quick Action buttons.
* Added 50-entry command audit log stored in `wp_options`.

= 1.1.0 =
* Added automatic JSON-to-HTML table rendering fallback.

= 1.0.0 =
* Initial release with secure command execution and output styling.
