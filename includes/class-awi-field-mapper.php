<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AWI_Field_Mapper {

    const WC_FIELDS = [
        'external_id'  => [ 'label' => 'External ID (required)',   'required' => true  ],
        'title'        => [ 'label' => 'Product Title (required)',  'required' => true  ],
        'description'  => [ 'label' => 'Description',              'required' => false ],
        'short_desc'   => [ 'label' => 'Short Description',        'required' => false ],
        'price'        => [ 'label' => 'Regular Price',            'required' => false ],
        'sale_price'   => [ 'label' => 'Sale Price',               'required' => false ],
        'sku'          => [ 'label' => 'SKU',                      'required' => false ],
        'stock'        => [ 'label' => 'Stock Quantity',           'required' => false ],
        'weight'       => [ 'label' => 'Weight',                   'required' => false ],
        'category'     => [ 'label' => 'Category',                 'required' => false ],
        'tags'         => [ 'label' => 'Tags',                     'required' => false ],
        'image'        => [ 'label' => 'Main Image URL',           'required' => false ],
        'gallery'      => [ 'label' => 'Gallery Images',           'required' => false ],
        'brand'        => [ 'label' => 'Brand / Manufacturer',     'required' => false ],
    ];

    const PATTERNS = [
        'external_id'  => ['id','product_id','pid','uid','external_id','item_id','ref_id','objectId'],
        'title'        => ['title','name','product_name','productname','item_name','label'],
        'description'  => ['description','desc','body','content','details','long_description','longDescription'],
        'short_desc'   => ['short_description','short_desc','summary','excerpt','overview','subtitle'],
        'price'        => ['price','regular_price','cost','amount','unit_price','retail_price','regularPrice','unitPrice'],
        'sale_price'   => ['sale_price','discount_price','special_price','offer_price','discountedPrice','salePrice'],
        'sku'          => ['sku','code','product_code','item_code','barcode','upc','isbn','ref','partNumber'],
        'stock'        => ['stock','quantity','qty','inventory','available_qty','availableQuantity','stock_quantity','stockQuantity'],
        'weight'       => ['weight','mass','grams','kg','lbs','weightInGrams'],
        'category'     => ['category','categories','cat','type','department','section','group','classification'],
        'tags'         => ['tags','tag','labels','keywords','attributes','hashtags'],
        'image'        => ['thumbnail','image','photo','picture','img','cover','main_image','primaryImage','featured_image','imageUrl','thumbnailUrl'],
        'gallery'      => ['images','gallery','photos','pictures','additional_images','imageList'],
        'brand'        => ['brand','manufacturer','vendor','make','company','supplier'],
    ];

    public static function analyze( $raw_data ): array {
        if ( is_string( $raw_data ) ) $raw_data = json_decode( $raw_data, true );
        if ( ! is_array( $raw_data ) ) {
            return [ 'error' => 'API returned invalid data — not a JSON object or array.' ];
        }
        [ $products_key, $products ] = self::find_products_array( $raw_data );
        if ( empty( $products ) ) {
            return [ 'error' => 'Could not locate a products list in the API response.' ];
        }
        $sample   = $products[0] ?? [];
        $all_keys = self::extract_flat_keys( $sample );
        $map      = self::auto_map( $all_keys );
        return [
            'products_key' => $products_key,
            'total_found'  => count( $products ),
            'sample'       => $sample,
            'all_keys'     => $all_keys,
            'map'          => $map,
        ];
    }

    public static function find_products_array( array $data ): array {
        $candidates = [];
        self::walk_for_candidates( $data, $candidates, 1, 4, '' );
        
        if ( empty( $candidates ) ) {
            return [ '', [] ];
        }
        
        usort( $candidates, function( $a, $b ) {
            return $b['score'] <=> $a['score'];
        });
        
        return [ $candidates[0]['path'], $candidates[0]['data'] ];
    }
    
    private static function walk_for_candidates( $data, &$candidates, $depth, $max_depth, $path ) {
        if ( $depth > $max_depth || ! is_array( $data ) ) return;

        if ( isset( $data[0] ) && is_array( $data[0] ) ) {
            $score = self::score_candidate( $data, $path );
            if ( $score > 0 ) {
                $candidates[] = [
                    'path'  => $path === '' ? '__root__' : $path,
                    'data'  => $data,
                    'score' => $score
                ];
            }
        }
        
        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) && ! isset( $value[0] ) ) { // only walk associative arrays (objects)
                $new_path = $path === '' ? (string) $key : $path . '.' . $key;
                self::walk_for_candidates( $value, $candidates, $depth + 1, $max_depth, $new_path );
            }
        }
    }
    
    private static function score_candidate( $data, $path ) {
        $score = 0;
        $count = count( $data );
        
        $score += $count >= 5 ? 10 : $count;
        
        $sample = $data[0];
        $flat_keys = self::extract_flat_keys( $sample );
        $lowered_keys = array_map( 'strtolower', $flat_keys );
        
        $matched_patterns = 0;
        foreach ( self::PATTERNS as $wc_field => $patterns ) {
            foreach ( $patterns as $pattern ) {
                if ( in_array( strtolower( $pattern ), $lowered_keys ) ) {
                    $matched_patterns++;
                    break;
                }
            }
        }
        
        $score += ( $matched_patterns * 5 );
        
        $path_lower = strtolower( $path );
        foreach ( ['products','items','data','results','records','entries','list','collection'] as $good_name ) {
            if ( strpos( $path_lower, $good_name ) !== false ) {
                $score += 5;
                break;
            }
        }
        
        return $score;
    }

    public static function get_products_from_raw( $raw, string $products_key ): array {
        if ( is_string( $raw ) ) $raw = json_decode( $raw, true );
        if ( ! is_array( $raw ) ) return [];
        if ( $products_key === '__root__' || $products_key === 'auto' ) {
            if ( isset( $raw[0] ) ) return $raw;
            foreach ( ['products','items','data','results','records','entries','list'] as $k ) {
                if ( isset( $raw[$k] ) && is_array( $raw[$k] ) ) return $raw[$k];
            }
            return [];
        }
        
        $parts = explode( '.', $products_key );
        $curr = $raw;
        foreach ( $parts as $p ) {
            if ( ! isset( $curr[ $p ] ) ) return [];
            $curr = $curr[ $p ];
        }
        return is_array( $curr ) ? $curr : [];
    }

    public static function extract_flat_keys( array $item ): array {
        $keys = [];
        foreach ( $item as $k => $v ) {
            $keys[] = (string) $k;
            if ( is_array( $v ) && ! isset( $v[0] ) ) {
                foreach ( $v as $sk => $sv ) {
                    if ( ! is_array( $sv ) ) $keys[] = $k . '.' . $sk;
                }
            }
        }
        return $keys;
    }

    public static function auto_map( array $api_keys ): array {
        $map     = [];
        $lowered = [];
        foreach ( $api_keys as $k ) $lowered[ strtolower( $k ) ] = $k;
        foreach ( self::PATTERNS as $wc_field => $patterns ) {
            foreach ( $patterns as $pattern ) {
                if ( isset( $lowered[ strtolower( $pattern ) ] ) ) {
                    $map[ $wc_field ] = $lowered[ strtolower( $pattern ) ];
                    break;
                }
            }
        }
        return $map;
    }

    public static function get_value( array $item, string $key ) {
        if ( strpos( $key, '.' ) !== false ) {
            [ $parent, $child ] = explode( '.', $key, 2 );
            return $item[ $parent ][ $child ] ?? null;
        }
        return $item[ $key ] ?? null;
    }

    public static function product_display_label( array $item, array $map ): array {
        $title = $price = $image = $cat = $sku = $stock = $ext_id = '';
        if ( ! empty( $map['title'] ) )       $title  = self::get_value( $item, $map['title'] )      ?? '';
        if ( ! empty( $map['price'] ) )       $price  = self::get_value( $item, $map['price'] )      ?? '';
        if ( ! empty( $map['image'] ) )       $image  = self::get_value( $item, $map['image'] )      ?? '';
        if ( ! empty( $map['category'] ) )    $cat    = self::get_value( $item, $map['category'] )   ?? '';
        if ( ! empty( $map['sku'] ) )         $sku    = self::get_value( $item, $map['sku'] )        ?? '';
        if ( ! empty( $map['stock'] ) )       $stock  = self::get_value( $item, $map['stock'] )      ?? '';
        if ( ! empty( $map['external_id'] ) ) $ext_id = self::get_value( $item, $map['external_id'] ) ?? '';
        if ( is_array( $image ) ) $image = reset( $image );
        return compact( 'title', 'price', 'image', 'cat', 'sku', 'stock', 'ext_id' );
    }
}
