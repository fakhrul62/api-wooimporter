<?php
/**
 * APIROSYNC_Webhook
 *
 * Handles incoming webhooks for specific connections.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class APIROSYNC_Webhook {

    public static function register_routes() {
        register_rest_route( 'apirosync/v1', '/webhook/(?P<conn_id>[a-zA-Z0-9_-]+)', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'handle_webhook' ],
            'permission_callback' => [ self::class, 'verify_webhook_permission' ],
        ] );
    }

    public static function verify_webhook_permission( WP_REST_Request $request ) {
        $conn_id  = sanitize_text_field( (string) $request->get_param( 'conn_id' ) );
        $settings = APIROSYNC_Connection_Manager::get( $conn_id );

        if ( ! $settings ) {
            return new WP_Error( 'not_found', 'Connection not found', [ 'status' => 404 ] );
        }

        $secret = sanitize_text_field( (string) ( $settings['webhook_secret'] ?? '' ) );
        if ( '' === $secret ) {
            return new WP_Error( 'webhook_secret_required', 'Webhook secret is required for this endpoint.', [ 'status' => 403 ] );
        }

        $provided = $request->get_param( 'secret' );
        if ( ! $provided ) {
            $provided = $request->get_header( 'x-apirosync-secret' );
        }
        if ( ! $provided ) {
            $auth = $request->get_header( 'authorization' );
            if ( is_string( $auth ) && 0 === strpos( $auth, 'Bearer ' ) ) {
                $provided = substr( $auth, 7 );
            }
        }

        $provided = sanitize_text_field( (string) $provided );
        if ( '' === $provided || ! hash_equals( $secret, $provided ) ) {
            return new WP_Error( 'unauthorized', 'Invalid secret', [ 'status' => 401 ] );
        }

        return true;
    }

    public static function handle_webhook( WP_REST_Request $request ) {
        $conn_id = sanitize_text_field( (string) $request->get_param( 'conn_id' ) );
        $settings = APIROSYNC_Connection_Manager::get( $conn_id );

        if ( ! $settings ) {
            return new WP_Error( 'not_found', 'Connection not found', [ 'status' => 404 ] );
        }

        $body = $request->get_json_params();
        if ( empty( $body ) || ! is_array( $body ) ) {
            return new WP_Error( 'invalid_payload', 'Payload must be a JSON object or array', [ 'status' => 400 ] );
        }

        if ( ! isset( $body[0] ) || ! is_array( $body[0] ) ) {
            $body = [ $body ];
        }

        $imported = 0;
        $updated = 0;
        $failed = 0;

        foreach ( $body as $item ) {
            if ( ! is_array( $item ) ) {
                $failed++;
                continue;
            }
            $item = self::sanitize_payload_item( $item );
            $result = APIROSYNC_Importer::import_single( $item, $conn_id, $settings );
            if ( is_numeric( $result ) ) {
                $imported++;
            } elseif ( is_string( $result ) && 0 === strpos( $result, 'updated:' ) ) {
                $updated++;
            } else {
                $failed++;
            }
        }

        $msg = "Webhook handled: $imported created, $updated updated, $failed failed.";
        APIROSYNC_Connection_Manager::add_log( $conn_id, $msg, $failed > 0 ? 'warning' : 'success' );

        return rest_ensure_response( [
            'success'  => true,
            'message'  => $msg,
            'imported' => $imported,
            'updated'  => $updated,
            'failed'   => $failed,
        ] );
    }

    private static function sanitize_payload_item( array $item ): array {
        $clean = [];

        foreach ( $item as $key => $value ) {
            $clean_key = is_int( $key ) ? $key : sanitize_text_field( (string) $key );

            if ( is_array( $value ) ) {
                $clean[ $clean_key ] = self::sanitize_payload_item( $value );
            } elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
                $clean[ $clean_key ] = $value;
            } else {
                $clean[ $clean_key ] = sanitize_text_field( (string) $value );
            }
        }

        return $clean;
    }
}
