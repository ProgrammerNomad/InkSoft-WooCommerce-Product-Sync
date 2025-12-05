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
        $logs[] = "Saved InkSoft product ID: {$product_id}";

        $attr_validation = $manager->validate_product_attributes( $product, $logs );
        $is_variable = $attr_validation['is_variable'];

        if ( $is_variable ) {
            update_post_meta( $product_id_wp, '_product_type', 'variable' );
            $manager->clear_product_cache( $product_id_wp, $logs );
            $manager->create_product_variations( $product_id_wp, $product, $price, $logs );
            $logs[] = "Created as variable product";
        } else {
            update_post_meta( $product_id_wp, '_product_type', 'simple' );
            $manager->clear_product_cache( $product_id_wp, $logs );
            $logs[] = "Created as simple product";
        }

        update_post_meta( $product_id_wp, '_stock_status', 'instock' );
        update_post_meta( $product_id_wp, '_stock', 999 );

        wp_send_json_success( array(
            'message' => 'Product synced successfully',
            'product_id' => $product_id,
            'wp_id' => $product_id_wp,
            'logs' => $logs
        ) );
    }
}

new InkSoft_Sync_AJAX();
