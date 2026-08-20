=== Admin WP-CLI Console ===
Contributors: custom
Tags: wp-cli, console, admin, terminal, maintenance
Requires at least: 5.0
Tested up to: 6.6
Stable tag: 1.8.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Execute WP-CLI commands directly from your WordPress dashboard with zero-latency caching, auto-discovered plugin commands, and safety confirmations.

== Description ==

Admin WP-CLI Console brings the power of the WordPress Command Line Interface (WP-CLI) directly into your WordPress admin panel. Built for site administrators, agencies, and developers, this plugin lets you run CLI commands without requiring SSH or cPanel terminal access.

= Key Features =

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
Yes. The web server host must have the `wp` CLI binary installed in its system PATH and PHP execution functions (`shell_exec` / `exec`) enabled.

= How do I render clean HTML tables instead of raw terminal text? =
Append `--format=json` to list commands (e.g., `plugin list --format=json` or `user list --format=json`) and the plugin will automatically parse the JSON into a styled WordPress admin table.

= Why does page loading stay fast even with dozens of active plugins? =
The plugin permanently caches all discovered subcommands in a WordPress transient (`0` expiration). It only executes the command inspection script when you manually click the **Rescan WP-CLI Commands** button.

= Is this plugin secure for multi-admin sites? =
Yes. It uses strict capability checks (`administrator`), incorporates WordPress security nonces for cross-site request forgery protection, logs every command executed by timestamp and username, and alerts the admin before running high-risk destructive commands.

== Changelog ==

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
