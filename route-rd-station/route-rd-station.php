<?php
/**
 * Plugin Name: Route RD Station Integration
 * Description: Integra WordPress, formulários, WooCommerce e RD Station Marketing API com OAuth2, API Key, logs e testes.
 * Version: 1.0.0
 * Author: Route
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: route-rd-station
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ROUTE_RD_VERSION', '1.0.0');
define('ROUTE_RD_FILE', __FILE__);
define('ROUTE_RD_PATH', plugin_dir_path(__FILE__));
define('ROUTE_RD_URL', plugin_dir_url(__FILE__));
define('ROUTE_RD_OPTION', 'route_rd_settings');
define('ROUTE_RD_TOKEN_OPTION', 'route_rd_tokens');
define('ROUTE_RD_CRON_REFRESH', 'route_rd_refresh_token_event');
define('ROUTE_RD_CRON_CLEAN_LOGS', 'route_rd_clean_logs_event');

require_once ROUTE_RD_PATH . 'includes/helpers.php';
require_once ROUTE_RD_PATH . 'includes/class-rd-logger.php';
require_once ROUTE_RD_PATH . 'includes/class-rd-oauth.php';
require_once ROUTE_RD_PATH . 'includes/class-rd-api.php';
require_once ROUTE_RD_PATH . 'includes/class-rd-form-handler.php';
require_once ROUTE_RD_PATH . 'includes/class-rd-woocommerce.php';
require_once ROUTE_RD_PATH . 'includes/class-rd-admin.php';

final class Route_RD_Station_Plugin
{
    /**
     * @var Route_RD_Station_Plugin|null
     */
    private static $instance = null;

    /**
     * @return Route_RD_Station_Plugin
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', array($this, 'load'));
        add_action(ROUTE_RD_CRON_REFRESH, array($this, 'cron_refresh_token'));
        add_action(ROUTE_RD_CRON_CLEAN_LOGS, array($this, 'cron_clean_logs'));
    }

    public function load()
    {
        Route_RD_OAuth::instance();
        Route_RD_API::instance();
        Route_RD_Form_Handler::instance();
        Route_RD_WooCommerce::instance();

        if (is_admin()) {
            Route_RD_Admin::instance();
        }
    }

    public static function activate($network_wide = false)
    {
        if (is_multisite() && $network_wide) {
            $sites = get_sites(array('fields' => 'ids'));
            foreach ($sites as $blog_id) {
                switch_to_blog($blog_id);
                self::activate_site();
                restore_current_blog();
            }
            return;
        }

        self::activate_site();
    }

    private static function activate_site()
    {
        Route_RD_Logger::create_table();

        if (false === get_option(ROUTE_RD_OPTION)) {
            add_option(ROUTE_RD_OPTION, route_rd_default_settings(), '', false);
        }

        if (false === get_option(ROUTE_RD_TOKEN_OPTION)) {
            add_option(ROUTE_RD_TOKEN_OPTION, array(), '', false);
        }

        if (!wp_next_scheduled(ROUTE_RD_CRON_REFRESH)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', ROUTE_RD_CRON_REFRESH);
        }

        if (!wp_next_scheduled(ROUTE_RD_CRON_CLEAN_LOGS)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', ROUTE_RD_CRON_CLEAN_LOGS);
        }
    }

    public static function deactivate()
    {
        $refresh = wp_next_scheduled(ROUTE_RD_CRON_REFRESH);
        if ($refresh) {
            wp_unschedule_event($refresh, ROUTE_RD_CRON_REFRESH);
        }

        $clean = wp_next_scheduled(ROUTE_RD_CRON_CLEAN_LOGS);
        if ($clean) {
            wp_unschedule_event($clean, ROUTE_RD_CRON_CLEAN_LOGS);
        }
    }

    public function cron_refresh_token()
    {
        $settings = route_rd_get_settings();
        if ('oauth' !== $settings['auth_mode']) {
            return;
        }

        $oauth = Route_RD_OAuth::instance();
        if ($oauth->should_refresh_soon()) {
            $oauth->refresh_access_token();
        }
    }

    public function cron_clean_logs()
    {
        $settings = route_rd_get_settings();
        $days = absint($settings['log_retention_days']);
        if ($days > 0) {
            Route_RD_Logger::clear_older_than($days);
        }
    }
}

register_activation_hook(__FILE__, array('Route_RD_Station_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('Route_RD_Station_Plugin', 'deactivate'));

Route_RD_Station_Plugin::instance();
