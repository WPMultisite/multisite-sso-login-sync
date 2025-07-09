<?php

namespace MultisiteSSO;

use MultisiteSSO\MultisiteSSOSettings;
use MultisiteSSO\MultisiteSSOLogin;

class MultisiteSSOApi {
    private static $instance = null;
    private MultisiteSSOSettings $settings;
    private MultisiteSSOLogin $login;
    private string $api_namespace = 'multisite-sso/v1';
    private string $api_version = '1';
    private $deprecated_version = null;

    public static function getInstance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = MultisiteSSOSettings::getInstance();
        $this->login = MultisiteSSOLogin::getInstance();
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        if ($this->deprecated_version && version_compare($this->api_version, $this->deprecated_version, '<=')) {
            add_action('admin_notices', [$this, 'show_deprecation_notice']);
        }
    }

    public function show_deprecation_notice(): void {
        echo '<div class="notice notice-warning"><p>';
        echo sprintf(
            'Warning: The Multisite SSO API v%s is deprecated. Please upgrade to the latest version.',
            esc_html($this->api_version)
        );
        echo '</p></div>';
    }

    private function error_response(string $code, string $message, int $status = 400): WP_Error {
        return new WP_Error(
            'multisite_sso_' . $code,
            $message,
            ['status' => $status]
        );
    }

    private function success_response(array $data): WP_REST_Response {
        return rest_ensure_response(['success' => true, 'data' => $data]);
    }

    private function check_rate_limit(WP_REST_Request $request) {
        $ip = $request->get_header('X-Real-IP') ?: $_SERVER['REMOTE_ADDR'];
        $endpoint = $request->get_route();
        
        $transient_key = 'multisite_sso_rate_limit_' . md5($ip . $endpoint);
        $limit_count = get_transient($transient_key);
        
        if ($limit_count === false) {
            set_transient($transient_key, 1, MINUTE_IN_SECONDS);
        } elseif ($limit_count > 60) {
            return $this->error_response('rate_limit_exceeded', 'Too many requests.', 429);
        } else {
            set_transient($transient_key, $limit_count + 1, MINUTE_IN_SECONDS);
        }
        
        return true;
    }

    private function log_api_request(WP_REST_Request $request, WP_REST_Response $response): void {
        if (!$this->settings->get_settings()['enable_logging']) {
            return;
        }
        
        $log_data = [
            'timestamp' => current_time('mysql'),
            'endpoint' => $request->get_route(),
            'method' => $request->get_method(),
            'ip' => $request->get_header('X-Real-IP') ?: $_SERVER['REMOTE_ADDR'],
            'user_id' => get_current_user_id(),
            'status' => $response->get_status(),
            'request_params' => $request->get_params(),
        ];
        
        error_log('[Multisite SSO API] ' . wp_json_encode($log_data) . "\n", 3, WP_CONTENT_DIR . '/multisite-sso-api.log');
    }

    public function register_rest_routes(): void {
        register_rest_route($this->api_namespace, '/version', [
            'methods' => 'GET',
            'callback' => [$this, 'get_api_version'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->api_namespace, '/docs', [
            'methods' => 'GET',
            'callback' => [$this, 'get_api_docs'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->api_namespace, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_sso_status'],
            'permission_callback' => [$this, 'check_api_permission'],
        ]);

        register_rest_route($this->api_namespace, '/settings', [
            'methods' => 'GET',
            'callback' => [$this, 'get_sso_settings'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        register_rest_route($this->api_namespace, '/settings', [
            'methods' => 'POST',
            'callback' => [$this, 'update_sso_settings'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => [
                'token_expiry' => [
                    'required' => false,
                    'type' => 'integer',
                    'minimum' => 60,
                ],
                'enable_logging' => [
                    'required' => false,
                    'type' => 'boolean',
                ],
                'allowed_sites' => [
                    'required' => false,
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
                'sso_button_text' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'sso_button_color' => [
                    'required' => false,
                    'type' => 'string',
                    'pattern' => '^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$'
                ],
                'default_redirect_url' => [
                    'required' => false,
                    'type' => 'string',
                    'format' => 'uri',
                ],
                'disable_admin_email_verify' => [
                    'required' => false,
                    'type' => 'boolean',
                ],
            ],
        ]);

        register_rest_route($this->api_namespace, '/login-url', [
            'methods' => 'GET',
            'callback' => [$this, 'get_sso_login_url'],
            'permission_callback' => [$this, 'check_api_permission'],
            'args' => [
                'blog_id' => ['required' => false, 'type' => 'integer'],
                'redirect_to' => ['required' => false, 'type' => 'string', 'format' => 'uri'],
            ],
        ]);

        register_rest_route($this->api_namespace, '/verify-token', [
            'methods' => 'POST',
            'callback' => [$this, 'verify_sso_token'],
            'permission_callback' => [$this, 'check_api_permission'],
            'args' => [
                'token' => ['required' => true, 'type' => 'string'],
                'user_id' => ['required' => true, 'type' => 'integer'],
            ],
        ]);
    }

    public function get_api_version(): WP_REST_Response {
        return $this->success_response([
            'version' => $this->api_version,
            'deprecated' => $this->deprecated_version ? true : false,
            'namespace' => $this->api_namespace,
        ]);
    }

    public function get_api_docs(): WP_REST_Response {
        $docs = [
            'version' => $this->api_version,
            'endpoints' => [
                '/version' => ['method' => 'GET', 'description' => 'Get API version information', 'requires_auth' => false],
                '/status' => ['method' => 'GET', 'description' => 'Get current SSO status', 'requires_auth' => false],
                '/settings' => ['method' => ['GET', 'POST'], 'description' => 'Manage SSO settings', 'requires_auth' => true, 'required_capability' => 'manage_network_options'],
                '/login-url' => ['method' => 'GET', 'description' => 'Get SSO login URL', 'requires_auth' => false],
                '/verify-token' => ['method' => 'POST', 'description' => 'Verify SSO token', 'requires_auth' => false],
            ],
        ];
        return $this->success_response($docs);
    }

    public function check_api_permission(WP_REST_Request $request) {
        $rate_limit_check = $this->check_rate_limit($request);
        return is_wp_error($rate_limit_check) ? $rate_limit_check : apply_filters('multisite_sso_api_permission', true);
    }

    public function check_admin_permission(): bool {
        return current_user_can('manage_network_options');
    }

    public function get_sso_status(WP_REST_Request $request) {
        try {
            $data = [
                'is_main_site' => $this->login->is_sso_site(),
                'current_blog_id' => get_current_blog_id(),
                'is_user_logged_in' => is_user_logged_in(),
                'current_user_id' => get_current_user_id(),
            ];
            $response = $this->success_response($data);
            $this->log_api_request($request, $response);
            return $response;
        } catch (Exception $e) {
            return $this->error_response('status_error', $e->getMessage());
        }
    }

    public function get_sso_settings(WP_REST_Request $request) {
        try {
            $settings = $this->settings->get_settings();
            $response = $this->success_response(['settings' => $settings]);
            $this->log_api_request($request, $response);
            return $response;
        } catch (Exception $e) {
            return $this->error_response('settings_error', $e->getMessage());
        }
    }

    public function update_sso_settings(WP_REST_Request $request) {
        try {
            $params = $request->get_params();
            $current_settings = $this->settings->get_settings();
            $new_settings = array_merge($current_settings, $params);
            $sanitized_settings = $this->settings->sanitize_settings($new_settings);
            update_site_option('multisite_sso_settings', $sanitized_settings);
            $response = $this->success_response(['settings' => $sanitized_settings]);
            $this->log_api_request($request, $response);
            return $response;
        } catch (Exception $e) {
            return $this->error_response('settings_update_error', $e->getMessage());
        }
    }

    public function get_sso_login_url(WP_REST_Request $request) {
        try {
            $blog_id = $request->get_param('blog_id') ?: get_current_blog_id();
            $redirect_to = $request->get_param('redirect_to');
            $args = $redirect_to ? ['redirect_to' => $redirect_to] : [];
            $login_url = $this->login->get_sso_login_url($args);
            $response = $this->success_response(['login_url' => $login_url]);
            $this->log_api_request($request, $response);
            return $response;
        } catch (Exception $e) {
            return $this->error_response('login_url_error', $e->getMessage());
        }
    }

    public function verify_sso_token(WP_REST_Request $request) {
        try {
            $token = $request->get_param('token');
            $user_id = $request->get_param('user_id');
            switch_to_blog(get_main_site_id());
            $token_data = get_user_meta($user_id, 'multisite_sso_token', true);
            restore_current_blog();
            $is_valid = !empty($token_data) && hash_equals($token_data['token'], $token) && $token_data['expires'] > time();
            $response = $this->success_response(['is_valid' => $is_valid, 'expires' => $is_valid ? $token_data['expires'] : 0]);
            $this->log_api_request($request, $response);
            return $response;
        } catch (Exception $e) {
            return $this->error_response('token_verification_error', $e->getMessage());
        }
    }
}