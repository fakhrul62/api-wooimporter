<?php
/**
 * Plugin Name: API WooImporter
 * Plugin URI:  https://fakhrul.codechronic.com/api-woo-importer
 * Description: Connect multiple REST APIs simultaneously, each fully isolated, and import products into WooCommerce automatically — no coding required.
 * Version:     3.0.0
 * Author:      Fakhrul Alam
 * Author URI:  https://fakhrul.codechronic.com/
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: api-woo-importer
 * Requires at least: 6.0
 * Tested up to: 6.7
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AWI_VERSION', '3.0.0' );
define( 'AWI_FILE',    __FILE__ );
define( 'AWI_DIR',     plugin_dir_path( __FILE__ ) );
define( 'AWI_URL',     plugin_dir_url( __FILE__ ) );

require_once AWI_DIR . 'includes/class-awi-connection-manager.php';
require_once AWI_DIR . 'includes/class-awi-api-fetcher.php';
require_once AWI_DIR . 'includes/class-awi-field-mapper.php';
require_once AWI_DIR . 'includes/class-awi-transformer.php';
require_once AWI_DIR . 'includes/class-awi-importer.php';
require_once AWI_DIR . 'includes/class-awi-webhook.php';
require_once AWI_DIR . 'includes/class-awi-history.php';
require_once AWI_DIR . 'includes/class-awi-scheduler.php';
require_once AWI_DIR . 'includes/class-awi-admin.php';
require_once AWI_DIR . 'includes/class-awi-ajax.php';

add_action( 'plugins_loaded', function() {
    AWI_Connection_Manager::get_instance();
    AWI_Scheduler::get_instance();
    AWI_Admin::get_instance();
    AWI_Ajax::get_instance();
} );

add_action( 'rest_api_init', function() {
    AWI_Webhook::register_routes();
} );

register_activation_hook( __FILE__, function() {
    AWI_Scheduler::schedule_all();
    // Migrate old single-API settings if they exist
    AWI_Connection_Manager::maybe_migrate_legacy();
} );

register_deactivation_hook( __FILE__, function() {
    AWI_Scheduler::unschedule_all();
} );
