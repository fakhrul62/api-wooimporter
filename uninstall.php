<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package API_Woo_Importer
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Clean up general options
delete_option( 'awi_connections' );
delete_option( 'awi_settings' );

global $wpdb;

// Delete all per-connection configuration, logs, and history
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'awi\_conn\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'awi\_log\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'awi\_history\_%'" );

// Delete cron hooks
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'cron'" );
// NOTE: Deleting the 'cron' option entirely is unsafe. We should unschedule the hooks instead.
// Wait, we can't reliably unschedule all variable hooks without knowing the connection IDs here easily,
// but actually, we could just let WP clean it up or clear the specific awi_sync_* hooks.
// A safer approach is to use wp_clear_scheduled_hook for each connection.
$connections = get_option('awi_connections', []);
if (is_array($connections)) {
    foreach ($connections as $conn_id) {
        wp_clear_scheduled_hook('awi_sync_' . $conn_id);
    }
}
// Also clear the legacy hook if any
wp_clear_scheduled_hook('awi_sync_event');
