<?php
if (!defined('ABSPATH')) {
    exit;
}

function route_rd_default_settings()
{
    return array(
        'auth_mode' => 'oauth',
        'client_id' => '',
        'client_secret' => '',
        'api_key' => '',
        'conversion_identifier' => 'Formulario Site',
        'default_tags' => 'site,wordpress',
        'update_contact_before_conversion' => 0,
        'enable_lgpd' => 0,
        'legal_category' => 'communications',
        'legal_type' => 'consent',
        'legal_status' => 'granted',
        'enable_cf7' => 0,
        'cf7_name_field' => 'your-name',
        'cf7_email_field' => 'your-email',
        'cf7_phone_field' => 'your-phone',
        'cf7_city_field' => 'city',
        'cf7_state_field' => 'state',
        'cf7_message_field' => 'your-message',
        'cf7_conversion_identifier' => 'Contact Form 7',
        'cf7_tags' => 'contact-form-7',
        'enable_elementor' => 0,
        'elementor_forms' => '',
        'elementor_conversion_identifier' => 'Elementor Form',
        'elementor_tags' => 'elementor',
        'enable_wc_order_created' => 0,
        'enable_wc_processing' => 0,
        'enable_wc_completed' => 0,
        'enable_wc_cancelled' => 0,
        'enable_wc_refunded' => 0,
        'enable_wc_failed' => 0,
        'wc_enriched_payload' => 1,
        'wc_include_customer_metrics' => 1,
        'wc_include_product_details' => 1,
        'wc_dynamic_tags' => 1,
        'wc_prevent_duplicate_events' => 1,
        'wc_conversion_identifier' => 'Pedido WooCommerce',
        'field_mappings' => array(),
        'remove_data_on_uninstall' => 0,
        'log_retention_days' => 90,
    );
}

function route_rd_get_settings()
{
    $settings = get_option(ROUTE_RD_OPTION, array());
    return wp_parse_args(is_array($settings) ? $settings : array(), route_rd_default_settings());
}

function route_rd_update_settings($settings)
{
    update_option(ROUTE_RD_OPTION, wp_parse_args($settings, route_rd_default_settings()), false);
}

function route_rd_get_tokens()
{
    $tokens = get_option(ROUTE_RD_TOKEN_OPTION, array());
    return is_array($tokens) ? $tokens : array();
}

function route_rd_update_tokens($tokens)
{
    update_option(ROUTE_RD_TOKEN_OPTION, is_array($tokens) ? $tokens : array(), false);
}

function route_rd_crypto_key()
{
    $material = '';
    if (defined('AUTH_KEY')) {
        $material .= AUTH_KEY;
    }
    if (defined('SECURE_AUTH_KEY')) {
        $material .= SECURE_AUTH_KEY;
    }
    if ('' === $material && defined('DB_PASSWORD')) {
        $material = DB_PASSWORD;
    }

    return hash('sha256', $material . home_url());
}

function route_rd_encrypt($value)
{
    $value = (string) $value;
    if ('' === $value || 0 === strpos($value, 'route_rd_enc:')) {
        return $value;
    }

    if (!function_exists('openssl_encrypt')) {
        return 'route_rd_plain:' . base64_encode($value);
    }

    $iv = wp_generate_password(16, false, false);
    $encrypted = openssl_encrypt($value, 'AES-256-CBC', route_rd_crypto_key(), 0, $iv);

    if (false === $encrypted) {
        return 'route_rd_plain:' . base64_encode($value);
    }

    return 'route_rd_enc:' . base64_encode($iv . '::' . $encrypted);
}

function route_rd_decrypt($value)
{
    $value = (string) $value;
    if ('' === $value) {
        return '';
    }

    if (0 === strpos($value, 'route_rd_plain:')) {
        return (string) base64_decode(substr($value, 15), true);
    }

    if (0 !== strpos($value, 'route_rd_enc:') || !function_exists('openssl_decrypt')) {
        return $value;
    }

    $decoded = base64_decode(substr($value, 13), true);
    if (!$decoded || false === strpos($decoded, '::')) {
        return '';
    }

    list($iv, $encrypted) = explode('::', $decoded, 2);
    $plain = openssl_decrypt($encrypted, 'AES-256-CBC', route_rd_crypto_key(), 0, $iv);

    return false === $plain ? '' : $plain;
}

function route_rd_mask_secret($value)
{
    $value = (string) $value;
    if ('' === $value) {
        return __('Não salvo', 'route-rd-station');
    }

    $last = substr($value, -6);
    return '******' . $last;
}

function route_rd_sanitize_bool($value)
{
    return empty($value) ? 0 : 1;
}

function route_rd_parse_tags($tags)
{
    if (is_array($tags)) {
        $items = $tags;
    } else {
        $items = explode(',', (string) $tags);
    }

    $clean = array();
    foreach ($items as $tag) {
        $tag = sanitize_text_field(trim((string) $tag));
        if ('' !== $tag) {
            $clean[] = $tag;
        }
    }

    return array_values(array_unique($clean));
}

function route_rd_sanitize_mappings($raw)
{
    $mappings = array();
    if (!is_array($raw)) {
        return $mappings;
    }

    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $source = isset($row['source']) ? sanitize_key($row['source']) : '';
        $target = isset($row['target']) ? sanitize_key($row['target']) : '';
        if ('' !== $source && '' !== $target) {
            $mappings[] = array(
                'source' => $source,
                'target' => $target,
            );
        }
    }

    return $mappings;
}

function route_rd_get_legal_bases()
{
    $settings = route_rd_get_settings();
    if (empty($settings['enable_lgpd'])) {
        return array();
    }

    return array(
        array(
            'category' => sanitize_key($settings['legal_category']),
            'type' => sanitize_key($settings['legal_type']),
            'status' => sanitize_key($settings['legal_status']),
        ),
    );
}

function route_rd_build_payload($data, $conversion_identifier = '', $tags = array())
{
    $settings = route_rd_get_settings();
    $payload = array();

    $standard = array(
        'name',
        'email',
        'personal_phone',
        'mobile_phone',
        'city',
        'state',
        'country',
        'website',
        'job_title',
        'company',
    );

    foreach ($standard as $field) {
        if (isset($data[$field]) && '' !== $data[$field]) {
            $payload[$field] = 'email' === $field ? sanitize_email($data[$field]) : sanitize_text_field($data[$field]);
        }
    }

    if (empty($payload['email']) && !empty($data['user_email'])) {
        $payload['email'] = sanitize_email($data['user_email']);
    }

    $payload['conversion_identifier'] = sanitize_text_field($conversion_identifier ?: $settings['conversion_identifier']);

    $merged_tags = array_merge(route_rd_parse_tags($settings['default_tags']), route_rd_parse_tags($tags));
    if (!empty($merged_tags)) {
        $payload['tags'] = $merged_tags;
    }

    $legal_bases = route_rd_get_legal_bases();
    if (!empty($legal_bases)) {
        $payload['legal_bases'] = $legal_bases;
    }

    foreach ($settings['field_mappings'] as $mapping) {
        $source = $mapping['source'];
        $target = $mapping['target'];
        if (isset($data[$source]) && '' !== $data[$source]) {
            $payload[$target] = sanitize_text_field($data[$source]);
        }
    }

    foreach ($data as $key => $value) {
        $key = sanitize_key($key);
        if (0 === strpos($key, 'cf_') && '' !== $value) {
            $payload[$key] = sanitize_text_field(is_array($value) ? implode(', ', $value) : $value);
        }
    }

    return $payload;
}

function route_rd_admin_url($tab = 'connection')
{
    return add_query_arg(
        array(
            'page' => 'route-rd-station',
            'tab' => sanitize_key($tab),
        ),
        admin_url('admin.php')
    );
}
