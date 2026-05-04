<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function route_rd_uninstall_site()
{
    global $wpdb;

    $settings = get_option('route_rd_settings', array());
    if (empty($settings['remove_data_on_uninstall'])) {
        return;
    }

    delete_option('route_rd_settings');
    delete_option('route_rd_tokens');

    $table = $wpdb->prefix . 'route_rd_logs';
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

if (is_multisite()) {
    $sites = get_sites(array('fields' => 'ids'));
    foreach ($sites as $blog_id) {
        switch_to_blog($blog_id);
        route_rd_uninstall_site();
        restore_current_blog();
    }
} else {
    route_rd_uninstall_site();
}
