<?php
/**
 * AWI_Webhook
 *
 * Handles incoming webhooks for specific connections.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class AWI_Webhook {

    public static function register_routes() {
        register_rest_route( 'awi/v1', '/webhook/(?P<conn_id>[a-zA-Z0-9_-]+)', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'handle_webhook' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function handle_webhook( WP_REST_Request $request ) {
        $conn_id = $request->get_param( 'conn_id' );
        $settings = AWI_Connection_Manager::get( $conn_id );

        if ( ! $settings ) {
            return new WP_Error( 'not_found', 'Connection not found', [ 'status' => 404 ] );
        }

        $secret = $settings['webhook_secret'] ?? '';
        if ( ! empty( $secret ) ) {
            $provided = $request->get_param( 'secret' );
            if ( ! $provided ) $provided = $request->get_header( 'x_awi_secret' );
            if ( ! $provided ) {
                $auth = $request->get_header( 'authorization' );
                if ( strpos( $auth, 'Bearer ' ) === 0 ) {
                    $provided = substr( $auth, 7 );
                }
            }
            if ( $provided !== $secret ) {
                return new WP_Error( 'unauthorized', 'Invalid secret', [ 'status' => 401 ] );
            }
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
            $result = AWI_Importer::import_single( $item, $conn_id, $settings );
            if ( $result === 'created' ) $imported++;
            elseif ( $result === 'updated' ) $updated++;
            else $failed++;
        }

        $msg = "Webhook handled: $imported created, $updated updated, $failed failed.";
        AWI_Connection_Manager::add_log( $conn_id, $msg, $failed > 0 ? 'warning' : 'success' );

        return rest_ensure_response( [
            'success'  => true,
            'message'  => $msg,
            'imported' => $imported,
            'updated'  => $updated,
            'failed'   => $failed,
        ] );
    }
}
