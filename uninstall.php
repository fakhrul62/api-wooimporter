<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package ApiroSync_Product_Sync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$apirosync_connections = get_option( 'apirosync_connections', [] );
if ( is_array( $apirosync_connections ) ) {
    foreach ( array_keys( $apirosync_connections ) as $apirosync_conn_id ) {
        wp_unschedule_hook( 'apirosync_auto_sync_' . sanitize_text_field( (string) $apirosync_conn_id ) );
    }
}

wp_unschedule_hook( 'apirosync_process_import_batch_cron' );
wp_unschedule_hook( 'apirosync_sync_event' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( '', [], 'apirosync' );
}

delete_option( 'apirosync_connections' );
delete_option( 'apirosync_settings' );

global $wpdb;

$apirosync_patterns = [
    $wpdb->esc_like( 'apirosync_conn_' ) . '%',
    $wpdb->esc_like( 'apirosync_conn_logs_' ) . '%',
    $wpdb->esc_like( 'apirosync_history_' ) . '%',
];

foreach ( $apirosync_patterns as $apirosync_pattern ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must discover dynamically named plugin options; caching is not appropriate.
    $apirosync_option_names = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $apirosync_pattern
        )
    );

    foreach ( $apirosync_option_names as $apirosync_option_name ) {
        delete_option( $apirosync_option_name );
    }
}
