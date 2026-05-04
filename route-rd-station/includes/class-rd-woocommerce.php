<?php
if (!defined('ABSPATH')) {
    exit;
}

class Route_RD_WooCommerce
{
    /**
     * @var Route_RD_WooCommerce|null
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
        add_action('woocommerce_new_order', array($this, 'handle_order_created'), 20, 1);
        add_action('woocommerce_order_status_processing', array($this, 'handle_order_processing'), 20, 1);
        add_action('woocommerce_order_status_completed', array($this, 'handle_order_completed'), 20, 1);
    }

    public function handle_order_created($order_id)
    {
        $settings = route_rd_get_settings();
        if (empty($settings['enable_wc_order_created'])) {
            return;
        }
        $this->send_order($order_id, 'woocommerce_order_created');
    }

    public function handle_order_processing($order_id)
    {
        $settings = route_rd_get_settings();
        if (empty($settings['enable_wc_processing'])) {
            return;
        }
        $this->send_order($order_id, 'woocommerce_processing');
    }

    public function handle_order_completed($order_id)
    {
        $settings = route_rd_get_settings();
        if (empty($settings['enable_wc_completed'])) {
            return;
        }
        $this->send_order($order_id, 'woocommerce_completed');
    }

    private function send_order($order_id, $source)
    {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $settings = route_rd_get_settings();
        $products = array();
        foreach ($order->get_items() as $item) {
            $products[] = $item->get_name() . ' x ' . $item->get_quantity();
        }

        $payload = route_rd_build_payload(
            array(
                'email' => $order->get_billing_email(),
                'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'mobile_phone' => $order->get_billing_phone(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'country' => $order->get_billing_country(),
                'cf_order_id' => (string) $order->get_id(),
                'cf_order_total' => (string) $order->get_total(),
                'cf_payment_method' => $order->get_payment_method_title(),
                'cf_order_status' => $order->get_status(),
                'cf_products' => implode(', ', $products),
                'cf_order_admin_url' => admin_url('post.php?post=' . absint($order->get_id()) . '&action=edit'),
            ),
            $settings['wc_conversion_identifier'],
            array('woocommerce', 'cliente', 'pedido-realizado')
        );

        // A integracao prioriza evento de conversao padrao, conforme recomendado para formularios e eventos customizados atuais.
        Route_RD_API::instance()->send_conversion(array('payload' => $payload), $source);
    }
}
