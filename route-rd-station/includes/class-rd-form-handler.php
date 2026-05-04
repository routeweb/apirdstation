<?php
if (!defined('ABSPATH')) {
    exit;
}

class Route_RD_Form_Handler
{
    /**
     * @var Route_RD_Form_Handler|null
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
        add_shortcode('route_rd_form', array($this, 'shortcode'));
        add_action('admin_post_route_rd_form_submit', array($this, 'handle_shortcode_submit'));
        add_action('admin_post_nopriv_route_rd_form_submit', array($this, 'handle_shortcode_submit'));
        add_action('user_register', array($this, 'handle_user_register'), 20, 1);
        add_action('wpcf7_mail_sent', array($this, 'handle_cf7_submission'), 20, 1);
        add_action('elementor_pro/forms/new_record', array($this, 'handle_elementor_submission'), 20, 2);
    }

    public function shortcode($atts)
    {
        $atts = shortcode_atts(
            array(
                'conversion_identifier' => route_rd_get_settings()['conversion_identifier'],
                'tags' => '',
                'button_text' => __('Enviar', 'route-rd-station'),
                'redirect' => '',
            ),
            $atts,
            'route_rd_form'
        );

        $settings = route_rd_get_settings();
        $status = isset($_GET['route_rd_form_status']) ? sanitize_key(wp_unslash($_GET['route_rd_form_status'])) : '';
        $message = '';

        if ('success' === $status) {
            $message = '<div class="route-rd-form-message route-rd-success">' . esc_html__('Dados enviados com sucesso.', 'route-rd-station') . '</div>';
        } elseif ('error' === $status) {
            $message = '<div class="route-rd-form-message route-rd-error">' . esc_html__('Nao foi possivel enviar os dados. Verifique as informacoes e tente novamente.', 'route-rd-station') . '</div>';
        }

        ob_start();
        echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
        <form class="route-rd-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('route_rd_form_submit', 'route_rd_nonce'); ?>
            <input type="hidden" name="action" value="route_rd_form_submit">
            <input type="hidden" name="conversion_identifier" value="<?php echo esc_attr($atts['conversion_identifier']); ?>">
            <input type="hidden" name="tags" value="<?php echo esc_attr($atts['tags']); ?>">
            <input type="hidden" name="redirect" value="<?php echo esc_url($atts['redirect']); ?>">

            <p>
                <label><?php esc_html_e('Nome', 'route-rd-station'); ?><br>
                    <input type="text" name="name" required>
                </label>
            </p>
            <p>
                <label><?php esc_html_e('Email', 'route-rd-station'); ?><br>
                    <input type="email" name="email" required>
                </label>
            </p>
            <p>
                <label><?php esc_html_e('Telefone', 'route-rd-station'); ?><br>
                    <input type="text" name="mobile_phone">
                </label>
            </p>
            <p>
                <label><?php esc_html_e('Empresa', 'route-rd-station'); ?><br>
                    <input type="text" name="company">
                </label>
            </p>
            <p>
                <label><?php esc_html_e('Cidade', 'route-rd-station'); ?><br>
                    <input type="text" name="city">
                </label>
            </p>
            <p>
                <label><?php esc_html_e('Estado', 'route-rd-station'); ?><br>
                    <input type="text" name="state">
                </label>
            </p>
            <p>
                <label><?php esc_html_e('Mensagem', 'route-rd-station'); ?><br>
                    <textarea name="cf_mensagem" rows="4"></textarea>
                </label>
            </p>
            <?php if (!empty($settings['enable_lgpd'])) : ?>
                <p>
                    <label>
                        <input type="checkbox" name="route_rd_lgpd" value="1" required>
                        <?php esc_html_e('Aceito receber comunicacoes.', 'route-rd-station'); ?>
                    </label>
                </p>
            <?php endif; ?>
            <p>
                <button type="submit"><?php echo esc_html($atts['button_text']); ?></button>
            </p>
        </form>
        <?php
        return ob_get_clean();
    }

    public function handle_shortcode_submit()
    {
        if (!isset($_POST['route_rd_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['route_rd_nonce'])), 'route_rd_form_submit')) {
            wp_die(esc_html__('Falha de seguranca. Recarregue a pagina e tente novamente.', 'route-rd-station'));
        }

        $settings = route_rd_get_settings();
        $redirect = isset($_POST['redirect']) ? esc_url_raw(wp_unslash($_POST['redirect'])) : '';
        $fallback = wp_get_referer() ? wp_get_referer() : home_url('/');

        if (!empty($settings['enable_lgpd']) && empty($_POST['route_rd_lgpd'])) {
            $this->redirect_with_status($redirect ?: $fallback, 'error');
        }

        $data = $this->sanitize_submission(wp_unslash($_POST));
        $conversion_identifier = isset($_POST['conversion_identifier']) ? sanitize_text_field(wp_unslash($_POST['conversion_identifier'])) : '';
        $tags = isset($_POST['tags']) ? sanitize_text_field(wp_unslash($_POST['tags'])) : '';

        $payload = route_rd_build_payload($data, $conversion_identifier, $tags);
        $result = Route_RD_API::instance()->send_conversion(array('payload' => $payload), 'shortcode');

        if (is_wp_error($result)) {
            $this->redirect_with_status($fallback, 'error');
        }

        $this->redirect_with_status($redirect ?: $fallback, 'success');
    }

    public function handle_user_register($user_id)
    {
        $user = get_userdata($user_id);
        if (!$user || empty($user->user_email)) {
            return;
        }

        $data = array(
            'email' => $user->user_email,
            'name' => $user->display_name,
        );

        $payload = route_rd_build_payload($data, __('Cadastro WordPress', 'route-rd-station'), array('wordpress-user'));
        Route_RD_API::instance()->send_conversion(array('payload' => $payload), 'user_register');
    }

    public function handle_cf7_submission($contact_form)
    {
        $settings = route_rd_get_settings();
        if (empty($settings['enable_cf7']) || !class_exists('WPCF7_Submission')) {
            return;
        }

        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            return;
        }

        $posted = $submission->get_posted_data();
        $data = array(
            'name' => $this->value_from($posted, $settings['cf7_name_field']),
            'email' => $this->value_from($posted, $settings['cf7_email_field']),
            'mobile_phone' => $this->value_from($posted, $settings['cf7_phone_field']),
            'city' => $this->value_from($posted, $settings['cf7_city_field']),
            'state' => $this->value_from($posted, $settings['cf7_state_field']),
            'cf_mensagem' => $this->value_from($posted, $settings['cf7_message_field']),
        );

        foreach ($posted as $key => $value) {
            if (!isset($data[$key])) {
                $data[sanitize_key($key)] = is_array($value) ? implode(', ', array_map('sanitize_text_field', $value)) : sanitize_text_field($value);
            }
        }

        $payload = route_rd_build_payload($data, $settings['cf7_conversion_identifier'], $settings['cf7_tags']);
        Route_RD_API::instance()->send_conversion(array('payload' => $payload), 'contact-form-7');
    }

    public function handle_elementor_submission($record, $handler)
    {
        $settings = route_rd_get_settings();
        if (empty($settings['enable_elementor'])) {
            return;
        }

        $form_name = method_exists($record, 'get_form_settings') ? (string) $record->get_form_settings('form_name') : '';
        $form_id = method_exists($record, 'get_form_settings') ? (string) $record->get_form_settings('id') : '';
        if ('' === $form_id && method_exists($record, 'get_form_settings')) {
            $form_id = (string) $record->get_form_settings('form_id');
        }
        $allowed = route_rd_parse_tags($settings['elementor_forms']);
        if (!empty($allowed) && !in_array($form_name, $allowed, true) && !in_array($form_id, $allowed, true)) {
            return;
        }

        $fields = method_exists($record, 'get') ? $record->get('fields') : array();
        $data = array();

        if (is_array($fields)) {
            foreach ($fields as $field) {
                if (!is_array($field) || empty($field['id'])) {
                    continue;
                }
                $data[sanitize_key($field['id'])] = isset($field['value']) ? sanitize_text_field($field['value']) : '';
            }
        }

        $data = $this->normalize_common_fields($data);
        $payload = route_rd_build_payload($data, $settings['elementor_conversion_identifier'], $settings['elementor_tags']);
        Route_RD_API::instance()->send_conversion(array('payload' => $payload), 'elementor');
    }

    public function sanitize_submission($raw)
    {
        $data = array();
        foreach ((array) $raw as $key => $value) {
            $key = sanitize_key($key);
            if (in_array($key, array('action', 'route_rd_nonce', 'redirect', 'tags', 'conversion_identifier', 'route_rd_lgpd'), true)) {
                continue;
            }
            $data[$key] = is_array($value) ? implode(', ', array_map('sanitize_text_field', $value)) : sanitize_text_field($value);
        }

        return $this->normalize_common_fields($data);
    }

    private function normalize_common_fields($data)
    {
        $aliases = array(
            'name' => array('name', 'nome', 'your-name', 'first_name'),
            'email' => array('email', 'your-email', 'e-mail'),
            'mobile_phone' => array('mobile_phone', 'telefone', 'phone', 'celular', 'your-phone'),
            'city' => array('city', 'cidade'),
            'state' => array('state', 'estado', 'uf'),
            'cf_mensagem' => array('message', 'mensagem', 'your-message'),
            'company' => array('company', 'empresa'),
        );

        foreach ($aliases as $target => $keys) {
            if (!empty($data[$target])) {
                continue;
            }
            foreach ($keys as $key) {
                $safe = sanitize_key($key);
                if (!empty($data[$safe])) {
                    $data[$target] = $data[$safe];
                    break;
                }
            }
        }

        return $data;
    }

    private function value_from($data, $key)
    {
        $key = (string) $key;
        if (isset($data[$key])) {
            return is_array($data[$key]) ? implode(', ', array_map('sanitize_text_field', $data[$key])) : sanitize_text_field($data[$key]);
        }

        return '';
    }

    private function redirect_with_status($url, $status)
    {
        wp_safe_redirect(add_query_arg('route_rd_form_status', sanitize_key($status), $url));
        exit;
    }
}
