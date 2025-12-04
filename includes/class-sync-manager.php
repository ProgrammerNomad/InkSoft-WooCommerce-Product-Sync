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

        $api_key = $settings['api_key'] ?? ( get_option( 'inksoft_woo_settings' )['api_key'] ?? '' );
        if ( empty( $api_key ) ) {
            return array( 'success' => false, 'error' => 'API key not set', 'logs' => $logs );
        }

        $base = rtrim( 'https://stores.inksoft.com/' . trim( $store_uri ), '/' );
        $api = new INKSOFT_API( $api_key, $base );

        $result = $api->request( 'GetProductBaseList', array( 'Page' => (int) $page, 'PageSize' => (int) $page_size ) );

        if ( ! $result['success'] ) {
            return array( 'success' => false, 'error' => $result['error'] ?? 'API error', 'logs' => $logs );
        }

        $products = $result['data'] ?? array();
        $pagination = $result['pagination'] ?? null;
        $totalResults = $pagination['TotalResults'] ?? count( $products );

        $processed = 0;

        foreach ( $products as $p ) {
            $sku = trim( $p['Sku'] ?? ( $p['ID'] ?? '' ) );
            if ( empty( $sku ) ) {
                $sku = 'inksoft-' . ($p['ID'] ?? rand(100000,999999));
            }

            $existing_id = wc_get_product_id_by_sku( $sku );

            $price = 0;
            if ( ! empty( $p['Styles'][0]['Price'] ) ) {
                $price = floatval( $p['Styles'][0]['Price'] );
            }

            $markup = floatval( $settings['markup'] ?? ( get_option( 'inksoft_woo_settings' )['markup'] ?? 0 ) );
            $price = $price * ( 1 + ( $markup / 100 ) );

            // Prepare product data
            $post_data = array(
                'post_title' => wp_strip_all_tags( $p['Name'] ?? 'InkSoft Product' ),
                'post_content' => $p['Description'] ?? '',
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

            // Set price
            if ( $price !== null ) {
                update_post_meta( $product_id, '_regular_price', wc_format_decimal( $price ) );
                update_post_meta( $product_id, '_price', wc_format_decimal( $price ) );
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
            if ( ! empty( $p['Styles'][0]['ImageFilePath'] ) ) {
                $image_path = $p['Styles'][0]['ImageFilePath'];
                $image_url = rtrim( $base, '/' ) . '/' . ltrim( $image_path, '/' );

                $this->maybe_set_featured_image( $product_id, $image_url, $image_replace );
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
        if ( empty( $image_url ) ) return false;

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
            return false;
        }

        $file_array = array();
        $file_array['name'] = basename( $image_url );
        $file_array['tmp_name'] = $tmp;

        $id = media_handle_sideload( $file_array, $post_id );
        if ( is_wp_error( $id ) ) {
            @unlink( $file_array['tmp_name'] );
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
