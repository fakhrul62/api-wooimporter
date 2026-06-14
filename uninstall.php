<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package ApiroSync_Product_Sync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$connections = get_option( 'apirosync_connections', [] );
if ( is_array( $connections ) ) {
    foreach ( array_keys( $connections ) as $conn_id ) {
        wp_clear_scheduled_hook( 'apirosync_auto_sync_' . sanitize_text_field( (string) $conn_id ) );
    }
}

wp_clear_scheduled_hook( 'apirosync_process_import_batch_cron' );
wp_clear_scheduled_hook( 'apirosync_sync_event' );

delete_option( 'apirosync_connections' );
delete_option( 'apirosync_settings' );

global $wpdb;

$patterns = [
    $wpdb->esc_like( 'apirosync_conn_' ) . '%',
    $wpdb->esc_like( 'apirosync_conn_logs_' ) . '%',
    $wpdb->esc_like( 'apirosync_history_' ) . '%',
];

foreach ( $patterns as $pattern ) {
    $option_names = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $pattern
        )
    );

    foreach ( $option_names as $option_name ) {
        delete_option( $option_name );
    }
}
