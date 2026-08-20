<?php
/**
 * Plugin Name: Admin WP-CLI Console
 * Description: Run WP-CLI commands safely with auto-detected cheatsheets, command audit logs, and destructive command confirmations.
 * Version: 1.4.0
 * Author: Custom
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('is_plugin_active')) {
    include_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

/**
 * Register Top-Level Menu
 */
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
 * Helper to log executed commands
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
 * Inspect installed plugins and return CLI command cheatsheets & recommendations
 */
function wp_cli_console_detect_cli_capabilities() {
    $detected = [
        'cheatsheets'     => [],
        'recommendations' => []
    ];

    // Gravity Forms
    if (class_exists('GFForms')) {
        $gf_cli_active = is_plugin_active('gravityformscli/gravityformscli.php') || class_exists('GF_CLI');
        if ($gf_cli_active) {
            $detected['cheatsheets']['Gravity Forms'] = [
                'wp gf form list --format=json' => 'List all forms',
                'wp gf entry list 1 --format=json' => 'List entries for Form ID 1',
                'wp gf entry export 1 entries.csv' => 'Export Form 1 entries to CSV'
            ];
        } else {
            $detected['recommendations'][] = [
                'plugin' => 'Gravity Forms',
                'notice' => 'Gravity Forms is active, but the CLI Add-On is missing.',
                'command' => 'plugin install gravityformscli --activate'
            ];
        }
    }

    // WooCommerce
    if (class_exists('WooCommerce')) {
        $detected['cheatsheets']['WooCommerce'] = [
            'wc product list --format=json' => 'List products',
            'wc order list --status=processing --format=json' => 'List processing orders',
            'wc tool run clear_transients' => 'Clear WC transients'
        ];
    }

    // Elementor
    if (defined('ELEMENTOR_VERSION')) {
        $detected['cheatsheets']['Elementor'] = [
            'elementor flush-css' => 'Regenerate CSS files',
            'elementor sync-library' => 'Sync Elementor templates',
            'elementor replace-urls http://old.test http://new.com' => 'Replace URLs in Elementor'
        ];
    }

    // WP Rocket
    if (defined('WP_ROCKET_VERSION')) {
        $detected['cheatsheets']['WP Rocket'] = [
            'rocket clean' => 'Clear entire cache',
            'rocket preload' => 'Preload cache'
        ];
    }

    // Yoast SEO
    if (defined('WPSEO_VERSION')) {
        $detected['cheatsheets']['Yoast SEO'] = [
            'yoast index --reindex' => 'Reindex site SEO data',
            'yoast redirect list --format=json' => 'List Yoast redirects'
        ];
    }

    return $detected;
}

/**
 * Main Page Render
 */
function wp_cli_console_render_page() {
    if (!current_user_can('administrator')) {
        wp_die(__('Access denied. You must be a full Administrator to access this tool.', 'wp-cli-console'));
    }

    $raw_output = '';
    $json_data = null;
    $last_command = '';

    if (isset($_POST['clear_wp_cli_logs']) && check_admin_referer('clear_wp_cli_logs_action', 'clear_logs_nonce')) {
        update_option('wp_cli_console_logs', []);
        echo '<div class="notice notice-success is-dismissible"><p>Audit logs cleared.</p></div>';
    }

    if (isset($_POST['wp_cli_command']) && check_admin_referer('run_wp_cli_action', 'wp_cli_nonce')) {
        $raw_input = trim($_POST['wp_cli_command']);
        $last_command = $raw_input;
        $clean_command = preg_replace('/^wp\s+/', '', $raw_input);

        if (!empty($clean_command)) {
            // High-risk patterns that require bypassing non-interactive prompts via --yes
            $destructive_patterns = ['db drop', 'db reset', 'site empty', 'plugin delete', 'theme delete', 'user delete', 'search-replace'];
            $is_destructive = false;

            foreach ($destructive_patterns as $pattern) {
                if (strpos($clean_command, $pattern) !== false) {
                    $is_destructive = true;
                    break;
                }
            }

            // Auto-append --yes if needed so non-interactive execution doesn't hang
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
    $cli_caps = wp_cli_console_detect_cli_capabilities();
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-terminal" style="font-size:30px; width:30px; height:30px;"></span> WP-CLI Console</h1>
        <p>Run CLI commands directly from your dashboard.</p>

        <!-- Dynamic Recommendations Panel -->
        <?php if (!empty($cli_caps['recommendations'])): ?>
            <?php foreach ($cli_caps['recommendations'] as $rec): ?>
                <div class="notice notice-info" style="margin-bottom: 15px; padding: 10px 15px;">
                    <p style="margin: 0 0 8px 0;"><strong>💡 Suggestion:</strong> <?php echo esc_html($rec['notice']); ?></p>
                    <button type="button" class="button button-small button-secondary" onclick="setAndSubmitCommand('<?php echo esc_js($rec['command']); ?>');">
                        Install via CLI: <code>wp <?php echo esc_html($rec['command']); ?></code>
                    </button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Quick Actions & Dynamic Command Reference -->
        <div class="card" style="max-width: 100%; margin-bottom: 20px; padding: 15px;">
            <h2 style="margin-top: 0;">Quick Actions & Active Plugin Reference</h2>
            
            <div style="margin-bottom: 15px;">
                <strong>Core Shortcuts:</strong><br>
                <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 5px;">
                    <button type="button" class="button" onclick="setAndSubmitCommand('cache flush');">Flush Cache</button>
                    <button type="button" class="button" onclick="setAndSubmitCommand('plugin list --format=json');">Plugin List (Table)</button>
                    <button type="button" class="button" onclick="setAndSubmitCommand('transient delete --all');">Clear Transients</button>
                    <button type="button" class="button" onclick="setAndSubmitCommand('db optimize');">Optimize DB</button>
                </div>
            </div>

            <?php if (!empty($cli_caps['cheatsheets'])): ?>
                <hr>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-top: 10px;">
                    <?php foreach ($cli_caps['cheatsheets'] as $plugin_name => $cmds): ?>
                        <div style="background: #f6f7f7; padding: 10px; border-left: 4px solid #2271b1; border-radius: 3px;">
                            <strong style="font-size: 14px; color: #1d2327;"><?php echo esc_html($plugin_name); ?> CLI</strong>
                            <ul style="margin: 8px 0 0 0; padding: 0; list-style: none;">
                                <?php foreach ($cmds as $cmd => $desc): ?>
                                    <li style="margin-bottom: 6px;">
                                        <a href="javascript:void(0);" onclick="setAndSubmitCommand('<?php echo esc_js($cmd); ?>');" style="text-decoration: none; font-family: monospace; font-size: 12px; font-weight: bold;">
                                            wp <?php echo esc_html($cmd); ?>
                                        </a>
                                        <div style="font-size: 11px; color: #646970;"><?php echo esc_html($desc); ?></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Command Input Form -->
        <form method="post" action="" id="wp_cli_form" onsubmit="return validateCommandExecution();">
            <?php wp_nonce_field('run_wp_cli_action', 'wp_cli_nonce'); ?>
            <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 15px;">
                <span style="font-family: monospace; font-weight: bold; font-size: 16px;">wp</span>
                <input type="text" id="wp_cli_command" name="wp_cli_command" class="regular-text" style="width: 75%; font-family: monospace; font-size: 14px;" value="<?php echo esc_attr($last_command); ?>" placeholder="plugin list --format=json" autofocus required>
                <?php submit_button('Execute', 'primary', 'submit', false); ?>
            </div>
        </form>

        <!-- Output Display -->
        <?php if (!empty($json_data)): ?>
            <h2>Output (Formatted Table):</h2>
            <table class="wp-list-table widefat fixed striped" style="margin-bottom: 30px;">
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
            <pre style="background: #1e1e1e; color: #00ff00; padding: 15px; border-radius: 4px; overflow-x: auto; font-family: 'Courier New', Courier, monospace; font-size: 13px; line-height: 1.25; white-space: pre; max-height: 500px; margin-bottom: 30px;"><?php echo esc_html($raw_output); ?></pre>
        <?php endif; ?>

        <!-- Audit Log -->
        <hr style="margin: 30px 0;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h2>Command Audit Log (Last 50 Commands)</h2>
            <?php if (!empty($logs)): ?>
                <form method="post" action="" style="margin: 0;">
                    <?php wp_nonce_field('clear_wp_cli_logs_action', 'clear_logs_nonce'); ?>
                    <input type="submit" name="clear_wp_cli_logs" class="button button-secondary" value="Clear Log" onclick="return confirm('Clear audit log?');">
                </form>
            <?php endif; ?>
        </div>

        <?php if (!empty($logs)): ?>
            <table class="wp-list-table widefat fixed striped">
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
    function validateCommandExecution() {
        const cmdInput = document.getElementById('wp_cli_command').value.trim().toLowerCase();
        
        // High-risk commands list
        const dangerousCommands = [
            'db drop',
            'db reset',
            'site empty',
            'plugin delete',
            'theme delete',
            'user delete',
            'search-replace'
        ];

        for (let i = 0; i < dangerousCommands.length; i++) {
            if (cmdInput.includes(dangerousCommands[i])) {
                return confirm(
                    "⚠️ HIGH RISK COMMAND DETECTED!\n\n" +
                    "You are about to execute: 'wp " + cmdInput + "'\n\n" +
                    "This action can permanently alter or delete database/site data.\n\n" +
                    "Are you sure you want to proceed?"
                );
            }
        }
        return true;
    }

    function setAndSubmitCommand(cmd) {
        document.getElementById('wp_cli_command').value = cmd;
        if (validateCommandExecution()) {
            document.getElementById('wp_cli_form').submit();
        }
    }
    </script>
    <?php
}
