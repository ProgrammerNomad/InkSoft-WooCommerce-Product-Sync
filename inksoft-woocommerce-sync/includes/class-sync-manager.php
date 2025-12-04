<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class InkSoft_Sync_Manager {

    public function __construct() {
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
            $sku = trim( $p['Sku'] ?? ( $p['ID'] ?? '' ) );
            if ( empty( $sku ) ) {
                $sku = 'inksoft-' . ($p['ID'] ?? rand(100000,999999));
            }

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
            
            // Set product type to 'simple'
            try {
                update_post_meta( $product_id, '_product_type', 'simple' );
            } catch ( Exception $e ) {
                $logs[] = "[ERROR] Failed to set product type: " . $e->getMessage();
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
                        $image_url = rtrim( $base, '/' ) . '/' . ltrim( $image_path, '/' );
                        $logs[] = "[DEBUG] Downloading image {$idx}: {$side['Side']}";
                        
                        $image_id = $this->maybe_set_featured_image( $product_id, $image_url, $image_replace );
                        if ( $image_id ) {
                            $image_ids[] = $image_id;
                            $logs[] = "[DEBUG] Image {$idx} saved with ID={$image_id}";
                        } else {
                            $logs[] = "[ERROR] Failed to download image {$idx}";
                        }
                    }
                }
            } elseif ( ! empty( $p['Styles'][0]['ImageFilePath'] ) ) {
                // Fallback: use single image if no sides array
                $image_path = $p['Styles'][0]['ImageFilePath'];
                $image_url = rtrim( $base, '/' ) . '/' . ltrim( $image_path, '/' );
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

        $file_array = array();
        $file_array['name'] = basename( $image_url );
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
}
