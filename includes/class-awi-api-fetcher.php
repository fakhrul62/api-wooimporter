<?php
/**
 * AWI_API_Fetcher — Built-in HTTP API fetcher (connection-aware).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class AWI_API_Fetcher {

    public static function fetch( $id_or_settings = [] ) {
        $res = self::fetch_raw( $id_or_settings );
        if ( is_string( $res ) ) return $res;
        return $res['body'];
    }

    public static function fetch_page( $id_or_settings, int $page, int $per_page ) {
        $settings = is_array( $id_or_settings ) ? $id_or_settings : AWI_Connection_Manager::get( $id_or_settings );
        if ( ! $settings ) return 'Connection not found.';

        $page_param    = $settings['pagination_param'] ?? 'page';
        $perpage_param = $settings['perpage_param'] ?? 'per_page';
        
        $original_url = $settings['api_url'] ?? '';
        $settings['api_url'] = add_query_arg([
            $page_param    => $page,
            $perpage_param => $per_page,
        ], $original_url);

        $res = self::fetch_raw( $settings );
        if ( is_string( $res ) ) return $res;

        $body = $res['body'];
        $headers = $res['headers'];
        
        $has_more = false;
        $total = null;
        
        $style = $settings['pagination_style'] ?? 'auto';

        if ( $style === 'auto' || $style === 'header' ) {
            $total_header = $headers['X-Total-Count'] ?? $headers['x-total-count'] ?? $headers['X-WP-Total'] ?? $headers['x-wp-total'] ?? null;
            if ( $total_header !== null ) {
                $total = (int) $total_header;
                $has_more = ( $page * $per_page ) < $total;
                if ( $style === 'auto' && ! is_array( $id_or_settings ) ) {
                    AWI_Connection_Manager::save( $id_or_settings, ['pagination_style' => 'header'] );
                }
                $style = 'header';
            } elseif ( isset( $headers['Link'] ) || isset( $headers['link'] ) ) {
                $link = $headers['Link'] ?? $headers['link'];
                $has_more = strpos( $link, 'rel="next"' ) !== false;
                if ( $style === 'auto' && ! is_array( $id_or_settings ) ) {
                    AWI_Connection_Manager::save( $id_or_settings, ['pagination_style' => 'header'] );
                }
                $style = 'header';
            }
        }

        if ( $style === 'auto' || $style === 'body' ) {
            $possible_totals = [ 'total', 'count', 'pages', 'meta.pagination.total' ];
            foreach ( $possible_totals as $path ) {
                $val = AWI_Field_Mapper::get_value( $body, $path );
                if ( $val !== null && is_numeric( $val ) ) {
                    $total = (int) $val;
                    if ( $path === 'pages' || $path === 'meta.pagination.total_pages' ) {
                        $has_more = $page < $total;
                    } else {
                        $has_more = ( $page * $per_page ) < $total;
                    }
                    if ( $style === 'auto' && ! is_array( $id_or_settings ) ) {
                        AWI_Connection_Manager::save( $id_or_settings, ['pagination_style' => 'body'] );
                    }
                    $style = 'body';
                    break;
                }
            }
        }

        if ( $style === 'auto' || $style === 'empty-page' ) {
            $products = AWI_Field_Mapper::get_products_from_raw( $body, $settings['products_key'] ?? 'auto' );
            $has_more = ! empty( $products );
            if ( $style === 'auto' && ! is_array( $id_or_settings ) ) {
                AWI_Connection_Manager::save( $id_or_settings, ['pagination_style' => 'empty-page'] );
            }
        }

        return [
            'data'     => $body,
            'has_more' => $has_more,
            'total'    => $total,
        ];
    }

    public static function fetch_preview_page( $id_or_settings ) {
        return self::fetch_page( $id_or_settings, 1, 20 );
    }

    private static function fetch_raw( $id_or_settings ) {
        $settings = is_array( $id_or_settings ) ? $id_or_settings : AWI_Connection_Manager::get( $id_or_settings );
        if ( ! $settings ) return 'Connection not found.';

        $url    = trim( $settings['api_url'] ?? '' );
        $method = strtoupper( $settings['api_method'] ?? 'GET' );

        if ( empty( $url ) ) return 'API URL is not configured.';

        $headers = [ 'Accept' => 'application/json' ];

        $bearer = trim( $settings['api_bearer'] ?? '' );
        if ( $bearer !== '' ) $headers['Authorization'] = 'Bearer ' . $bearer;

        $basic_user = trim( $settings['api_basic_user'] ?? '' );
        $basic_pass = trim( $settings['api_basic_pass'] ?? '' );
        if ( $basic_user !== '' ) {
            $headers['Authorization'] = 'Basic ' . base64_encode( $basic_user . ':' . $basic_pass );
        }

        $key_header = trim( $settings['api_key_header'] ?? '' );
        $key_value  = trim( $settings['api_key_value']  ?? '' );
        if ( $key_header !== '' && $key_value !== '' ) $headers[ $key_header ] = $key_value;

        $key_param = trim( $settings['api_key_param'] ?? '' );
        if ( $key_param !== '' && $key_value !== '' ) $url = add_query_arg( $key_param, $key_value, $url );

        $extra_params = trim( $settings['api_extra_params'] ?? '' );
        if ( $extra_params !== '' ) {
            $qargs = [];
            foreach ( array_filter( array_map( 'trim', explode( "\n", $extra_params ) ) ) as $pair ) {
                if ( strpos( $pair, '=' ) !== false ) {
                    [ $k, $v ] = explode( '=', $pair, 2 );
                    $qargs[ trim($k) ] = trim($v);
                }
            }
            if ( ! empty( $qargs ) ) $url = add_query_arg( $qargs, $url );
        }

        $body = null;
        if ( $method === 'POST' ) {
            $raw_body = trim( $settings['api_body'] ?? '' );
            if ( $raw_body !== '' ) {
                $body = $raw_body;
                $headers['Content-Type'] = 'application/json';
            }
        }

        $args = [
            'method'    => $method,
            'headers'   => $headers,
            'timeout'   => 30,
            'sslverify' => true,
        ];
        if ( $body !== null ) $args['body'] = $body;

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) return $response->get_error_message();

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $resp_headers = wp_remote_retrieve_headers( $response );

        if ( $code < 200 || $code >= 300 ) {
            return "API returned HTTP {$code}. Body: " . substr( $raw, 0, 300 );
        }

        $decoded = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return 'API response is not valid JSON: ' . json_last_error_msg();
        }

        return [
            'body'    => $decoded,
            'headers' => $resp_headers,
        ];
    }

    public static function test_and_analyze( array $settings ): array {
        $raw = self::fetch( $settings );
        if ( is_string( $raw ) ) return [ 'error' => $raw ];
        return AWI_Field_Mapper::analyze( $raw );
    }
}

