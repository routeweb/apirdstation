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
        add_action('woocommerce_order_status_cancelled', array($this, 'handle_order_cancelled'), 20, 1);
        add_action('woocommerce_order_status_refunded', array($this, 'handle_order_refunded'), 20, 1);
        add_action('woocommerce_order_status_failed', array($this, 'handle_order_failed'), 20, 1);
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

    public function handle_order_cancelled($order_id)
    {
        $settings = route_rd_get_settings();
        if (empty($settings['enable_wc_cancelled'])) {
            return;
        }
        $this->send_order($order_id, 'woocommerce_cancelled');
    }

    public function handle_order_refunded($order_id)
    {
        $settings = route_rd_get_settings();
        if (empty($settings['enable_wc_refunded'])) {
            return;
        }
        $this->send_order($order_id, 'woocommerce_refunded');
    }

    public function handle_order_failed($order_id)
    {
        $settings = route_rd_get_settings();
        if (empty($settings['enable_wc_failed'])) {
            return;
        }
        $this->send_order($order_id, 'woocommerce_failed');
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

        if (!empty($settings['wc_prevent_duplicate_events']) && $this->was_event_sent($order, $source)) {
            Route_RD_Logger::add(array(
                'source' => $source,
                'action' => 'woocommerce_duplicate_skipped',
                'email' => $order->get_billing_email(),
                'status' => 'success',
                'request_payload' => array('order_id' => $order->get_id(), 'event' => $source),
                'error_message' => 'Evento WooCommerce ja enviado anteriormente.',
            ));
            return;
        }

        $order_data = $this->build_order_data($order, $source, $settings);
        $payload = route_rd_build_payload($order_data['data'], $settings['wc_conversion_identifier'], $order_data['tags']);

        // A integracao prioriza evento de conversao padrao, conforme recomendado para formularios e eventos customizados atuais.
        $result = Route_RD_API::instance()->send_conversion(array('payload' => $payload), $source);

        if (!is_wp_error($result) && !empty($settings['wc_prevent_duplicate_events'])) {
            $this->mark_event_sent($order, $source);
        }
    }

    private function build_order_data($order, $source, $settings)
    {
        $product_details = $this->get_product_details($order);
        $created = $order->get_date_created();
        $paid = $order->get_date_paid();
        $completed = $order->get_date_completed();

        $data = array(
            'email' => $order->get_billing_email(),
            'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'mobile_phone' => $order->get_billing_phone(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'country' => $order->get_billing_country(),
            'cf_order_id' => (string) $order->get_id(),
            'cf_order_number' => (string) $order->get_order_number(),
            'cf_order_key' => (string) $order->get_order_key(),
            'cf_order_total' => $this->format_decimal($order->get_total()),
            'cf_order_subtotal' => $this->format_decimal($order->get_subtotal()),
            'cf_order_discount_total' => $this->format_decimal($order->get_total_discount()),
            'cf_order_shipping_total' => $this->format_decimal($order->get_shipping_total()),
            'cf_order_tax_total' => $this->format_decimal($order->get_total_tax()),
            'cf_order_currency' => $order->get_currency(),
            'cf_payment_method' => $order->get_payment_method_title(),
            'cf_payment_method_id' => $order->get_payment_method(),
            'cf_order_status' => $order->get_status(),
            'cf_order_event' => $source,
            'cf_order_created_at' => $created ? $created->date_i18n('Y-m-d H:i:s') : '',
            'cf_order_paid_at' => $paid ? $paid->date_i18n('Y-m-d H:i:s') : '',
            'cf_order_completed_at' => $completed ? $completed->date_i18n('Y-m-d H:i:s') : '',
            'cf_order_admin_url' => admin_url('post.php?post=' . absint($order->get_id()) . '&action=edit'),
            'cf_customer_id' => (string) $order->get_customer_id(),
            'cf_customer_note' => $order->get_customer_note(),
            'cf_shipping_method' => $this->get_shipping_methods($order),
            'cf_coupon_codes' => implode(', ', $order->get_coupon_codes()),
            'cf_item_count' => (string) $product_details['item_count'],
            'cf_product_count' => (string) count($product_details['product_ids']),
            'cf_products' => implode(', ', $product_details['summary']),
        );

        if (!empty($settings['wc_enriched_payload']) && !empty($settings['wc_include_product_details'])) {
            $data['cf_product_ids'] = implode(', ', $product_details['product_ids']);
            $data['cf_product_skus'] = implode(', ', $product_details['skus']);
            $data['cf_product_names'] = implode(', ', $product_details['names']);
            $data['cf_product_categories'] = implode(', ', $product_details['categories']);
            $data['cf_product_quantities'] = implode(', ', $product_details['quantities']);
            $data['cf_products_json'] = $this->trim_for_rd(wp_json_encode($product_details['items']));
        }

        if (!empty($settings['wc_enriched_payload']) && !empty($settings['wc_include_customer_metrics'])) {
            $metrics = $this->get_customer_metrics($order);
            $data = array_merge($data, $metrics);
        }

        $tags = array('woocommerce', 'cliente', 'pedido-realizado');
        if (!empty($settings['wc_dynamic_tags'])) {
            $tags = array_merge($tags, $this->get_dynamic_tags($order, $product_details, $data));
        }

        return array(
            'data' => $data,
            'tags' => $tags,
        );
    }

    private function get_product_details($order)
    {
        $details = array(
            'items' => array(),
            'summary' => array(),
            'names' => array(),
            'skus' => array(),
            'product_ids' => array(),
            'categories' => array(),
            'quantities' => array(),
            'item_count' => 0,
        );

        foreach ($order->get_items() as $item) {
            if (!is_a($item, 'WC_Order_Item_Product')) {
                continue;
            }

            $product = $item->get_product();
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $quantity = (int) $item->get_quantity();
            $sku = $product ? $product->get_sku() : '';
            $categories = $this->get_product_categories($product_id);

            $details['item_count'] += $quantity;
            $details['summary'][] = $item->get_name() . ' x ' . $quantity;
            $details['names'][] = $item->get_name();
            $details['quantities'][] = $item->get_name() . ':' . $quantity;

            if ($product_id) {
                $details['product_ids'][] = (string) $product_id;
            }
            if ($variation_id) {
                $details['product_ids'][] = (string) $variation_id;
            }
            if ('' !== $sku) {
                $details['skus'][] = $sku;
            }
            $details['categories'] = array_merge($details['categories'], $categories);

            $details['items'][] = array(
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'sku' => $sku,
                'name' => $item->get_name(),
                'quantity' => $quantity,
                'subtotal' => $this->format_decimal($item->get_subtotal()),
                'total' => $this->format_decimal($item->get_total()),
                'categories' => $categories,
            );
        }

        foreach (array('summary', 'names', 'skus', 'product_ids', 'categories', 'quantities') as $key) {
            $details[$key] = array_values(array_unique(array_filter(array_map('sanitize_text_field', $details[$key]))));
        }

        return $details;
    }

    private function get_product_categories($product_id)
    {
        $terms = $product_id ? get_the_terms($product_id, 'product_cat') : array();
        if (empty($terms) || is_wp_error($terms)) {
            return array();
        }

        $categories = array();
        foreach ($terms as $term) {
            $categories[] = $term->name;
        }

        return $categories;
    }

    private function get_customer_metrics($order)
    {
        $email = $order->get_billing_email();
        $customer_id = $order->get_customer_id();
        $orders = array();

        if ($customer_id) {
            $orders = wc_get_orders(array(
                'customer_id' => $customer_id,
                'limit' => -1,
                'status' => array('wc-processing', 'wc-completed', 'wc-on-hold'),
                'return' => 'objects',
            ));
        } elseif ($email) {
            $orders = wc_get_orders(array(
                'billing_email' => $email,
                'limit' => -1,
                'status' => array('wc-processing', 'wc-completed', 'wc-on-hold'),
                'return' => 'objects',
            ));
        }

        $total_orders = 0;
        $total_spent = 0.0;
        $last_order_date = '';
        $last_order_id = '';

        foreach ($orders as $customer_order) {
            if (!is_a($customer_order, 'WC_Order')) {
                continue;
            }

            $total_orders++;
            $total_spent += (float) $customer_order->get_total();
            $created = $customer_order->get_date_created();
            if ($created && ('' === $last_order_date || $created->getTimestamp() > strtotime($last_order_date))) {
                $last_order_date = $created->date_i18n('Y-m-d H:i:s');
                $last_order_id = (string) $customer_order->get_id();
            }
        }

        return array(
            'cf_customer_total_orders' => (string) $total_orders,
            'cf_customer_total_spent' => $this->format_decimal($total_spent),
            'cf_customer_average_order_value' => $total_orders > 0 ? $this->format_decimal($total_spent / $total_orders) : '0.00',
            'cf_customer_last_order_id' => $last_order_id,
            'cf_customer_last_order_date' => $last_order_date,
            'cf_customer_type' => $total_orders > 1 ? 'recorrente' : 'primeira-compra',
        );
    }

    private function get_dynamic_tags($order, $product_details, $data)
    {
        $tags = array(
            'wc-status-' . sanitize_title($order->get_status()),
        );

        if ($order->get_payment_method()) {
            $tags[] = 'wc-pagamento-' . sanitize_title($order->get_payment_method());
        }

        if (!empty($data['cf_customer_type'])) {
            $tags[] = 'cliente-' . sanitize_title($data['cf_customer_type']);
        }

        foreach ($product_details['names'] as $name) {
            $tags[] = 'produto-' . sanitize_title($name);
        }

        foreach ($product_details['categories'] as $category) {
            $tags[] = 'categoria-' . sanitize_title($category);
        }

        return array_slice(array_values(array_unique(array_filter($tags))), 0, 50);
    }

    private function get_shipping_methods($order)
    {
        $methods = array();
        foreach ($order->get_shipping_methods() as $method) {
            $methods[] = $method->get_name();
        }

        return implode(', ', array_map('sanitize_text_field', $methods));
    }

    private function was_event_sent($order, $source)
    {
        $sent = $order->get_meta('_route_rd_sent_events', true);
        $sent = is_array($sent) ? $sent : array();

        return in_array($source, $sent, true);
    }

    private function mark_event_sent($order, $source)
    {
        $sent = $order->get_meta('_route_rd_sent_events', true);
        $sent = is_array($sent) ? $sent : array();
        $sent[] = $source;
        $sent = array_values(array_unique($sent));

        $order->update_meta_data('_route_rd_sent_events', $sent);
        $order->save();
    }

    private function format_decimal($value)
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function trim_for_rd($value)
    {
        $value = (string) $value;
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 5000);
        }

        return substr($value, 0, 5000);
    }
}
