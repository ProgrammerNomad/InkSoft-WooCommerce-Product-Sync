<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Dynamic Attribute Mapper for flexible product structure handling
 * Supports ANY number of attributes with ANY nesting level
 */
class InkSoft_Attribute_Mapper {

    /**
     * Get default attribute configuration
     * Can be overridden via WordPress options
     */
    public static function get_attribute_config() {
        $default_config = array(
            // Each attribute defines: inksoft_path => woocommerce_attribute
            'color' => array(
                'inksoft_path' => 'Styles',          // Where to find this in API response
                'attribute_slug' => 'pa_color',      // WooCommerce attribute
                'attribute_label' => 'Color',
                'enabled' => true,
            ),
            'size' => array(
                'inksoft_path' => 'Styles.Sizes',    // FIXED: Sizes are inside Styles!
                'attribute_slug' => 'pa_size',
                'attribute_label' => 'Size',
                'enabled' => true,
            ),
            // Example future attributes:
            // 'material' => array(
            //     'inksoft_path' => 'Materials',
            //     'attribute_slug' => 'pa_material',
            //     'attribute_label' => 'Material',
            //     'enabled' => true,
            // ),
        );

        // Allow override via WordPress options
        $custom_config = get_option( 'inksoft_attribute_config', array() );
        
        if ( ! empty( $custom_config ) ) {
            $default_config = array_merge( $default_config, $custom_config );
        }

        return array_filter( $default_config, function( $attr ) {
            return $attr['enabled'] ?? true;
        });
    }

    /**
     * Extract attribute values from product structure
     * Handles nested paths dynamically
     *
     * @param array $product - Full product from InkSoft API
     * @param string $inksoft_path - Path to attribute (e.g., 'Styles', 'Styles.Sizes')
     * @return array - Array of attribute values
     */
    public static function extract_attribute_values( $product, $inksoft_path ) {
        $values = array();
        $path_parts = explode( '.', $inksoft_path );

        if ( count( $path_parts ) === 1 ) {
            // Simple path: Styles
            $attr_name = $path_parts[0];
            if ( ! empty( $product[ $attr_name ] ) && is_array( $product[ $attr_name ] ) ) {
                foreach ( $product[ $attr_name ] as $item ) {
                    if ( is_array( $item ) && ! empty( $item['Name'] ) ) {
                        $values[] = $item;
                    }
                }
            }
        } else if ( count( $path_parts ) === 2 ) {
            // Nested path: e.g., 'Styles.Sizes'
            $parent_key = $path_parts[0];
            $child_key = $path_parts[1];
            
            if ( ! empty( $product[ $parent_key ] ) && is_array( $product[ $parent_key ] ) ) {
                // Collect all child items from all parent items
                foreach ( $product[ $parent_key ] as $parent_item ) {
                    if ( is_array( $parent_item ) && ! empty( $parent_item[ $child_key ] ) && is_array( $parent_item[ $child_key ] ) ) {
                        foreach ( $parent_item[ $child_key ] as $child_item ) {
                            if ( is_array( $child_item ) && ! empty( $child_item['Name'] ) ) {
                                // Avoid duplicates by checking if already in values
                                $child_name = $child_item['Name'];
                                $exists = false;
                                foreach ( $values as $v ) {
                                    if ( $v['Name'] === $child_name ) {
                                        $exists = true;
                                        break;
                                    }
                                }
                                if ( ! $exists ) {
                                    $values[] = $child_item;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        return $values;
    }

    /**
     * Create cartesian product of all attribute values
     * Generates all possible combinations
     *
     * @param array $attributes - Array of attribute arrays
     * @return array - All possible combinations
     */
    public static function generate_combinations( ...$attributes ) {
        if ( empty( $attributes ) ) {
            return array();
        }

        if ( count( $attributes ) === 1 ) {
            return array_map( function( $item ) {
                return array( $item );
            }, $attributes[0] );
        }

        $result = array();
        $rest_combinations = self::generate_combinations( ...array_slice( $attributes, 1 ) );

        foreach ( $attributes[0] as $item ) {
            foreach ( $rest_combinations as $combination ) {
                $result[] = array_merge( array( $item ), $combination );
            }
        }

        return $result;
    }

    /**
     * Generate unique SKU for variation combination
     *
     * @param string $base_sku - Product's base SKU
     * @param array $combination - Array of attribute items
     * @return string - Generated variation SKU
     */
    public static function generate_variation_sku( $base_sku, $combination ) {
        $parts = array( $base_sku );

        foreach ( $combination as $item ) {
            if ( is_array( $item ) && ! empty( $item['Name'] ) ) {
                $parts[] = sanitize_title( $item['Name'] );
            } elseif ( is_string( $item ) ) {
                $parts[] = sanitize_title( $item );
            }
        }

        return implode( '-', $parts );
    }

    /**
     * Get price from variation combination
     * Traverses nested structure to find UnitPrice
     *
     * @param array $combination - Variation attributes
     * @param float $default_price - Fallback price
     * @return float - Determined price
     */
    public static function get_variation_price( $combination, $default_price = 0 ) {
        if ( empty( $combination ) ) {
            return $default_price;
        }

        // Try to get price from deepest item (usually Size)
        $deepest_item = end( $combination );
        
        if ( is_array( $deepest_item ) && ! empty( $deepest_item['UnitPrice'] ) ) {
            return floatval( $deepest_item['UnitPrice'] );
        }

        // Try other items
        foreach ( $combination as $item ) {
            if ( is_array( $item ) && ! empty( $item['UnitPrice'] ) ) {
                return floatval( $item['UnitPrice'] );
            }
        }

        return $default_price;
    }

    /**
     * Generate variation title
     *
     * @param string $product_name - Base product name
     * @param array $combination - Variation attributes
     * @return string - Full variation title
     */
    public static function generate_variation_title( $product_name, $combination ) {
        $parts = array( $product_name );

        foreach ( $combination as $item ) {
            if ( is_array( $item ) && ! empty( $item['Name'] ) ) {
                $parts[] = $item['Name'];
            }
        }

        return implode( ' - ', $parts );
    }

    /**
     * Build attribute meta for variation
     * Creates attribute_pa_X meta entries
     *
     * @param array $combination - Variation attributes
     * @param array $config - Attribute configuration
     * @return array - Meta entries like ['attribute_pa_color' => 'value', ...]
     */
    public static function build_attribute_meta( $combination, $config ) {
        $meta = array();
        $enabled_attrs = self::get_attribute_config();
        $attr_list = array_values( $enabled_attrs );

        foreach ( $combination as $index => $item ) {
            if ( $index >= count( $attr_list ) ) {
                break;
            }

            $attr_config = $attr_list[ $index ];
            $attr_slug = $attr_config['attribute_slug'] ?? '';

            if ( ! empty( $attr_slug ) && is_array( $item ) && ! empty( $item['Name'] ) ) {
                $meta[ $attr_slug ] = sanitize_title( $item['Name'] );
            }
        }

        return $meta;
    }

    /**
     * Ensure WooCommerce attributes exist
     * Creates them if missing
     */
    public static function ensure_attributes() {
        $config = self::get_attribute_config();

        foreach ( $config as $attr_key => $attr_data ) {
            $slug = $attr_data['attribute_slug'] ?? '';
            $label = $attr_data['attribute_label'] ?? ucfirst( $attr_key );

            if ( empty( $slug ) ) {
                continue;
            }

            // Check if exists
            $existing = wc_get_attribute( $slug );
            
            if ( ! $existing ) {
                // Create it
                try {
                    wc_create_attribute( array(
                        'name' => $label,
                        'slug' => str_replace( 'pa_', '', $slug ),
                        'type' => 'select',
                        'order_by' => 'menu_order',
                        'has_archives' => false,
                    ));
                } catch ( Exception $e ) {
                    // Log but don't fail
                    error_log( "Failed to create attribute {$slug}: " . $e->getMessage() );
                }
            }
        }
    }
}
