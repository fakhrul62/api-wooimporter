<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AWI_Ajax {

    private static $instance;

    public static function get_instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $actions = [
            'awi_get_connections',
            'awi_create_connection',
            'awi_delete_connection',
            'awi_duplicate_connection',
            'awi_save_connection',
            'awi_analyze_api',
            'awi_save_field_map',
            'awi_save_transforms',
            'awi_fetch_preview',
            'awi_run_import',
            'awi_run_import_selected',
            'awi_delete_imported',
            'awi_get_progress',
            'awi_get_logs',
            'awi_clear_logs',
            'awi_get_history',
            'awi_rollback_import',
            'awi_get_dashboard',
        ];
        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ $this, str_replace( 'awi_', 'handle_', $action ) ] );
        }
    }

    private function check(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }
        check_ajax_referer( 'awi_nonce', 'nonce' );
    }

    private function conn_id(): string {
        $id = sanitize_text_field( wp_unslash( $_POST['conn_id'] ?? '' ) );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'conn_id is required.' ] );
        return $id;
    }

    public function handle_get_connections() {
        $this->check();
        
        $conns = AWI_Connection_Manager::all();
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
        $id    = AWI_Connection_Manager::create( $label );
        wp_send_json_success( [ 'id' => $id, 'message' => 'Connection created.' ] );
    }

    public function handle_delete_connection() {
        $this->check();
        $id = $this->conn_id();
        AWI_Connection_Manager::delete( $id );
        wp_send_json_success( [ 'message' => 'Connection deleted.' ] );
    }

    public function handle_duplicate_connection() {
        $this->check();
        $id     = $this->conn_id();
        $new_id = AWI_Connection_Manager::duplicate( $id );
        if ( ! $new_id ) wp_send_json_error( [ 'message' => 'Connection not found.' ] );
        wp_send_json_success( [ 'id' => $new_id, 'message' => 'Connection duplicated.' ] );
    }

    public function handle_save_connection() {
        $this->check();
        $id      = $this->conn_id();
        $allowed = array_keys( AWI_Connection_Manager::defaults() );
        $data    = [];
        foreach ( $allowed as $key ) {
            if ( ! isset( $_POST[ $key ] ) ) continue;
            $val = wp_unslash( $_POST[ $key ] );
            
            if ( in_array( $key, [ 'sync_enabled','import_images','update_existing' ], true ) ) {
                $val = in_array( (string)$val, [ '1','true','yes' ], true );
            } elseif ( $key === 'conflict_fields' || $key === 'field_transforms' ) {
                $val = is_string( $val ) ? json_decode( $val, true ) : (array) $val;
            } else {
                $val = sanitize_text_field( (string)$val );
            }
            $data[ $key ] = $val;
        }
        $ok = AWI_Connection_Manager::save( $id, $data );
        if ( ! $ok ) wp_send_json_error( [ 'message' => 'Connection not found.' ] );

        AWI_Scheduler::unschedule( $id );
        AWI_Scheduler::schedule( $id );

        wp_send_json_success( [ 'message' => 'Connection saved.' ] );
    }

    public function handle_analyze_api() {
        $this->check();
        $id       = $this->conn_id();
        $settings = AWI_Connection_Manager::get( $id );
        if ( ! $settings ) wp_send_json_error( [ 'message' => 'Connection not found.' ] );

        foreach ( [ 'api_url','api_method','api_bearer','api_basic_user','api_basic_pass',
                    'api_key_header','api_key_param','api_key_value','api_extra_params','api_body' ] as $f ) {
            if ( isset( $_POST[ $f ] ) ) {
                $settings[ $f ] = sanitize_text_field( wp_unslash( $_POST[ $f ] ) );
            }
        }

        if ( empty( $settings['api_url'] ) ) {
            wp_send_json_error( [ 'message' => 'API URL is required.' ] );
        }

        $analysis = AWI_API_Fetcher::test_and_analyze( $settings );
        if ( isset( $analysis['error'] ) ) {
            wp_send_json_error( [ 'message' => $analysis['error'] ] );
        }
        wp_send_json_success( $analysis );
    }

    public function handle_save_field_map() {
        $this->check();
        $id      = $this->conn_id();
        $raw_map = isset( $_POST['field_map'] ) ? wp_unslash( $_POST['field_map'] ) : '';
        $map     = is_string( $raw_map ) ? json_decode( $raw_map, true ) : (array) $raw_map;
        if ( ! is_array( $map ) ) wp_send_json_error( [ 'message' => 'Invalid field map data.' ] );

        $clean = [];
        foreach ( $map as $wc_field => $api_key ) {
            $clean[ sanitize_key( $wc_field ) ] = sanitize_text_field( $api_key );
        }

        $data = [ 'field_map' => $clean ];
        if ( ! empty( $_POST['products_key'] ) ) {
            $data['products_key'] = sanitize_text_field( wp_unslash( $_POST['products_key'] ) );
        }

        AWI_Connection_Manager::save( $id, $data );
        wp_send_json_success( [ 'message' => 'Field mapping saved.' ] );
    }

    public function handle_save_transforms() {
        $this->check();
        $id = $this->conn_id();
        $raw = isset( $_POST['field_transforms'] ) ? wp_unslash( $_POST['field_transforms'] ) : '';
        $transforms = is_string( $raw ) ? json_decode( $raw, true ) : (array) $raw;
        
        AWI_Connection_Manager::save( $id, [ 'field_transforms' => $transforms ] );
        wp_send_json_success( [ 'message' => 'Transforms saved.' ] );
    }

    public function handle_fetch_preview() {
        $this->check();
        $id     = $this->conn_id();
        $result = AWI_Importer::fetch_preview( $id );
        if ( isset( $result['error'] ) ) wp_send_json_error( [ 'message' => $result['error'] ] );
        wp_send_json_success( $result );
    }

    public function handle_run_import() {
        $this->check();
        set_time_limit( 300 );
        $id     = $this->conn_id();
        $result = AWI_Importer::run( $id );
        if ( $result['status'] === 'error' ) wp_send_json_error( [ 'message' => $result['message'] ] );
        wp_send_json_success( $result );
    }

    public function handle_run_import_selected() {
        $this->check();
        set_time_limit( 300 );
        $id      = $this->conn_id();
        $ids_raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : '';
        $ids     = is_string( $ids_raw ) ? json_decode( $ids_raw, true ) : (array) $ids_raw;
        $ids     = array_map( 'sanitize_text_field', (array) $ids );
        $result  = AWI_Importer::run( $id, $ids );
        if ( $result['status'] === 'error' ) wp_send_json_error( [ 'message' => $result['message'] ] );
        wp_send_json_success( $result );
    }

    public function handle_delete_imported() {
        $this->check();
        $id    = $this->conn_id();
        $count = AWI_Importer::delete_imported( $id );
        wp_send_json_success( [ 'message' => "{$count} product(s) deleted." ] );
    }

    public function handle_get_progress() {
        $this->check();
        $id = $this->conn_id();
        wp_send_json_success( AWI_Importer::get_import_progress( $id ) );
    }

    public function handle_get_logs() {
        $this->check();
        $id   = $this->conn_id();
        $logs = AWI_Connection_Manager::get_logs( $id );
        wp_send_json_success( [ 'logs' => $logs ] );
    }

    public function handle_clear_logs() {
        $this->check();
        $id = $this->conn_id();
        AWI_Connection_Manager::clear_logs( $id );
        wp_send_json_success( [ 'message' => 'Logs cleared.' ] );
    }

    public function handle_get_history() {
        $this->check();
        $id = $this->conn_id();
        $history = AWI_History::get_history( $id );
        wp_send_json_success( [ 'history' => $history ] );
    }

    public function handle_rollback_import() {
        $this->check();
        $id = $this->conn_id();
        $run_id = sanitize_text_field( wp_unslash( $_POST['run_id'] ?? '' ) );
        if ( ! $run_id ) wp_send_json_error( [ 'message' => 'run_id missing' ] );
        
        $res = AWI_History::rollback( $id, $run_id );
        if ( isset( $res['error'] ) ) wp_send_json_error( [ 'message' => $res['error'] ] );
        wp_send_json_success( [ 'message' => "Rollback complete. {$res['deleted']} products deleted." ] );
    }

    public function handle_get_dashboard() {
        $this->check();
        $conns = AWI_Connection_Manager::all();
        $list = [];
        foreach ( $conns as $id => $data ) {
            $data['id'] = $id;
            $data['wc_count'] = AWI_Importer::count_imported( $id );
            $list[] = $data;
        }
        wp_send_json_success( [ 'connections' => $list ] );
    }
}
