<?php
/**
 * AWI_Connection_Manager
 *
 * Manages multiple isolated API connections.
 * Each connection is stored under its own option key so data never merges.
 *
 * Option layout:
 *   awi_connections          => [ { id, label, ...meta } ]   (index)
 *   awi_conn_{id}            => { full settings }            (per-connection data)
 *   awi_conn_{id}_logs       => [ { time, message, type } ]  (per-connection logs)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class AWI_Connection_Manager {

    private static $instance;

    const INDEX_KEY  = 'awi_connections';
    const CONN_KEY   = 'awi_conn_';          // prefix + id
    const LOG_KEY    = 'awi_conn_logs_';     // prefix + id

    public static function get_instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    // ──────────────────────────────────────────
    // Connection defaults
    // ──────────────────────────────────────────

    public static function defaults(): array {
        return [
            'label'            => 'New API Connection',
            'api_url'          => '',
            'api_method'       => 'GET',
            'api_bearer'       => '',
            'api_basic_user'   => '',
            'api_basic_pass'   => '',
            'api_key_header'   => '',
            'api_key_param'    => '',
            'api_key_value'    => '',
            'api_extra_params' => '',
            'api_body'         => '',
            'products_key'     => 'auto',
            'sync_interval'    => 'hourly',
            'sync_enabled'     => false,
            'import_images'    => true,
            'update_existing'  => true,
            'publish_status'   => 'publish',
            'field_map'        => [],
            'last_sync'        => null,
            'last_sync_count'  => 0,
            'wc_category'      => '',    // optional global default category
            'tag_prefix'       => '',    // optional prefix to add to all tags
            'color_tag'        => '',    // UI color tag for this connection
            'conflict_strategy'=> 'update', // 'skip' | 'update' | 'merge'
            'conflict_fields'  => [],       // which fields to merge vs overwrite
            'webhook_secret'   => '',
            'pagination_style' => 'auto',   // 'header' | 'body' | 'empty-page' | 'auto'
            'pagination_param' => 'page',
            'perpage_param'    => 'per_page',
            'perpage_size'     => 100,
            'import_status'    => 'idle',
            'import_processed' => 0,
            'import_total'     => 0,
            'field_transforms' => [],
        ];
    }

    // ──────────────────────────────────────────
    // Index helpers
    // ──────────────────────────────────────────

    /** Return all connections as [ id => label ] */
    public static function get_index(): array {
        return get_option( self::INDEX_KEY, [] ) ?: [];
    }

    private static function save_index( array $index ): void {
        update_option( self::INDEX_KEY, $index );
    }

    // ──────────────────────────────────────────
    // CRUD
    // ──────────────────────────────────────────

    /** Generate a unique connection ID. */
    public static function generate_id(): string {
        return 'c' . bin2hex( random_bytes( 5 ) );
    }

    /** Create a brand-new connection and return its ID. */
    public static function create( string $label = '' ): string {
        $id      = self::generate_id();
        $data    = self::defaults();
        $data['label'] = $label ?: 'New API Connection';

        update_option( self::CONN_KEY . $id, $data );

        $index       = self::get_index();
        $index[ $id ] = $data['label'];
        self::save_index( $index );

        return $id;
    }

    /** Retrieve full settings for a connection. */
    public static function get( string $id ): ?array {
        $data = get_option( self::CONN_KEY . $id, null );
        if ( $data === null ) return null;
        return wp_parse_args( $data, self::defaults() );
    }

    /** Save / update a connection's settings. */
    public static function save( string $id, array $data ): bool {
        $existing = self::get( $id );
        if ( $existing === null ) return false;

        foreach ( $data as $k => $v ) {
            if ( array_key_exists( $k, self::defaults() ) ) {
                $existing[ $k ] = $v;
            }
        }
        update_option( self::CONN_KEY . $id, $existing );

        // Keep label in index in sync
        $index = self::get_index();
        $index[ $id ] = $existing['label'];
        self::save_index( $index );

        return true;
    }

    /** Delete a connection and all its data. */
    public static function delete( string $id ): bool {
        delete_option( self::CONN_KEY . $id );
        delete_option( self::LOG_KEY  . $id );

        $index = self::get_index();
        unset( $index[ $id ] );
        self::save_index( $index );

        AWI_Scheduler::unschedule( $id );
        return true;
    }

    /** Duplicate a connection (copies all settings, clears sync timestamps). */
    public static function duplicate( string $source_id ): ?string {
        $source = self::get( $source_id );
        if ( ! $source ) return null;

        $new_id = self::generate_id();
        $source['label']           = $source['label'] . ' (copy)';
        $source['last_sync']       = null;
        $source['last_sync_count'] = 0;

        update_option( self::CONN_KEY . $new_id, $source );

        $index         = self::get_index();
        $index[ $new_id ] = $source['label'];
        self::save_index( $index );

        return $new_id;
    }

    // ──────────────────────────────────────────
    // Logging (per-connection)
    // ──────────────────────────────────────────

    public static function add_log( string $id, string $message, string $type = 'info' ): void {
        $key  = self::LOG_KEY . $id;
        $logs = get_option( $key, [] ) ?: [];
        array_unshift( $logs, [
            'time'    => current_time( 'mysql' ),
            'message' => $message,
            'type'    => $type,
        ]);
        $logs = array_slice( $logs, 0, 300 );
        update_option( $key, $logs );
    }

    public static function get_logs( string $id ): array {
        return get_option( self::LOG_KEY . $id, [] ) ?: [];
    }

    public static function clear_logs( string $id ): void {
        update_option( self::LOG_KEY . $id, [] );
    }

    // ──────────────────────────────────────────
    // Stats helper (for dashboard)
    // ──────────────────────────────────────────

    /** Return summary stats for all connections (fast — reads index only). */
    public static function all_summary(): array {
        $index   = self::get_index();
        $summary = [];
        foreach ( $index as $id => $label ) {
            $data      = self::get( $id );
            if ( ! $data ) continue;
            $summary[] = [
                'id'             => $id,
                'label'          => $data['label'],
                'api_url'        => $data['api_url'],
                'sync_enabled'   => $data['sync_enabled'],
                'sync_interval'  => $data['sync_interval'],
                'last_sync'      => $data['last_sync'],
                'last_sync_count'=> $data['last_sync_count'],
                'has_map'        => ! empty( $data['field_map'] ),
                'color_tag'      => $data['color_tag'] ?? '',
                'next_run'       => AWI_Scheduler::next_run( $id ),
            ];
        }
        return $summary;
    }

    // ──────────────────────────────────────────
    // Legacy migration
    // ──────────────────────────────────────────

    /** If old single-connection settings exist, import them as connection #1. */
    public static function maybe_migrate_legacy(): void {
        $legacy = get_option( 'awi_settings', null );
        if ( ! $legacy || ! is_array( $legacy ) ) return;
        if ( ! empty( $legacy['_migrated'] ) ) return;
        if ( empty( $legacy['api_url'] ) ) return;

        // Already have connections? Don't migrate.
        if ( ! empty( self::get_index() ) ) return;

        $id = self::create( 'Migrated Connection' );
        self::save( $id, array_intersect_key( $legacy, self::defaults() ) );

        // Migrate old logs
        $old_logs = $legacy['logs'] ?? [];
        if ( ! empty( $old_logs ) ) {
            update_option( self::LOG_KEY . $id, $old_logs );
        }

        // Mark legacy as migrated so we don't repeat
        $legacy['_migrated'] = true;
        update_option( 'awi_settings', $legacy );
    }
}
