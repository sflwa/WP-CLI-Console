<?php
/**
 * Plugin Name: Admin WP-CLI Console
 * Description: High-efficiency WP-CLI runner featuring permanent command caching (manual rescan only), top-level command input, and an on-demand dropdown reference table.
 * Version: 1.8.0
 * Author: Custom
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function() {
    add_menu_page(
        'WP-CLI Console',
        'WP-CLI Console',
        'administrator',
        'wp-cli-console',
        'wp_cli_console_render_page',
        'dashicons-terminal',
        80
    );
});

/**
 * Audit Log Helper
 */
function wp_cli_console_log_command($command) {
    $current_user = wp_get_current_user();
    $logs = get_option('wp_cli_console_logs', []);
    if (!is_array($logs)) $logs = [];

    array_unshift($logs, [
        'timestamp' => current_time('Y-m-d H:i:s'),
        'user'      => $current_user->user_login,
        'command'   => $command
    ]);

    $logs = array_slice($logs, 0, 50);
    update_option('wp_cli_console_logs', $logs, false);
}

/**
 * Dynamic Discovery Engine (Permanent Cache with Manual Refresh)
 */
function wp_cli_console_get_discovered_commands($force_refresh = false) {
    $cache_key = 'wp_cli_discovered_commands_v4';
    $cached = get_transient($cache_key);

    // Serve from cache indefinitely unless a manual rescan is triggered
    if (!$force_refresh && $cached !== false) {
        return $cached;
    }

    $discovered = [];
    $core_namespaces = ['help', 'cli', 'config', 'core', 'cron', 'db', 'embed', 'eval', 'eval-file', 'export', 'import', 'language', 'media', 'menu', 'network', 'option', 'package', 'plugin', 'post', 'post-type', 'rewrite', 'role', 'search-replace', 'server', 'sidebar', 'site', 'super-admin', 'taxonomy', 'term', 'theme', 'transient', 'user', 'widget'];

    // Method A: Direct PHP Reflection if WP_CLI is loaded
    if (class_exists('WP_CLI')) {
        $root = WP_CLI::get_root_command();
        $subcommands = $root->get_subcommands();

        foreach ($subcommands as $name => $cmd_obj) {
            if (in_array($name, $core_namespaces, true)) continue;

            $sub_list = [];
            if (method_exists($cmd_obj, 'get_subcommands')) {
                foreach ($cmd_obj->get_subcommands() as $sub_name => $sub_obj) {
                    $sub_list[] = [
                        'command'     => $name . ' ' . $sub_name,
                        'description' => method_exists($sub_obj, 'get_shortdesc') ? $sub_obj->get_shortdesc() : ''
                    ];
                }
            }

            $discovered[$name] = [
                'description' => method_exists($cmd_obj, 'get_shortdesc') ? $cmd_obj->get_shortdesc() : '',
                'subcommands' => $sub_list
            ];
        }
    } 
    // Method B: Parse 'wp help' text blocks via shell_exec
    else {
        $wp_path = escapeshellarg(ABSPATH);
        $raw_help = shell_exec("wp help --path={$wp_path} 2>&1");

        if (!empty($raw_help) && preg_match('/SUBCOMMANDS\s*\n\n(.*?)\n\nGLOBAL PARAMETERS/s', $raw_help, $matches)) {
            $subcommands_block = $matches[1];
            $lines = explode("\n", $subcommands_block);

            $current_cmd = '';
            $current_desc = '';

            foreach ($lines as $line) {
                if (preg_match('/^\s{2}([a-z0-9_\-]+)\s+(.*)$/', $line, $item)) {
                    if (!empty($current_cmd) && !in_array($current_cmd, $core_namespaces, true)) {
                        $discovered[$current_cmd] = [
                            'description' => trim($current_desc),
                            'subcommands' => []
                        ];
                    }
                    $current_cmd = trim($item[1]);
                    $current_desc = trim($item[2]);
                } elseif (preg_match('/^\s{25,}(.*)$/', $line, $continuation)) {
                    $current_desc .= ' ' . trim($continuation[1]);
                }
            }

            if (!empty($current_cmd) && !in_array($current_cmd, $core_namespaces, true)) {
                $discovered[$current_cmd] = [
                    'description' => trim($current_desc),
                    'subcommands' => []
                ];
            }

            foreach ($discovered as $name => $data) {
                $raw_sub_help = shell_exec("wp help " . escapeshellcmd($name) . " --path={$wp_path} 2>&1");
                if (preg_match('/SUBCOMMANDS\s*\n\n(.*?)\n\n/s', $raw_sub_help, $sub_matches)) {
                    $sub_lines = explode("\n", $sub_matches[1]);
                    $sub_list = [];

                    foreach ($sub_lines as $sub_line) {
                        if (preg_match('/^\s{2}([a-z0-9_\-]+)\s+(.*)$/', $sub_line, $sub_item)) {
                            $sub_list[] = [
                                'command'     => $name . ' ' . trim($sub_item[1]),
                                'description' => trim($sub_item[2])
                            ];
                        }
                    }
                    $discovered[$name]['subcommands'] = $sub_list;
                }
            }
        }
    }

    // Set transient to NEVER expire automatically (0 = no expiration)
    set_transient($cache_key, $discovered, 0);

    return $discovered;
}

/**
 * Main Page Render
 */
function wp_cli_console_render_page() {
    if (!current_user_can('administrator')) {
        wp_die(__('Access denied. Full Administrator rights required.', 'wp-cli-console'));
    }

    $raw_output = '';
    $json_data = null;
    $last_command = '';

    // Handle Manual Rescan Request
    if (isset($_POST['refresh_cli_discovery']) && check_admin_referer('refresh_cli_action', 'refresh_cli_nonce')) {
        wp_cli_console_get_discovered_commands(true);
        echo '<div class="notice notice-success is-dismissible"><p>WP-CLI command tree rescanned and permanently cached!</p></div>';
    }

    // Handle Clearing Logs
    if (isset($_POST['clear_wp_cli_logs']) && check_admin_referer('clear_wp_cli_logs_action', 'clear_logs_nonce')) {
        update_option('wp_cli_console_logs', []);
        echo '<div class="notice notice-success is-dismissible"><p>Audit logs cleared.</p></div>';
    }

    // Handle Command Execution
    if (isset($_POST['wp_cli_command']) && check_admin_referer('run_wp_cli_action', 'wp_cli_nonce')) {
        $raw_input = trim($_POST['wp_cli_command']);
        $last_command = $raw_input;
        $clean_command = preg_replace('/^wp\s+/', '', $raw_input);

        if (!empty($clean_command)) {
            $destructive_patterns = ['db drop', 'db reset', 'site empty', 'plugin delete', 'theme delete', 'user delete', 'search-replace'];
            $is_destructive = false;

            foreach ($destructive_patterns as $pattern) {
                if (strpos($clean_command, $pattern) !== false) {
                    $is_destructive = true;
                    break;
                }
            }

            if ($is_destructive && strpos($clean_command, '--yes') === false) {
                $clean_command .= ' --yes';
            }

            wp_cli_console_log_command($raw_input);

            $wp_path = escapeshellarg(ABSPATH);
            $cmd = "wp " . $clean_command . " --path={$wp_path} 2>&1";
            $raw_output = trim(shell_exec($cmd));

            $decoded = json_decode($raw_output, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $json_data = $decoded;
            }
        }
    }

    $logs = get_option('wp_cli_console_logs', []);
    $discovered_commands = wp_cli_console_get_discovered_commands();

    // Default Core Shortcuts List
    $core_shortcuts = [
        ['command' => 'cache flush', 'description' => 'Flush object cache'],
        ['command' => 'plugin list --format=json', 'description' => 'List all plugins in table view'],
        ['command' => 'transient delete --all', 'description' => 'Delete all expired transients'],
        ['command' => 'db optimize', 'description' => 'Optimize site database tables'],
        ['command' => 'cli version', 'description' => 'Display current WP-CLI version']
    ];
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-terminal" style="font-size:30px; width:30px; height:30px;"></span> WP-CLI Console</h1>
        <p style="margin-bottom: 20px;">Run CLI commands directly from your dashboard.</p>

        <!-- 1. PRIMARY INPUT FORM (TOP OF PAGE) -->
        <form method="post" action="" id="wp_cli_form" onsubmit="return validateCommandExecution();">
            <?php wp_nonce_field('run_wp_cli_action', 'wp_cli_nonce'); ?>
            <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 20px; background: #fff; padding: 15px; border: 1px solid #c3c4c7; border-radius: 4px;">
                <span style="font-family: monospace; font-weight: bold; font-size: 18px;">wp</span>
                <input type="text" id="wp_cli_command" name="wp_cli_command" class="regular-text" style="width: 80%; font-family: monospace; font-size: 15px; padding: 6px 10px;" value="<?php echo esc_attr($last_command); ?>" placeholder="plugin list --format=json" autofocus required>
                <?php submit_button('Execute', 'primary', 'submit', false, ['style' => 'padding: 4px 20px; font-size: 14px;']); ?>
            </div>
        </form>

        <!-- 2. OUTPUT DISPLAY AREA -->
        <?php if (!empty($json_data)): ?>
            <h2>Output (Formatted Table):</h2>
            <table class="wp-list-table widefat fixed striped" style="margin-bottom: 25px;">
                <thead>
                    <tr>
                        <?php foreach (array_keys(reset($json_data)) as $column_name): ?>
                            <th><strong><?php echo esc_html(strtoupper($column_name)); ?></strong></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($json_data as $row): ?>
                        <tr>
                            <?php foreach ($row as $value): ?>
                                <td><?php echo esc_html(is_array($value) ? wp_json_encode($value) : $value); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif (!empty($raw_output)): ?>
            <h2>Output:</h2>
            <pre style="background: #1e1e1e; color: #00ff00; padding: 15px; border-radius: 4px; overflow-x: auto; font-family: 'Courier New', Courier, monospace; font-size: 13px; line-height: 1.25; white-space: pre; max-height: 450px; margin-bottom: 25px;"><?php echo esc_html($raw_output); ?></pre>
        <?php endif; ?>

        <!-- 3. COMPACT DROPDOWN REFERENCE SELECTOR -->
        <div class="card" style="max-width: 100%; margin-bottom: 25px; padding: 15px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <h2 style="margin: 0;">Command Helper & Reference</h2>
                <form method="post" action="" style="margin:0;">
                    <?php wp_nonce_field('refresh_cli_action', 'refresh_cli_nonce'); ?>
                    <input type="submit" name="refresh_cli_discovery" class="button button-secondary button-small" value="Rescan WP-CLI Commands">
                </form>
            </div>

            <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 15px;">
                <label for="command_group_select" style="font-weight: 600;">Select Command Group / Plugin:</label>
                <select id="command_group_select" onchange="renderCommandTable(this.value);" style="max-width: 350px;">
                    <option value="">-- Choose Group or Plugin --</option>
                    <option value="core_shortcuts">Core Shortcuts</option>
                    <?php foreach ($discovered_commands as $namespace => $data): ?>
                        <option value="<?php echo esc_attr($namespace); ?>"><?php echo esc_html(strtoupper($namespace)); ?> Commands</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Dynamic 2-Column Reference Table -->
            <div id="reference_table_container" style="display: none;">
                <table class="wp-list-table widefat fixed striped" style="margin-top: 5px;">
                    <thead>
                        <tr>
                            <th style="width: 35%;"><strong>Command (Click to Populate)</strong></th>
                            <th><strong>Description</strong></th>
                        </tr>
                    </thead>
                    <tbody id="reference_table_body">
                        <!-- Populated via Javascript -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 4. AUDIT LOG -->
        <hr style="margin: 25px 0;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h2>Command Audit Log (Last 50 Commands)</h2>
            <?php if (!empty($logs)): ?>
                <form method="post" action="" style="margin: 0;">
                    <?php wp_nonce_field('clear_wp_cli_logs_action', 'clear_logs_nonce'); ?>
                    <input type="submit" name="clear_wp_cli_logs" class="button button-secondary button-small" value="Clear Log" onclick="return confirm('Clear audit log?');">
                </form>
            <?php endif; ?>
        </div>

        <?php if (!empty($logs)): ?>
            <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th style="width: 180px;"><strong>Timestamp</strong></th>
                        <th style="width: 150px;"><strong>User</strong></th>
                        <th><strong>Executed Command</strong></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><code><?php echo esc_html($log['timestamp']); ?></code></td>
                            <td><strong><?php echo esc_html($log['user']); ?></strong></td>
                            <td><code style="background: #f0f0f1; padding: 2px 6px; border-radius: 3px;">wp <?php echo esc_html($log['command']); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p><em>No commands executed yet.</em></p>
        <?php endif; ?>
    </div>

    <!-- DATA STORE FOR JAVASCRIPT -->
    <script>
    const cliCommandData = {
        core_shortcuts: <?php echo json_encode($core_shortcuts); ?>,
        <?php foreach ($discovered_commands as $namespace => $data): ?>
            "<?php echo esc_js($namespace); ?>": [
                <?php if (!empty($data['subcommands'])): ?>
                    <?php foreach ($data['subcommands'] as $sub): ?>
                        {
                            command: "<?php echo esc_js($sub['command']); ?>",
                            description: "<?php echo esc_js($sub['description']); ?>"
                        },
                    <?php endforeach; ?>
                <?php else: ?>
                    {
                        command: "<?php echo esc_js($namespace); ?> --help",
                        description: "<?php echo esc_js($data['description'] ?: 'View plugin help documentation.'); ?>"
                    }
                <?php endif; ?>
            ],
        <?php endforeach; ?>
    };

    function renderCommandTable(groupKey) {
        const container = document.getElementById('reference_table_container');
        const tbody = document.getElementById('reference_table_body');
        
        if (!groupKey || !cliCommandData[groupKey]) {
            container.style.display = 'none';
            tbody.innerHTML = '';
            return;
        }

        const items = cliCommandData[groupKey];
        let html = '';

        items.forEach(item => {
            html += `
                <tr>
                    <td style="padding: 6px 10px;">
                        <a href="javascript:void(0);" onclick="populateCommand('${escapeJsString(item.command)}');" style="font-family: monospace; font-weight: bold; text-decoration: none;">
                            wp ${escapeHtml(item.command)}
                        </a>
                    </td>
                    <td style="padding: 6px 10px; color: #50575e; font-size: 13px;">
                        ${escapeHtml(item.description)}
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
        container.style.display = 'block';
    }

    function populateCommand(cmd) {
        document.getElementById('wp_cli_command').value = cmd;
        document.getElementById('wp_cli_command').focus();
    }

    function validateCommandExecution() {
        const cmdInput = document.getElementById('wp_cli_command').value.trim().toLowerCase();
        const dangerousCommands = ['db drop', 'db reset', 'site empty', 'plugin delete', 'theme delete', 'user delete', 'search-replace'];

        for (let i = 0; i < dangerousCommands.length; i++) {
            if (cmdInput.includes(dangerousCommands[i])) {
                return confirm("⚠️ HIGH RISK COMMAND DETECTED!\n\nYou are about to execute: 'wp " + cmdInput + "'\n\nAre you sure you want to proceed?");
            }
        }
        return true;
    }

    function escapeHtml(str) {
        return (str || '').replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
    }

    function escapeJsString(str) {
        return (str || '').replace(/'/g, "\\'");
    }
    </script>
    <?php
}
