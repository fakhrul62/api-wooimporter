<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class APIROSYNC_Ajax {

    private static $instance;

    public static function get_instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $actions = [
            'apirosync_get_connections',
            'apirosync_create_connection',
            'apirosync_delete_connection',
            'apirosync_duplicate_connection',
            'apirosync_save_connection',
            'apirosync_analyze_api',
            'apirosync_save_field_map',
            'apirosync_save_transforms',
            'apirosync_fetch_preview',
            'apirosync_run_import',
            'apirosync_run_import_selected',
            'apirosync_delete_imported',
            'apirosync_get_progress',
            'apirosync_get_logs',
            'apirosync_clear_logs',
            'apirosync_get_history',
            'apirosync_rollback_import',
            'apirosync_get_dashboard',
        ];
        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ $this, str_replace( 'apirosync_', 'handle_', $action ) ] );
        }
    }

    private function check(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }
        check_ajax_referer( 'apirosync_nonce', 'nonce' );
    }

    private function conn_id(): string {
        $id = sanitize_text_field( wp_unslash( $_POST['conn_id'] ?? '' ) );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'conn_id is required.' ] );
        return $id;
    }

    private function decode_json_array( $raw, string $message ): array {
        $data = is_string( $raw ) ? json_decode( $raw, true ) : (array) $raw;
        if ( ! is_array( $data ) || ( is_string( $raw ) && JSON_ERROR_NONE !== json_last_error() ) ) {
            wp_send_json_error( [ 'message' => $message ], 400 );
        }

        return self::sanitize_nested_array( $data );
    }

    private static function sanitize_nested_array( array $data ): array {
        $clean = [];

        foreach ( $data as $key => $value ) {
            $clean_key = is_int( $key ) ? $key : sanitize_key( (string) $key );

            if ( is_array( $value ) ) {
                $clean[ $clean_key ] = self::sanitize_nested_array( $value );
            } elseif ( is_bool( $value ) ) {
                $clean[ $clean_key ] = $value;
            } elseif ( is_int( $value ) || is_float( $value ) ) {
                $clean[ $clean_key ] = $value;
            } else {
                $clean[ $clean_key ] = sanitize_text_field( (string) $value );
            }
        }

        return $clean;
    }

    private function sanitize_connection_value( string $key, $value ) {
        switch ( $key ) {
            case 'api_url':
                return esc_url_raw( (string) $value );
            case 'api_body':
            case 'api_extra_params':
                return sanitize_textarea_field( (string) $value );
            case 'api_method':
                $method = strtoupper( sanitize_key( (string) $value ) );
                return in_array( $method, [ 'GET', 'POST' ], true ) ? $method : 'GET';
            case 'sync_interval':
                $interval = sanitize_key( (string) $value );
                return in_array( $interval, [ 'hourly', 'twicedaily', 'daily', 'weekly' ], true ) ? $interval : 'hourly';
            case 'publish_status':
                $status = sanitize_key( (string) $value );
                return in_array( $status, [ 'publish', 'draft', 'pending' ], true ) ? $status : 'publish';
            case 'pagination_style':
                $style = sanitize_key( (string) $value );
                return in_array( $style, [ 'auto', 'header', 'body', 'empty-page' ], true ) ? $style : 'auto';
            case 'perpage_size':
            case 'import_processed':
            case 'import_total':
                return absint( $value );
            case 'field_map':
            case 'field_transforms':
                return is_array( $value ) ? self::sanitize_nested_array( $value ) : [];
            case 'conflict_fields':
                return array_values( array_map( 'sanitize_key', (array) $value ) );
            default:
                return sanitize_text_field( (string) $value );
        }
    }

    public function handle_get_connections() {
        $this->check();
        
        $conns = APIROSYNC_Connection_Manager::all();
        $list = [];
        foreach ($conns as $id => $data) {
            $data['id'] = $id;
            $list[] = $data;
        }
        
        wp_send_json_success( $list );
    }

    public function handle_create_connection() {
        $this->check();
        $label = sanitize_text_field( wp_unslash( $_POST['label'] ?? 'New API Connection' ) );
        $id    = APIROSYNC_Connection_Manager::create( $label );
        wp_send_json_success( [ 'id' => $id, 'message' => 'Connection created.' ] );
    }

    public function handle_delete_connection() {
        $this->check();
        $id = $this->conn_id();
        APIROSYNC_Connection_Manager::delete( $id );
        wp_send_json_success( [ 'message' => 'Connection deleted.' ] );
    }

    public function handle_duplicate_connection() {
        $this->check();
        $id     = $this->conn_id();
        $new_id = APIROSYNC_Connection_Manager::duplicate( $id );
        if ( ! $new_id ) wp_send_json_error( [ 'message' => 'Connection not found.' ] );
        wp_send_json_success( [ 'id' => $new_id, 'message' => 'Connection duplicated.' ] );
    }

    public function handle_save_connection() {
        $this->check();
        $id      = $this->conn_id();
        $allowed = array_keys( APIROSYNC_Connection_Manager::defaults() );
        $data    = [];
        foreach ( $allowed as $key ) {
            if ( ! isset( $_POST[ $key ] ) ) continue;
            $val = wp_unslash( $_POST[ $key ] );
            
            if ( in_array( $key, [ 'sync_enabled','import_images','update_existing' ], true ) ) {
                $val = in_array( (string)$val, [ '1','true','yes' ], true );
            } elseif ( $key === 'conflict_fields' || $key === 'field_transforms' ) {
                $val = $this->decode_json_array( $val, 'Invalid JSON data.' );
            } else {
                $val = $this->sanitize_connection_value( $key, $val );
            }
            $data[ $key ] = $val;
        }
        $ok = APIROSYNC_Connection_Manager::save( $id, $data );
        if ( ! $ok ) wp_send_json_error( [ 'message' => 'Connection not found.' ] );

        APIROSYNC_Scheduler::unschedule( $id );
        APIROSYNC_Scheduler::schedule( $id );

        wp_send_json_success( [ 'message' => 'Connection saved.' ] );
    }

    public function handle_analyze_api() {
        $this->check();
        $id       = $this->conn_id();
        $settings = APIROSYNC_Connection_Manager::get( $id );
        if ( ! $settings ) wp_send_json_error( [ 'message' => 'Connection not found.' ] );

        foreach ( [ 'api_url','api_method','api_bearer','api_basic_user','api_basic_pass',
                    'api_key_header','api_key_param','api_key_value','api_extra_params','api_body' ] as $f ) {
            if ( isset( $_POST[ $f ] ) ) {
                $settings[ $f ] = $this->sanitize_connection_value( $f, wp_unslash( $_POST[ $f ] ) );
            }
        }

        if ( empty( $settings['api_url'] ) ) {
            wp_send_json_error( [ 'message' => 'API URL is required.' ] );
        }

        $analysis = APIROSYNC_API_Fetcher::test_and_analyze( $settings );
        if ( isset( $analysis['error'] ) ) {
            wp_send_json_error( [ 'message' => $analysis['error'] ] );
        }
        wp_send_json_success( $analysis );
    }

    public function handle_save_field_map() {
        $this->check();
        $id      = $this->conn_id();
        $raw_map = isset( $_POST['field_map'] ) ? wp_unslash( $_POST['field_map'] ) : '';
        $map     = $this->decode_json_array( $raw_map, 'Invalid field map data.' );

        $clean = [];
        foreach ( $map as $wc_field => $api_key ) {
            $clean[ sanitize_key( $wc_field ) ] = sanitize_text_field( (string) $api_key );
        }

        $data = [ 'field_map' => $clean ];
        $products_key = isset( $_POST['products_key'] ) ? sanitize_text_field( wp_unslash( $_POST['products_key'] ) ) : '';
        if ( '' !== $products_key ) {
            $data['products_key'] = $products_key;
        }

        APIROSYNC_Connection_Manager::save( $id, $data );
        wp_send_json_success( [ 'message' => 'Field mapping saved.' ] );
    }

    public function handle_save_transforms() {
        $this->check();
        $id = $this->conn_id();
        $raw = isset( $_POST['field_transforms'] ) ? wp_unslash( $_POST['field_transforms'] ) : '';
        $transforms = $this->decode_json_array( $raw, 'Invalid transform data.' );
        
        APIROSYNC_Connection_Manager::save( $id, [ 'field_transforms' => $transforms ] );
        wp_send_json_success( [ 'message' => 'Transforms saved.' ] );
    }

    public function handle_fetch_preview() {
        $this->check();
        $id     = $this->conn_id();
        $result = APIROSYNC_Importer::fetch_preview( $id );
        if ( isset( $result['error'] ) ) wp_send_json_error( [ 'message' => $result['error'] ] );
        wp_send_json_success( $result );
    }

    public function handle_run_import() {
        $this->check();
        set_time_limit( 300 );
        $id     = $this->conn_id();
        $result = APIROSYNC_Importer::run( $id );
        if ( $result['status'] === 'error' ) wp_send_json_error( [ 'message' => $result['message'] ] );
        wp_send_json_success( $result );
    }

    public function handle_run_import_selected() {
        $this->check();
        set_time_limit( 300 );
        $id      = $this->conn_id();
        $ids_raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : '';
        $ids     = $this->decode_json_array( $ids_raw, 'Invalid product selection data.' );
        $ids     = array_map( 'sanitize_text_field', (array) $ids );
        $result  = APIROSYNC_Importer::run( $id, $ids );
        if ( $result['status'] === 'error' ) wp_send_json_error( [ 'message' => $result['message'] ] );
        wp_send_json_success( $result );
    }

    public function handle_delete_imported() {
        $this->check();
        $id    = $this->conn_id();
        $count = APIROSYNC_Importer::delete_imported( $id );
        wp_send_json_success( [ 'message' => "{$count} product(s) deleted." ] );
    }

    public function handle_get_progress() {
        $this->check();
        $id = $this->conn_id();
        wp_send_json_success( APIROSYNC_Importer::get_import_progress( $id ) );
    }

    public function handle_get_logs() {
        $this->check();
        $id   = $this->conn_id();
        $logs = APIROSYNC_Connection_Manager::get_logs( $id );
        wp_send_json_success( [ 'logs' => $logs ] );
    }

    public function handle_clear_logs() {
        $this->check();
        $id = $this->conn_id();
        APIROSYNC_Connection_Manager::clear_logs( $id );
        wp_send_json_success( [ 'message' => 'Logs cleared.' ] );
    }

    public function handle_get_history() {
        $this->check();
        $id = $this->conn_id();
        $history = APIROSYNC_History::get_history( $id );
        wp_send_json_success( [ 'history' => $history ] );
    }

    public function handle_rollback_import() {
        $this->check();
        $id = $this->conn_id();
        $run_id = sanitize_text_field( wp_unslash( $_POST['run_id'] ?? '' ) );
        if ( ! $run_id ) wp_send_json_error( [ 'message' => 'run_id missing' ] );
        
        $res = APIROSYNC_History::rollback( $id, $run_id );
        if ( isset( $res['error'] ) ) wp_send_json_error( [ 'message' => $res['error'] ] );
        wp_send_json_success( [ 'message' => "Rollback complete. {$res['deleted']} products deleted." ] );
    }

    public function handle_get_dashboard() {
        $this->check();
        $conns = APIROSYNC_Connection_Manager::all();
        $list = [];
        foreach ( $conns as $id => $data ) {
            $data['id'] = $id;
            $data['wc_count'] = APIROSYNC_Importer::count_imported( $id );
            $data['next_run'] = APIROSYNC_Scheduler::next_run( $id );
            $list[] = $data;
        }
        wp_send_json_success( [ 'connections' => $list ] );
    }
}
