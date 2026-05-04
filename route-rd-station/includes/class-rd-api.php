<?php
if (!defined('ABSPATH')) {
    exit;
}

class Route_RD_API
{
    const BASE_URL = 'https://api.rd.services';

    /**
     * @var Route_RD_API|null
     */
    private static $instance = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function send_conversion($data, $source = 'manual')
    {
        $settings = route_rd_get_settings();
        $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : route_rd_build_payload($data);
        $email = isset($payload['email']) ? sanitize_email($payload['email']) : '';

        if (empty($email) || !is_email($email)) {
            $error = __('Email invalido ou ausente.', 'route-rd-station');
            Route_RD_Logger::add(array(
                'source' => $source,
                'action' => 'send_conversion',
                'email' => $email,
                'status' => 'error',
                'request_payload' => $payload,
                'error_message' => $error,
            ));
            return new WP_Error('route_rd_invalid_email', $error);
        }

        if (!empty($settings['update_contact_before_conversion']) && 'oauth' === $settings['auth_mode']) {
            $this->create_or_update_contact($payload, $source);
        }

        $body = array(
            'event_type' => 'CONVERSION',
            'event_family' => 'CDP',
            'payload' => $payload,
        );

        if ('api_key' === $settings['auth_mode']) {
            $api_key = route_rd_decrypt($settings['api_key']);
            if ('' === $api_key) {
                $error = __('API Key RD Station ausente.', 'route-rd-station');
                Route_RD_Logger::add(array(
                    'source' => $source,
                    'action' => 'send_conversion_api_key',
                    'email' => $email,
                    'status' => 'error',
                    'request_payload' => $body,
                    'error_message' => $error,
                ));
                return new WP_Error('route_rd_missing_api_key', $error);
            }

            $endpoint = '/platform/conversions?api_key=' . rawurlencode($api_key);
            $result = $this->request('POST', $endpoint, $body, false);
            $action = 'send_conversion_api_key';
        } else {
            $result = $this->request('POST', '/platform/events?event_type=conversion', $body, true);
            $action = 'send_conversion_oauth';
        }

        $this->log_result($source, $action, $email, $body, $result);

        return $result;
    }

    public function create_or_update_contact($data, $source = 'manual')
    {
        $email = isset($data['email']) ? sanitize_email($data['email']) : '';
        if (empty($email) || !is_email($email)) {
            return new WP_Error('route_rd_invalid_email', __('Email invalido para atualizar contato.', 'route-rd-station'));
        }

        $body = $data;
        unset($body['email'], $body['conversion_identifier']);

        $endpoint = '/platform/contacts/email:' . rawurlencode($email);
        $result = $this->request('PATCH', $endpoint, $body, true);
        $this->log_result($source, 'create_or_update_contact', $email, $body, $result);

        return $result;
    }

    public function add_tags_to_contact($email, $tags)
    {
        $email = sanitize_email($email);
        if (empty($email) || !is_email($email)) {
            return new WP_Error('route_rd_invalid_email', __('Email invalido para adicionar tags.', 'route-rd-station'));
        }

        $body = array('tags' => route_rd_parse_tags($tags));
        if (empty($body['tags'])) {
            return new WP_Error('route_rd_missing_tags', __('Nenhuma tag informada.', 'route-rd-station'));
        }

        $endpoint = '/platform/contacts/email:' . rawurlencode($email) . '/tag';
        $result = $this->request('POST', $endpoint, $body, true);
        $this->log_result('manual', 'add_tags_to_contact', $email, $body, $result);

        return $result;
    }

    public function refresh_token()
    {
        return Route_RD_OAuth::instance()->refresh_access_token();
    }

    public function request($method, $endpoint, $body = array(), $auth = true, $retry = true)
    {
        $method = strtoupper(sanitize_text_field($method));
        $url = 0 === strpos($endpoint, 'http') ? $endpoint : self::BASE_URL . $endpoint;

        $headers = array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'Route RD Station Integration/' . ROUTE_RD_VERSION . '; ' . home_url(),
        );

        if ($auth) {
            $token = Route_RD_OAuth::instance()->get_access_token();
            if (is_wp_error($token)) {
                return $token;
            }
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $args = array(
            'method' => $method,
            'timeout' => 25,
            'headers' => $headers,
        );

        if ('GET' !== $method) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded = json_decode($response_body, true);

        if (401 === (int) $code && $auth && $retry) {
            $refreshed = Route_RD_OAuth::instance()->refresh_access_token();
            if (!is_wp_error($refreshed)) {
                return $this->request($method, $endpoint, $body, $auth, false);
            }

            $tokens = route_rd_get_tokens();
            $tokens['connection_status'] = 'invalid';
            route_rd_update_tokens($tokens);
        }

        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'route_rd_api_error',
                $this->extract_error_message($decoded, $response_body, $code),
                array(
                    'status' => $code,
                    'body' => $response_body,
                    'decoded' => $decoded,
                )
            );
        }

        return array(
            'success' => true,
            'status' => $code,
            'body' => $response_body,
            'data' => is_array($decoded) ? $decoded : array(),
        );
    }

    public function resend_from_log($log_id)
    {
        $row = Route_RD_Logger::get($log_id);
        if (!$row) {
            return new WP_Error('route_rd_log_not_found', __('Log nao encontrado.', 'route-rd-station'));
        }

        $payload = Route_RD_Logger::decode($row['request_payload']);
        if (!is_array($payload)) {
            return new WP_Error('route_rd_invalid_log_payload', __('Payload do log invalido.', 'route-rd-station'));
        }

        if (isset($payload['payload']) && is_array($payload['payload'])) {
            return $this->send_conversion(array('payload' => $payload['payload']), 'resend-log-' . absint($log_id));
        }

        return $this->send_conversion(array('payload' => $payload), 'resend-log-' . absint($log_id));
    }

    private function log_result($source, $action, $email, $request_payload, $result)
    {
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            Route_RD_Logger::add(array(
                'source' => $source,
                'action' => $action,
                'email' => $email,
                'status' => 'error',
                'request_payload' => $request_payload,
                'response_code' => is_array($data) && isset($data['status']) ? absint($data['status']) : null,
                'response_body' => is_array($data) && isset($data['body']) ? $data['body'] : '',
                'error_message' => $result->get_error_message(),
            ));
            return;
        }

        Route_RD_Logger::add(array(
            'source' => $source,
            'action' => $action,
            'email' => $email,
            'status' => 'success',
            'request_payload' => $request_payload,
            'response_code' => isset($result['status']) ? absint($result['status']) : null,
            'response_body' => isset($result['body']) ? $result['body'] : '',
        ));
    }

    private function extract_error_message($decoded, $body, $code)
    {
        if (is_array($decoded)) {
            foreach (array('error_description', 'message', 'error', 'errors') as $key) {
                if (!empty($decoded[$key])) {
                    return is_array($decoded[$key]) ? wp_json_encode($decoded[$key]) : sanitize_text_field($decoded[$key]);
                }
            }
        }

        if (!empty($body)) {
            return wp_strip_all_tags(substr($body, 0, 500));
        }

        return sprintf(__('Erro HTTP %d na API RD Station.', 'route-rd-station'), absint($code));
    }
}
