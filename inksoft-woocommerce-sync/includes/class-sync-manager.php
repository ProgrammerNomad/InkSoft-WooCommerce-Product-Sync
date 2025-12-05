<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class InkSoft_Sync_Manager {

    public function __construct() {
    }

    /**
     * Check if product has valid attributes (Styles with Names, Sizes, etc)
     * This determines if product should be VARIABLE or SIMPLE
     *
     * @param array $product - Product data from InkSoft API
     * @param array &$logs - Logs array to append debug info
     * @return array - ['is_variable' => bool, 'reason' => string]
     */
    public function validate_product_attributes( $product, &$logs ) {
        $logs[] = "[DEBUG] Validating product attributes for ID: " . ( $product['ID'] ?? 'unknown' );

        // Check if Styles array exists and has items
        if ( empty( $product['Styles'] ) || ! is_array( $product['Styles'] ) ) {
            $logs[] = "[DEBUG] No Styles array found - SIMPLE product";
            return array( 'is_variable' => false, 'reason' => 'No Styles array' );
        }

        $style_count = count( $product['Styles'] );
        $logs[] = "[DEBUG] Found {$style_count} style(s)";

        // A product needs at least 2 different styles to be variable
        if ( $style_count < 2 ) {
            $logs[] = "[DEBUG] Only 1 style - SIMPLE product";
            return array( 'is_variable' => false, 'reason' => 'Only 1 style' );
        }

        // Validate that each style has a valid Name AND has Sizes
        $valid_styles_with_sizes = 0;
        $total_variations = 0;
        
        foreach ( $product['Styles'] as $idx => $style ) {
            if ( ! is_array( $style ) || empty( $style['Name'] ) ) {
                $logs[] = "[DEBUG] Style {$idx}: INVALID (missing Name) - skipping";
                continue;
            }

            $style_name = $style['Name'];
            $logs[] = "[DEBUG] Style {$idx}: '{$style_name}'";

            // Check for sizes within this style
            if ( empty( $style['Sizes'] ) || ! is_array( $style['Sizes'] ) ) {
                $logs[] = "[DEBUG]   └─ INVALID: No Sizes array";
                continue;
            }

            $size_count = count( $style['Sizes'] );
            $logs[] = "[DEBUG]   └─ Has {$size_count} size(s)";

            // Validate sizes have Names
            $valid_sizes = 0;
            foreach ( $style['Sizes'] as $size ) {
                if ( is_array( $size ) && ! empty( $size['Name'] ) ) {
                    $valid_sizes++;
                }
            }

            if ( $valid_sizes > 0 ) {
                $valid_styles_with_sizes++;
                $total_variations += $valid_sizes;
                $logs[] = "[DEBUG]   └─ {$valid_sizes} valid size(s) - will create {$valid_sizes} variation(s)";
            } else {
                $logs[] = "[DEBUG]   └─ INVALID: No valid sizes with Names";
            }
        }

        // Product is VARIABLE if it has 2+ valid styles with sizes
        if ( $valid_styles_with_sizes < 2 ) {
            $logs[] = "[DEBUG] Only {$valid_styles_with_sizes} valid style(s) with sizes - SIMPLE product";
            return array( 'is_variable' => false, 'reason' => "Only {$valid_styles_with_sizes} valid style(s)" );
        }

        $logs[] = "[DEBUG] Product has {$valid_styles_with_sizes} valid styles with total {$total_variations} size variations - VARIABLE product";
        return array( 'is_variable' => true, 'reason' => "{$valid_styles_with_sizes} styles x sizes = {$total_variations} variations" );
    }

    /**
     * Sync all configured stores
     * Called by cron job or manual trigger
     */
    public function sync_all_stores() {
        $settings = get_option( 'inksoft_woo_settings', array() );
        $stores_raw = $settings['stores'] ?? '';
        $stores = array_filter( array_map( 'trim', explode( ',', $stores_raw ) ) );

        if ( empty( $stores ) ) {
            return array( 'success' => false, 'error' => 'No stores configured' );
        }

        $total_processed = 0;
        $total_pages = 0;
        $errors = array();

        foreach ( $stores as $store_uri ) {
            $page = 0;
            $page_size = (int) ( $settings['page_size'] ?? 100 );

            while ( true ) {
                $result = $this->process_chunk( $store_uri, $page, $page_size, $settings );

                if ( ! $result['success'] ) {
                    $errors[] = "Store {$store_uri} page {$page}: " . ( $result['error'] ?? 'Unknown error' );
                    break;
                }

                $total_processed += $result['processed'];
                $total_pages++;

                if ( $result['nextPage'] === null ) {
                    break;
                }

                $page = $result['nextPage'];
            }

            // Delete missing products for this store if enabled
            if ( (int) ( $settings['delete_missing'] ?? 1 ) ) {
                $this->delete_missing_products( $store_uri );
            }
        }

        return array(
            'success' => true,
            'processed' => $total_processed,
            'pages' => $total_pages,
            'errors' => $errors,
        );
    }

    /**
     * Process a single page (chunk) for a store
     * Returns array with keys: success, processed, totalResults, nextPage, logs
     */
    public function process_chunk( $store_uri, $page = 0, $page_size = 100, $settings = array() ) {
        $logs = array();
        $logs[] = "[DEBUG] process_chunk started - store={$store_uri}, page={$page}, page_size={$page_size}";

        $api_key = $settings['api_key'] ?? ( get_option( 'inksoft_woo_settings' )['api_key'] ?? '' );
        if ( empty( $api_key ) ) {
            $logs[] = "[ERROR] API key not set";
            return array( 'success' => false, 'error' => 'API key not set', 'logs' => $logs );
        }
        $logs[] = "[DEBUG] API key found";

        $base = rtrim( 'https://stores.inksoft.com/' . trim( $store_uri ), '/' );
        $logs[] = "[DEBUG] API base URL: {$base}";
        $api = new INKSOFT_API( $api_key, $base );

        $logs[] = "[DEBUG] Calling API GetProductBaseList...";
        $result = $api->request( 'GetProductBaseList', array( 'Page' => (int) $page, 'PageSize' => (int) $page_size ) );

        if ( ! $result['success'] ) {
            $logs[] = "[ERROR] API request failed: " . ( $result['error'] ?? 'Unknown error' );
            return array( 'success' => false, 'error' => $result['error'] ?? 'API error', 'logs' => $logs );
        }

        $products = $result['data'] ?? array();
        $pagination = $result['pagination'] ?? null;
        $totalResults = $pagination['TotalResults'] ?? count( $products );

        $logs[] = "[DEBUG] API response received - product count: " . count( $products ) . ", total results: {$totalResults}";

        $processed = 0;

        foreach ( $products as $p ) {
            $logs[] = "[DEBUG] Processing product ID: " . $p['ID'];

            // Get detailed product info including pricing and sizes
            $logs[] = "[DEBUG] Fetching detailed product data for ID: " . $p['ID'];
            $detail_result = $api->get_product_detail( $p['ID'] );
            
            if ( ! $detail_result['success'] ) {
                $logs[] = "[WARNING] Could not fetch detailed product info for ID: " . $p['ID'];
                continue;
            }
            
            $p = $detail_result['product']; // Use detailed product data
            
            // Get SKU - use original format to avoid duplicates on re-sync
            $sku = $p['Sku'] ?? $p['SKU'] ?? ('inksoft-' . $p['ID']);
            
            $existing_id = wc_get_product_id_by_sku( $sku );

            // Get price: try Styles[0].Price first, then UnitPrice, then UnitCost, default to 0
            $price = 0;
            $price_source = 'default';
            
            if ( ! empty( $p['Styles'][0]['Price'] ) ) {
                $price = floatval( $p['Styles'][0]['Price'] );
                $price_source = 'Styles[0].Price';
            } elseif ( ! empty( $p['UnitPrice'] ) ) {
                $price = floatval( $p['UnitPrice'] );
                $price_source = 'UnitPrice';
            } elseif ( ! empty( $p['UnitCost'] ) ) {
                $price = floatval( $p['UnitCost'] );
                $price_source = 'UnitCost';
            }

            // Apply markup
            $markup = floatval( $settings['markup'] ?? ( get_option( 'inksoft_woo_settings' )['markup'] ?? 0 ) );
            if ( $price > 0 ) {
                $price = $price * ( 1 + ( $markup / 100 ) );
            }
            
            $logs[] = "[DEBUG] Price: {$price} (source: {$price_source}, markup: {$markup}%)";

            // Get description (long description or fallback)
            $description = $p['LongDescription'] ?? $p['Description'] ?? '';

            // Prepare product data
            $post_data = array(
                'post_title' => wp_strip_all_tags( $p['Name'] ?? 'InkSoft Product' ),
                'post_content' => $description,
                'post_status' => 'publish',
                'post_type' => 'product',
            );

            if ( $existing_id ) {
                // update
                wp_update_post( array_merge( array( 'ID' => $existing_id ), $post_data ) );
                $product_id = $existing_id;
                $logs[] = "Updated product SKU={$sku} (ID={$product_id})";
            } else {
                // create
                $post_data['post_excerpt'] = $p['ShortDescription'] ?? '';
                $product_id = wp_insert_post( $post_data );
                if ( is_wp_error( $product_id ) ) {
                    $logs[] = "Failed to create product SKU={$sku}: " . $product_id->get_error_message();
                    continue;
                }
                update_post_meta( $product_id, '_sku', $sku );
                update_post_meta( $product_id, '_visibility', 'visible' );
                $logs[] = "Created product SKU={$sku} (ID={$product_id})";
            }

            // Set price (or default to 0 if no price found)
            try {
                update_post_meta( $product_id, '_regular_price', wc_format_decimal( $price ) );
                update_post_meta( $product_id, '_price', wc_format_decimal( $price ) );
                $logs[] = "[DEBUG] Price set: {$price}";
            } catch ( Exception $e ) {
                $logs[] = "[ERROR] Failed to set price: " . $e->getMessage();
            }
            
            // Validate product attributes and set correct product type
            try {
                // Check if product has valid attributes for variable product
                $attr_validation = $this->validate_product_attributes( $p, $logs );
                $is_variable = $attr_validation['is_variable'];
                $validation_reason = $attr_validation['reason'];
                
                if ( $is_variable ) {
                    $logs[] = "[DEBUG] Product classified as VARIABLE ({$validation_reason})";
                    // Set product type to variable using meta
                    update_post_meta( $product_id, '_product_type', 'variable' );
                    // Clear ALL product caches to force WooCommerce to re-read the type
                    $this->clear_product_cache( $product_id, $logs );
                    $logs[] = "[DEBUG] Product type set to: variable";
                    // Create variations for each style+size combination
                    $this->create_product_variations( $product_id, $p, $price, $logs );
                } else {
                    $logs[] = "[DEBUG] Product classified as SIMPLE ({$validation_reason})";
                    // Set as simple product
                    update_post_meta( $product_id, '_product_type', 'simple' );
                    // Clear ALL product caches
                    $this->clear_product_cache( $product_id, $logs );
                    $logs[] = "[DEBUG] Product type set to: simple";
                }
            } catch ( Exception $e ) {
                $logs[] = "[ERROR] Failed to validate/set product type: " . $e->getMessage();
            }
            
            // Set stock status to instock
            try {
                update_post_meta( $product_id, '_stock_status', 'instock' );
            } catch ( Exception $e ) {
                $logs[] = "[ERROR] Failed to set stock status: " . $e->getMessage();
            }
            
            // Set default stock
            try {
                update_post_meta( $product_id, '_stock', 999 );
            } catch ( Exception $e ) {
                $logs[] = "[ERROR] Failed to set stock: " . $e->getMessage();
            }
            
            // Add manufacturer and supplier as meta
            try {
                if ( ! empty( $p['Manufacturer'] ) ) {
                    update_post_meta( $product_id, '_manufacturer', $p['Manufacturer'] );
                    $logs[] = "[DEBUG] Manufacturer set: " . $p['Manufacturer'];
                }
                if ( ! empty( $p['Supplier'] ) ) {
                    update_post_meta( $product_id, '_supplier', $p['Supplier'] );
                    $logs[] = "[DEBUG] Supplier set: " . $p['Supplier'];
                }
            } catch ( Exception $e ) {
                $logs[] = "[ERROR] Failed to set manufacturer/supplier: " . $e->getMessage();
            }

            // Categories (best-effort)
            $cats = array();
            if ( ! empty( $p['Categories'] ) && is_array( $p['Categories'] ) ) {
                foreach ( $p['Categories'] as $c ) {
                    $name = is_array( $c ) ? ( $c['Name'] ?? '' ) : $c;
                    if ( empty( $name ) ) continue;
                    $term = term_exists( $name, 'product_cat' );
                    if ( ! $term ) {
                        $term = wp_insert_term( $name, 'product_cat' );
                        if ( is_wp_error( $term ) ) continue;
                        $term_id = $term['term_id'];
                    } else {
                        $term_id = is_array( $term ) ? $term['term_id'] : $term[0];
                    }
                    $cats[] = (int) $term_id;
                }
            } elseif ( ! empty( $p['Category'] ) ) {
                $name = $p['Category'];
                $term = term_exists( $name, 'product_cat' );
                if ( ! $term ) {
                    $term = wp_insert_term( $name, 'product_cat' );
                    if ( ! is_wp_error( $term ) ) $cats[] = (int) $term['term_id'];
                } else {
                    $cats[] = (int) ( is_array( $term ) ? $term['term_id'] : $term[0] );
                }
            }

            if ( ! empty( $cats ) ) {
                wp_set_post_terms( $product_id, $cats, 'product_cat' );
            }

            // Images (styles)
            $image_replace = (int) ( $settings['image_replace'] ?? ( get_option( 'inksoft_woo_settings' )['image_replace'] ?? 1 ) );
            
            // Download all images from all sides
            $image_ids = array();
            $logs[] = "[DEBUG] Processing images for product {$product_id}";
            
            if ( ! empty( $p['Styles'][0]['Sides'] ) && is_array( $p['Styles'][0]['Sides'] ) ) {
                $logs[] = "[DEBUG] Found " . count( $p['Styles'][0]['Sides'] ) . " sides with images";
                foreach ( $p['Styles'][0]['Sides'] as $idx => $side ) {
                    if ( ! empty( $side['ImageFilePath'] ) ) {
                        $image_path = $side['ImageFilePath'];
                        // Image paths are absolute paths on stores.inksoft.com, NOT relative to store
                        $image_url = 'https://stores.inksoft.com' . $image_path;
                        $logs[] = "[DEBUG] Downloading image {$idx}: {$side['Side']} from {$image_url}";
                        
                        $image_id = $this->maybe_set_featured_image( $product_id, $image_url, $image_replace );
                        if ( $image_id ) {
                            $image_ids[] = $image_id;
                            $logs[] = "[DEBUG] Image {$idx} saved with ID={$image_id}";
                        } else {
                            $logs[] = "[ERROR] Failed to download image {$idx}: {$image_url}";
                        }
                    }
                }
            } elseif ( ! empty( $p['Styles'][0]['ImageFilePath'] ) ) {
                // Fallback: use single image if no sides array
                $image_path = $p['Styles'][0]['ImageFilePath'];
                $image_url = 'https://stores.inksoft.com' . $image_path;
                $logs[] = "[DEBUG] Using fallback single image from Styles[0]";
                
                $image_id = $this->maybe_set_featured_image( $product_id, $image_url, $image_replace );
                if ( $image_id ) {
                    $image_ids[] = $image_id;
                    $logs[] = "[DEBUG] Fallback image saved with ID={$image_id}";
                }
            } else {
                $logs[] = "[WARNING] No images found for product {$product_id}";
            }
            
            // Set gallery images (all except the first as featured)
            if ( count( $image_ids ) > 1 ) {
                update_post_meta( $product_id, '_product_image_gallery', implode( ',', array_slice( $image_ids, 1 ) ) );
                $logs[] = "[DEBUG] Set gallery with " . (count($image_ids) - 1) . " additional images";
            } elseif ( count( $image_ids ) === 1 ) {
                $logs[] = "[DEBUG] Single image set as featured only";
            }

            // VERIFICATION: Check product type after creation
            try {
                $product_type_check = get_post_meta( $product_id, '_product_type', true );
                $variation_count = count( get_children( array( 'post_parent' => $product_id, 'post_type' => 'product_variation' ) ) );
                
                // Force a fresh WooCommerce product instance from the factory
                // This will read the updated _product_type meta and create the correct object class
                wp_cache_delete( $product_id, 'post' );
                clean_post_cache( $product_id );
                
                // Use WooCommerce's product factory to create a fresh instance
                if ( class_exists( 'WC_Product_Factory' ) ) {
                    $factory = new WC_Product_Factory();
                    $product_obj = $factory->get_product( $product_id );
                } else {
                    $product_obj = wc_get_product( $product_id );
                }
                $wc_type = $product_obj ? $product_obj->get_type() : 'unknown';
                
                $logs[] = "[VERIFY] Product ID={$product_id} | Meta Type='{$product_type_check}' | WC Type='{$wc_type}' | Variations={$variation_count}";
            } catch ( Exception $e ) {
                $logs[] = "[DEBUG] Verification check: " . $e->getMessage();
            }

            // Track imported SKUs per store
            $this->track_imported_product( $store_uri, $sku, $product_id );

            $processed++;
        }

        // Determine next page
        $nextPage = null;
        $included = $pagination['IncludedResults'] ?? null;
        if ( $pagination && $totalResults > ( ($page + 1) * $page_size ) ) {
            $nextPage = $page + 1;
        }

        return array( 'success' => true, 'processed' => $processed, 'totalResults' => $totalResults, 'nextPage' => $nextPage, 'logs' => $logs );
    }

    protected function track_imported_product( $store_uri, $sku, $product_id ) {
        $opt = 'inksoft_imported_products_' . sanitize_key( $store_uri );
        $list = get_option( $opt, array() );
        $list[ $sku ] = $product_id;
        update_option( $opt, $list );
    }

    protected function maybe_set_featured_image( $post_id, $image_url, $replace = 1 ) {
        if ( empty( $image_url ) ) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $existing = get_post_thumbnail_id( $post_id );
        if ( $existing && ! $replace ) {
            return $existing;
        }

        // Download to temp and sideload
        $tmp = download_url( $image_url );
        if ( is_wp_error( $tmp ) ) {
            error_log( "[InkSoft Sync] Failed to download image: " . $tmp->get_error_message() . " from URL: " . $image_url );
            return false;
        }

        // Get original filename without query params for proper MIME detection
        $parsed_url = parse_url( $image_url );
        $path = $parsed_url['path'];
        $filename = basename( $path );
        
        $file_array = array();
        $file_array['name'] = $filename;
        $file_array['tmp_name'] = $tmp;

        $id = media_handle_sideload( $file_array, $post_id );
        if ( is_wp_error( $id ) ) {
            @unlink( $file_array['tmp_name'] );
            error_log( "[InkSoft Sync] Failed to sideload image: " . $id->get_error_message() . " for post: " . $post_id );
            return false;
        }

        // Optionally remove previous
        if ( $existing && $replace ) {
            wp_delete_attachment( $existing, true );
        }

        set_post_thumbnail( $post_id, $id );
        return $id;
    }

    /**
     * Delete or mark as out-of-stock products that are no longer in InkSoft
     */
    protected function delete_missing_products( $store_uri ) {
        $opt = 'inksoft_imported_products_' . sanitize_key( $store_uri );
        $imported_skus = get_option( $opt, array() );

        if ( empty( $imported_skus ) ) {
            return;
        }

        // Get current products from InkSoft for this store
        $api_key = get_option( 'inksoft_woo_settings' )['api_key'] ?? '';
        if ( empty( $api_key ) ) {
            return;
        }

        $base = rtrim( 'https://stores.inksoft.com/' . trim( $store_uri ), '/' );
        $api = new INKSOFT_API( $api_key, $base );

        $current_skus = array();
        $page = 0;
        while ( true ) {
            $result = $api->request( 'GetProductBaseList', array( 'Page' => $page, 'PageSize' => 100 ) );
            if ( ! $result['success'] ) {
                break;
            }

            $products = $result['data'] ?? array();
            if ( empty( $products ) ) {
                break;
            }

            foreach ( $products as $p ) {
                $sku = trim( $p['Sku'] ?? ( $p['ID'] ?? '' ) );
                if ( empty( $sku ) ) {
                    $sku = 'inksoft-' . ($p['ID'] ?? rand(100000,999999));
                }
                $current_skus[ $sku ] = true;
            }

            $pagination = $result['pagination'] ?? null;
            $totalResults = $pagination['TotalResults'] ?? count( $products );
            if ( $pagination && $totalResults <= ( ($page + 1) * 100 ) ) {
                break;
            }

            $page++;
        }

        // Find missing SKUs and delete their products
        foreach ( $imported_skus as $sku => $product_id ) {
            if ( ! isset( $current_skus[ $sku ] ) ) {
                // Product no longer exists in InkSoft, delete it
                wp_delete_post( $product_id, true );
                unset( $imported_skus[ $sku ] );
            }
        }

        // Update the imported SKUs list
        update_option( $opt, $imported_skus );
    }
    
    /**
     * Ensure attribute exists in WooCommerce
     */
    protected function ensure_attribute( $attribute_slug ) {
        global $wpdb;
        
        $attr = wc_get_attribute( $attribute_slug );
        if ( $attr ) {
            return $attr->get_id();
        }
        
        // Create attribute if doesn't exist
        $attribute_id = wc_create_attribute( array(
            'name' => ucfirst( str_replace( 'pa_', '', $attribute_slug ) ),
            'slug' => $attribute_slug,
            'type' => 'select',
            'orderby' => 'menu_order',
            'has_archives' => true,
        ) );
        
        return $attribute_id;
    }
    
    /**
     * Clear all WooCommerce product caches
     * This forces WooCommerce to re-instantiate the product object with the correct type
     */
    protected function clear_product_cache( $product_id, &$logs ) {
        // Clear WordPress post cache
        wp_cache_delete( $product_id, 'post' );
        wp_cache_delete( $product_id, 'posts' );
        clean_post_cache( $product_id );
        
        // Clear WooCommerce product transients
        if ( function_exists( 'wc_delete_product_transients' ) ) {
            wc_delete_product_transients( $product_id );
        }
        
        // Force WooCommerce factory to clear its internal cache
        if ( class_exists( 'WC_Product_Factory' ) && function_exists( 'WC' ) ) {
            $factory = WC()->product_factory;
            // Clear the internal products cache
            if ( property_exists( $factory, 'products' ) ) {
                unset( $factory->products[ $product_id ] );
            }
        }
        
        $logs[] = "[DEBUG] Cleared all caches for product {$product_id}";
    }

    /**
     * Create product variations for variable products
     * Now uses dynamic attribute mapping - handles ANY structure!
     */
    protected function create_product_variations( $parent_id, $product, $base_price, &$logs ) {
        // Load attribute mapper
        if ( ! class_exists( 'InkSoft_Attribute_Mapper' ) ) {
            require_once dirname( __FILE__ ) . '/class-attribute-mapper.php';
        }

        // Get enabled attributes
        $attribute_config = InkSoft_Attribute_Mapper::get_attribute_config();
        
        if ( empty( $attribute_config ) ) {
            $logs[] = "[ERROR] No attributes configured for variations";
            return;
        }

        $logs[] = "[DEBUG] Creating variations with " . count( $attribute_config ) . " attributes";

        // Ensure all attributes exist in WooCommerce
        InkSoft_Attribute_Mapper::ensure_attributes();

        // Extract values for each attribute
        $attribute_values = array();
        $attribute_keys = array();
        $parent_attributes = array(); // For assigning to parent product

        foreach ( $attribute_config as $attr_key => $attr_data ) {
            $inksoft_path = $attr_data['inksoft_path'] ?? '';
            
            if ( empty( $inksoft_path ) ) {
                continue;
            }

            $values = InkSoft_Attribute_Mapper::extract_attribute_values( $product, $inksoft_path );
            
            if ( ! empty( $values ) ) {
                $attribute_values[] = $values;
                $attribute_keys[] = $attr_key;
                
                // Build parent attribute data
                $attr_slug = $attr_data['attribute_slug'] ?? '';
                $attr_label = $attr_data['attribute_label'] ?? ucfirst( $attr_key );
                $attr_terms = array();
                
                foreach ( $values as $value_item ) {
                    if ( is_array( $value_item ) && ! empty( $value_item['Name'] ) ) {
                        $attr_terms[] = sanitize_title( $value_item['Name'] );
                    }
                }
                
                if ( ! empty( $attr_slug ) && ! empty( $attr_terms ) ) {
                    $parent_attributes[ $attr_slug ] = array(
                        'label' => $attr_label,
                        'terms' => $attr_terms,
                    );
                }
                
                $logs[] = "[DEBUG] Attribute '{$attr_key}': " . count( $values ) . " values";
            }
        }

        if ( empty( $attribute_values ) ) {
            $logs[] = "[WARNING] Could not extract attribute values from product";
            return;
        }

        // CRITICAL: Assign attributes to parent product before creating variations
        $logs[] = "[DEBUG] Assigning " . count( $parent_attributes ) . " attributes to parent product";
        try {
            // Get existing product attributes meta
            $product_attributes = get_post_meta( $parent_id, '_product_attributes', true );
            if ( ! is_array( $product_attributes ) ) {
                $product_attributes = array();
            }
            
            // Get global attribute taxonomy for each attribute
            foreach ( $parent_attributes as $attr_slug => $attr_data ) {
                $attr_terms = $attr_data['terms'] ?? array();
                
                if ( empty( $attr_terms ) ) {
                    continue;
                }
                
                // Check if this attribute already exists in WooCommerce
                global $wpdb;
                $attr_name = str_replace( 'pa_', '', $attr_slug );
                $existing_attr = $wpdb->get_row( $wpdb->prepare(
                    "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s",
                    $attr_name
                ) );
                $attribute_id = $existing_attr ? (int) $existing_attr->attribute_id : 0;
                
                if ( ! $existing_attr ) {
                    // Attribute doesn't exist, create it
                    $wpdb->insert(
                        "{$wpdb->prefix}woocommerce_attribute_taxonomies",
                        array(
                            'attribute_name'    => $attr_name,
                            'attribute_label'   => ucwords( str_replace( '-', ' ', $attr_name ) ),
                            'attribute_type'    => 'select',
                            'attribute_orderby' => 'menu_order',
                            'attribute_public'  => 1,
                        ),
                        array( '%s', '%s', '%s', '%s', '%d' )
                    );
                    $logs[] = "[DEBUG] Created new global product attribute: {$attr_slug}";
                    $attribute_id = (int) $wpdb->insert_id;
                } else {
                    $logs[] = "[DEBUG] Using existing global product attribute: {$attr_slug}";
                }
                
                // Create/get term IDs and slugs for each attribute value
                $term_ids = array();
                $term_slugs = array();
                foreach ( $attr_terms as $term_name ) {
                    // Create sanitized slug
                    $slug = sanitize_title( $term_name );
                    
                    // Get or create term
                    $term_result = wp_insert_term( $term_name, $attr_slug, array( 'slug' => $slug ) );
                    
                    if ( is_wp_error( $term_result ) ) {
                        // Term might already exist
                        $term_obj = get_term_by( 'slug', $slug, $attr_slug );
                        if ( $term_obj ) {
                            $term_ids[] = $term_obj->term_id;
                            $term_slugs[] = $term_obj->slug;
                        }
                    } else {
                        $term_ids[] = $term_result['term_id'];
                        $term_slugs[] = $slug;
                    }
                }
                
                // Register in _product_attributes (WooCommerce's canonical location)
                if ( ! empty( $term_ids ) ) {
                    $product_attributes[ $attr_slug ] = array(
                        'id'         => $attribute_id,
                        'name'       => $attr_slug,
                        'value'      => '',
                        'position'   => 0,
                        'is_visible' => 1,
                        'is_variation' => 1,
                        'is_taxonomy' => 1,
                        'options'    => array_map( 'absint', $term_ids ),
                    );
                    // Also store term IDs mapping separately for later use
                    update_post_meta( $parent_id, $attr_slug . '_ids', implode( '|', $term_ids ) );
                    update_post_meta( $parent_id, $attr_slug, implode( '|', $term_slugs ) );
                    // Assign terms to the parent product so WooCommerce admin shows them selected
                    wp_set_object_terms( $parent_id, $term_ids, $attr_slug, false );
                    $logs[] = "[DEBUG] Set attribute '{$attr_slug}' with " . count( $term_ids ) . " terms (Slugs: " . implode( ',', $term_slugs ) . ", IDs: " . implode( ',', $term_ids ) . ")";
                }
            }
            
            // Force WooCommerce to recognize this as a variable product
            // by instantiating it as WC_Product_Variable and setting attributes
            if ( class_exists( 'WC_Product_Variable' ) ) {
                try {
                    $product_variable = new WC_Product_Variable( $parent_id );
                    
                    // Convert product_attributes format to WooCommerce attribute objects
                    $wc_attributes = array();
                    foreach ( $product_attributes as $attr_slug => $attr_data ) {
                        $attr_obj = new WC_Product_Attribute();
                        $attr_obj->set_name( $attr_slug );
                        
                        // Get term IDs from the stored IDs meta
                        $ids_meta = get_post_meta( $parent_id, $attr_slug . '_ids', true );
                        if ( ! empty( $ids_meta ) ) {
                            // Term IDs were stored in meta, use them directly
                            $term_ids = array_map( 'absint', array_filter( explode( '|', $ids_meta ) ) );
                        } elseif ( ! empty( $attr_data['options'] ) && is_array( $attr_data['options'] ) ) {
                            $term_ids = array_map( 'absint', $attr_data['options'] );
                        } else {
                            // Fallback: look up term IDs from slugs in the value field
                            $term_slugs = explode( ' | ', $attr_data['value'] );
                            $term_ids = array();
                            foreach ( $term_slugs as $slug ) {
                                $term_obj = get_term_by( 'slug', trim( $slug ), $attr_slug );
                                if ( $term_obj ) {
                                    $term_ids[] = $term_obj->term_id;
                                }
                            }
                        }
                        
                        $attr_obj->set_options( $term_ids );
                        $attr_obj->set_position( $attr_data['position'] ?? 0 );
                        $attr_obj->set_visible( $attr_data['is_visible'] ?? 1 );
                        $attr_obj->set_variation( $attr_data['is_variation'] ?? 1 );
                        
                        $wc_attributes[ $attr_slug ] = $attr_obj;
                    }
                    
                    // Set attributes on the product
                    $product_variable->set_attributes( $wc_attributes );
                    $product_variable->save();
                    $logs[] = "[DEBUG] Product instantiated as WC_Product_Variable with attributes and saved";
                } catch ( Exception $e ) {
                    $logs[] = "[WARNING] Failed to instantiate as WC_Product_Variable: " . $e->getMessage();
                }
            } else {
                // Fallback: save attributes to meta if WC_Product_Variable not available
                if ( ! empty( $product_attributes ) ) {
                    update_post_meta( $parent_id, '_product_attributes', $product_attributes );
                }
            }

            // Ensure _product_attributes meta always reflects the latest slug list for admin display
            if ( ! empty( $product_attributes ) ) {
                update_post_meta( $parent_id, '_product_attributes', $product_attributes );
            }
            
            $logs[] = "[SUCCESS] Parent product attributes configured";
        } catch ( Exception $e ) {
            $logs[] = "[ERROR] Failed to assign attributes to parent: " . $e->getMessage();
        }

        // Generate all combinations
        $combinations = InkSoft_Attribute_Mapper::generate_combinations( ...$attribute_values );
        $logs[] = "[DEBUG] Generated " . count( $combinations ) . " variation combinations";

        if ( empty( $combinations ) ) {
            $logs[] = "[WARNING] No variation combinations generated";
            return;
        }

        // Create variations
        $variation_ids = array();
        $base_sku = $product['Sku'] ?? $product['SKU'] ?? 'inksoft-' . $product['ID'];

        foreach ( $combinations as $combination ) {
            // Generate variation data
            $variation_sku = InkSoft_Attribute_Mapper::generate_variation_sku( $base_sku, $combination );
            $variation_title = InkSoft_Attribute_Mapper::generate_variation_title( $product['Name'], $combination );
            $variation_price = InkSoft_Attribute_Mapper::get_variation_price( $combination, $base_price );
            $attribute_meta = InkSoft_Attribute_Mapper::build_attribute_meta( $combination, $attribute_config );

            // Create variation post
            $variation_data = array(
                'post_parent' => $parent_id,
                'post_type' => 'product_variation',
                'post_status' => 'publish',
                'post_title' => $variation_title,
            );

            $variation_id = wp_insert_post( $variation_data );

            if ( $variation_id && ! is_wp_error( $variation_id ) ) {
                // Set meta
                update_post_meta( $variation_id, '_sku', $variation_sku );
                update_post_meta( $variation_id, '_price', wc_format_decimal( $variation_price ) );
                update_post_meta( $variation_id, '_regular_price', wc_format_decimal( $variation_price ) );
                update_post_meta( $variation_id, '_stock_status', 'instock' );
                update_post_meta( $variation_id, '_stock', 999 );

                // Set attributes
                foreach ( $attribute_meta as $attr_slug => $attr_value ) {
                    $variation_meta_key = 'attribute_' . $attr_slug;
                    update_post_meta( $variation_id, $variation_meta_key, $attr_value );
                }

                $variation_ids[] = $variation_id;
            } else {
                $logs[] = "[ERROR] Failed to create variation: {$variation_title}";
            }
        }

        // Set variation IDs on parent
        if ( ! empty( $variation_ids ) ) {
            update_post_meta( $parent_id, '_children', $variation_ids );
            $logs[] = "[SUCCESS] Created " . count( $variation_ids ) . " variations";
        }
    }
}
