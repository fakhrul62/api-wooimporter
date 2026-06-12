<?php
/**
 * Plugin Name: ApiroSync Product Sync for WooCommerce
 * Description: Connect multiple REST APIs simultaneously, each fully isolated, and import products into WooCommerce automatically.
 * Version:     1.0.0
 * Author:      Fakhrul Alam
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fakhrulalam16-api-product-sync-woocommerce
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FAPI_VERSION', '1.0.0' );
define( 'FAPI_FILE', __FILE__ );
define( 'FAPI_DIR', plugin_dir_path( __FILE__ ) );
define( 'FAPI_URL', plugin_dir_url( __FILE__ ) );

require_once FAPI_DIR . 'includes/class-fapi-connection-manager.php';
require_once FAPI_DIR . 'includes/class-fapi-api-fetcher.php';
require_once FAPI_DIR . 'includes/class-fapi-field-mapper.php';
require_once FAPI_DIR . 'includes/class-fapi-transformer.php';
require_once FAPI_DIR . 'includes/class-fapi-importer.php';
require_once FAPI_DIR . 'includes/class-fapi-webhook.php';
require_once FAPI_DIR . 'includes/class-fapi-history.php';
require_once FAPI_DIR . 'includes/class-fapi-scheduler.php';
require_once FAPI_DIR . 'includes/class-fapi-admin.php';
require_once FAPI_DIR . 'includes/class-fapi-ajax.php';

add_action(
    'plugins_loaded',
    function() {
        FAPI_Connection_Manager::get_instance();
        FAPI_Scheduler::get_instance();
        FAPI_Admin::get_instance();
        FAPI_Ajax::get_instance();
    }
);

add_action(
    'rest_api_init',
    function() {
        FAPI_Webhook::register_routes();
    }
);

register_activation_hook(
    __FILE__,
    function() {
        FAPI_Scheduler::schedule_all();
        FAPI_Connection_Manager::maybe_migrate_legacy();
    }
);

register_deactivation_hook(
    __FILE__,
    function() {
        FAPI_Scheduler::unschedule_all();
    }
);
