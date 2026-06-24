<?php
/**
 * Plugin Name: ApiroSync Product Sync for WooCommerce
 * Description: Connect multiple REST APIs simultaneously, each fully isolated, and import products into WooCommerce automatically.
 * Version:     1.0.0
 * Author:      Fakhrul Alam
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: apirosync-product-sync-for-woocommerce
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'APIROSYNC_VERSION', '1.0.0' );
define( 'APIROSYNC_FILE', __FILE__ );
define( 'APIROSYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'APIROSYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'APIROSYNC_BRANDING_DIR', APIROSYNC_DIR . 'branding/' );
define( 'APIROSYNC_BRANDING_URL', APIROSYNC_URL . 'branding/' );

require_once APIROSYNC_DIR . 'includes/class-apirosync-connection-manager.php';
require_once APIROSYNC_DIR . 'includes/class-apirosync-api-fetcher.php';
require_once APIROSYNC_DIR . 'includes/class-apirosync-field-mapper.php';
require_once APIROSYNC_DIR . 'includes/class-apirosync-transformer.php';
require_once APIROSYNC_DIR . 'includes/class-apirosync-importer.php';
require_once APIROSYNC_DIR . 'includes/class-apirosync-webhook.php';
require_once APIROSYNC_DIR . 'includes/class-apirosync-history.php';
require_once APIROSYNC_DIR . 'includes/class-apirosync-scheduler.php';
require_once APIROSYNC_DIR . 'includes/class-apirosync-admin.php';
require_once APIROSYNC_DIR . 'includes/class-apirosync-ajax.php';

add_action(
    'plugins_loaded',
    function() {
        APIROSYNC_Connection_Manager::get_instance();
        APIROSYNC_Scheduler::get_instance();
        APIROSYNC_Admin::get_instance();
        APIROSYNC_Ajax::get_instance();
    }
);

add_action(
    'rest_api_init',
    function() {
        APIROSYNC_Webhook::register_routes();
    }
);

register_activation_hook(
    __FILE__,
    function() {
        APIROSYNC_Connection_Manager::maybe_migrate_legacy();
        APIROSYNC_Scheduler::schedule_all();
    }
);

register_deactivation_hook(
    __FILE__,
    function() {
        APIROSYNC_Scheduler::unschedule_all();
        wp_unschedule_hook( 'apirosync_process_import_batch_cron' );

        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( '', [], 'apirosync' );
        }
    }
);
