<?php
/**
 * AWI_History
 *
 * Tracks import runs and allows rolling them back.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class AWI_History {

    public static function log_run( string $conn_id, array $data ) {
        $key = 'awi_history_' . $conn_id;
        $history = get_option( $key, [] );
        if ( ! is_array( $history ) ) $history = [];

        array_unshift( $history, [
            'id'          => uniqid('run_'),
            'date'        => current_time( 'mysql' ),
            'imported'    => $data['imported'] ?? 0,
            'updated'     => $data['updated'] ?? 0,
            'failed'      => $data['failed'] ?? 0,
            'product_ids' => $data['product_ids'] ?? [],
        ]);

        if ( count( $history ) > 20 ) {
            $history = array_slice( $history, 0, 20 );
        }

        update_option( $key, $history );
    }

    public static function get_history( string $conn_id ): array {
        $key = 'awi_history_' . $conn_id;
        $history = get_option( $key, [] );
        return is_array( $history ) ? $history : [];
    }

    public static function rollback( string $conn_id, string $run_id ): array {
        $key = 'awi_history_' . $conn_id;
        $history = get_option( $key, [] );
        if ( ! is_array( $history ) ) return [ 'error' => 'No history found' ];

        $run_index = false;
        foreach ( $history as $i => $run ) {
            if ( $run['id'] === $run_id ) {
                $run_index = $i;
                break;
            }
        }

        if ( $run_index === false ) {
            return [ 'error' => 'Run not found' ];
        }

        $run = $history[$run_index];
        $deleted = 0;

        if ( ! empty( $run['product_ids'] ) ) {
            foreach ( $run['product_ids'] as $pid ) {
                if ( wp_delete_post( $pid, true ) ) {
                    $deleted++;
                }
            }
        }

        array_splice( $history, $run_index, 1 );
        update_option( $key, $history );

        return [ 'success' => true, 'deleted' => $deleted ];
    }
}
