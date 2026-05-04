<?php
if (!defined('ABSPATH')) {
    exit;
}

class Route_RD_OAuth
{
    const AUTH_DIALOG_URL = 'https://api.rd.services/auth/dialog';
    const TOKEN_URL = 'https://api.rd.services/auth/token';

    /**
     * @var Route_RD_OAuth|null
     */
    private static $instance = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        register_rest_route(
            'route-rd/v1',
            '/oauth/callback',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'handle_callback'),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function callback_url()
    {
        return rest_url('route-rd/v1/oauth/callback');
    }

    public function authorization_url()
    {
        $settings = route_rd_get_settings();
        $client_id = trim((string) $settings['client_id']);
        if ('' === $client_id) {
            return '';
        }

        $state = wp_generate_password(32, false, false);
        set_transient('route_rd_oauth_state_' . md5($state), 1, 15 * MINUTE_IN_SECONDS);

        // A documentacao atual usa redirect_uri no FAQ; SDKs antigos mencionam redirect_url.
        // Mantemos redirect_uri como principal e deixamos o parametro filtravel para compatibilidade.
        $redirect_param = apply_filters('route_rd_oauth_redirect_param', 'redirect_uri');

        return add_query_arg(
            array(
                'client_id' => $client_id,
                $redirect_param => $this->callback_url(),
                'state' => $state,
            ),
            self::AUTH_DIALOG_URL
        );
    }

    public function handle_callback(WP_REST_Request $request)
    {
        $code = sanitize_text_field((string) $request->get_param('code'));
        $state = sanitize_text_field((string) $request->get_param('state'));

        if ('' === $code) {
            Route_RD_Logger::add(array(
                'source' => 'oauth',
                'action' => 'callback',
                'status' => 'error',
                'error_message' => 'Callback sem code.',
            ));
            return new WP_REST_Response(array('success' => false, 'message' => 'Codigo OAuth ausente.'), 400);
        }

        if ('' !== $state) {
            $state_key = 'route_rd_oauth_state_' . md5($state);
            if (!get_transient($state_key)) {
                Route_RD_Logger::add(array(
                    'source' => 'oauth',
                    'action' => 'callback',
                    'status' => 'error',
                    'error_message' => 'State OAuth invalido ou expirado.',
                ));
                return new WP_REST_Response(array('success' => false, 'message' => 'State invalido.'), 403);
            }
            delete_transient($state_key);
        }

        $result = $this->exchange_code($code);
        $redirect = add_query_arg(
            array(
                'page' => 'route-rd-station',
                'tab' => 'connection',
                'route_rd_oauth' => is_wp_error($result) ? 'error' : 'connected',
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function exchange_code($code)
    {
        $settings = route_rd_get_settings();
        $client_id = trim((string) $settings['client_id']);
        $client_secret = route_rd_decrypt($settings['client_secret']);

        if ('' === $client_id || '' === $client_secret) {
            return new WP_Error('route_rd_missing_credentials', __('Client ID e Client Secret sao obrigatorios.', 'route-rd-station'));
        }

        $body = array(
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'code' => sanitize_text_field($code),
        );

        $response = wp_remote_post(
            self::TOKEN_URL . '?token_by=code',
            array(
                'timeout' => 20,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => wp_json_encode($body),
            )
        );

        return $this->handle_token_response($response, 'exchange_code', $body);
    }

    public function refresh_access_token()
    {
        $settings = route_rd_get_settings();
        $tokens = route_rd_get_tokens();
        $client_id = trim((string) $settings['client_id']);
        $client_secret = route_rd_decrypt($settings['client_secret']);
        $refresh_token = isset($tokens['refresh_token']) ? route_rd_decrypt($tokens['refresh_token']) : '';

        if ('' === $client_id || '' === $client_secret || '' === $refresh_token) {
            Route_RD_Logger::add(array(
                'source' => 'oauth',
                'action' => 'refresh_token',
                'status' => 'error',
                'error_message' => 'Credenciais OAuth ou refresh_token ausentes.',
            ));
            return new WP_Error('route_rd_missing_refresh_credentials', __('Credenciais OAuth ou refresh token ausentes.', 'route-rd-station'));
        }

        $body = array(
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token,
        );

        $response = wp_remote_post(
            self::TOKEN_URL,
            array(
                'timeout' => 20,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => wp_json_encode($body),
            )
        );

        return $this->handle_token_response($response, 'refresh_token', $body);
    }

    public function get_access_token()
    {
        $tokens = route_rd_get_tokens();
        $access = isset($tokens['access_token']) ? route_rd_decrypt($tokens['access_token']) : '';

        if ('' === $access) {
            $result = $this->refresh_access_token();
            if (is_wp_error($result)) {
                return $result;
            }
            $tokens = route_rd_get_tokens();
            $access = isset($tokens['access_token']) ? route_rd_decrypt($tokens['access_token']) : '';
        }

        if ($this->should_refresh_soon()) {
            $result = $this->refresh_access_token();
            if (!is_wp_error($result)) {
                $tokens = route_rd_get_tokens();
                $access = isset($tokens['access_token']) ? route_rd_decrypt($tokens['access_token']) : $access;
            }
        }

        return '' === $access ? new WP_Error('route_rd_no_access_token', __('Access token ausente.', 'route-rd-station')) : $access;
    }

    public function should_refresh_soon()
    {
        $tokens = route_rd_get_tokens();
        $expires_at = isset($tokens['expires_at']) ? absint($tokens['expires_at']) : 0;

        return $expires_at > 0 && $expires_at <= (time() + 2 * HOUR_IN_SECONDS);
    }

    public function disconnect()
    {
        route_rd_update_tokens(array());
        Route_RD_Logger::add(array(
            'source' => 'oauth',
            'action' => 'disconnect',
            'status' => 'success',
            'error_message' => 'Conexao removida pelo administrador.',
        ));
    }

    private function handle_token_response($response, $action, $request_body)
    {
        $safe_request = $request_body;
        if (isset($safe_request['client_secret'])) {
            $safe_request['client_secret'] = route_rd_mask_secret($safe_request['client_secret']);
        }
        if (isset($safe_request['refresh_token'])) {
            $safe_request['refresh_token'] = route_rd_mask_secret($safe_request['refresh_token']);
        }

        if (is_wp_error($response)) {
            Route_RD_Logger::add(array(
                'source' => 'oauth',
                'action' => $action,
                'status' => 'error',
                'request_payload' => $safe_request,
                'error_message' => $response->get_error_message(),
            ));
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($code < 200 || $code >= 300 || !is_array($decoded) || empty($decoded['access_token'])) {
            Route_RD_Logger::add(array(
                'source' => 'oauth',
                'action' => $action,
                'status' => 'error',
                'request_payload' => $safe_request,
                'response_code' => $code,
                'response_body' => $body,
                'error_message' => 'Falha ao obter token OAuth.',
            ));
            return new WP_Error('route_rd_token_error', __('Falha ao obter token OAuth.', 'route-rd-station'), array('status' => $code, 'body' => $body));
        }

        $tokens = route_rd_get_tokens();
        $tokens['access_token'] = route_rd_encrypt($decoded['access_token']);
        if (!empty($decoded['refresh_token'])) {
            $tokens['refresh_token'] = route_rd_encrypt($decoded['refresh_token']);
        }
        $tokens['expires_in'] = isset($decoded['expires_in']) ? absint($decoded['expires_in']) : DAY_IN_SECONDS;
        $tokens['expires_at'] = time() + absint($tokens['expires_in']);
        $tokens['last_refresh'] = current_time('mysql');
        $tokens['connection_status'] = 'connected';

        route_rd_update_tokens($tokens);

        Route_RD_Logger::add(array(
            'source' => 'oauth',
            'action' => $action,
            'status' => 'success',
            'request_payload' => $safe_request,
            'response_code' => $code,
            'response_body' => array('access_token' => 'saved', 'refresh_token' => empty($decoded['refresh_token']) ? 'not_returned' : 'saved'),
        ));

        return $tokens;
    }
}
