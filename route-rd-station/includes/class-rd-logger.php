<?php
if (!defined('ABSPATH')) {
    exit;
}

class Route_RD_Logger
{
    public static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'route_rd_logs';
    }

    public static function create_table()
    {
        global $wpdb;

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            source varchar(100) NOT NULL DEFAULT '',
            action varchar(100) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            status varchar(30) NOT NULL DEFAULT '',
            request_payload longtext NULL,
            response_code int(11) NULL,
            response_body longtext NULL,
            error_message text NULL,
            PRIMARY KEY  (id),
            KEY email (email),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function add($data)
    {
        global $wpdb;

        $defaults = array(
            'created_at' => current_time('mysql'),
            'source' => '',
            'action' => '',
            'email' => '',
            'status' => 'info',
            'request_payload' => '',
            'response_code' => null,
            'response_body' => '',
            'error_message' => '',
        );

        $data = wp_parse_args($data, $defaults);
        $wpdb->insert(
            self::table_name(),
            array(
                'created_at' => sanitize_text_field($data['created_at']),
                'source' => sanitize_text_field($data['source']),
                'action' => sanitize_text_field($data['action']),
                'email' => sanitize_email($data['email']),
                'status' => sanitize_key($data['status']),
                'request_payload' => self::encode($data['request_payload']),
                'response_code' => null === $data['response_code'] ? null : absint($data['response_code']),
                'response_body' => self::encode($data['response_body']),
                'error_message' => sanitize_textarea_field($data['error_message']),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        return (int) $wpdb->insert_id;
    }

    public static function get_logs($limit = 50, $offset = 0)
    {
        global $wpdb;

        $limit = max(1, min(200, absint($limit)));
        $offset = max(0, absint($offset));
        $table = self::table_name();

        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset),
            ARRAY_A
        );
    }

    public static function get($id)
    {
        global $wpdb;

        $table = self::table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", absint($id)), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public static function latest()
    {
        global $wpdb;

        $table = self::table_name();
        $row = $wpdb->get_row("SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT 1", ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public static function counts()
    {
        global $wpdb;

        $table = self::table_name();
        $success = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'success'");
        $error = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'error'");

        return array(
            'success' => $success,
            'error' => $error,
        );
    }

    public static function clear_older_than($days)
    {
        global $wpdb;

        $days = absint($days);
        if ($days <= 0) {
            return 0;
        }

        $table = self::table_name();
        $threshold = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $threshold));
    }

    public static function decode($value)
    {
        $decoded = json_decode((string) $value, true);
        return null === $decoded ? $value : $decoded;
    }

    private static function encode($value)
    {
        if (is_array($value) || is_object($value)) {
            return wp_json_encode($value);
        }

        return (string) $value;
    }
}
