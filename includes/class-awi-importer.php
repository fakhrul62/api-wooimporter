<?php
/**
 * AWI_Importer — Connection-aware importer.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class AWI_Importer {

    public static function run( string $conn_id, array $only_ids = [] ): array {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return [ 'status' => 'error', 'message' => 'WooCommerce not active.' ];
        }

        $settings = AWI_Connection_Manager::get( $conn_id );
        if ( ! $settings ) {
            return [ 'status' => 'error', 'message' => 'Connection not found.' ];
        }
        if ( empty( $settings['api_url'] ) ) {
            return [ 'status' => 'error', 'message' => 'API URL not configured.' ];
        }
        if ( empty( $settings['field_map'] ) ) {
            return [ 'status' => 'error', 'message' => 'Field mapping not saved yet.' ];
        }

        AWI_Connection_Manager::save( $conn_id, [
            'import_status'    => 'running',
            'import_processed' => 0,
            'import_total'     => 0,
        ] );

        self::schedule_batch( $conn_id, 1, $only_ids );

        return [
            'status'   => 'success',
            'message'  => 'Import started in the background.',
        ];
    }

    public static function schedule_batch( $conn_id, $page, $only_ids = [] ) {
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time(), 'awi_process_import_batch', [ $conn_id, $page, $only_ids ], 'awi' );
        } else {
            wp_schedule_single_event( time(), 'awi_process_import_batch_cron', [ $conn_id, $page, $only_ids ] );
        }
    }

    public static function process_batch( $conn_id, $page, $only_ids = [] ) {
        $settings = AWI_Connection_Manager::get( $conn_id );
        if ( ! $settings ) return;

        $per_page = $settings['perpage_size'] ?? 100;

        $res = AWI_API_Fetcher::fetch_page( $conn_id, $page, $per_page );
        
        if ( is_string( $res ) ) {
            AWI_Connection_Manager::add_log( $conn_id, 'API fetch error: ' . $res, 'error' );
            AWI_Connection_Manager::save( $conn_id, [ 'import_status' => 'error' ] );
            return;
        }

        $products = AWI_Field_Mapper::get_products_from_raw( $res['data'], $settings['products_key'] ?? 'auto' );
        
        if ( empty( $products ) ) {
            if ( $page === 1 ) {
                AWI_Connection_Manager::add_log( $conn_id, 'No products found.', 'warning' );
            }
            AWI_Connection_Manager::save( $conn_id, [ 'import_status' => 'done' ] );
            return;
        }

        if ( ! empty( $only_ids ) ) {
            $id_field = $settings['field_map']['external_id'] ?? 'id';
            $products = array_filter( $products, function( $p ) use ( $id_field, $only_ids ) {
                $id = AWI_Field_Mapper::get_value( $p, $id_field );
                return in_array( (string) $id, array_map( 'strval', $only_ids ), true );
            });
        }

        if ( $page === 1 && $res['total'] !== null ) {
            AWI_Connection_Manager::save( $conn_id, [ 'import_total' => $res['total'] ] );
        } elseif ( $page === 1 ) {
             AWI_Connection_Manager::save( $conn_id, [ 'import_total' => count($products) ] );
        }

        $imported = $updated = $failed = 0;
        $errors = [];
        $product_ids = [];

        foreach ( $products as $item ) {
            $result = self::import_single( $item, $conn_id, $settings );
            if ( is_numeric( $result ) ) {
                $imported++;
                $product_ids[] = $result;
            } elseif ( strpos( $result, 'updated:' ) === 0 ) {
                $updated++;
                $product_ids[] = (int) str_replace( 'updated:', '', $result );
            } else {
                $failed++;
                $errors[] = $result;
            }
        }

        $current_processed = $settings['import_processed'] + count( $products );
        AWI_Connection_Manager::save( $conn_id, [ 'import_processed' => $current_processed ] );

        AWI_History::log_run( $conn_id, [
            'imported' => $imported,
            'updated'  => $updated,
            'failed'   => $failed,
            'product_ids' => $product_ids
        ] );

        if ( $res['has_more'] && empty( $only_ids ) ) {
            self::schedule_batch( $conn_id, $page + 1, $only_ids );
        } else {
            AWI_Connection_Manager::save( $conn_id, [
                'import_status' => 'done',
                'last_sync' => current_time( 'mysql' ),
                'last_sync_count' => $current_processed,
            ] );
            AWI_Connection_Manager::add_log( $conn_id, "Import completed.", 'success' );
        }
    }

    public static function get_import_progress( string $conn_id ): array {
        $settings = AWI_Connection_Manager::get( $conn_id );
        if ( ! $settings ) return [ 'status' => 'error' ];

        $status    = $settings['import_status'] ?? 'idle';
        $processed = (int) ( $settings['import_processed'] ?? 0 );
        $total     = (int) ( $settings['import_total'] ?? 0 );
        $percent   = $total > 0 ? min( 100, round( ( $processed / $total ) * 100 ) ) : 0;
        
        if ( $status === 'done' || $status === 'error' ) $percent = 100;

        return compact( 'status', 'processed', 'total', 'percent' );
    }

    public static function import_single( array $item, string $conn_id, array $settings ) {
        $map = $settings['field_map'];
        $transforms = $settings['field_transforms'] ?? [];

        $get = function( $field ) use ( $item, $map, $transforms ) {
            if ( ! isset( $map[$field] ) ) return null;
            $val = AWI_Field_Mapper::get_value( $item, $map[$field] );
            if ( isset( $transforms[$field] ) ) {
                $val = AWI_Transformer::transform( $val, $transforms[$field] );
            }
            return $val;
        };

        $ext_id = $get( 'external_id' );
        $title  = $get( 'title' );

        if ( empty( $ext_id ) && empty( $title ) ) return 'Skipped: missing ID and title.';

        $source_key = $conn_id . ':' . ( (string) $ext_id );

        $post_id = null;
        $is_update = false;

        if ( ! empty( $ext_id ) ) {
            global $wpdb;
            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_awi_source' AND meta_value = %s LIMIT 1",
                $source_key
            ) );
            
            if ( $existing_id ) {
                $post_id   = $existing_id;
                $is_update = true;
                
                $strategy = $settings['conflict_strategy'] ?? 'update';
                if ( $strategy === 'skip' ) {
                    return "updated:$post_id";
                }
            }
        }

        if ( ! $post_id ) {
            $post_id = wp_insert_post([
                'post_title'  => sanitize_text_field( (string) ( $title ?? 'Untitled Product' ) ),
                'post_status' => $settings['publish_status'] ?? 'publish',
                'post_type'   => 'product',
            ]);
            if ( ! $post_id || is_wp_error( $post_id ) ) return 'Error creating post';
            
            update_post_meta( $post_id, '_awi_source',      $source_key );
            update_post_meta( $post_id, '_awi_conn_id',     $conn_id );
            update_post_meta( $post_id, '_awi_external_id', (string) $ext_id );
        }

        $strategy = $settings['conflict_strategy'] ?? 'update';
        $conflict_fields = $settings['conflict_fields'] ?? [];

        $update_field = function( $key, $new_val, $is_meta = true ) use ( $post_id, $is_update, $strategy, $conflict_fields ) {
            if ( $new_val === null ) return;

            if ( $is_update && $strategy === 'merge' ) {
                if ( ! empty( $conflict_fields ) && in_array( $key, $conflict_fields ) ) {
                    $existing_val = $is_meta ? get_post_meta( $post_id, $key, true ) : get_post( $post_id )->$key;
                    if ( ! empty( $existing_val ) ) return;
                }
            }

            if ( $is_meta ) {
                update_post_meta( $post_id, $key, $new_val );
            }
        };

        $post_args = [ 'ID' => $post_id ];
        $do_update_post = false;

        $desc = $get( 'description' );
        if ( $desc !== null ) {
            if ( ! ( $is_update && $strategy === 'merge' && in_array('post_content', $conflict_fields) && ! empty( get_post($post_id)->post_content ) ) ) {
                $post_args['post_content'] = wp_kses_post( (string) $desc );
                $do_update_post = true;
            }
        }

        $short_desc = $get( 'short_desc' );
        if ( $short_desc !== null ) {
            if ( ! ( $is_update && $strategy === 'merge' && in_array('post_excerpt', $conflict_fields) && ! empty( get_post($post_id)->post_excerpt ) ) ) {
                $post_args['post_excerpt'] = wp_kses_post( (string) $short_desc );
                $do_update_post = true;
            }
        }
        
        $title_val = $get('title');
        if ( $title_val !== null ) {
             if ( ! ( $is_update && $strategy === 'merge' && in_array('post_title', $conflict_fields) && ! empty( get_post($post_id)->post_title ) ) ) {
                $post_args['post_title'] = sanitize_text_field( (string) $title_val );
                $do_update_post = true;
            }
        }

        if ( $do_update_post ) {
            wp_update_post( $post_args );
        }

        $price = $get( 'price' );
        $sale_price = $get( 'sale_price' );
        if ( $price !== null ) {
            $update_field( '_regular_price', (float) $price );
            $update_field( '_price', $sale_price !== null ? (float) $sale_price : (float) $price );
        }
        if ( $sale_price !== null ) {
            $update_field( '_sale_price', (float) $sale_price );
        }
        
        if ( $get('sku') !== null ) $update_field( '_sku', sanitize_text_field( (string) $get( 'sku' ) ) );
        if ( $get('weight') !== null ) $update_field( '_weight', (float) $get( 'weight' ) );
        
        $stock = $get( 'stock' );
        if ( $stock !== null ) {
            $update_field( '_manage_stock', 'yes' );
            $update_field( '_stock', (int) $stock );
            $update_field( '_stock_status', ( (int) $stock > 0 ? 'instock' : 'outofstock' ) );
        }

        $category = $get( 'category' );
        if ( empty( $category ) && ! empty( $settings['wc_category'] ) ) {
            $category = $settings['wc_category'];
        }
        if ( ! empty( $category ) && ! ( $is_update && $strategy === 'merge' && in_array('category', $conflict_fields) && has_term('', 'product_cat', $post_id) ) ) {
            $cats = is_array( $category ) ? $category : [ $category ];
            $term_ids = [];
            foreach ( $cats as $cat_name ) {
                $term = get_term_by( 'name', $cat_name, 'product_cat' );
                if ( ! $term ) {
                    $new_term = wp_insert_term( sanitize_text_field($cat_name), 'product_cat' );
                    if ( ! is_wp_error( $new_term ) ) $term_ids[] = $new_term['term_id'];
                } else {
                    $term_ids[] = $term->term_id;
                }
            }
            if ( ! empty( $term_ids ) ) wp_set_object_terms( $post_id, $term_ids, 'product_cat', false );
        }

        $tags = $get( 'tags' );
        $tag_prefix = trim( $settings['tag_prefix'] ?? '' );
        if ( ! empty( $tags ) && ! ( $is_update && $strategy === 'merge' && in_array('tags', $conflict_fields) && has_term('', 'product_tag', $post_id) ) ) {
            $tag_list = is_array( $tags ) ? $tags : [ $tags ];
            if ( $tag_prefix !== '' ) {
                $tag_list = array_map( fn($t) => $tag_prefix . sanitize_text_field( (string) $t ), $tag_list );
            }
            wp_set_object_terms( $post_id, $tag_list, 'product_tag', false );
        }

        $brand = $get( 'brand' );
        if ( ! empty( $brand ) ) {
            $update_field( '_awi_brand', sanitize_text_field( (string) $brand ) );
        }

        if ( ! empty( $settings['import_images'] ) ) {
            $image_url = $get( 'image' );
            if ( is_array( $image_url ) ) $image_url = reset( $image_url );
            if ( ! empty( $image_url ) && ! ( $is_update && $strategy === 'merge' && in_array('image', $conflict_fields) && has_post_thumbnail($post_id) ) ) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                $thumb_id = media_sideload_image( $image_url, $post_id, '', 'id' );
                if ( ! is_wp_error( $thumb_id ) ) set_post_thumbnail( $post_id, $thumb_id );
            }
        }

        wp_set_object_terms( $post_id, 'simple', 'product_type' );
        return $is_update ? "updated:$post_id" : (int) $post_id;
    }

    public static function fetch_preview( string $conn_id ): array {
        $settings = AWI_Connection_Manager::get( $conn_id );
        if ( ! $settings ) return [ 'error' => 'Connection not found.' ];

        $res = AWI_API_Fetcher::fetch_preview_page( $conn_id );
        if ( is_string( $res ) ) return [ 'error' => $res ];

        $raw = $res['data'];
        $analysis = AWI_Field_Mapper::analyze( $raw );
        if ( isset( $analysis['error'] ) ) return [ 'error' => $analysis['error'] ];

        $map      = ! empty( $settings['field_map'] ) ? $settings['field_map'] : $analysis['map'];
        $products = AWI_Field_Mapper::get_products_from_raw( $raw, $analysis['products_key'] );

        $rows = [];
        foreach ( $products as $item ) {
            $display   = AWI_Field_Mapper::product_display_label( $item, $map );
            $source_key = $conn_id . ':' . $display['ext_id'];
            $imported  = false;
            if ( ! empty( $display['ext_id'] ) ) {
                global $wpdb;
                $imported = (bool) $wpdb->get_var( $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_awi_source' AND meta_value = %s LIMIT 1",
                    $source_key
                ) );
            }
            $display['imported'] = $imported;
            $rows[]              = $display;
        }

        return [
            'products' => $rows,
            'total'    => count( $rows ),
            'map'      => $map,
            'analysis' => $analysis,
        ];
    }

    public static function count_imported( string $conn_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_awi_conn_id' AND meta_value = %s",
            $conn_id
        ) );
    }

    public static function delete_imported( string $conn_id ): int {
        global $wpdb;
        $total_deleted = 0;
        
        while ( true ) {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_awi_conn_id' AND meta_value = %s LIMIT 50",
                $conn_id
            ) );
            
            if ( empty( $ids ) ) break;
            
            foreach ( $ids as $id ) {
                if ( wp_delete_post( $id, true ) ) {
                    $total_deleted++;
                }
            }
        }
        
        return $total_deleted;
    }
}

add_action( 'awi_process_import_batch', [ 'AWI_Importer', 'process_batch' ], 10, 3 );
add_action( 'awi_process_import_batch_cron', [ 'AWI_Importer', 'process_batch' ], 10, 3 );
