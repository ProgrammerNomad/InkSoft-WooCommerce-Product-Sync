<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class InkSoft_Sync_AJAX {
    public function __construct() {
        add_action( 'wp_ajax_inksoft_woo_sync_start', array( $this, 'ajax_start' ) );
        add_action( 'wp_ajax_inksoft_woo_sync_process_chunk', array( $this, 'ajax_process_chunk' ) );
        add_action( 'wp_ajax_inksoft_woo_sync_status', array( $this, 'ajax_status' ) );
        add_action( 'wp_ajax_inksoft_woo_get_product_list', array( $this, 'ajax_get_product_list' ) );
        add_action( 'wp_ajax_inksoft_woo_sync_single_product', array( $this, 'ajax_sync_single_product' ) );
        add_action( 'wp_ajax_inksoft_woo_purge_check', array( $this, 'ajax_purge_check' ) );
        add_action( 'wp_ajax_inksoft_woo_purge_get_ids', array( $this, 'ajax_purge_get_ids' ) );
        add_action( 'wp_ajax_inksoft_woo_purge_delete_batch', array( $this, 'ajax_purge_delete_batch' ) );
        add_action( 'wp_ajax_inksoft_woo_purge_cleanup', array( $this, 'ajax_purge_cleanup' ) );
    }

    public function ajax_start() {
        check_ajax_referer( 'inksoft-woo-sync', 'nonce' );
        $settings = get_option( 'inksoft_woo_settings', array() );
        $stores_raw = $settings['stores'] ?? '';
        $stores = array();
        if ( ! empty( $stores_raw ) ) {
            $parts = explode( ',', $stores_raw );
            foreach ( $parts as $p ) {
                $s = trim( $p );
                if ( ! empty( $s ) ) $stores[] = $s;
            }
        }

        if ( empty( $stores ) && ! empty( $settings['stores_single'] ) ) {
            $stores[] = $settings['stores_single'];
        }

        wp_send_json_success( array( 'stores' => $stores ) );
    }

    public function ajax_process_chunk() {
        check_ajax_referer( 'inksoft-woo-sync', 'nonce' );
        $store = sanitize_text_field( $_POST['store'] ?? '' );
        $page = intval( $_POST['page'] ?? 0 );
        $page_size = intval( $_POST['page_size'] ?? ( get_option( 'inksoft_woo_settings' )['page_size'] ?? 100 ) );

        if ( empty( $store ) ) {
            wp_send_json_error( 'Store is required' );
        }

        $settings = get_option( 'inksoft_woo_settings', array() );
        $manager = new InkSoft_Sync_Manager();
        $res = $manager->process_chunk( $store, $page, $page_size, $settings );

        if ( ! empty( $res['logs'] ) ) {
            // append logs to transient
            $key = 'inksoft_sync_log_' . sanitize_key( $store );
            $existing = get_transient( $key ) ?: array();
            $existing = array_merge( $existing, $res['logs'] );
            set_transient( $key, $existing, HOUR_IN_SECONDS );
        }

        wp_send_json( $res );
    }

    public function ajax_status() {
        check_ajax_referer( 'inksoft-woo-sync', 'nonce' );
        $store = sanitize_text_field( $_GET['store'] ?? '' );
        $key = 'inksoft_sync_log_' . sanitize_key( $store );
        $logs = get_transient( $key ) ?: array();
        wp_send_json_success( array( 'logs' => $logs ) );
    }

    public function ajax_get_product_list() {
        check_ajax_referer( 'inksoft-woo-sync', 'nonce' );
        $store = sanitize_text_field( $_POST['store'] ?? '' );
        
        if ( empty( $store ) ) {
            wp_send_json_error( 'Store is required' );
        }

        $settings = get_option( 'inksoft_woo_settings', array() );
        $api_key = $settings['api_key'] ?? '';
        
        if ( empty( $api_key ) ) {
            wp_send_json_error( 'API key not configured' );
        }

        $base = rtrim( 'https://stores.inksoft.com/' . trim( $store ), '/' );
        require_once dirname( __FILE__ ) . '/class-inksoft-api.php';
        $api = new INKSOFT_API( $api_key, $base );

        $all_products = array();
        $page = 0;
        $page_size = 100;

        while ( true ) {
            $result = $api->request( 'GetProductBaseList', array( 'Page' => $page, 'PageSize' => $page_size ) );
            
            if ( ! $result['success'] ) {
                wp_send_json_error( 'API error: ' . ( $result['error'] ?? 'Unknown' ) );
            }

            $products = $result['data'] ?? array();
            $pagination = $result['pagination'] ?? null;
            $total_results = $pagination['TotalResults'] ?? count( $products );

            foreach ( $products as $p ) {
                $all_products[] = array(
                    'id' => $p['ID'],
                    'name' => $p['Name'] ?? 'Product ' . $p['ID'],
                    'sku' => $p['Sku'] ?? $p['SKU'] ?? 'inksoft-' . $p['ID']
                );
            }

            if ( ! $pagination || $total_results <= ( ( $page + 1 ) * $page_size ) ) {
                break;
            }

            $page++;
        }

        wp_send_json_success( array(
            'products' => $all_products,
            'total' => count( $all_products )
        ) );
    }

    public function ajax_sync_single_product() {
        check_ajax_referer( 'inksoft-woo-sync', 'nonce' );
        $store = sanitize_text_field( $_POST['store'] ?? '' );
        $product_id = intval( $_POST['product_id'] ?? 0 );

        if ( empty( $store ) || empty( $product_id ) ) {
            wp_send_json_error( 'Store and product_id are required' );
        }

        $settings = get_option( 'inksoft_woo_settings', array() );
        $api_key = $settings['api_key'] ?? '';
        
        if ( empty( $api_key ) ) {
            wp_send_json_error( 'API key not configured' );
        }

        $base = rtrim( 'https://stores.inksoft.com/' . trim( $store ), '/' );
        require_once dirname( __FILE__ ) . '/class-inksoft-api.php';
        require_once dirname( __FILE__ ) . '/class-sync-manager.php';
        require_once dirname( __FILE__ ) . '/class-attribute-mapper.php';
        
        $api = new INKSOFT_API( $api_key, $base );
        $manager = new InkSoft_Sync_Manager();
        
        $logs = array();
        $logs[] = "Fetching product ID: {$product_id}";
        
        $detail_result = $api->get_product_detail( $product_id );
        
        if ( ! $detail_result['success'] ) {
            wp_send_json_error( array(
                'message' => 'Failed to fetch product details',
                'logs' => $logs
            ) );
        }

        $product = $detail_result['product'];
        $logs[] = "Processing: " . ( $product['Name'] ?? 'Unknown' );

        // Diagnostic: log top-level product fields so we can identify where category data lives.
        $logs[] = '[WARNING] DIAG - Product detail keys: ' . implode( ', ', array_keys( (array) $product ) );
        if ( isset( $product['Categories'] ) ) {
            $logs[] = '[WARNING] DIAG - Categories raw: ' . wp_json_encode( $product['Categories'] );
        } else {
            $logs[] = '[WARNING] DIAG - No Categories key in product detail response';
        }

        $sku = $product['Sku'] ?? $product['SKU'] ?? ( 'inksoft-' . $product_id );
        $existing_id = wc_get_product_id_by_sku( $sku );

        $price = 0;
        if ( ! empty( $product['Styles'][0]['Price'] ) ) {
            $price = floatval( $product['Styles'][0]['Price'] );
        } elseif ( ! empty( $product['UnitPrice'] ) ) {
            $price = floatval( $product['UnitPrice'] );
        } elseif ( ! empty( $product['UnitCost'] ) ) {
            $price = floatval( $product['UnitCost'] );
        }

        $markup = floatval( $settings['markup'] ?? 0 );
        if ( $price > 0 ) {
            $price = $price * ( 1 + ( $markup / 100 ) );
        }

        $description = $product['LongDescription'] ?? $product['Description'] ?? '';

        $post_data = array(
            'post_title' => wp_strip_all_tags( $product['Name'] ?? 'InkSoft Product' ),
            'post_content' => $description,
            'post_status' => 'publish',
            'post_type' => 'product',
        );

        if ( $existing_id ) {
            wp_update_post( array_merge( array( 'ID' => $existing_id ), $post_data ) );
            $product_id_wp = $existing_id;
            $logs[] = "Updated existing product (WP ID: {$product_id_wp})";
        } else {
            $product_id_wp = wp_insert_post( $post_data );
            if ( is_wp_error( $product_id_wp ) ) {
                wp_send_json_error( array( 'message' => 'Failed to create product', 'logs' => $logs ) );
            }
            update_post_meta( $product_id_wp, '_sku', $sku );
            update_post_meta( $product_id_wp, '_visibility', 'visible' );
            $logs[] = "Created new product (WP ID: {$product_id_wp})";
        }

        update_post_meta( $product_id_wp, '_regular_price', wc_format_decimal( $price ) );
        update_post_meta( $product_id_wp, '_price', wc_format_decimal( $price ) );
        
        update_post_meta( $product_id_wp, '_inksoft_product_id', $product_id );
        update_post_meta( $product_id_wp, '_inksoft_store_uri', $store );
        update_post_meta( $product_id_wp, '_inksoft_synced_at', current_time( 'mysql' ) );
        $logs[] = "Saved InkSoft product ID: {$product_id}";

        $attr_validation = $manager->validate_product_attributes( $product, $logs );
        $is_variable = $attr_validation['is_variable'];

        if ( $is_variable ) {
            update_post_meta( $product_id_wp, '_product_type', 'variable' );
            $manager->clear_product_cache( $product_id_wp, $logs );
            // On re-sync, delete existing variations first to avoid duplicates
            if ( $existing_id ) {
                $manager->delete_existing_variations( $product_id_wp, $logs );
            }
            $manager->create_product_variations( $product_id_wp, $product, $price, $logs );
            $logs[] = "Created as variable product";
        } else {
            update_post_meta( $product_id_wp, '_product_type', 'simple' );
            $manager->clear_product_cache( $product_id_wp, $logs );
            $logs[] = "Created as simple product";
        }

        update_post_meta( $product_id_wp, '_stock_status', 'instock' );
        update_post_meta( $product_id_wp, '_stock', 999 );

        // Categories — primary path: use the store-wide category map.
        $cat_map_result = $manager->get_category_map_cached( $api, $store, $logs );
        $cat_map        = $cat_map_result['map'] ?? array();
        $manager->assign_product_categories( $product_id_wp, $product_id, $cat_map, $logs );

        // Categories — fallback path: use the Categories array embedded in the product detail
        // response (available because we pass IncludeCategories=1 to GetProduct).
        // This fires even if the map succeeded, so both sources are additive.
        if ( ! empty( $product['Categories'] ) && is_array( $product['Categories'] ) ) {
            $manager->assign_categories_from_product_detail( $product_id_wp, $product['Categories'], $logs );
        }

        // Images - single source of truth via shared method
        $image_replace = (int) ( $settings['image_replace'] ?? 1 );
        $manager->sync_product_images( $product_id_wp, $product, $image_replace, $logs );

        // Auto-detect display type from InkSoft product capabilities.
        // Only set on first sync; admin manual override via _inksoft_display_type is preserved.
        $existing_type = get_post_meta( $product_id_wp, '_inksoft_display_type', true );
        if ( empty( $existing_type ) ) {
            $can_design = ! empty( $product['CanPrint'] ) || ! empty( $product['CanDigitalPrint'] )
                       || ! empty( $product['CanScreenPrint'] ) || ! empty( $product['CanEmbroider'] );
            update_post_meta( $product_id_wp, '_inksoft_display_type', $can_design ? 'designer' : 'contact_form' );
            $logs[] = "Display type auto-set to " . ( $can_design ? 'designer' : 'contact_form' );
        }

        wp_send_json_success( array(
            'message'    => 'Product synced successfully',
            'product_id' => $product_id,
            'wp_id'      => $product_id_wp,
            'logs'       => $logs,
        ) );
    }

    // -------------------------------------------------------------------------
    // Purge helpers
    // -------------------------------------------------------------------------

    private function get_synced_product_ids() {
        global $wpdb;
        $ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_inksoft_product_id'" );
        return array_map( 'intval', $ids );
    }

    private function get_product_image_ids( array $product_ids ) {
        if ( empty( $product_ids ) ) {
            return array();
        }
        $image_ids = array();
        foreach ( $product_ids as $pid ) {
            $thumb = get_post_thumbnail_id( (int) $pid );
            if ( $thumb ) {
                $image_ids[] = (int) $thumb;
            }
            $gallery = get_post_meta( (int) $pid, '_product_image_gallery', true );
            if ( ! empty( $gallery ) ) {
                foreach ( array_filter( explode( ',', $gallery ) ) as $gid ) {
                    $image_ids[] = (int) $gid;
                }
            }
        }
        return array_values( array_unique( array_filter( $image_ids ) ) );
    }

    private function collect_terms_for_products( array $product_ids, $taxonomy ) {
        $term_ids = array();
        foreach ( $product_ids as $pid ) {
            $terms = wp_get_post_terms( (int) $pid, $taxonomy, array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $terms ) ) {
                $term_ids = array_merge( $term_ids, $terms );
            }
        }
        return array_values( array_unique( $term_ids ) );
    }

    private function get_attr_taxonomies() {
        if ( ! class_exists( 'InkSoft_Attribute_Mapper' ) ) {
            require_once dirname( __FILE__ ) . '/class-attribute-mapper.php';
        }
        $taxonomies = array();
        foreach ( InkSoft_Attribute_Mapper::get_attribute_config() as $attr ) {
            if ( ! empty( $attr['enabled'] ) && ! empty( $attr['attribute_slug'] ) ) {
                $taxonomies[] = $attr['attribute_slug'];
            }
        }
        return $taxonomies;
    }

    /**
     * Delete terms that are no longer used by any posts (exclusively InkSoft-sourced terms).
     * After product deletion the objects_in_term count drops to 0 for exclusive terms.
     * Without product deletion, we skip any term that still has non-InkSoft products.
     */
    private function delete_exclusive_terms( array $term_ids, $taxonomy ) {
        $deleted = 0;
        foreach ( $term_ids as $term_id ) {
            $objects = get_objects_in_term( (int) $term_id, $taxonomy );
            if ( is_wp_error( $objects ) || empty( $objects ) ) {
                wp_delete_term( (int) $term_id, $taxonomy );
                $deleted++;
            }
        }
        return $deleted;
    }

    // -------------------------------------------------------------------------
    // Purge: dry-run count
    // -------------------------------------------------------------------------

    public function ajax_purge_check() {
        check_ajax_referer( 'inksoft-woo-sync', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $product_ids     = $this->get_synced_product_ids();
        $attr_term_count = 0;
        foreach ( $this->get_attr_taxonomies() as $tax ) {
            $attr_term_count += count( $this->collect_terms_for_products( $product_ids, $tax ) );
        }

        wp_send_json_success( array(
            'products'        => count( $product_ids ),
            'images'          => count( $this->get_product_image_ids( $product_ids ) ),
            'categories'      => count( $this->collect_terms_for_products( $product_ids, 'product_cat' ) ),
            'tags'            => count( $this->collect_terms_for_products( $product_ids, 'product_tag' ) ),
            'attribute_terms' => $attr_term_count,
        ) );
    }

    // -------------------------------------------------------------------------
    // Purge step 1: collect IDs and cache term associations for later cleanup
    // -------------------------------------------------------------------------

    public function ajax_purge_get_ids() {
        check_ajax_referer( 'inksoft-woo-sync', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $product_ids = $this->get_synced_product_ids();

        // Collect term associations BEFORE any deletion and store in transient.
        // The cleanup step reads from this transient after products are gone.
        $term_data = array(
            'categories' => $this->collect_terms_for_products( $product_ids, 'product_cat' ),
            'tags'       => $this->collect_terms_for_products( $product_ids, 'product_tag' ),
            'attributes' => array(),
        );
        foreach ( $this->get_attr_taxonomies() as $tax ) {
            $term_data['attributes'][ $tax ] = $this->collect_terms_for_products( $product_ids, $tax );
        }
        set_transient( 'inksoft_purge_term_data', $term_data, HOUR_IN_SECONDS );

        wp_send_json_success( array(
            'product_ids' => $product_ids,
            'total'       => count( $product_ids ),
        ) );
    }

    // -------------------------------------------------------------------------
    // Purge step 2: delete one batch of products (JS calls this in a loop)
    // Batch size is controlled by the JS (default 25). Each request stays fast.
    // -------------------------------------------------------------------------

    public function ajax_purge_delete_batch() {
        check_ajax_referer( 'inksoft-woo-sync', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $product_ids  = array_map( 'intval', (array) ( $_POST['product_ids'] ?? array() ) );
        $del_images   = ! empty( $_POST['delete_images'] );
        $deleted      = 0;
        $imgs_deleted = 0;

        foreach ( $product_ids as $pid ) {
            if ( $del_images ) {
                foreach ( $this->get_product_image_ids( array( $pid ) ) as $img_id ) {
                    if ( wp_delete_attachment( $img_id, true ) ) {
                        $imgs_deleted++;
                    }
                }
            }
            $children = get_children( array(
                'post_parent'    => $pid,
                'post_type'      => 'product_variation',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ) );
            foreach ( $children as $vid ) {
                wp_delete_post( $vid, true );
            }
            if ( wp_delete_post( $pid, true ) ) {
                $deleted++;
            }
        }

        wp_send_json_success( array(
            'deleted'        => $deleted,
            'images_deleted' => $imgs_deleted,
        ) );
    }

    // -------------------------------------------------------------------------
    // Purge step 3: clean up terms, options, verify database
    // Called once after all product batches finish.
    // -------------------------------------------------------------------------

    public function ajax_purge_cleanup() {
        check_ajax_referer( 'inksoft-woo-sync', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $del_categories = ! empty( $_POST['delete_categories'] );
        $del_tags       = ! empty( $_POST['delete_tags'] );
        $del_attributes = ! empty( $_POST['delete_attributes'] );

        // Retrieve term associations collected before deletion
        $term_data = get_transient( 'inksoft_purge_term_data' );
        if ( ! is_array( $term_data ) ) {
            $term_data = array( 'categories' => array(), 'tags' => array(), 'attributes' => array() );
        }
        delete_transient( 'inksoft_purge_term_data' );

        $cats_deleted  = 0;
        $tags_deleted  = 0;
        $attrs_deleted = 0;

        if ( $del_categories && ! empty( $term_data['categories'] ) ) {
            $cats_deleted = $this->delete_exclusive_terms( $term_data['categories'], 'product_cat' );
        }
        if ( $del_tags && ! empty( $term_data['tags'] ) ) {
            $tags_deleted = $this->delete_exclusive_terms( $term_data['tags'], 'product_tag' );
        }
        if ( $del_attributes && ! empty( $term_data['attributes'] ) ) {
            foreach ( $term_data['attributes'] as $tax => $term_ids ) {
                if ( ! empty( $term_ids ) ) {
                    $attrs_deleted += $this->delete_exclusive_terms( $term_ids, $tax );
                }
            }
        }

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'inksoft\_imported\_products\_%'" );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_inksoft\_cat\_map\_%' OR option_name LIKE '\_transient\_timeout\_inksoft\_cat\_map\_%'" );

        $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_inksoft_product_id'" );

        wp_send_json_success( array(
            'categories_deleted'      => $cats_deleted,
            'tags_deleted'            => $tags_deleted,
            'attribute_terms_deleted' => $attrs_deleted,
            'verification'            => array( 'remaining_products' => $remaining ),
        ) );
    }
}

new InkSoft_Sync_AJAX();
