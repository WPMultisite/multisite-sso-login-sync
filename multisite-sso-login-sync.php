<?php
/**
 * Plugin Name: Multisite SSO Login Sync
 * Plugin URI: https://wpmultisite.com/plugins/multisite-sso-login-sync
 * Description: Enable Single Sign-On functionality across WordPress multisite network with synchronization features and cross-domain support.
 * Version: 1.3.2
 * Author: WPMultisite.com
 * Author URI: https://WPMultisite.com
 * Network: true
 * Requires PHP: 7.4
 * Text Domain: multisite-sso-login-sync
 * Domain Path: /languages
 * Requires at least: 6.7.2
 */

namespace MultisiteSSO;

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/api.php';

if (!is_multisite()) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>' . esc_html__('Multisite SSO Login Sync requires WordPress Multisite to be enabled.', 'multisite-sso-login-sync') . '</p></div>';
    });
    return;
}

class MultisiteSSOSettings {
    private static $instance = null;
    private string $option_name = 'multisite_sso_settings';
    private array $default_settings = [
        'token_expiry' => 300,
        'enable_logging' => false,
        'allowed_sites' => [],
        'sso_button_text' => '',
        'sso_button_color' => '#0085ba',
        'default_redirect_url' => '',
        'disable_admin_email_verify' => false,
        'shared_cookie_domain' => '',
    ];

    public static function getInstance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('network_admin_menu', [$this, 'add_network_settings_page']);
        add_action('wp_ajax_save_sso_settings', [$this, 'ajax_save_sso_settings']);
    }

    public function add_network_settings_page(): void {
        $hook = add_submenu_page(
            'settings.php',
            __('Multisite SSO Login Sync', 'multisite-sso-login-sync'),
            __('Login Sync', 'multisite-sso-login-sync'),
            'manage_network_options',
            'multisite-sso-settings',
            [$this, 'render_settings_page']
        );
        add_action("admin_print_scripts-{$hook}", function() {
            wp_enqueue_script('jquery');
        });
    }

    public function get_settings(): array {
        return get_site_option($this->option_name, $this->default_settings);
    }

    private function sanitize_settings(array $input, array $existing_settings): array {
        $sanitized = array_merge($existing_settings, []);
        if (isset($input['token_expiry'])) {
            $sanitized['token_expiry'] = absint($input['token_expiry']);
            if ($sanitized['token_expiry'] < 60) $sanitized['token_expiry'] = 60;
        }
        if (isset($input['enable_logging'])) {
            $sanitized['enable_logging'] = (bool)$input['enable_logging'];
        }
        if (isset($input['disable_admin_email_verify'])) {
            $sanitized['disable_admin_email_verify'] = (bool)$input['disable_admin_email_verify'];
        }
        if (isset($input['allowed_sites'])) {
            $sanitized['allowed_sites'] = array_map('absint', (array)$input['allowed_sites']);
        }
        if (isset($input['sso_button_text'])) {
            $sanitized['sso_button_text'] = sanitize_text_field($input['sso_button_text']);
        }
        if (isset($input['sso_button_color'])) {
            $sanitized['sso_button_color'] = sanitize_hex_color($input['sso_button_color']);
        }
        if (isset($input['default_redirect_url'])) {
            $sanitized['default_redirect_url'] = esc_url_raw($input['default_redirect_url']);
        }
        if (isset($input['shared_cookie_domain'])) {
            $domain = trim($input['shared_cookie_domain']);
            $sanitized['shared_cookie_domain'] = (preg_match('/^(\.[a-zA-Z0-9-]+\.[a-zA-Z]{2,}|([a-zA-Z0-9-]+\.)*[a-zA-Z0-9-]+\.[a-zA-Z]{2,})$/', $domain)) ? $domain : '';
        }
        return $sanitized;
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_network_options')) {
            wp_die(__('Sorry, you are not allowed to access this page.', 'multisite-sso-login-sync'));
        }
        $settings = $this->get_settings();
        $sites = get_sites(['archived' => 0, 'deleted' => 0]);
        $allowed_sites = $settings['allowed_sites'];
        $main_site_id = get_main_site_id(); // 获取主站点 ID
        $sync_stats = get_site_option('multisite_sso_sync_stats', [
            'total_attempts' => 0,
            'successful_logins' => 0,
            'failed_logins' => 0,
            'active_sites' => [],
            'last_active_site' => ['id' => 0, 'name' => '', 'time' => null],
        ]);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Multisite SSO Login Sync', 'multisite-sso-login-sync'); ?></h1>

            <div class="card">
                <h2><?php _e('SSO Settings', 'multisite-sso-login-sync'); ?></h2>
                <span id="settings-status" class="notice" style="display:none; margin-top: 10px;"></span>
                <p><?php _e('Configure SSO settings for your multisite network.', 'multisite-sso-login-sync'); ?></p>
                <form id="sso-settings-form" method="post">
                    <?php wp_nonce_field('multisite_sso_settings_nonce', 'sso_nonce'); ?>
                    <table class="wp-list-table widefat fixed">
                        <thead>
                            <tr>
                                <th><?php _e('Title', 'multisite-sso-login-sync'); ?></th>
                                <th><?php _e('Option', 'multisite-sso-login-sync'); ?></th>
                                <th><?php _e('Description', 'multisite-sso-login-sync'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <th><label for="token_expiry"><?php _e('Token Expiry (seconds)', 'multisite-sso-login-sync'); ?></label></th>
                                <td><input type="number" id="token_expiry" name="token_expiry" value="<?php echo esc_attr($settings['token_expiry']); ?>" min="60" step="60" class="small-text"></td>
                                <td><p class="description"><?php _e('Minimum: 60 seconds. Recommended: 300 seconds (5 minutes).', 'multisite-sso-login-sync'); ?></p></td>
                            </tr>
                            <tr>
                                <th><label for="enable_logging"><?php _e('Enable Logging', 'multisite-sso-login-sync'); ?></label></th>
                                <td><input type="checkbox" id="enable_logging" name="enable_logging" value="1" <?php checked($settings['enable_logging']); ?>></td>
                                <td><p class="description"><?php _e('Log SSO activities for debugging.', 'multisite-sso-login-sync'); ?></p></td>
                            </tr>
                            <tr>
                                <th><label for="disable_admin_email_verify"><?php _e('Disable Admin Email Verification', 'multisite-sso-login-sync'); ?></label></th>
                                <td><input type="checkbox" id="disable_admin_email_verify" name="disable_admin_email_verify" value="1" <?php checked($settings['disable_admin_email_verify']); ?>></td>
                                <td><p class="description"><?php _e('Disable periodic admin email verification prompts.', 'multisite-sso-login-sync'); ?></p></td>
                            </tr>
                            <tr>
                                <th><label for="sso_button_text"><?php _e('SSO Button Text', 'multisite-sso-login-sync'); ?></label></th>
                                <td><input type="text" id="sso_button_text" name="sso_button_text" value="<?php echo esc_attr($settings['sso_button_text']); ?>" class="regular-text"></td>
                                <td><p class="description"><?php _e('Leave empty for default: "Login with Multisite SSO".', 'multisite-sso-login-sync'); ?></p></td>
                            </tr>
                            <tr>
                                <th><label for="sso_button_color"><?php _e('SSO Button Color', 'multisite-sso-login-sync'); ?></label></th>
                                <td><input type="color" id="sso_button_color" name="sso_button_color" value="<?php echo esc_attr($settings['sso_button_color']); ?>"></td>
                                <td><p class="description"><?php _e('Choose the button color for the SSO login.', 'multisite-sso-login-sync'); ?></p></td>
                            </tr>
                            <tr>
                                <th><label for="default_redirect_url"><?php _e('Default Redirect URL', 'multisite-sso-login-sync'); ?></label></th>
                                <td><input type="url" id="default_redirect_url" name="default_redirect_url" value="<?php echo esc_url($settings['default_redirect_url']); ?>" class="regular-text"></td>
                                <td><p class="description"><?php _e('URL to redirect after login. Leave empty for admin dashboard.', 'multisite-sso-login-sync'); ?></p></td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="submit">
                        <button type="button" id="save-settings" class="button button-primary"><?php _e('Save Settings', 'multisite-sso-login-sync'); ?></button>
                    </p>
                </form>
            </div>

            <div class="card">
                <h2><?php _e('Cross-Domain Settings', 'multisite-sso-login-sync'); ?></h2>
                <span id="cross-domain-status" class="notice" style="display:none; margin-top: 10px;"></span>
                <p><?php _e('Configure settings for cross-domain SSO support.', 'multisite-sso-login-sync'); ?></p>
                <form id="cross-domain-form" method="post">
                    <?php wp_nonce_field('multisite_sso_settings_nonce', 'sso_nonce_cross'); ?>
                    <table class="wp-list-table widefat fixed">
                        <thead>
                            <tr>
                                <th><?php _e('Title', 'multisite-sso-login-sync'); ?></th>
                                <th><?php _e('Option', 'multisite-sso-login-sync'); ?></th>
                                <th><?php _e('Description', 'multisite-sso-login-sync'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <th><label for="shared_cookie_domain"><?php _e('Shared Cookie Domain', 'multisite-sso-login-sync'); ?></label></th>
                                <td><input type="text" id="shared_cookie_domain" name="shared_cookie_domain" value="<?php echo esc_attr($settings['shared_cookie_domain']); ?>" class="regular-text" placeholder=".example.com"></td>
                                <td>
                                    <p class="description"><?php _e('Enter a shared top-level domain (e.g., .example.com) for cookie sharing across subdomains. Leave empty to disable.', 'multisite-sso-login-sync'); ?></p>
                                    <p class="description"><?php _e('For different top-level domains (e.g., site1.com and site2.com), SSO will use token redirection automatically. No additional setup is required.', 'multisite-sso-login-sync'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="submit">
                        <button type="button" id="save-cross-domain" class="button button-primary"><?php _e('Save Cross-Domain Settings', 'multisite-sso-login-sync'); ?></button>
                    </p>
                </form>
            </div>

            <div class="card">
                <h2><?php _e('SSO Statistics', 'multisite-sso-login-sync'); ?></h2>
                <p><?php _e('View SSO activity across the network.', 'multisite-sso-login-sync'); ?></p>
                <table class="wp-list-table widefat fixed">
                    <thead>
                        <tr><th><?php _e('Metric', 'multisite-sso-login-sync'); ?></th><th><?php _e('Value', 'multisite-sso-login-sync'); ?></th></tr>
                    </thead>
                    <tbody>
                        <tr><th><?php _e('Total Sites', 'multisite-sso-login-sync'); ?></th><td><?php echo esc_html(count($sites)); ?></td></tr>
                        <tr><th><?php _e('Total Login Attempts', 'multisite-sso-login-sync'); ?></th><td><?php echo esc_html($sync_stats['total_attempts']); ?></td></tr>
                        <tr><th><?php _e('Successful Logins', 'multisite-sso-login-sync'); ?></th><td><?php echo esc_html($sync_stats['successful_logins']); ?></td></tr>
                        <tr><th><?php _e('Failed Logins', 'multisite-sso-login-sync'); ?></th><td><?php echo esc_html($sync_stats['failed_logins']); ?></td></tr>
                        <tr><th><?php _e('Active SSO Sites', 'multisite-sso-login-sync'); ?></th><td><?php echo esc_html(count($sync_stats['active_sites'])); ?></td></tr>
                        <tr><th><?php _e('Last Active Site', 'multisite-sso-login-sync'); ?></th><td><?php echo esc_html($sync_stats['last_active_site']['name'] ?: __('None', 'multisite-sso-login-sync')); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2><?php _e('Site Management', 'multisite-sso-login-sync'); ?></h2>
                <span id="site-management-status" class="notice" style="display:none; margin-top: 10px;"></span>
                <p><?php _e('Manage which sites will participate in SSO login (Main Site is the SSO authentication center and cannot be toggled).', 'multisite-sso-login-sync'); ?></p>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php _e('Site', 'multisite-sso-login-sync'); ?></th>
                            <th><?php _e('URL', 'multisite-sso-login-sync'); ?></th>
                            <th><?php _e('Participate in SSO', 'multisite-sso-login-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sites as $site): ?>
                            <?php
                            $site_id = $site->blog_id;
                            $is_main_site = ($site_id == $main_site_id);
                            $is_allowed = in_array($site_id, $allowed_sites);
                            $details = get_blog_details($site_id);
                            ?>
                            <tr<?php echo $is_main_site ? ' class="main-site"' : ''; ?>>
                                <td>
                                    <?php echo esc_html($details->blogname); ?>
                                    <?php if ($is_main_site): ?>
                                        <span class="main-site-badge"><?php _e('(Main Site)', 'multisite-sso-login-sync'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($details->siteurl); ?>" target="_blank">
                                        <?php echo esc_html($details->siteurl); ?>
                                    </a>
                                </td>
                                <td class="sync-status">
                                    <?php if ($is_main_site): ?>
                                        <span class="source-badge"><?php _e('Source Site', 'multisite-sso-login-sync'); ?></span>
                                    <?php else: ?>
                                        <label>
                                            <input type="checkbox" class="site-sync-toggle" name="site_sync_<?php echo esc_attr($site_id); ?>" data-site-id="<?php echo esc_attr($site_id); ?>"
                                                <?php checked($is_allowed); ?>>
                                            <span class="<?php echo $is_allowed ? 'included' : 'excluded'; ?>">
                                                <?php echo $is_allowed ? __('Included', 'multisite-sso-login-sync') : __('Excluded', 'multisite-sso-login-sync'); ?>
                                            </span>
                                        </label>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            function getCurrentSettings(formId) {
                const settings = {};
                $(formId).serializeArray().forEach(item => {
                    if (item.name.includes('nonce')) settings.nonce = item.value;
                    else settings[item.name] = item.name === 'token_expiry' ? parseInt(item.value) : 
                                              (item.name === 'enable_logging' || item.name === 'disable_admin_email_verify') ? !!item.value : item.value;
                });
                return settings;
            }

            $('#save-settings').on('click', function() {
                const button = $(this);
                const settings = getCurrentSettings('#sso-settings-form');
                settings.allowed_sites = [];
                $('.site-sync-toggle:checked').each(function() {
                    settings.allowed_sites.push($(this).data('site-id'));
                });

                button.prop('disabled', true);
                $('#settings-status').text('<?php _e("Saving...", "multisite-sso-login-sync"); ?>').show();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'save_sso_settings',
                        settings: settings,
                        _ajax_nonce: settings.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#settings-status')
                                .removeClass('notice-error')
                                .addClass('notice-success')
                                .text('<?php _e("Settings saved successfully!", "multisite-sso-login-sync"); ?>')
                                .show()
                                .delay(3000)
                                .fadeOut();
                        } else {
                            $('#settings-status')
                                .removeClass('notice-success')
                                .addClass('notice-error')
                                .text(response.data.message || '<?php _e("Failed to save settings.", "multisite-sso-login-sync"); ?>')
                                .show();
                        }
                        button.prop('disabled', false);
                    },
                    error: function(xhr, status, error) {
                        $('#settings-status')
                            .removeClass('notice-success')
                            .addClass('notice-error')
                            .text('<?php _e("An error occurred: ", "multisite-sso-login-sync"); ?>' + error)
                            .show();
                        button.prop('disabled', false);
                    }
                });
            });

            $('#save-cross-domain').on('click', function() {
                const button = $(this);
                const settings = getCurrentSettings('#cross-domain-form');
                settings.allowed_sites = [];
                $('.site-sync-toggle:checked').each(function() {
                    settings.allowed_sites.push($(this).data('site-id'));
                });

                button.prop('disabled', true);
                $('#cross-domain-status').text('<?php _e("Saving...", "multisite-sso-login-sync"); ?>').show();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'save_sso_settings',
                        settings: settings,
                        _ajax_nonce: settings.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#cross-domain-status')
                                .removeClass('notice-error')
                                .addClass('notice-success')
                                .text('<?php _e("Settings saved successfully!", "multisite-sso-login-sync"); ?>')
                                .show()
                                .delay(3000)
                                .fadeOut();
                        } else {
                            $('#cross-domain-status')
                                .removeClass('notice-success')
                                .addClass('notice-error')
                                .text(response.data.message || '<?php _e("Failed to save settings.", "multisite-sso-login-sync"); ?>')
                                .show();
                        }
                        button.prop('disabled', false);
                    },
                    error: function(xhr, status, error) {
                        $('#cross-domain-status')
                            .removeClass('notice-success')
                            .addClass('notice-error')
                            .text('<?php _e("An error occurred: ", "multisite-sso-login-sync"); ?>' + error)
                            .show();
                        button.prop('disabled', false);
                    }
                });
            });

            $('.site-sync-toggle').on('change', function() {
                const checkbox = $(this);
                const siteId = checkbox.data('site-id');
                const statusSpan = checkbox.siblings('span');
                const settings = getCurrentSettings('#sso-settings-form');
                settings.allowed_sites = [];
                $('.site-sync-toggle:checked').each(function() {
                    settings.allowed_sites.push($(this).data('site-id'));
                });

                if (checkbox.prop('checked')) {
                    statusSpan.removeClass('excluded').addClass('included').text('<?php _e("Included", "multisite-sso-login-sync"); ?>');
                } else {
                    statusSpan.removeClass('included').addClass('excluded').text('<?php _e("Excluded", "multisite-sso-login-sync"); ?>');
                }

                $('#site-management-status').text('<?php _e("Saving...", "multisite-sso-login-sync"); ?>').show();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'save_sso_settings',
                        settings: settings,
                        _ajax_nonce: settings.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#site-management-status')
                                .removeClass('notice-error')
                                .addClass('notice-success')
                                .text('<?php _e("Site management saved successfully!", "multisite-sso-login-sync"); ?>')
                                .show()
                                .delay(3000)
                                .fadeOut();
                        } else {
                            $('#site-management-status')
                                .removeClass('notice-success')
                                .addClass('notice-error')
                                .text(response.data.message || '<?php _e("Failed to save site management.", "multisite-sso-login-sync"); ?>')
                                .show();
                            checkbox.prop('checked', !checkbox.prop('checked'));
                            statusSpan.toggleClass('included excluded').text(checkbox.prop('checked') ? '<?php _e("Included", "multisite-sso-login-sync"); ?>' : '<?php _e("Excluded", "multisite-sso-login-sync"); ?>');
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#site-management-status')
                            .removeClass('notice-success')
                            .addClass('notice-error')
                            .text('<?php _e("An error occurred: ", "multisite-sso-login-sync"); ?>' + error)
                            .show();
                        checkbox.prop('checked', !checkbox.prop('checked'));
                        statusSpan.toggleClass('included excluded').text(checkbox.prop('checked') ? '<?php _e("Included", "multisite-sso-login-sync"); ?>' : '<?php _e("Excluded", "multisite-sso-login-sync"); ?>');
                    }
                });
            });
        });
        </script>

        <style>
        .card { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; max-width: unset; margin-top: 20px; padding: 20px; }
        .sync-status .included { color: #46b450; }
        .sync-status .excluded { color: #dc3232; }
        .source-badge { background: #007cba; color: white; padding: 3px 8px; border-radius: 4px; font-size: 12px;}
        .main-site-badge { background: #ffe000; color: #23282d; padding: 2px 5px; border-radius: 3px; font-size: 11px; margin-left: 5px; }
        .notice { padding: 8px 12px; border-radius: 3px; }
        .notice-success { background-color: #dff0d8; border-left: 4px solid #46b450; }
        .notice-error { background-color: #f2dede; border-left: 4px solid #dc3232; }
        </style>
        <?php
    }

    public function ajax_save_sso_settings() {
        check_ajax_referer('multisite_sso_settings_nonce', '_ajax_nonce') || wp_send_json_error(['message' => __('Invalid nonce.', 'multisite-sso-login-sync')]);
        if (!current_user_can('manage_network_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'multisite-sso-login-sync')]);
        }

        $settings = isset($_POST['settings']) ? wp_unslash((array)$_POST['settings']) : [];
        if (empty($settings)) {
            wp_send_json_error(['message' => __('No settings provided.', 'multisite-sso-login-sync')]);
        }

        $existing_settings = $this->get_settings();
        $new_settings = $this->sanitize_settings($settings, $existing_settings);
        update_site_option($this->option_name, $new_settings);
        wp_send_json_success(['message' => isset($settings['allowed_sites']) ? __('Site management saved.', 'multisite-sso-login-sync') : __('Settings saved.', 'multisite-sso-login-sync')]);
    }

    public function update_stats($total_sites, $successful = true, $site_id = 0) {
        $stats = get_site_option('multisite_sso_sync_stats', [
            'total_attempts' => 0,
            'successful_logins' => 0,
            'failed_logins' => 0,
            'active_sites' => [],
            'last_active_site' => ['id' => 0, 'name' => '', 'time' => null],
        ]);
        $stats['total_attempts']++;
        if ($successful && $site_id != get_main_site_id()) { // 确保成功登录且不是主站点
            $stats['successful_logins']++;
            if ($site_id) {
                $stats['active_sites'][$site_id] = true;
                $stats['last_active_site'] = [
                    'id' => $site_id,
                    'name' => get_blog_details($site_id)->blogname,
                    'time' => current_time('mysql'),
                ];
            }
        } else {
            $stats['failed_logins']++;
        }
        $stats['total_sites'] = $total_sites;
        update_site_option('multisite_sso_sync_stats', $stats);
    }
}

class MultisiteSSOLogin {
    private static $instance = null;
    private string $token_meta_key = 'multisite_sso_token';
    private MultisiteSSOSettings $settings;

    public static function getInstance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = MultisiteSSOSettings::getInstance();
        $this->init_hooks();
    }

    private function init_hooks(): void {
        add_action('init', [$this, 'start_session']);
        add_filter('login_message', [$this, 'add_sso_button'], 200);
        add_action('login_init', [$this, 'handle_sso_login']);
        add_action('login_init', [$this, 'process_sso_callback']);
        add_filter('login_redirect', [$this, 'handle_login_redirect'], 100, 3);
        add_action('set_auth_cookie', [$this, 'sync_remember_me_cookie'], 10, 6);
        add_filter('admin_email_check_interval', [$this, 'disable_admin_email_verify']);
        add_action('wp_before_admin_bar_render', [$this, 'change_site_switcher_links']);

        if (!wp_next_scheduled('clean_expired_sso_tokens')) {
            wp_schedule_event(time(), 'daily', 'clean_expired_sso_tokens');
        }
        add_action('clean_expired_sso_tokens', [$this, 'clean_expired_tokens']);
    }

    public function start_session(): void {
        if (!session_id() && !headers_sent()) {
            session_start();
        }
    }

    public function is_sso_site(): bool {
        return is_main_site();
    }

    public function get_sso_login_url(array $query_args = []): string {
        $query_args = wp_parse_args($query_args, [
            'multisite_sso_blog_id' => get_current_blog_id(),
            'nonce' => wp_create_nonce('multisite_sso_login'),
        ]);
        return add_query_arg($query_args, network_site_url('wp-login.php', 'login'));
    }

    public function add_sso_button(string $messages): string {
        if (is_user_logged_in() || $this->is_sso_site()) return $messages;

        $current_blog_id = get_current_blog_id();
        $allowed = empty($this->settings->get_settings()['allowed_sites']) || in_array($current_blog_id, $this->settings->get_settings()['allowed_sites']);
        if (!$allowed) return $messages;

        $sso_args = [];
        $redirect_to = filter_input(INPUT_GET, 'redirect_to', FILTER_SANITIZE_URL);
        if ($redirect_to) $sso_args['redirect_to'] = rawurlencode($redirect_to);

        $button_text = $this->settings->get_settings()['sso_button_text'] ?: __('Login with Multisite SSO', 'multisite-sso-login-sync');
        $button_color = $this->settings->get_settings()['sso_button_color'] ?: '#0085ba';

        ob_start();
        ?>
        <style type="text/css">
            .wp-multisite-sso-login--cta { clear: both; margin: 0 0 20px; padding: 0; background: transparent; box-sizing: border-box; width: 100%; border: none; }
            .wp-multisite-sso-login--button { display: block; padding: 10px 24px; background: <?php echo esc_attr($button_color); ?>; color: #fff !important; text-decoration: none; border-radius: 4px; text-align: center; font-size: 14px; line-height: 1.5; font-weight: 500; transition: all 0.2s ease; border: none; cursor: pointer; margin: 0; }
            .wp-multisite-sso-login--button:hover, .wp-multisite-sso-login--button:focus { opacity: 0.9; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            #loginform { margin-top: 20px; }
        </style>
        <div class="wp-multisite-sso-login--cta">
            <a href="<?php echo esc_url($this->get_sso_login_url($sso_args)); ?>" class="wp-multisite-sso-login--button">
                <?php echo esc_html($button_text); ?>
            </a>
        </div>
        <?php
        return ob_get_clean() . $messages;
    }

    public function handle_sso_login(): void {
        if (!$this->is_sso_site()) return;

        $blog_id = filter_input(INPUT_GET, 'multisite_sso_blog_id', FILTER_VALIDATE_INT);
        $nonce = filter_input(INPUT_GET, 'nonce', FILTER_DEFAULT);

        if (!$blog_id || !$nonce || is_user_logged_in()) return;

        $nonce = sanitize_text_field($nonce);
        if (!wp_verify_nonce($nonce, 'multisite_sso_login')) return;

        $blog = get_blog_details($blog_id);
        if (!$blog) wp_die(__('Invalid blog ID', 'multisite-sso-login-sync'));

        add_action('login_form', function() use ($blog_id) {
            printf('<input type="hidden" name="multisite_sso_blog_id" value="%d">', esc_attr($blog_id));
        });

        add_filter('wp_login_errors', function($errors) use ($blog) {
            $message = sprintf(__('You are logging into %s', 'multisite-sso-login-sync'), sprintf('<a href="%s" target="_blank">%s</a>', esc_url($blog->home), esc_html($blog->blogname)));
            $errors->add('multisite_sso_notice', $message, 'message');
            return $errors;
        });
    }

    public function handle_login_redirect(string $redirect_to, string $requested_redirect_to, $user): string {
        if (!$this->is_sso_site() || !($user instanceof WP_User)) return $this->apply_default_redirect($redirect_to, $requested_redirect_to);

        $blog_id = filter_input(INPUT_POST, 'multisite_sso_blog_id', FILTER_VALIDATE_INT);
        if (empty($blog_id)) return $this->apply_default_redirect($redirect_to, $requested_redirect_to);

        $session_token = wp_generate_password(42, false, false);
        $token_data = [
            'token' => $session_token,
            'expires' => time() + $this->settings->get_settings()['token_expiry'],
        ];
        
        update_user_meta($user->ID, $this->token_meta_key, $token_data);

        $args = [
            'multisite_sso_token' => $session_token,
            'multisite_sso_user' => $user->ID,
            'nonce' => wp_create_nonce('multisite_sso_callback'),
        ];

        if ($requested_redirect_to) $args['redirect_to'] = rawurlencode($requested_redirect_to);

        $callback_url = add_query_arg($args, get_site_url($blog_id, 'wp-login.php', 'login'));

        if ($this->settings->get_settings()['enable_logging']) {
            $this->log_sso_activity($user->ID, $blog_id, 'login_redirect', ['token' => $session_token, 'callback_url' => $callback_url]);
        }

        return $callback_url;
    }

    private function apply_default_redirect(string $redirect_to, string $requested_redirect_to): string {
        if ($requested_redirect_to) return $requested_redirect_to;
        $default_url = $this->settings->get_settings()['default_redirect_url'];
        return $default_url ?: ($redirect_to ?: admin_url());
    }

    public function process_sso_callback(): void {
        if ($this->is_sso_site() || is_user_logged_in()) return;

        $token = filter_input(INPUT_GET, 'multisite_sso_token', FILTER_SANITIZE_STRING);
        $user_id = filter_input(INPUT_GET, 'multisite_sso_user', FILTER_VALIDATE_INT);
        $nonce = filter_input(INPUT_GET, 'nonce', FILTER_SANITIZE_STRING);
        $current_blog_id = get_current_blog_id();
        $total_sites = count(get_sites(['archived' => 0, 'deleted' => 0]));

        // 支持 Multisite Multidomain SSO 的逻辑
        if (isset($_GET['msso-get-auth-from']) && !$token) {
            $coming_from = intval($_GET['msso-get-auth-from']);
            $sso_site = get_site($coming_from);
            if (!$sso_site || !wp_verify_nonce($nonce, 'multisite-sso-' . $coming_from . '-' . $current_blog_id)) {
                $this->settings->update_stats($total_sites, false, $current_blog_id);
                wp_die('Invalid SSO request.');
            }
            $return_url = remove_query_arg(['msso-get-auth-from', 'nonce']);
            wp_redirect(add_query_arg(['msso-auth-return-to' => $return_url, 'nonce' => $nonce], get_site_url($coming_from)));
            exit;
        }

        if (isset($_GET['msso-auth-return-to']) && is_user_logged_in()) {
            $return_url = esc_url_raw($_GET['msso-auth-return-to']);
            $requesting_site_id = get_blog_id_from_url(parse_url($return_url, PHP_URL_HOST));
            if (!$requesting_site_id || !wp_verify_nonce($nonce, 'multisite-sso-' . $current_blog_id . '-' . $requesting_site_id)) {
                $this->settings->update_stats($total_sites, false, $current_blog_id);
                wp_die('Invalid SSO authorization.');
            }
            $user = wp_get_current_user();
            $expires = time() + 120; // 2分钟
            $user_pass_hash = $this->get_user_password_hash($user->ID);
            $hash = hash_hmac('sha256', implode('||', [$user->ID, $expires, $user_pass_hash]), AUTH_SALT);
            wp_redirect(add_query_arg(['msso-auth' => $hash, 'msso-user-id' => $user->ID, 'msso-expires' => $expires], $return_url));
            exit;
        }

        if (isset($_GET['msso-auth']) && isset($_GET['msso-user-id']) && isset($_GET['msso-expires'])) {
            $user_id = intval($_GET['msso-user-id']);
            $expires = intval($_GET['msso-expires']);
            $received_hash = sanitize_text_field($_GET['msso-auth']);
            $final_destination = remove_query_arg(['msso-auth', 'msso-user-id', 'msso-expires']);

            if ($expires < time()) {
                $this->settings->update_stats($total_sites, false, $current_blog_id);
                wp_die('Your Single Sign On link has expired.');
            }
            $user_pass_hash = $this->get_user_password_hash($user_id);
            $expected_hash = hash_hmac('sha256', implode('||', [$user_id, $expires, $user_pass_hash]), AUTH_SALT);
            if (!hash_equals($expected_hash, $received_hash)) {
                $this->settings->update_stats($total_sites, false, $current_blog_id);
                wp_die('Invalid SSO hash.');
            }
            if (!user_can($user_id, 'read')) {
                $this->settings->update_stats($total_sites, false, $current_blog_id);
                wp_die('User not authorized for this site.');
            }

            wp_set_auth_cookie($user_id, true);
            $this->settings->update_stats($total_sites, true, $current_blog_id);
            if ($this->settings->get_settings()['enable_logging']) {
                $this->log_sso_activity($user_id, $current_blog_id, 'sso_callback_multidomain', ['hash' => $received_hash]);
            }
            wp_safe_redirect($final_destination);
            exit;
        }

        // 原有令牌逻辑
        if (!$token || !$user_id || !wp_verify_nonce($nonce, 'multisite_sso_callback')) {
            $this->settings->update_stats($total_sites, false, $current_blog_id);
            if ($this->settings->get_settings()['enable_logging']) {
                $this->log_sso_activity(0, $current_blog_id, 'sso_callback_failed', ['reason' => 'Invalid token or nonce']);
            }
            return;
        }

        switch_to_blog(get_main_site_id());
        $token_data = get_user_meta($user_id, $this->token_meta_key, true);
        restore_current_blog();

        if (empty($token_data) || !hash_equals($token_data['token'], $token) || $token_data['expires'] < time()) {
            $this->settings->update_stats($total_sites, false, $current_blog_id);
            if ($this->settings->get_settings()['enable_logging']) {
                $this->log_sso_activity($user_id, $current_blog_id, 'sso_callback_failed', ['reason' => 'Invalid or expired token']);
            }
            wp_die(__('Invalid or expired SSO token', 'multisite-sso-login-sync'));
        }

        switch_to_blog(get_main_site_id());
        delete_user_meta($user_id, $this->token_meta_key);
        restore_current_blog();

        $user = get_user_by('id', $user_id);
        if (!$user) {
            $this->settings->update_stats($total_sites, false, $current_blog_id);
            if ($this->settings->get_settings()['enable_logging']) {
                $this->log_sso_activity($user_id, $current_blog_id, 'sso_callback_failed', ['reason' => 'User not found']);
            }
            wp_die(__('User not found', 'multisite-sso-login-sync'));
        }

        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        do_action('wp_login', $user->user_login, $user);

        // 确保成功登录被记录
        $this->settings->update_stats($total_sites, true, $current_blog_id);
        if ($this->settings->get_settings()['enable_logging']) {
            $this->log_sso_activity($user_id, $current_blog_id, 'sso_callback_success', ['token' => $token]);
        }

        $redirect_to = filter_input(INPUT_GET, 'redirect_to', FILTER_SANITIZE_URL) ?: $this->settings->get_settings()['default_redirect_url'] ?: admin_url();
        wp_safe_redirect($redirect_to);
        exit;
    }

    public function change_site_switcher_links() {
        global $wp_admin_bar;
        $nodes = $wp_admin_bar->get_nodes();
        $current_site_id = get_current_blog_id();
        $current_site = get_site($current_site_id);

        foreach ($nodes as $id => $node) {
            if (empty($node->href)) continue;
            $is_site_node = (0 === stripos($id, 'blog'));
            $is_network_admin_node = (0 === stripos($id, 'network-admin'));
            if (!($is_site_node || $is_network_admin_node)) continue;
            if (in_array($current_site->domain, explode('/', $node->href), true)) continue;

            $target_url_parts = wp_parse_url($node->href);
            $target_site = get_site_by_path($target_url_parts['host'], $target_url_parts['path']);
            $nonce = wp_create_nonce('multisite-sso-' . $current_site_id . '-' . $target_site->blog_id);
            $node->href = add_query_arg([
                'msso-get-auth-from' => $current_site_id,
                'nonce' => $nonce
            ], $node->href);
            $wp_admin_bar->add_node($node);
        }
    }

    public function sync_remember_me_cookie(string $auth_cookie, int $expire, int $expiration, int $user_id, string $scheme, string $token): void {
        if ($scheme !== 'logged_in' || $expire <= time()) return;

        $allowed_sites = $this->settings->get_settings()['allowed_sites'];
        $shared_domain = $this->settings->get_settings()['shared_cookie_domain'];
        $top_domain = $shared_domain ?: '.' . parse_url(network_site_url(), PHP_URL_HOST);
        $sites = empty($allowed_sites) ? get_sites() : array_filter(get_sites(), fn($site) => in_array($site->blog_id, $allowed_sites));

        foreach ($sites as $site) {
            if ($site->blog_id === get_current_blog_id()) continue;
            switch_to_blog($site->blog_id);
            wp_set_auth_cookie($user_id, true, is_ssl(), $token);
            if ($shared_domain) {
                setcookie(LOGGED_IN_COOKIE, $auth_cookie, $expire, '/', $top_domain, is_ssl(), true);
            }
            restore_current_blog();
        }
    }

    public function disable_admin_email_verify($interval) {
        return $this->settings->get_settings()['disable_admin_email_verify'] ? false : $interval;
    }

    public function clean_expired_tokens(): void {
        global $wpdb;
        $meta_key = $this->token_meta_key;
        $current_time = time();

        $tokens = $wpdb->get_results($wpdb->prepare("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", $meta_key));
        foreach ($tokens as $token) {
            $token_data = maybe_unserialize($token->meta_value);
            if (isset($token_data['expires']) && $token_data['expires'] < $current_time) {
                delete_user_meta($token->user_id, $meta_key);
                if ($this->settings->get_settings()['enable_logging']) {
                    $this->log_sso_activity($token->user_id, 0, 'clean_expired_token', ['expired_at' => $token_data['expires']]);
                }
            }
        }
    }

    private function log_sso_activity(int $user_id, int $blog_id, string $action, array $data = []): void {
        if (!$this->settings->get_settings()['enable_logging']) return;

        $log_entry = [
            'timestamp' => current_time('mysql'),
            'user_id' => $user_id,
            'blog_id' => $blog_id,
            'action' => $action,
            'ip' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'data' => $data,
        ];
        $log_file = WP_CONTENT_DIR . '/multisite-sso.log';
        
        // 确保目录可写，尝试创建文件
        if (!file_exists($log_file)) {
            @file_put_contents($log_file, '');
        }
        if (is_writable($log_file)) {
            error_log(wp_json_encode($log_entry) . "\n", 3, $log_file);
        } else {
            // 如果文件不可写，记录到 WordPress 默认日志
            error_log('Multisite SSO: Cannot write to ' . $log_file . '. Log entry: ' . wp_json_encode($log_entry));
        }
    }

    private function get_client_ip(): string {
        $ip_headers = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = filter_var($_SERVER[$header], FILTER_VALIDATE_IP);
                if ($ip) return $ip;
            }
        }
        return '0.0.0.0';
    }

    protected function get_user_password_hash($uid) {
        global $wpdb;
        $hash = $wpdb->get_var($wpdb->prepare("SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", $uid));
        return $hash ? substr($hash, 0, -2) : '';
    }
}

add_action('plugins_loaded', function(): void {
    MultisiteSSOSettings::getInstance();
    MultisiteSSOLogin::getInstance();
    MultisiteSSOApi::getInstance();
});

register_activation_hook(__FILE__, function(): void {
    $settings = MultisiteSSOSettings::getInstance();
    if (!get_site_option('multisite_sso_settings')) {
        update_site_option('multisite_sso_settings', $settings->get_settings());
    }
    if (!get_site_option('multisite_sso_sync_stats')) {
        update_site_option('multisite_sso_sync_stats', [
            'total_attempts' => 0,
            'successful_logins' => 0,
            'failed_logins' => 0,
            'active_sites' => [],
            'last_active_site' => ['id' => 0, 'name' => '', 'time' => null],
        ]);
    }
});