<?php
if (!defined('ABSPATH')) {
    exit;
}

class Route_RD_Admin
{
    /**
     * @var Route_RD_Admin|null
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
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('admin_post_route_rd_save_settings', array($this, 'save_settings'));
        add_action('admin_post_route_rd_connect', array($this, 'connect'));
        add_action('admin_post_route_rd_disconnect', array($this, 'disconnect'));
        add_action('admin_post_route_rd_refresh_token', array($this, 'refresh_token'));
        add_action('admin_post_route_rd_send_test', array($this, 'send_test'));
        add_action('admin_post_route_rd_resend_log', array($this, 'resend_log'));
        add_action('admin_post_route_rd_clear_logs', array($this, 'clear_logs'));
    }

    public function menu()
    {
        add_menu_page(
            __('RD Station', 'route-rd-station'),
            __('RD Station', 'route-rd-station'),
            'manage_options',
            'route-rd-station',
            array($this, 'render'),
            'dashicons-email-alt2',
            58
        );
    }

    public function register_settings()
    {
        register_setting('route_rd_settings_group', ROUTE_RD_OPTION, array($this, 'sanitize_settings'));
    }

    public function assets($hook)
    {
        if ('toplevel_page_route-rd-station' !== $hook) {
            return;
        }

        wp_enqueue_style('route-rd-admin', ROUTE_RD_URL . 'assets/admin.css', array(), ROUTE_RD_VERSION);
        wp_enqueue_script('route-rd-admin', ROUTE_RD_URL . 'assets/admin.js', array(), ROUTE_RD_VERSION, true);
    }

    public function render()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Voce nao tem permissao para acessar esta pagina.', 'route-rd-station'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'connection';
        $tabs = array(
            'connection' => __('Conexao', 'route-rd-station'),
            'conversion' => __('Configuracoes de Conversao', 'route-rd-station'),
            'mapping' => __('Mapeamento de Campos', 'route-rd-station'),
            'integrations' => __('Integracoes', 'route-rd-station'),
            'logs' => __('Logs', 'route-rd-station'),
            'tests' => __('Testes', 'route-rd-station'),
        );

        if (!isset($tabs[$tab])) {
            $tab = 'connection';
        }

        echo '<div class="wrap route-rd-admin">';
        echo '<h1>' . esc_html__('RD Station', 'route-rd-station') . '</h1>';
        $this->render_notice();
        $this->render_status_cards();

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $class = $key === $tab ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . esc_attr($class) . '" href="' . esc_url(route_rd_admin_url($key)) . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';

        echo '<div class="route-rd-panel">';
        switch ($tab) {
            case 'conversion':
                $this->render_conversion_tab();
                break;
            case 'mapping':
                $this->render_mapping_tab();
                break;
            case 'integrations':
                $this->render_integrations_tab();
                break;
            case 'logs':
                $this->render_logs_tab();
                break;
            case 'tests':
                $this->render_tests_tab();
                break;
            case 'connection':
            default:
                $this->render_connection_tab();
                break;
        }
        echo '</div>';
        echo '</div>';
    }

    public function sanitize_settings($raw)
    {
        $current = route_rd_get_settings();
        $raw = is_array($raw) ? $raw : array();
        $tab = isset($raw['_route_rd_tab']) ? sanitize_key($raw['_route_rd_tab']) : '';
        unset($raw['_route_rd_tab']);

        $settings = $current;
        if (isset($raw['auth_mode'])) {
            $settings['auth_mode'] = 'api_key' === $raw['auth_mode'] ? 'api_key' : 'oauth';
        }
        if (isset($raw['client_id'])) {
            $settings['client_id'] = sanitize_text_field($raw['client_id']);
        }

        if (!empty($raw['client_secret'])) {
            $settings['client_secret'] = route_rd_encrypt(sanitize_text_field($raw['client_secret']));
        }

        if (!empty($raw['api_key'])) {
            $settings['api_key'] = route_rd_encrypt(sanitize_text_field($raw['api_key']));
        }

        $text_fields = array(
            'conversion_identifier',
            'default_tags',
            'legal_category',
            'legal_type',
            'legal_status',
            'cf7_name_field',
            'cf7_email_field',
            'cf7_phone_field',
            'cf7_city_field',
            'cf7_state_field',
            'cf7_message_field',
            'cf7_conversion_identifier',
            'cf7_tags',
            'elementor_forms',
            'elementor_conversion_identifier',
            'elementor_tags',
            'wc_conversion_identifier',
        );

        foreach ($text_fields as $field) {
            if (isset($raw[$field])) {
                $settings[$field] = sanitize_text_field($raw[$field]);
            }
        }

        $bool_fields = array(
            'update_contact_before_conversion',
            'enable_lgpd',
            'enable_cf7',
            'enable_elementor',
            'enable_wc_order_created',
            'enable_wc_processing',
            'enable_wc_completed',
            'remove_data_on_uninstall',
        );

        foreach ($bool_fields as $field) {
            if (isset($raw[$field])) {
                $settings[$field] = route_rd_sanitize_bool($raw[$field]);
            } elseif ($this->checkbox_belongs_to_tab($field, $tab)) {
                $settings[$field] = 0;
            }
        }

        if (isset($raw['log_retention_days'])) {
            $settings['log_retention_days'] = absint($raw['log_retention_days']);
            if (!in_array($settings['log_retention_days'], array(7, 15, 30, 90), true)) {
                $settings['log_retention_days'] = 90;
            }
        }

        if (isset($raw['field_mappings']) || 'mapping' === $tab) {
            $settings['field_mappings'] = isset($raw['field_mappings']) ? route_rd_sanitize_mappings($raw['field_mappings']) : array();
        }

        return $settings;
    }

    public function save_settings()
    {
        $this->guard('route_rd_save_settings');
        $raw = isset($_POST[ROUTE_RD_OPTION]) ? wp_unslash($_POST[ROUTE_RD_OPTION]) : array();
        if (!is_array($raw)) {
            $raw = array();
        }
        $raw['_route_rd_tab'] = $this->posted_tab();
        route_rd_update_settings($this->sanitize_settings($raw));
        $this->redirect('saved', $this->posted_tab());
    }

    public function connect()
    {
        $this->guard('route_rd_connect');
        $url = Route_RD_OAuth::instance()->authorization_url();
        if ('' === $url) {
            $this->redirect('missing_credentials', 'connection');
        }
        wp_safe_redirect($url);
        exit;
    }

    public function disconnect()
    {
        $this->guard('route_rd_disconnect');
        Route_RD_OAuth::instance()->disconnect();
        $this->redirect('disconnected', 'connection');
    }

    public function refresh_token()
    {
        $this->guard('route_rd_refresh_token');
        $result = Route_RD_OAuth::instance()->refresh_access_token();
        $this->redirect(is_wp_error($result) ? 'refresh_error' : 'refresh_success', 'connection');
    }

    public function send_test()
    {
        $this->guard('route_rd_send_test');

        $data = array(
            'email' => isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '',
            'name' => isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '',
            'mobile_phone' => isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '',
        );
        $conversion_identifier = isset($_POST['conversion_identifier']) ? sanitize_text_field(wp_unslash($_POST['conversion_identifier'])) : '';
        $tags = isset($_POST['tags']) ? sanitize_text_field(wp_unslash($_POST['tags'])) : '';

        $payload = route_rd_build_payload($data, $conversion_identifier, $tags);
        $result = Route_RD_API::instance()->send_conversion(array('payload' => $payload), 'admin-test');

        set_transient(
            'route_rd_last_test_' . get_current_user_id(),
            array(
                'success' => !is_wp_error($result),
                'message' => is_wp_error($result) ? $result->get_error_message() : __('Teste enviado com sucesso.', 'route-rd-station'),
                'payload' => $payload,
                'result' => is_wp_error($result) ? $result->get_error_data() : $result,
            ),
            10 * MINUTE_IN_SECONDS
        );

        $this->redirect(is_wp_error($result) ? 'test_error' : 'test_success', 'tests');
    }

    public function resend_log()
    {
        $this->guard('route_rd_resend_log');
        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        $result = Route_RD_API::instance()->resend_from_log($log_id);
        $this->redirect(is_wp_error($result) ? 'resend_error' : 'resend_success', 'logs');
    }

    public function clear_logs()
    {
        $this->guard('route_rd_clear_logs');
        $days = isset($_POST['days']) ? absint($_POST['days']) : 90;
        if (!in_array($days, array(7, 15, 30, 90), true)) {
            $days = 90;
        }
        Route_RD_Logger::clear_older_than($days);
        $this->redirect('logs_cleared', 'logs');
    }

    private function render_status_cards()
    {
        $settings = route_rd_get_settings();
        $tokens = route_rd_get_tokens();
        $counts = Route_RD_Logger::counts();
        $latest = Route_RD_Logger::latest();
        $connected = !empty($tokens['access_token']) && 'invalid' !== ($tokens['connection_status'] ?? '');
        $mode = 'api_key' === $settings['auth_mode'] ? __('API Key', 'route-rd-station') : __('OAuth2', 'route-rd-station');

        echo '<div class="route-rd-cards">';
        $this->card(__('Conexao RD Station', 'route-rd-station'), $connected ? __('Conectado', 'route-rd-station') : __('Nao conectado', 'route-rd-station'), $connected ? 'good' : 'bad');
        $this->card(__('Modo de autenticacao', 'route-rd-station'), $mode, 'neutral');
        $this->card(__('Ultimo envio', 'route-rd-station'), $latest ? $latest['created_at'] . ' - ' . $latest['status'] : __('Nenhum envio', 'route-rd-station'), $latest && 'error' === $latest['status'] ? 'bad' : 'neutral');
        $this->card(__('Sucessos', 'route-rd-station'), (string) $counts['success'], 'good');
        $this->card(__('Erros', 'route-rd-station'), (string) $counts['error'], $counts['error'] > 0 ? 'bad' : 'neutral');
        echo '</div>';
    }

    private function checkbox_belongs_to_tab($field, $tab)
    {
        $groups = array(
            'conversion' => array('update_contact_before_conversion', 'enable_lgpd', 'remove_data_on_uninstall'),
            'integrations' => array('enable_cf7', 'enable_elementor', 'enable_wc_order_created', 'enable_wc_processing', 'enable_wc_completed'),
        );

        return isset($groups[$tab]) && in_array($field, $groups[$tab], true);
    }

    private function card($title, $value, $state)
    {
        echo '<div class="route-rd-card route-rd-' . esc_attr($state) . '">';
        echo '<strong>' . esc_html($title) . '</strong>';
        echo '<span>' . esc_html($value) . '</span>';
        echo '</div>';
    }

    private function render_connection_tab()
    {
        $settings = route_rd_get_settings();
        $tokens = route_rd_get_tokens();
        $client_secret = route_rd_decrypt($settings['client_secret']);
        $api_key = route_rd_decrypt($settings['api_key']);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="route-rd-form-table">
            <?php wp_nonce_field('route_rd_save_settings'); ?>
            <input type="hidden" name="action" value="route_rd_save_settings">
            <input type="hidden" name="tab" value="connection">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Modo de autenticacao', 'route-rd-station'); ?></th>
                    <td>
                        <select name="<?php echo esc_attr(ROUTE_RD_OPTION); ?>[auth_mode]">
                            <option value="oauth" <?php selected($settings['auth_mode'], 'oauth'); ?>>OAuth2</option>
                            <option value="api_key" <?php selected($settings['auth_mode'], 'api_key'); ?>>API Key simples</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Client ID</th>
                    <td><input type="text" class="regular-text" name="<?php echo esc_attr(ROUTE_RD_OPTION); ?>[client_id]" value="<?php echo esc_attr($settings['client_id']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row">Client Secret</th>
                    <td>
                        <input type="password" class="regular-text" name="<?php echo esc_attr(ROUTE_RD_OPTION); ?>[client_secret]" value="" placeholder="<?php echo esc_attr(route_rd_mask_secret($client_secret)); ?>">
                        <p class="description"><?php esc_html_e('Deixe em branco para manter o valor salvo.', 'route-rd-station'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Callback URL', 'route-rd-station'); ?></th>
                    <td><code><?php echo esc_html(Route_RD_OAuth::instance()->callback_url()); ?></code></td>
                </tr>
                <tr>
                    <th scope="row">API Key RD Station</th>
                    <td>
                        <input type="password" class="regular-text" name="<?php echo esc_attr(ROUTE_RD_OPTION); ?>[api_key]" value="" placeholder="<?php echo esc_attr(route_rd_mask_secret($api_key)); ?>">
                        <p class="description"><?php esc_html_e('Usada somente quando o modo API Key estiver ativo.', 'route-rd-station'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Status dos tokens', 'route-rd-station'); ?></th>
                    <td>
                        <p><?php esc_html_e('Access Token salvo:', 'route-rd-station'); ?> <strong><?php echo empty($tokens['access_token']) ? esc_html__('Nao', 'route-rd-station') : esc_html__('Sim', 'route-rd-station'); ?></strong></p>
                        <p><?php esc_html_e('Refresh Token salvo:', 'route-rd-station'); ?> <strong><?php echo empty($tokens['refresh_token']) ? esc_html__('Nao', 'route-rd-station') : esc_html__('Sim', 'route-rd-station'); ?></strong></p>
                        <p><?php esc_html_e('Ultima renovacao:', 'route-rd-station'); ?> <strong><?php echo esc_html($tokens['last_refresh'] ?? '-'); ?></strong></p>
                        <p><?php esc_html_e('Expiracao:', 'route-rd-station'); ?> <strong><?php echo !empty($tokens['expires_at']) ? esc_html(date_i18n('Y-m-d H:i:s', absint($tokens['expires_at']))) : '-'; ?></strong></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Salvar configuracoes', 'route-rd-station')); ?>
        </form>
        <div class="route-rd-actions">
            <?php $this->action_button('route_rd_connect', 'route_rd_connect', __('Conectar com RD Station', 'route-rd-station'), 'primary'); ?>
            <?php $this->action_button('route_rd_refresh_token', 'route_rd_refresh_token', __('Renovar Token', 'route-rd-station'), 'secondary'); ?>
            <?php $this->action_button('route_rd_disconnect', 'route_rd_disconnect', __('Desconectar', 'route-rd-station'), 'secondary'); ?>
        </div>
        <?php
    }

    private function render_conversion_tab()
    {
        $settings = route_rd_get_settings();
        $this->settings_form_start('conversion');
        ?>
        <table class="form-table" role="presentation">
            <?php $this->text_row('conversion_identifier', __('Conversion Identifier padrao', 'route-rd-station'), $settings['conversion_identifier']); ?>
            <?php $this->text_row('default_tags', __('Tags padrao', 'route-rd-station'), $settings['default_tags']); ?>
            <?php $this->checkbox_row('update_contact_before_conversion', __('Criar/atualizar contato antes da conversao', 'route-rd-station'), $settings['update_contact_before_conversion']); ?>
            <?php $this->checkbox_row('enable_lgpd', __('Enviar base legal LGPD', 'route-rd-station'), $settings['enable_lgpd']); ?>
            <?php $this->text_row('legal_category', __('LGPD category', 'route-rd-station'), $settings['legal_category']); ?>
            <?php $this->text_row('legal_type', __('LGPD type', 'route-rd-station'), $settings['legal_type']); ?>
            <?php $this->text_row('legal_status', __('LGPD status', 'route-rd-station'), $settings['legal_status']); ?>
            <tr>
                <th scope="row"><?php esc_html_e('Retencao de logs', 'route-rd-station'); ?></th>
                <td>
                    <select name="<?php echo esc_attr(ROUTE_RD_OPTION); ?>[log_retention_days]">
                        <?php foreach (array(7, 15, 30, 90) as $days) : ?>
                            <option value="<?php echo esc_attr($days); ?>" <?php selected((int) $settings['log_retention_days'], $days); ?>><?php echo esc_html($days); ?> dias</option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <?php $this->checkbox_row('remove_data_on_uninstall', __('Remover dados ao desinstalar', 'route-rd-station'), $settings['remove_data_on_uninstall']); ?>
        </table>
        <?php
        $this->settings_form_end();
    }

    private function render_mapping_tab()
    {
        $settings = route_rd_get_settings();
        $this->settings_form_start('mapping');
        echo '<p>' . esc_html__('Configure pares campo_formulario => campo_rd. Use campos RD como name, email, mobile_phone ou campos personalizados cf_*.', 'route-rd-station') . '</p>';
        echo '<table class="widefat striped route-rd-mapping"><thead><tr><th>' . esc_html__('Campo do formulario', 'route-rd-station') . '</th><th>' . esc_html__('Campo RD Station', 'route-rd-station') . '</th><th></th></tr></thead><tbody>';
        $rows = !empty($settings['field_mappings']) ? $settings['field_mappings'] : array(array('source' => '', 'target' => ''));
        foreach ($rows as $index => $row) {
            $this->mapping_row($index, $row);
        }
        echo '</tbody></table>';
        echo '<p><button type="button" class="button" id="route-rd-add-mapping">' . esc_html__('Adicionar mapeamento', 'route-rd-station') . '</button></p>';
        $this->settings_form_end();
    }

    private function render_integrations_tab()
    {
        $settings = route_rd_get_settings();
        $this->settings_form_start('integrations');
        ?>
        <h2>Contact Form 7</h2>
        <table class="form-table" role="presentation">
            <?php $this->checkbox_row('enable_cf7', __('Ativar integracao com Contact Form 7', 'route-rd-station'), $settings['enable_cf7']); ?>
            <?php $this->text_row('cf7_name_field', __('Campo de nome', 'route-rd-station'), $settings['cf7_name_field']); ?>
            <?php $this->text_row('cf7_email_field', __('Campo de email', 'route-rd-station'), $settings['cf7_email_field']); ?>
            <?php $this->text_row('cf7_phone_field', __('Campo de telefone', 'route-rd-station'), $settings['cf7_phone_field']); ?>
            <?php $this->text_row('cf7_city_field', __('Campo de cidade', 'route-rd-station'), $settings['cf7_city_field']); ?>
            <?php $this->text_row('cf7_state_field', __('Campo de estado', 'route-rd-station'), $settings['cf7_state_field']); ?>
            <?php $this->text_row('cf7_message_field', __('Campo de mensagem', 'route-rd-station'), $settings['cf7_message_field']); ?>
            <?php $this->text_row('cf7_conversion_identifier', __('Conversion Identifier', 'route-rd-station'), $settings['cf7_conversion_identifier']); ?>
            <?php $this->text_row('cf7_tags', __('Tags', 'route-rd-station'), $settings['cf7_tags']); ?>
        </table>
        <h2>Elementor Forms</h2>
        <table class="form-table" role="presentation">
            <?php $this->checkbox_row('enable_elementor', __('Ativar integracao com Elementor Forms', 'route-rd-station'), $settings['enable_elementor']); ?>
            <?php $this->text_row('elementor_forms', __('Nomes dos formularios habilitados', 'route-rd-station'), $settings['elementor_forms']); ?>
            <?php $this->text_row('elementor_conversion_identifier', __('Conversion Identifier', 'route-rd-station'), $settings['elementor_conversion_identifier']); ?>
            <?php $this->text_row('elementor_tags', __('Tags', 'route-rd-station'), $settings['elementor_tags']); ?>
        </table>
        <h2>WooCommerce</h2>
        <table class="form-table" role="presentation">
            <?php $this->checkbox_row('enable_wc_order_created', __('Enviar cliente quando pedido for criado', 'route-rd-station'), $settings['enable_wc_order_created']); ?>
            <?php $this->checkbox_row('enable_wc_processing', __('Enviar evento quando pedido mudar para processing', 'route-rd-station'), $settings['enable_wc_processing']); ?>
            <?php $this->checkbox_row('enable_wc_completed', __('Enviar evento quando pedido mudar para completed', 'route-rd-station'), $settings['enable_wc_completed']); ?>
            <?php $this->text_row('wc_conversion_identifier', __('Conversion Identifier', 'route-rd-station'), $settings['wc_conversion_identifier']); ?>
        </table>
        <?php
        $this->settings_form_end();
    }

    private function render_logs_tab()
    {
        $logs = Route_RD_Logger::get_logs(100, 0);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="route-rd-clear-logs">
            <?php wp_nonce_field('route_rd_clear_logs'); ?>
            <input type="hidden" name="action" value="route_rd_clear_logs">
            <select name="days">
                <?php foreach (array(7, 15, 30, 90) as $days) : ?>
                    <option value="<?php echo esc_attr($days); ?>"><?php echo esc_html(sprintf(__('Mais de %d dias', 'route-rd-station'), $days)); ?></option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Limpar logs antigos', 'route-rd-station'), 'secondary', 'submit', false); ?>
        </form>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Data', 'route-rd-station'); ?></th>
                    <th><?php esc_html_e('Origem', 'route-rd-station'); ?></th>
                    <th><?php esc_html_e('Acao', 'route-rd-station'); ?></th>
                    <th><?php esc_html_e('Email', 'route-rd-station'); ?></th>
                    <th><?php esc_html_e('Status', 'route-rd-station'); ?></th>
                    <th><?php esc_html_e('HTTP', 'route-rd-station'); ?></th>
                    <th><?php esc_html_e('Mensagem', 'route-rd-station'); ?></th>
                    <th><?php esc_html_e('Payload', 'route-rd-station'); ?></th>
                    <th><?php esc_html_e('Reenviar', 'route-rd-station'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)) : ?>
                    <tr><td colspan="9"><?php esc_html_e('Nenhum log encontrado.', 'route-rd-station'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log) : ?>
                    <tr>
                        <td><?php echo esc_html($log['created_at']); ?></td>
                        <td><?php echo esc_html($log['source']); ?></td>
                        <td><?php echo esc_html($log['action']); ?></td>
                        <td><?php echo esc_html($log['email']); ?></td>
                        <td><?php echo esc_html($log['status']); ?></td>
                        <td><?php echo esc_html($log['response_code']); ?></td>
                        <td><?php echo esc_html($log['error_message']); ?></td>
                        <td><details><summary><?php esc_html_e('Ver payload', 'route-rd-station'); ?></summary><pre><?php echo esc_html(wp_json_encode(Route_RD_Logger::decode($log['request_payload']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('route_rd_resend_log'); ?>
                                <input type="hidden" name="action" value="route_rd_resend_log">
                                <input type="hidden" name="log_id" value="<?php echo esc_attr($log['id']); ?>">
                                <?php submit_button(__('Reenviar', 'route-rd-station'), 'secondary small', 'submit', false); ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_tests_tab()
    {
        $settings = route_rd_get_settings();
        $last = get_transient('route_rd_last_test_' . get_current_user_id());
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('route_rd_send_test'); ?>
            <input type="hidden" name="action" value="route_rd_send_test">
            <table class="form-table" role="presentation">
                <tr><th scope="row">Email</th><td><input type="email" class="regular-text" name="email" required></td></tr>
                <tr><th scope="row"><?php esc_html_e('Nome', 'route-rd-station'); ?></th><td><input type="text" class="regular-text" name="name"></td></tr>
                <tr><th scope="row"><?php esc_html_e('Telefone', 'route-rd-station'); ?></th><td><input type="text" class="regular-text" name="phone"></td></tr>
                <tr><th scope="row">Conversion Identifier</th><td><input type="text" class="regular-text" name="conversion_identifier" value="<?php echo esc_attr($settings['conversion_identifier']); ?>"></td></tr>
                <tr><th scope="row">Tags</th><td><input type="text" class="regular-text" name="tags" value="<?php echo esc_attr($settings['default_tags']); ?>"></td></tr>
            </table>
            <?php submit_button(__('Enviar teste para RD Station', 'route-rd-station')); ?>
        </form>
        <?php if (is_array($last)) : ?>
            <h2><?php esc_html_e('Resultado do ultimo teste', 'route-rd-station'); ?></h2>
            <p><strong><?php echo $last['success'] ? esc_html__('Sucesso', 'route-rd-station') : esc_html__('Erro', 'route-rd-station'); ?>:</strong> <?php echo esc_html($last['message']); ?></p>
            <details open><summary>Payload</summary><pre><?php echo esc_html(wp_json_encode($last['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
            <details><summary>Resposta</summary><pre><?php echo esc_html(wp_json_encode($last['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
        <?php endif; ?>
        <?php
    }

    private function settings_form_start($tab)
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('route_rd_save_settings');
        echo '<input type="hidden" name="action" value="route_rd_save_settings">';
        echo '<input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
    }

    private function settings_form_end()
    {
        submit_button(__('Salvar configuracoes', 'route-rd-station'));
        echo '</form>';
    }

    private function text_row($key, $label, $value)
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td><input type="text" class="regular-text" name="' . esc_attr(ROUTE_RD_OPTION) . '[' . esc_attr($key) . ']" value="' . esc_attr($value) . '"></td></tr>';
    }

    private function checkbox_row($key, $label, $value)
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label><input type="checkbox" name="' . esc_attr(ROUTE_RD_OPTION) . '[' . esc_attr($key) . ']" value="1" ' . checked($value, 1, false) . '> ' . esc_html__('Ativar', 'route-rd-station') . '</label></td></tr>';
    }

    private function mapping_row($index, $row)
    {
        echo '<tr>';
        echo '<td><input type="text" name="' . esc_attr(ROUTE_RD_OPTION) . '[field_mappings][' . esc_attr($index) . '][source]" value="' . esc_attr($row['source']) . '"></td>';
        echo '<td><input type="text" name="' . esc_attr(ROUTE_RD_OPTION) . '[field_mappings][' . esc_attr($index) . '][target]" value="' . esc_attr($row['target']) . '"></td>';
        echo '<td><button type="button" class="button route-rd-remove-mapping">' . esc_html__('Remover', 'route-rd-station') . '</button></td>';
        echo '</tr>';
    }

    private function action_button($action, $nonce, $label, $class)
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field($nonce);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        submit_button($label, $class, 'submit', false);
        echo '</form>';
    }

    private function guard($nonce)
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permissao insuficiente.', 'route-rd-station'));
        }
        check_admin_referer($nonce);
    }

    private function posted_tab()
    {
        return isset($_POST['tab']) ? sanitize_key(wp_unslash($_POST['tab'])) : 'connection';
    }

    private function redirect($message, $tab)
    {
        wp_safe_redirect(add_query_arg('route_rd_message', sanitize_key($message), route_rd_admin_url($tab)));
        exit;
    }

    private function render_notice()
    {
        $message = isset($_GET['route_rd_message']) ? sanitize_key(wp_unslash($_GET['route_rd_message'])) : '';
        if ('' === $message && isset($_GET['route_rd_oauth'])) {
            $message = sanitize_key(wp_unslash($_GET['route_rd_oauth']));
        }

        $messages = array(
            'saved' => __('Configuracoes salvas.', 'route-rd-station'),
            'missing_credentials' => __('Informe Client ID e Client Secret antes de conectar.', 'route-rd-station'),
            'disconnected' => __('Conexao removida.', 'route-rd-station'),
            'refresh_success' => __('Token renovado com sucesso.', 'route-rd-station'),
            'refresh_error' => __('Erro ao renovar token. Confira os logs.', 'route-rd-station'),
            'test_success' => __('Teste enviado com sucesso.', 'route-rd-station'),
            'test_error' => __('Erro ao enviar teste. Confira o resultado abaixo e os logs.', 'route-rd-station'),
            'resend_success' => __('Log reenviado com sucesso.', 'route-rd-station'),
            'resend_error' => __('Nao foi possivel reenviar o log.', 'route-rd-station'),
            'logs_cleared' => __('Logs antigos removidos.', 'route-rd-station'),
            'connected' => __('OAuth conectado com sucesso.', 'route-rd-station'),
            'error' => __('Erro no OAuth. Confira os logs.', 'route-rd-station'),
        );

        if (isset($messages[$message])) {
            $class = false !== strpos($message, 'error') || 'missing_credentials' === $message ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($messages[$message]) . '</p></div>';
        }
    }
}
