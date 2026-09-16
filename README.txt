=== Admin WP-CLI Console ===
Contributors: custom
Tags: wp-cli, console, admin, terminal, maintenance
Requires at least: 5.0
Tested up to: 6.6
Stable tag: 2.3.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Execute WP-CLI commands directly from your WordPress dashboard with multi-environment PHP detection, zero-latency caching, auto-discovered plugin commands, and safety confirmations.

== Description ==

Admin WP-CLI Console brings the power of the WordPress Command Line Interface (WP-CLI) directly into your WordPress admin panel. Built for site administrators, agencies, and developers, this plugin lets you run CLI commands without requiring SSH or cPanel terminal access.

= Key Features =

* **Multi-Host PHP Engine Auto-Detection:** Automatically locates and forces execution through your active web server PHP CLI binary (supporting Remi Repository, LiquidWeb MultiPHP, cPanel EasyApache 4, SiteGround, and custom environments) to prevent version mismatch errors (e.g., executing on system PHP 7.1 instead of 8.3).
* **Robust WP-CLI Binary Resolver:** Dynamically resolves physical paths to the `wp` or `wp-cli.phar` executable on the host server.
* **Top-Level Input Form:** Primary command bar positioned at the top of the dashboard for rapid execution.
* **On-Demand Dropdown Helper:** High-density, 2-column reference table (`Command` | `Description`) populated via an interactive dropdown menu to keep the UI clean.
* **Zero-Latency Permanent Caching:** Auto-discovered plugin subcommands are permanently cached in WordPress transients so page loads remain instantaneous. Re-scan on demand with a single click.
* **Smart Output Rendering:** Renders text in a high-contrast dark terminal window or automatically parses `--format=json` outputs into native WordPress HTML tables.
* **Destructive Command Confirmation:** Client-side JavaScript detection intercepts high-risk commands (e.g., `db drop`, `db reset`, `site empty`, `search-replace`) and prompts for explicit confirmation before non-interactive execution (`--yes`).
* **Command Audit Log:** Lightweight `wp_options` log tracking timestamps, users, and executed commands (automatically capped at 50 entries).
* **Strict Administrator Role Security:** Restricted strictly to full Administrator privileges (`manage_options` / `administrator`) to block access by lower roles.

== Installation ==

1. Download or clone the plugin repository into your `/wp-content/plugins/` directory:
   `/wp-content/plugins/wp-cli-console/`
2. Ensure the main plugin file is named `wp-cli-executor.php`.
3. Log into your WordPress Dashboard and navigate to **Plugins > Installed Plugins**.
4. Locate **Admin WP-CLI Console** and click **Activate**.
5. Access the console via the **WP-CLI Console** menu item in your main sidebar.

== Frequently Asked Questions ==

= Does this plugin require WP-CLI to be installed on the server? =
Yes. The web server host must have the `wp` CLI binary installed in its system PATH (or standard binary directories) and PHP execution functions (`shell_exec` / `exec`) enabled.

= Why does it show my detected PHP engine and WP-CLI binary at the top of the page? =
Web server environments (like LiquidWeb, cPanel, or SiteGround) often run a different PHP version for HTTP requests than the default CLI terminal user. The console detects your active web server PHP CLI binary (e.g., `/opt/remi/php83/root/usr/bin/php`) and environment binary path to guarantee commands execute under your intended PHP version rather than a legacy system fallback.

= What is the minimum PHP version required? =
While the plugin code itself requires PHP 7.4 or higher, it dynamically binds execution to whatever PHP version your site is running under (up to PHP 8.3+).

= How do I render clean HTML tables instead of raw terminal text? =
Append `--format=json` to list commands (e.g., `plugin list --format=json` or `user list --format=json`) and the plugin will automatically parse the JSON into a styled WordPress admin table.

= Why does page loading stay fast even with dozens of active plugins? =
The plugin permanently caches all discovered subcommands in a WordPress transient (`0` expiration). It only executes the command inspection script when you manually click the **Rescan WP-CLI Commands** button.

= Is this plugin secure for multi-admin sites? =
Yes. It uses strict capability checks (`administrator`), incorporates WordPress security nonces for cross-site request forgery protection, logs every command executed by timestamp and username, and alerts the admin before running high-risk destructive commands.

== Changelog ==

= 2.3.0 =
* Added dynamic WP-CLI binary path resolver (`wp_cli_console_get_wp_cli_path()`) to fix `Could not open input file` execution errors across cPanel/LiquidWeb hosts.
* Added environmental diagnostic header displaying detected PHP Engine and WP-CLI binary paths.

= 2.2.0 =
* Added Remi Repository PHP paths (`/opt/remi/php8*/root/usr/bin/php`) for LiquidWeb and Enterprise Linux environments.
* Added filter to prevent `PHP_BINARY` from accidentally resolving to `php-fpm`.

= 2.1.0 =
* Added LiquidWeb / cPanel EasyApache 4 MultiPHP path auto-detection (`/opt/cpanel/ea-php*/root/usr/bin/php`).

= 1.8.0 =
* Updated discovery transient caching to permanent mode (`0` expiration) to eliminate page-load delays. Command re-scans are now strictly on-demand.

= 1.7.0 =
* Moved command input box to the very top of the interface.
* Converted command grid into an on-demand dropdown selector with a compact 2-column reference table.

= 1.6.0 =
* Replaced text parsing with direct PHP introspection (`WP_CLI::get_root_command()`) to ensure 100% accurate subcommand names (underscores vs. hyphens).

= 1.4.0 =
* Added JavaScript pop-up confirmations for high-risk commands (`db drop`, `site empty`, `search-replace`, etc.) and auto-appended `--yes` flags.

= 1.2.0 =
* Moved console to a top-level admin menu item.
* Added 50-entry command audit log stored in `wp_options`.

= 1.0.0 =
* Initial release with secure command execution and output styling.
