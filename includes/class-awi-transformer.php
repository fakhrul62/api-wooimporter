<?php
/**
 * AWI_Transformer
 *
 * Handles field data transformations.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class AWI_Transformer {

    public static function transform( $value, array $rules ) {
        if ( empty( $rules ) ) {
            return $value;
        }

        foreach ( $rules as $rule ) {
            $type = $rule['type'] ?? $rule['transform'] ?? '';
            $arg  = $rule['arg'] ?? $rule['factor'] ?? '';

            if ( is_array( $value ) ) {
                foreach ( $value as &$v ) {
                    $v = self::apply_single( $v, $type, $arg );
                }
                unset( $v );
            } else {
                $value = self::apply_single( $value, $type, $arg );
            }
        }

        return $value;
    }

    private static function apply_single( $val, $type, $arg ) {
        switch ( $type ) {
            case 'multiply':
                if ( is_numeric( $val ) && is_numeric( $arg ) ) {
                    $val = (float) $val * (float) $arg;
                }
                break;
            case 'divide':
                if ( is_numeric( $val ) && is_numeric( $arg ) && (float) $arg !== 0.0 ) {
                    $val = (float) $val / (float) $arg;
                }
                break;
            case 'round':
                if ( is_numeric( $val ) ) {
                    $precision = is_numeric( $arg ) ? (int) $arg : 0;
                    $val = round( (float) $val, $precision );
                }
                break;
            case 'prepend':
                $val = $arg . $val;
                break;
            case 'append':
                $val = $val . $arg;
                break;
            case 'regex_replace':
                // arg format: pattern|replacement
                $parts = explode( '|', $arg );
                if ( count( $parts ) >= 2 ) {
                    $pattern = '/' . trim( $parts[0], '/' ) . '/';
                    $replacement = $parts[1];
                    $val = preg_replace( $pattern, $replacement, (string) $val );
                }
                break;
            case 'uppercase':
                $val = strtoupper( (string) $val );
                break;
            case 'lowercase':
                $val = strtolower( (string) $val );
                break;
            case 'strip_html':
                $val = wp_strip_all_tags( (string) $val );
                break;
        }
        return $val;
    }
}
