<?php
/**
 * Plugin Name: Admin WP-CLI Console
 * Description: High-efficiency WP-CLI runner with auto-detected Remi/LiquidWeb/cPanel/SiteGround PHP binary paths, robust WP-CLI binary resolution, permanent command caching, and an on-demand dropdown reference table.
 * Version: 2.3.0
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
 * Detect the actual PHP CLI binary across Remi Repository (LiquidWeb), cPanel, SiteGround, or generic environments.
 */
function wp_cli_console_get_php_binary() {
    // 1. Check Remi Repository paths (LiquidWeb / Enterprise Linux)
    $remi_paths = [
        '/opt/remi/php83/root/usr/bin/php',
        '/opt/remi/php82/root/usr/bin/php',
        '/opt/remi/php81/root/usr/bin/php',
        '/opt/remi/php80/root/usr/bin/php',
    ];

    foreach ($remi_paths as $path) {
        if (@file_exists($path) && @is_executable($path)) {
            return $path;
        }
    }

    // 2. Check LiquidWeb / cPanel EasyApache 4 MultiPHP paths
    $liquidweb_paths = [
        '/opt/cpanel/ea-php83/root/usr/bin/php',
        '/opt/cpanel/ea-php82/root/usr/bin/php',
        '/opt/cpanel/ea-php81/root/usr/bin/php',
        '/opt/cpanel/ea-php80/root/usr/bin/php',
    ];

    foreach ($liquidweb_paths as $path) {
        if (@file_exists($path) && @is_executable($path)) {
            return $path;
        }
    }

    // 3. Check SiteGround specific PHP paths
    $sg_paths = [
        '/usr/local/php83/bin/php-cli',
        '/usr/local/php83/bin/php',
        '/usr/local/php82/bin/php-cli',
        '/usr/local/php82/bin/php',
        '/usr/local/php81/bin/php-cli',
        '/usr/local/php81/bin/php',
    ];

    foreach ($sg_paths as $path) {
        if (@file_exists($path) && @is_executable($path)) {
            return $path;
        }
    }

    // 4. Fallback to PHP_BINARY only if it is NOT php-fpm
    if (defined('PHP_BINARY') && !empty(PHP_BINARY) && strpos(PHP_BINARY, 'php-fpm') === false && strpos(PHP_BINARY, 'php') !== false) {
        return PHP_BINARY;
    }

    // 5. Default system fallback
    return 'php';
}

/**
 * Locate the explicit path to the WP-CLI executable on the server.
 */
function wp_cli_console_get_wp_cli_path() {
    $common_paths = [
        '/usr/local/bin/wp',
        '/usr/bin/wp',
        '/usr/local/bin/wp-cli.phar',
        '/home/siteground/bin/wp',
    ];

    foreach ($common_paths as $path) {
        if (@file_exists($path) && @is_executable($path)) {
            return $path;
        }
    }

    // Try finding via shell 'which'
    $which_wp = trim(shell_exec('which wp 2>/dev/null'));
    if (!empty($which_wp) && @file_exists($which_wp)) {
        return $which_wp;
    }

    return 'wp';
}

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
    $cache_key = 'wp_cli_discovered_commands_v9';
    $cached = get_transient($cache_key);

    if (!$force_refresh && $cached !== false) {
        return $cached;
    }

    $discovered = [];
    $core_namespaces = ['help', 'cli', 'config', 'core', 'cron', 'db', 'embed', 'eval', 'eval-file', 'export', 'import', 'language', 'media', 'menu', 'network', 'option', 'package', 'plugin', 'post', 'post-type', 'rewrite', 'role', 'search-replace', 'server', 'sidebar', 'site', 'super-admin', 'taxonomy', 'term', 'theme', 'transient', 'user', 'widget'];
    
    $php_binary = escapeshellarg(wp_cli_console_get_php_binary());
    $wp_cli = escapeshellarg(wp_cli_console_get_wp_cli_path());
    $wp_path = escapeshellarg(ABSPATH);

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
    } else {
        $raw_help = shell_exec("WP_CLI_PHP={$php_binary} {$php_binary} {$wp_cli} help --path={$wp_path} 2>&1");

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
                $raw_sub_help = shell_exec("WP_CLI_PHP={$php_binary} {$php_binary} {$wp_cli} help " . escapeshellcmd($name) . " --path={$wp_path} 2>&1");
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

    if (isset($_POST['refresh_cli_discovery']) && check_admin_referer('refresh_cli_action', 'refresh_cli_nonce')) {
        wp_cli_console_get_discovered_commands(true);
        echo '<div class="notice notice-success is-dismissible"><p>WP-CLI command tree rescanned and permanently cached!</p></div>';
    }

    if (isset($_POST['clear_wp_cli_logs']) && check_admin_referer('clear_wp_cli_logs_action', 'clear_logs_nonce')) {
        update_option('wp_cli_console_logs', []);
        echo '<div class="notice notice-success is-dismissible"><p>Audit logs cleared.</p></div>';
    }

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
            $detected_php = wp_cli_console_get_php_binary();
            $php_binary = escapeshellarg($detected_php);
            $wp_cli_binary = escapeshellarg(wp_cli_console_get_wp_cli_path());

            // Build execution string: WP_CLI_PHP=/path/to/php /path/to/php /path/to/wp command --path=/path/to/wp
            $cmd = "WP_CLI_PHP={$php_binary} {$php_binary} {$wp_cli_binary} " . $clean_command . " --path={$wp_path} 2>&1";
            $raw_output = trim(shell_exec($cmd));

            $decoded = json_decode($raw_output, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $json_data = $decoded;
            }
        }
    }

    $logs = get_option('wp_cli_console_logs', []);
    $discovered_commands = wp_cli_console_get_discovered_commands();

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
        <p style="margin-bottom: 5px;">Run CLI commands directly from your dashboard.</p>
        <p style="font-size: 12px; color: #646970; margin-top: 0; margin-bottom: 20px;">
            <strong>Detected PHP Engine:</strong> <code><?php echo esc_html(wp_cli_console_get_php_binary()); ?></code> | 
            <strong>Detected WP-CLI Binary:</strong> <code><?php echo esc_html(wp_cli_console_get_wp_cli_path()); ?></code>
        </p>

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

            <div id="reference_table_container" style="display: none;">
                <table class="wp-list-table widefat fixed striped" style="margin-top: 5px;">
                    <thead>
                        <tr>
                            <th style="width: 35%;"><strong>Command (Click to Populate)</strong></th>
                            <th><strong>Description</strong></th>
                        </tr>
                    </thead>
                    <tbody id="reference_table_body"></tbody>
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
