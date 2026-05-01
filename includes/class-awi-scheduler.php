<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AWI_Scheduler {

    private static $instance;
    const HOOK_PREFIX = 'awi_auto_sync_';

    public static function get_instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Register hooks for all existing connections
        $index = AWI_Connection_Manager::get_index();
        foreach ( array_keys( $index ) as $id ) {
            add_action( self::HOOK_PREFIX . $id, function() use ( $id ) {
                $this->run_connection( $id );
            });
        }
        add_action( 'update_option_' . AWI_Connection_Manager::INDEX_KEY, [ $this, 'resync_all_hooks' ] );
    }

    public function run_connection( string $id ): void {
        $settings = AWI_Connection_Manager::get( $id );
        if ( empty( $settings['sync_enabled'] ) ) return;
        AWI_Importer::run( $id );
    }

    public function resync_all_hooks(): void {
        self::unschedule_all();
        self::schedule_all();
    }

    public static function schedule( string $id ): void {
        $settings = AWI_Connection_Manager::get( $id );
        if ( ! $settings || empty( $settings['sync_enabled'] ) ) return;
        $hook     = self::HOOK_PREFIX . $id;
        $interval = $settings['sync_interval'] ?? 'hourly';
        if ( ! wp_next_scheduled( $hook ) ) {
            wp_schedule_event( time(), $interval, $hook );
        }
        // Ensure the action is registered in the current request
        if ( ! has_action( $hook ) ) {
            add_action( $hook, function() use ( $id ) {
                ( new self() )->run_connection( $id );
            });
        }
    }

    public static function unschedule( string $id ): void {
        $hook = self::HOOK_PREFIX . $id;
        $ts   = wp_next_scheduled( $hook );
        if ( $ts ) wp_unschedule_event( $ts, $hook );
        wp_unschedule_hook( $hook );
    }

    public static function schedule_all(): void {
        foreach ( array_keys( AWI_Connection_Manager::get_index() ) as $id ) {
            self::schedule( $id );
        }
    }

    public static function unschedule_all(): void {
        foreach ( array_keys( AWI_Connection_Manager::get_index() ) as $id ) {
            self::unschedule( $id );
        }
    }

    public static function next_run( string $id ): string {
        $ts   = wp_next_scheduled( self::HOOK_PREFIX . $id );
        if ( ! $ts ) return 'Not scheduled';
        $diff = $ts - time();
        if ( $diff < 0 ) return 'Overdue';
        $mins = round( $diff / 60 );
        if ( $mins < 60 ) return "In {$mins} min";
        return 'In ' . round( $mins / 60, 1 ) . ' hr';
    }
}
